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

# The release ships EXACTLY the library versions recorded in composer.lock.
# Without it, `composer install` resolves whatever is newest on build day,
# so two builds of the same code could ship different Guzzle / Google
# client versions and nobody would know (0.12.2 did, before this check).
# A lock that no longer matches composer.json is refused for the same
# reason: Composer would warn and install the stale set anyway.
if [ ! -f composer.lock ]; then
    echo "Error: composer.lock is missing. Run 'composer update', test, and commit it (see RELEASE.md, \"Library versions\")."
    exit 1
fi
if ! composer validate --no-check-all --no-check-publish --quiet; then
    echo "Error: composer.lock is out of date with composer.json. Run 'composer update', test, and commit the new composer.lock."
    exit 1
fi

# Clean previous build.
rm -rf "${BUILD_DIR}"
mkdir -p "${TEMP_DIR}"

# Copy production files (exclude dev/test files).
#
# The exclude list drops VCS/IDE state, lint/test config, build & test
# artifacts (build/, release/, *.zip, .phpunit.result.cache, *.log), bin/
# (the build script and the local test harnesses), source-only dirs
# (node_modules, tests), the notes written for developers and AI sessions
# (CLAUDE.md anywhere, AGENTS.md, RELEASE.md, NEXT_SESSION_*.md,
# *_AUDIT.md at the root), and OS junk. Until 0.12.3 those notes shipped,
# and with them the names of the installs they were written about. vendor/ is left out
# too: the install below builds it fresh from composer.lock, so the local
# development vendor/ (with its dev packages) never leaks into the ZIP.
# composer.lock IS copied: the install reads it, and it ships as the record
# of what the ZIP contains. Plugin Check (the
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
vendor
node_modules
tests
/bin/
CLAUDE.md
AGENTS.md
/RELEASE.md
/NEXT_SESSION_*.md
/*_AUDIT.md
build
release
*.zip
*.log
_to_delete
.DS_Store
Thumbs.db
EXCLUDE

# Install production-only Composer dependencies.
if [ -f "${TEMP_DIR}/composer.json" ]; then
    echo "Installing production composer dependencies..."
    cd "${TEMP_DIR}"
    composer install --no-dev --optimize-autoloader --quiet

    # Write the class map again now that the Google client's cleanup script
    # (post-install-cmd) has removed every API service except Drive.
    # Composer writes the optimized class map BEFORE running that script,
    # so until 0.12.3 the ZIP shipped a 6.8 MB map with 38,285 entries,
    # 37,707 of them for files the script had just deleted. Every request
    # to the site loads that map. Measured on a page view with only
    # Promptless Forms and FlowMint active: FlowMint added 25 MB of peak
    # memory with the stale map and 3 MB without it (no opcache), and at
    # memory_limit=40M the page died with the stale map even with opcache
    # on (8.5 MB peak without it). Now 579 entries.
    composer dump-autoload --no-dev --optimize --quiet

    # Refuse to ship a class map that names files the ZIP does not contain.
    php -r '
        $map = require "vendor/composer/autoload_classmap.php";
        $missing = array_filter( $map, function ( $f ) { return ! file_exists( $f ); } );
        printf( "Class map: %d entries, %d missing files\n", count( $map ), count( $missing ) );
        exit( $missing ? 1 : 0 );
    ' || { echo "Error: the class map lists files that are not in the build."; exit 1; }
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

# Development-only files of ours must never ship (see the exclude list
# above). vendor/ is the libraries' own business: some ship a CLAUDE.md.
DEV_ONLY=$(echo "$ZIP_MANIFEST" | grep -v " ${PLUGIN_SLUG}/vendor/" | grep -E " ${PLUGIN_SLUG}/(bin/|(.*/)?CLAUDE\.md$|AGENTS\.md$|RELEASE\.md$|NEXT_SESSION_.*\.md$|[^/]*_AUDIT\.md$)" || true)
if [ -n "$DEV_ONLY" ]; then
    echo "  DEVELOPMENT-ONLY FILES IN ZIP:"
    echo "$DEV_ONLY"
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
