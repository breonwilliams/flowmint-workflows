#!/bin/bash
#
# Build a release ZIP for FlowMint Workflows.
#
# Mirrors the pattern from form-runtime-engine/bin/build-release.sh.
#
# Usage:
#   ./bin/build-release.sh
#
# Output:
#   ./build/flowmint-workflows.zip

set -e

PLUGIN_SLUG="flowmint-workflows"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

# Get the version from the main plugin file.
VERSION=$(grep -m1 "define( 'FMW_VERSION'" "${PLUGIN_SLUG}.php" | sed "s/.*'\\(.*\\)'.*/\\1/")

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect plugin version."
    exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build.
rm -rf "${BUILD_DIR}"
mkdir -p "${TEMP_DIR}"

# Copy production files (exclude dev/test files).
#
# The exclude list drops VCS/IDE state, lint/test config, build & test
# artifacts (build/, release/, *.zip, .phpunit.result.cache, *.log), the
# build script itself (we don't ship the script that builds the ZIP),
# source-only dirs (node_modules, tests), and OS junk. Plugin Check (the
# WordPress.org compliance tool) flags ZIPs, hidden files, and shell
# scripts that ship inside a plugin — the exclusions below keep the
# deployed plugin folder clean.
rsync -av --exclude-from=- . "${TEMP_DIR}/" <<'EXCLUDE'
.git
.github
.claude
.gitignore
.phpcs.xml
.phpunit.xml
.phpunit.result.cache
phpunit.xml
phpunit.xml.dist
composer.lock
node_modules
tests
bin/install-wp-tests.sh
bin/build-release.sh
build
release
*.zip
*.log
.DS_Store
Thumbs.db
EXCLUDE

# Install production-only Composer dependencies.
if [ -f "${TEMP_DIR}/composer.json" ]; then
    echo "Installing production composer dependencies..."
    cd "${TEMP_DIR}"
    composer install --no-dev --optimize-autoloader --quiet
    cd - > /dev/null
fi

echo "Creating zip..."

cd "${BUILD_DIR}"
zip -r "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*" > /dev/null
cd ..

ZIP_SIZE=$(du -h "${BUILD_DIR}/${PLUGIN_SLUG}.zip" | cut -f1)
echo ""
echo "Done! Created ${BUILD_DIR}/${PLUGIN_SLUG}.zip (${ZIP_SIZE})"
echo "Version: ${VERSION}"
echo ""
echo "Install on production:"
echo "  WP Admin → Plugins → Add New → Upload Plugin → choose ${BUILD_DIR}/${PLUGIN_SLUG}.zip"
