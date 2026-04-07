#!/usr/bin/env bash
set -e

PLUGIN_SLUG="performance-optimisation"
# Dynamically get version from the plugin main file
VERSION=$(grep "Version:" performance-optimisation.php | awk '{print $NF}' | tr -d '\r')
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-pkg"

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
rm -f "./${ZIP_NAME}"
cd "$BUILD_DIR" && zip -r "../../${ZIP_NAME}" . && cd ../..

echo "==> Cleanup..."
rm -rf "$BUILD_DIR"

echo "==> Done! Release zip created at: ./${ZIP_NAME}"
