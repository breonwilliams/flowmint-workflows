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
bin/smoke-test-*.sh
bin/smoke-test-*.php
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

# ============================================
# VERIFY ZIP INTERNAL STRUCTURE
# ============================================
# Guards against a flattened/hand-assembled archive (Promptless Theme
# v1.2.5 incident: a manually built zip lost a directory level and fataled
# on every site). Checks the SHIPPED ARTIFACT's manifest, not the staging
# folder. This script is the only sanctioned packaging path.
#
# The vendor/ entries also enforce the documented FlowMint gotcha: if
# Composer isn't on PATH, the ZIP ships without vendor/ (no autoloader,
# no Action Scheduler) and the plugin is dead on arrival.
echo ""
echo "Verifying ZIP internal structure..."

ZIP_MANIFEST=$(unzip -l "${BUILD_DIR}/${PLUGIN_SLUG}.zip")

REQUIRED_ZIP_PATHS=(
    "${PLUGIN_SLUG}/flowmint-workflows.php"
    "${PLUGIN_SLUG}/includes/class-fmw-autoloader.php"
    "${PLUGIN_SLUG}/includes/Core/class-fmw-workflow-executor.php"
    "${PLUGIN_SLUG}/includes/Database/class-fmw-schema.php"
    "${PLUGIN_SLUG}/includes/Connectors/MCP/assets/flowmint-connector.js"
    "${PLUGIN_SLUG}/vendor/autoload.php"
    "${PLUGIN_SLUG}/vendor/woocommerce/action-scheduler/action-scheduler.php"
)

ZIP_STRUCTURE_OK=1
for path in "${REQUIRED_ZIP_PATHS[@]}"; do
    if echo "$ZIP_MANIFEST" | grep -q " ${path}$"; then
        echo "  OK  $path"
    else
        echo "  MISSING FROM ZIP: $path"
        ZIP_STRUCTURE_OK=0
    fi
done

# A flattened build puts nested files at the plugin root — detect that too.
if echo "$ZIP_MANIFEST" | grep -q " ${PLUGIN_SLUG}/class-fmw-workflow-executor.php$"; then
    echo "  FLATTENED STRUCTURE DETECTED: includes/ files found at plugin root"
    ZIP_STRUCTURE_OK=0
fi

if [ $ZIP_STRUCTURE_OK -eq 0 ]; then
    rm -f "${BUILD_DIR}/${PLUGIN_SLUG}.zip"
    echo ""
    echo "ERROR: ZIP structure verification FAILED — archive deleted."
    echo "Do NOT hand-assemble release zips; this script is the only sanctioned packaging path."
    exit 1
fi

echo "ZIP structure verified."

ZIP_SIZE=$(du -h "${BUILD_DIR}/${PLUGIN_SLUG}.zip" | cut -f1)
echo ""
echo "Done! Created ${BUILD_DIR}/${PLUGIN_SLUG}.zip (${ZIP_SIZE})"
echo "Version: ${VERSION}"
echo ""
echo "Install on production:"
echo "  WP Admin → Plugins → Add New → Upload Plugin → choose ${BUILD_DIR}/${PLUGIN_SLUG}.zip"
