#!/usr/bin/env bash
set -e
set -o pipefail

PLUGIN_SLUG="performance-optimisation"
# Dynamically get version from the plugin main file
VERSION=$(grep "Version:" performance-optimisation.php | awk '{print $NF}' | tr -d '\r')
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-pkg"
ORIG_PWD="$(pwd)"

echo "==> Preparing production dependencies..."
composer install --no-dev --optimize-autoloader
npm ci && npm run build

echo "==> Staging files using .distignore..."
rm -rf "$BUILD_DIR" && mkdir -p "$BUILD_DIR/${PLUGIN_SLUG}"
# rsync copies the files into the build directory while respecting excludes
rsync -a --exclude-from=".distignore" \
  --exclude=".git" \
  --exclude=".github" \
  --exclude="scripts/" \
  ./ "$BUILD_DIR/${PLUGIN_SLUG}/"

echo "==> Creating release ZIP: ${ZIP_NAME}..."
# Remove any existing zip in root
rm -f "${ORIG_PWD}/${ZIP_NAME}"
( cd "$BUILD_DIR" && zip -r "${ORIG_PWD}/${ZIP_NAME}" "${PLUGIN_SLUG}" )
test -f "${ORIG_PWD}/${ZIP_NAME}" || { echo "ERROR: ZIP not created at ${ORIG_PWD}/${ZIP_NAME}" >&2; exit 1; }
unzip -l "${ORIG_PWD}/${ZIP_NAME}" | head -n 40
echo "==> Verifying vendor/ present..."
unzip -l "${ORIG_PWD}/${ZIP_NAME}" | grep -q "vendor/autoload.php" || { echo "ERROR: vendor/autoload.php missing in ZIP" >&2; exit 1; }

echo "==> Cleanup..."
rm -rf "$BUILD_DIR"

echo "==> Done! Release zip created at: ${ORIG_PWD}/${ZIP_NAME}"
