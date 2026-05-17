<?php
/**
 * Plugin Name: FlowMint Workflows
 * Plugin URI: https://flowmint.dev
 * Description: Async workflow runtime that orchestrates form submissions and recurring schedules through configurable pipelines (Drive, Printavo, Email, HTTP, FE retention, etc.). Companion plugin to Form Runtime Engine.
 * Version: 0.6.1
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
define( 'FMW_VERSION', '0.6.1' );

// Database schema version. Bump when DDL changes; triggers migration.
//
// 0.2.0 (v0.6.0 dev) — Scheduled triggers. Adds trigger_type column to
// wp_fmw_workflows, makes form_id nullable, adds idx_trigger_type index.
// See FMW_Schema::migrate_to_0_2_0() for the migration logic.
define( 'FMW_DB_VERSION', '0.3.0' );

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
     * Schedule listener instance.
     *
     * Phase 1 ships only the wiring + stub; Phase 2 fills in cron
     * registration and tick handling. The property exists from Phase 1
     * onward so callers can introspect `fmw()->schedule_listener` at
     * any point in the v0.6.x cycle.
     *
     * @var FMW_Schedule_Listener|null
     */
    public $schedule_listener;

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

        // Reconciliation bootstrap is deferred to `init` priority 20.
        // Action Scheduler's data store doesn't initialize until `init`
        // priority 1; calling as_schedule_recurring_action earlier than
        // that emits a "called incorrectly" PHP notice AND silently
        // does nothing. Running at init priority 20 gives AS time to
        // finish setup.
        add_action( 'init', [ $this, 'maybe_schedule_reconciliation' ], 20 );

        /**
         * Fires after FlowMint Workflows is fully initialized.
         *
         * @param FlowMint_Workflows $plugin The plugin instance.
         */
        do_action( 'fmw_init', $this );
    }

    /**
     * Bootstrap the schedule listener's daily reconciliation.
     *
     * Hooked on `init` priority 20 — after Action Scheduler's data
     * store is initialized (init priority 1).
     *
     * Self-healing: even when the `fmw_reconciliation_bootstrapped`
     * option is set, we double-check that the daily AS recurring
     * action actually exists. If it doesn't (e.g., AS data was
     * wiped, OR a prior bootstrap attempt set the option but the
     * AS call no-op'd because of a timing issue), we re-schedule.
     *
     * Three things this guarantees on first call:
     *
     *   1. The daily `fmw_reconcile_scheduled_events` AS recurring
     *      action exists. From day 2 onward it self-maintains the
     *      cron events for every scheduled workflow.
     *
     *   2. Reconciliation runs IMMEDIATELY so existing scheduled
     *      workflows (e.g., created on a fresh v0.6.0 install
     *      before the daily action fires) get their cron events
     *      registered without a 24h wait.
     *
     *   3. The bootstrap flag is set so subsequent loads short-
     *      circuit in the common (steady-state) case where both
     *      the flag and the AS action are present.
     *
     * `public` so the `init` hook can invoke it.
     */
    public function maybe_schedule_reconciliation() {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        $bootstrapped = (bool) get_option( 'fmw_reconciliation_bootstrapped' );
        $scheduled    = as_has_scheduled_action(
            FMW_Schedule_Listener::RECONCILE_HOOK,
            [],
            FMW_Schedule_Listener::ACTION_GROUP
        );

        // Steady state — both the flag and the AS action are in place.
        // No-op for every subsequent page load on a healthy install.
        if ( $bootstrapped && $scheduled ) {
            return;
        }

        // Either a fresh install OR a previously-failed bootstrap
        // (e.g., the flag got set but the AS call was a no-op due to
        // a timing issue). Schedule the daily action if it's missing.
        if ( ! $scheduled ) {
            as_schedule_recurring_action(
                time() + HOUR_IN_SECONDS,
                DAY_IN_SECONDS,
                FMW_Schedule_Listener::RECONCILE_HOOK,
                [],
                FMW_Schedule_Listener::ACTION_GROUP
            );
        }

        // Immediate reconciliation pass — only on the very first
        // bootstrap. Catches workflows that existed before the
        // listener was wired (e.g., Phase 1 → Phase 2 transition).
        // Idempotent for subsequent calls, but no need to repeat.
        if ( ! $bootstrapped && $this->schedule_listener instanceof FMW_Schedule_Listener ) {
            $this->schedule_listener->ensure_recurring_events_registered();
        }

        update_option( 'fmw_reconciliation_bootstrapped', '1' );
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
     *
     * Also runs the capability re-grant on every version upgrade. Mirrors
     * FRE_Upgrader's pattern — capability grants are idempotent (WP's
     * add_cap no-ops when the role already has the cap) so this is safe
     * to call repeatedly. Necessary because plugin auto-updates and
     * wp-cli updates don't always re-trigger the activation hook;
     * version-bump is the reliable signal that "something changed."
     */
    private function maybe_run_db_migration() {
        $stored_version = get_option( 'fmw_db_version', '0.0.0' );

        if ( version_compare( $stored_version, FMW_DB_VERSION, '<' ) ) {
            FMW_Schema::migrate( $stored_version, FMW_DB_VERSION );
            update_option( 'fmw_db_version', FMW_DB_VERSION );

            // Re-grant capabilities on every version upgrade so existing
            // sites pick up new caps without manual re-activation. Safe
            // for the no-new-caps case — add_cap is idempotent.
            if ( class_exists( 'FMW_Capabilities' ) ) {
                FMW_Capabilities::grant_default_capabilities();
            }
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

        // Schedule listener — Phase 1 stub. Subscribes to
        // fmw_workflow_saved/disabled/deleted and the AS tick action so
        // Phase 2 only needs to fill in method bodies. In Phase 1 all
        // handlers are no-ops; scheduled workflows can be created and
        // persisted but won't actually fire on their schedule yet.
        $this->schedule_listener = new FMW_Schedule_Listener();
        $this->schedule_listener->init();

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

        // Grant the scoped capability to default roles. The version-upgrade
        // path also calls this, but activation is the canonical first
        // grant on fresh installs (and on manual re-activation). add_cap
        // is idempotent so calling both paths is safe.
        if ( class_exists( 'FMW_Capabilities' ) ) {
            FMW_Capabilities::grant_default_capabilities();
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
