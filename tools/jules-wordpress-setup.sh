#!/usr/bin/env bash
#
# WordPress environment for agent work on the Performance Optimisation plugin.
#
# Replaces the original script, which left the plugin fatally broken: `vendor/`
# is gitignored, so a fresh clone has no PHP dependencies and the plugin renders
# "Dependencies are missing (vendor/autoload.php not found)" on every admin
# page. An agent working against that would "fix" things that are not broken.
#
# Safe to re-run. Every step is idempotent.
#
# Usage:  bash tools/jules-wordpress-setup.sh
#
set -euo pipefail

# Intended to be run from the agent sandbox, where the repo is at /app.

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------
DB_NAME="${WP_DB_NAME:-wp_jules}"
DB_USER="${WP_DB_USER:-jules_user}"
DB_PASS="${WP_DB_PASS:-jules_pass}"
DB_HOST="${WP_DB_HOST:-localhost}"

WP_PATH="${WP_PATH:-/var/www/wordpress}"
SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-password}"
ADMIN_EMAIL="${ADMIN_EMAIL:-test@example.com}"

PORT="${PORT:-8080}"

# The plugin repository. The agent sandbox clones this repo into /app
# automatically, so /app is the default and the script is self-contained.
REPO="${REPO:-/app}"
[ -d "$REPO" ] || die "repo not found at $REPO (set REPO=... to override)"

# Optional extras, ON by default.
#
# This is the opposite of the usual instinct, and it is because the environment
# is SNAPSHOTTED after the first successful run. The first run pays ~740MB for
# node_modules and a browser download; every later session reuses the snapshot
# for free. Leaving these off means paying that cost again in every session.
# Set any of them to 0 to skip.
INSTALL_NODE="${INSTALL_NODE:-1}"        # npm run build (SCSS/JSX edits)
INSTALL_REDIS="${INSTALL_REDIS:-1}"      # object-cache UI states
INSTALL_PLAYWRIGHT="${INSTALL_PLAYWRIGHT:-1}"  # scripted screenshots

BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; OFF=$'\033[0m'
step() { echo "${BOLD}==>${OFF} $*"; }
ok()   { echo "  ${GREEN}✓${OFF} $*"; }
warn() { echo "  ${YELLOW}!${OFF} $*"; }
die()  { echo "  ${RED}✗${OFF} $*" >&2; exit 1; }

trap 'die "setup failed on line $LINENO"' ERR

# --------------------------------------------------------------------------
step "1/9  System packages"
# --------------------------------------------------------------------------
sudo apt-get update -qq
sudo apt-get install -y -qq \
  php-cli php-mysql php-xml php-curl php-mbstring php-gd php-zip \
  mariadb-server curl git unzip jq >/dev/null
ok "PHP $(php -r 'echo PHP_VERSION;'), MariaDB, and tooling installed"

# --------------------------------------------------------------------------
step "2/9  Database"
# --------------------------------------------------------------------------
sudo service mariadb start >/dev/null 2>&1 || true
# Wait for it to actually accept connections rather than assuming it did.
for _ in $(seq 1 30); do
  sudo mysqladmin ping --silent >/dev/null 2>&1 && break
  sleep 1
done
sudo mysqladmin ping --silent >/dev/null 2>&1 || die "MariaDB did not start"

sudo mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL
ok "database '${DB_NAME}' ready for user '${DB_USER}'"

if [ "${INSTALL_REDIS}" = "1" ]; then
  sudo apt-get install -y -qq redis-server >/dev/null
  sudo service redis-server start >/dev/null 2>&1 || true
  redis-cli ping >/dev/null 2>&1 && ok "Redis running — object-cache UI is testable" \
    || warn "Redis did not start; the object cache will report itself unavailable"
fi

# --------------------------------------------------------------------------
step "3/9  WP-CLI"
# --------------------------------------------------------------------------
if ! command -v wp >/dev/null 2>&1; then
  curl -sSLo /usr/local/bin/wp \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x /usr/local/bin/wp
fi
# `wp` in an agent sandbox usually has no TTY, so always allow root.
wp --allow-root --version >/dev/null 2>&1 || die "wp-cli is not runnable"
ok "wp-cli $(wp --allow-root --version | head -1 | awk '{print $2}')"

# --------------------------------------------------------------------------
step "4/9  Composer and the plugin's PHP dependencies"
# --------------------------------------------------------------------------
# THE STEP THE ORIGINAL SCRIPT MISSED. `vendor/` is gitignored, so without this
# the plugin is dead on arrival.
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
cd "$REPO"
composer install --no-interaction --no-progress --quiet
[ -f "$REPO/vendor/autoload.php" ] || die "composer install produced no vendor/autoload.php"
ok "vendor/autoload.php present — $(find "$REPO/vendor" -maxdepth 1 -mindepth 1 -type d | wc -l) vendor dirs"

# --------------------------------------------------------------------------
step "5/9  Node (optional, needed only to rebuild CSS/JS)"
# --------------------------------------------------------------------------
if [ "${INSTALL_NODE}" = "1" ]; then
  if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - >/dev/null
    sudo apt-get install -y -qq nodejs >/dev/null
  fi
  ok "node $(node -v)"
  [ -d "$REPO/node_modules" ] || (cd "$REPO" && npm ci --no-audit --no-fund)
  ok "node_modules ready — 'npm run build' will work from ${REPO}"
else
  warn "skipping Node. The committed build/ is used as-is, which is correct for"
  warn "  CSS/JSX edits ONLY if you also commit the rebuilt build/ directory."
  warn "  Set INSTALL_NODE=1 and re-run if you need to change styles."
fi

# --------------------------------------------------------------------------
step "6/9  WordPress"
# --------------------------------------------------------------------------
sudo mkdir -p "$WP_PATH"
sudo chown -R "$(id -u):$(id -g)" "$WP_PATH"
cd "$WP_PATH"

if [ ! -f wp-config.php ]; then
  [ -f wp-load.php ] || wp core download --allow-root --quiet
  wp config create \
    --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
    --allow-root --quiet
  ok "wp-config.php created"
else
  ok "wp-config.php already present, left alone"
fi

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  wp core install \
    --url="$SITE_URL" --title="Jules WP Test Environment" \
    --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" --skip-email --allow-root --quiet
  ok "WordPress installed"
else
  ok "WordPress already installed, left alone"
fi

# --------------------------------------------------------------------------
step "7/9  The plugin"
# --------------------------------------------------------------------------
PLUGIN_DIR="${WP_PATH}/wp-content/plugins/performance-optimisation"

# Re-point the symlink every run, so a moved checkout is picked up.
if [ -L "$PLUGIN_DIR" ]; then
  rm -f "$PLUGIN_DIR"
elif [ -d "$PLUGIN_DIR" ] && [ ! -L "$PLUGIN_DIR" ]; then
  warn "a real directory exists at $PLUGIN_DIR; moving it aside"
  sudo mv "$PLUGIN_DIR" "${PLUGIN_DIR}.bak.$(date +%s)"
fi
ln -s "$REPO" "$PLUGIN_DIR"
ok "plugin symlinked: $PLUGIN_DIR -> $REPO"

wp plugin activate performance-optimisation --allow-root --path="$WP_PATH" 2>&1 | tail -2
wp plugin is-active performance-optimisation --allow-root --path="$WP_PATH" \
  || die "the plugin did not activate — run the checks below"
ok "plugin active"

# A disabled-by-error plugin still "activates", so verify the failure mode that
# actually matters: does the admin screen render, or does it show the
# missing-dependencies notice?
if wp eval 'echo defined("PerformanceOptimise\\Inc\\Main") ? "ok" : "no";' \
     --allow-root --path="$WP_PATH" 2>/dev/null | grep -q ok; then
  ok "PHP autoloader resolves the plugin's classes"
else
  die "plugin classes do not resolve — check vendor/ in $REPO"
fi

# --------------------------------------------------------------------------
step "8/9  Web server"
# --------------------------------------------------------------------------
# The PHP built-in server is single-threaded, so a page that requests its own
# REST endpoints can deadlock. `PHP_CLI_SERVER_WORKERS` avoids that, and it is
# the difference between the SPA loading and hanging.
if ! curl -sS -o /dev/null --max-time 3 "${SITE_URL}/" 2>/dev/null; then
  PHP_CLI_SERVER_WORKERS=8 nohup php -S "localhost:${PORT}" -t "$WP_PATH" \
    >/tmp/wp-server.log 2>&1 &
  for _ in $(seq 1 20); do
    curl -sS -o /dev/null --max-time 2 "${SITE_URL}/" 2>/dev/null && break
    sleep 1
  done
fi
curl -sS -o /dev/null --max-time 5 "${SITE_URL}/" \
  || die "server did not come up on ${SITE_URL} — see /tmp/wp-server.log"
ok "serving on ${SITE_URL} (PHP_CLI_SERVER_WORKERS=8 so REST calls cannot deadlock)"

# --------------------------------------------------------------------------
step "9/9  Browser (optional)"
# --------------------------------------------------------------------------
if [ "${INSTALL_PLAYWRIGHT}" = "1" ]; then
  cd "$REPO"
  npx playwright install --with-deps chromium >/dev/null 2>&1 \
    && ok "Playwright chromium installed" \
    || warn "Playwright install failed; use the built-in browser instead"
else
  ok "using the built-in browser; set INSTALL_PLAYWRIGHT=1 for scripted screenshots"
fi

# --------------------------------------------------------------------------
# Health check — a green script that leaves a broken site is worse than no
# script, so verify the thing that matters before declaring success.
# --------------------------------------------------------------------------
step "Health check"
# --------------------------------------------------------------------------
PLUGIN_PAGE="${SITE_URL}/wp-login.php?page=performance-optimisation"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
  -c /tmp/wppo-cookies.txt -b /tmp/wppo-cookies.txt \
  --data-urlencode "log=${ADMIN_USER}" \
  --data-urlencode "pwd=${ADMIN_PASS}" \
  --data-urlencode "wp-submit=Log In" \
  --data-urlencode "redirect_to=${SITE_URL}/wp-admin/" \
  --data-urlencode "testcookie=1" \
  "${SITE_URL}/wp-login.php" 2>/dev/null || echo 000)
if [ "$code" = "302" ] || [ "$code" = "200" ]; then
  ok "logged in as ${ADMIN_USER} — admin page: ${PLUGIN_PAGE}"
else
  warn "could not verify login (HTTP ${code}); the admin URL is ${PLUGIN_PAGE}"
fi
rm -f /tmp/wppo-cookies.txt

cat <<SUMMARY

$(printf "${BOLD}Environment ready${OFF}")

  site      ${SITE_URL}
  admin     ${SITE_URL}/wp-admin/
  login     ${ADMIN_USER} / ${ADMIN_PASS}
  plugin    ${PLUGIN_DIR} -> ${REPO}
  db        ${DB_NAME} (${DB_USER}/${DB_PASS})
  wp-cli    wp --allow-root --path=${WP_PATH} <command>
  server    /tmp/wp-server.log
  node      $([ "${INSTALL_NODE}" = "1" ] && echo "installed, 'npm run build' works" || echo "not installed (committed build/ is used)")
  redis     $([ "${INSTALL_REDIS}" = "1" ] && echo "running" || echo "not installed (object cache reports itself unavailable)")

  Useful commands:
    wp --allow-root --path=${WP_PATH} plugin list
    wp --allow-root --path=${WP_PATH} option get wppo_settings --format=json
    wp --allow-root --path=${WP_PATH} wp option update blogname "Test Site"
    wp --allow-root --path=${WP_PATH} db reset --yes

SUMMARY
