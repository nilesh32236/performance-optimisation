#!/usr/bin/env bash
set -e

PLUGIN_SLUG="performance-optimisation"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-release"

echo "==> Cleaning previous build..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "==> Copying plugin files..."
rsync -a --exclude-from=".distignore" \
  --exclude=".git" \
  --exclude="scripts/" \
  ./ "$BUILD_DIR/"

echo "==> Installing production dependencies..."
composer install --no-dev --optimize-autoloader --working-dir="$BUILD_DIR"

echo "==> Done! Release build at: $BUILD_DIR"
