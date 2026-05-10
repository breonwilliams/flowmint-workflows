<?php
/**
 * Plugin Name: FlowMint Workflows
 * Plugin URI: https://flowmint.dev
 * Description: Async workflow runtime that orchestrates form submissions through configurable pipelines (Drive, Printavo, Email, HTTP, etc.). Companion plugin to Form Runtime Engine.
 * Version: 0.5.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: FlowMint
 * Author URI: https://flowmint.dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flowmint-workflows
 * Domain Path: /languages
 *
 * @package FlowMintWorkflows
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version.
define( 'FMW_VERSION', '0.5.0' );

// Database schema version. Bump when DDL changes; triggers migration.
define( 'FMW_DB_VERSION', '0.1.0' );

// Plugin paths and URLs.
define( 'FMW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FMW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FMW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FMW_PLUGIN_FILE', __FILE__ );

// Minimum FormEngine version required.
define( 'FMW_REQUIRED_FRE_VERSION', '1.6.0' );

// REST namespace.
define( 'FMW_REST_NAMESPACE', 'flowmint/v1' );
define( 'FMW_REST_BASE', 'connector' );

// Autoloader (PSR-like for FMW_* classes).
require_once FMW_PLUGIN_DIR . 'includes/class-fmw-autoloader.php';
FMW_Autoloader::register();

// Composer autoload for vendor/* dependencies (google/apiclient, action-scheduler).
// Loaded after FMW autoloader so vendor classes don't interfere with FMW class name lookup.
if ( file_exists( FMW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once FMW_PLUGIN_DIR . 'vendor/autoload.php';
}

// Action Scheduler bootstrap.
//
// Action Scheduler intentionally does NOT load via Composer's PSR-4 autoloader.
// Each plugin that depends on AS must `require_once` its bootstrap file so AS
// can register itself with `ActionScheduler_Versions`. At `init`, AS picks the
// HIGHEST registered version across all plugins and loads only that one — this
// is how AS supports multiple plugins (incl. WooCommerce, FMW) shipping their
// own copies without conflict.
if ( file_exists( FMW_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
    require_once FMW_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * Main plugin class.
 *
 * Singleton. Handles dependency checks, hook registration, component init.
 */
final class FlowMint_Workflows {

    /**
     * @var FlowMint_Workflows|null
     */
    private static $instance = null;

    /**
     * Workflow registry instance.
     *
     * @var FMW_Workflow_Registry|null
     */
    public $registry;

    /**
     * Step registry instance.
     *
     * @var FMW_Step_Registry|null
     */
    public $steps;

    /**
     * Submission listener instance.
     *
     * @var FMW_Submission_Listener|null
     */
    public $listener;

    /**
     * Get singleton instance.
     *
     * @return FlowMint_Workflows
     */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->register_lifecycle_hooks();
        $this->register_init_hooks();
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }

    /**
     * Register activation / deactivation hooks. Always runs, even if FRE is missing.
     */
    private function register_lifecycle_hooks() {
        register_activation_hook( FMW_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( FMW_PLUGIN_FILE, [ $this, 'deactivate' ] );
    }

    /**
     * Register init hooks.
     */
    private function register_init_hooks() {
        // Run dependency check + version upgrade on plugins_loaded priority 20
        // (after FormEngine which uses priority 10).
        add_action( 'plugins_loaded', [ $this, 'maybe_initialize' ], 20 );

        // Admin notices for missing dependencies.
        add_action( 'admin_notices', [ $this, 'show_dependency_notices' ] );
    }

    /**
     * Initialize the plugin if all dependencies are met.
     */
    public function maybe_initialize() {
        if ( ! $this->dependencies_met() ) {
            // Don't initialize components — admin_notices will show the issue.
            return;
        }

        $this->maybe_run_db_migration();
        $this->init_components();

        /**
         * Fires after FlowMint Workflows is fully initialized.
         *
         * @param FlowMint_Workflows $plugin The plugin instance.
         */
        do_action( 'fmw_init', $this );
    }

    /**
     * Check whether all dependencies are met.
     *
     * @return bool
     */
    private function dependencies_met() {
        if ( ! defined( 'FRE_VERSION' ) ) {
            return false;
        }

        if ( version_compare( FRE_VERSION, FMW_REQUIRED_FRE_VERSION, '<' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Run database schema migration if needed.
     */
    private function maybe_run_db_migration() {
        $stored_version = get_option( 'fmw_db_version', '0.0.0' );

        if ( version_compare( $stored_version, FMW_DB_VERSION, '<' ) ) {
            FMW_Schema::migrate( $stored_version, FMW_DB_VERSION );
            update_option( 'fmw_db_version', FMW_DB_VERSION );
        }
    }

    /**
     * Instantiate plugin components.
     */
    private function init_components() {
        // Workflow registry (DB-backed).
        $this->registry = new FMW_Workflow_Registry();

        // Step registry — singleton, populated by step type registrations.
        $this->steps = FMW_Step_Registry::instance();
        $this->steps->register_core_steps();
        $this->steps->register_drive_steps();
        $this->steps->register_email_steps();
        $this->steps->register_printavo_steps();
        $this->steps->register_http_steps();

        // Submission listener — listens to fre_submission_complete.
        $this->listener = new FMW_Submission_Listener();
        $this->listener->init();

        // Workflow job handler — registers Action Scheduler hook.
        FMW_Workflow_Job::register();

        // REST API.
        ( new FMW_REST_Api() )->register_routes();

        // Admin (only in admin context).
        if ( is_admin() ) {
            new FMW_Admin();

            // Claude Cowork connector admin page — registers the
            // FlowMint Workflows → Claude Connection submenu and the
            // AJAX handlers for password generate/revoke + connector
            // toggle + MCP script download. Available on all installs;
            // FlowMint's connector is an add-on, not a premium feature.
            ( new FMW_Connector_Admin() )->init();
        }
    }

    /**
     * Plugin activation handler.
     *
     * Runs DDL to create tables. Doesn't fail if FRE is missing —
     * we just won't function until FRE is also active.
     */
    public function activate() {
        // Schema requires the autoloader, which is loaded above.
        if ( class_exists( 'FMW_Schema' ) ) {
            FMW_Schema::create_tables();
            update_option( 'fmw_db_version', FMW_DB_VERSION );
        }

        // Stamp activation time.
        update_option( 'fmw_activated_at', current_time( 'mysql' ) );
    }

    /**
     * Plugin deactivation handler.
     */
    public function deactivate() {
        // Unschedule any pending Action Scheduler jobs in our group.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( null, [], 'fmw' );
        }
    }

    /**
     * Show admin notices for missing/incompatible dependencies.
     */
    public function show_dependency_notices() {
        if ( ! defined( 'FRE_VERSION' ) ) {
            $this->render_notice(
                'error',
                __( 'FlowMint Workflows', 'flowmint-workflows' ),
                __( 'requires Form Runtime Engine to be installed and activated.', 'flowmint-workflows' )
            );
            return;
        }

        if ( version_compare( FRE_VERSION, FMW_REQUIRED_FRE_VERSION, '<' ) ) {
            $this->render_notice(
                'error',
                __( 'FlowMint Workflows', 'flowmint-workflows' ),
                sprintf(
                    /* translators: 1: required FRE version, 2: current FRE version */
                    __( 'requires Form Runtime Engine %1$s or higher. Currently installed: %2$s.', 'flowmint-workflows' ),
                    FMW_REQUIRED_FRE_VERSION,
                    FRE_VERSION
                )
            );
            return;
        }

        // Action Scheduler check (warning only — plugin loads but listener won't enqueue jobs).
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            $this->render_notice(
                'warning',
                __( 'FlowMint Workflows', 'flowmint-workflows' ),
                __( 'Action Scheduler is not loaded. Workflows will not execute until Composer dependencies are installed (run `composer install` in the plugin directory).', 'flowmint-workflows' )
            );
        }
    }

    /**
     * Render an admin notice.
     *
     * @param string $level   error|warning|success|info
     * @param string $heading Bold prefix
     * @param string $message Body
     */
    private function render_notice( $level, $heading, $message ) {
        printf(
            '<div class="notice notice-%s"><p><strong>%s</strong> %s</p></div>',
            esc_attr( $level ),
            esc_html( $heading ),
            esc_html( $message )
        );
    }
}

/**
 * Get the plugin singleton.
 *
 * @return FlowMint_Workflows
 */
function fmw() {
    return FlowMint_Workflows::instance();
}

// Bootstrap.
fmw();
