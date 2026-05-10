<?php
/**
 * PHPUnit Bootstrap File for FlowMint Workflows Tests.
 *
 * Modeled on the bootstrap pattern used by Form Runtime Engine and
 * Post Runtime Engine in the same repository, so the testing
 * conventions stay consistent across all three plugins.
 *
 * Two test modes are supported:
 *
 *   - Unit (default): Brain\Monkey mocks the WordPress functions
 *     FlowMint touches. Fast, deterministic, no DB, no WP install
 *     required.
 *
 *   - Integration: a real WordPress test instance loads FlowMint as
 *     a plugin so async execution, REST endpoints, and DB persistence
 *     can be exercised end-to-end. (Not yet wired — Phase 0b in the
 *     audit roadmap. composer test:integration will fail with a
 *     guidance message until that work lands.)
 *
 * Selected via the TEST_SUITE env var; composer scripts pass it in.
 *
 * @package FlowMintWorkflows\Tests
 */

if ( ! defined( 'FMW_TESTING' ) ) {
    define( 'FMW_TESTING', true );
}

if ( ! defined( 'FMW_TEST_PLUGIN_DIR' ) ) {
    define( 'FMW_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Composer autoloader — loads Brain\Monkey, Yoast polyfills, etc.
$composer_autoload = FMW_TEST_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $composer_autoload ) ) {
    echo "Error: Run 'composer install' from " . FMW_TEST_PLUGIN_DIR . " before running tests.\n";
    exit( 1 );
}
require_once $composer_autoload;

// Dispatch on TEST_SUITE.
$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( $test_suite === 'integration' ) {
    fmw_bootstrap_integration_tests();
} else {
    fmw_bootstrap_unit_tests();
}

/**
 * Bootstrap unit tests with Brain\Monkey.
 *
 * Defines the FMW_* constants the plugin code references and loads
 * the autoloader so individual test files can `require_once` the
 * class under test (or rely on autoload-on-touch).
 */
function fmw_bootstrap_unit_tests() {
    // Yoast polyfills give us cross-version assertion API + the
    // set_up / tear_down naming convention.
    require_once FMW_TEST_PLUGIN_DIR . 'vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

    // WordPress constants the plugin references.
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', '/tmp/wordpress/' );
    }

    // Plugin constants. Match flowmint-workflows.php exactly so
    // version-related code under test sees the same values.
    if ( ! defined( 'FMW_VERSION' ) ) {
        define( 'FMW_VERSION', '0.4.0-rc7' );
    }
    if ( ! defined( 'FMW_DB_VERSION' ) ) {
        define( 'FMW_DB_VERSION', '0.1.0' );
    }
    if ( ! defined( 'FMW_PLUGIN_DIR' ) ) {
        define( 'FMW_PLUGIN_DIR', FMW_TEST_PLUGIN_DIR );
    }
    if ( ! defined( 'FMW_PLUGIN_URL' ) ) {
        define( 'FMW_PLUGIN_URL', 'http://example.com/wp-content/plugins/flowmint-workflows/' );
    }
    if ( ! defined( 'FMW_PLUGIN_BASENAME' ) ) {
        define( 'FMW_PLUGIN_BASENAME', 'flowmint-workflows/flowmint-workflows.php' );
    }
    if ( ! defined( 'FMW_PLUGIN_FILE' ) ) {
        define( 'FMW_PLUGIN_FILE', FMW_TEST_PLUGIN_DIR . 'flowmint-workflows.php' );
    }
    if ( ! defined( 'FMW_REQUIRED_FRE_VERSION' ) ) {
        define( 'FMW_REQUIRED_FRE_VERSION', '1.6.0' );
    }
    if ( ! defined( 'FMW_REST_NAMESPACE' ) ) {
        define( 'FMW_REST_NAMESPACE', 'flowmint/v1' );
    }
    if ( ! defined( 'FMW_REST_BASE' ) ) {
        define( 'FMW_REST_BASE', 'connector' );
    }

    // FlowMint autoloader — resolves FMW_* class lookups to the
    // includes/ directory so test files don't need explicit
    // require_once on every class.
    require_once FMW_TEST_PLUGIN_DIR . 'includes/class-fmw-autoloader.php';
    FMW_Autoloader::register();
}

/**
 * Bootstrap integration tests against a real WordPress test instance.
 *
 * Same shape as PRE's integration bootstrap: resolves the WP test
 * library, fails clearly with an install hint if it's not present,
 * loads the plugin via tests_add_filter('muplugins_loaded'), then
 * boots the WP testing environment.
 *
 * The integration suite for FlowMint isn't populated yet — see audit
 * C1 Phase 0b roadmap. This branch is here so the dispatch path is
 * consistent with the other two plugins from day one.
 */
function fmw_bootstrap_integration_tests() {
    $wp_tests_dir = getenv( 'WP_TESTS_DIR' );

    if ( ! $wp_tests_dir ) {
        $candidates = array(
            '/tmp/wordpress-tests-lib',
            getenv( 'HOME' ) . '/.wp-tests/wordpress-tests-lib',
        );

        foreach ( $candidates as $candidate ) {
            if ( file_exists( $candidate . '/includes/functions.php' ) ) {
                $wp_tests_dir = $candidate;
                break;
            }
        }
    }

    if ( ! $wp_tests_dir ) {
        echo "Error: WordPress test framework not found.\n";
        echo "\nThe FlowMint integration suite is not yet populated (audit C1 Phase 0b).\n";
        echo "When that work lands, install the WP test library via the same script\n";
        echo "PRE and FRE use:\n";
        echo "  composer install-wp-tests   # in either of the other plugin directories\n";
        echo "  WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration\n";
        echo "\nFor unit tests (no WP install required), run:\n";
        echo "  composer test:unit\n";
        exit( 1 );
    }

    require_once $wp_tests_dir . '/includes/functions.php';

    tests_add_filter( 'muplugins_loaded', function () {
        require FMW_TEST_PLUGIN_DIR . 'flowmint-workflows.php';
    } );

    require $wp_tests_dir . '/includes/bootstrap.php';
}
