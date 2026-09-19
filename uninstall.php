<?php
/**
 * Uninstall handler for FlowMint Workflows.
 *
 * Fires when the plugin is DELETED from the Plugins screen (deactivation
 * keeps everything).
 *
 * KEEPS workflows, run history and stored credentials unless the site owner
 * opted in, so reinstalling picks up where the site left off — the stack's
 * data-protection rule ("never delete user data without explicit consent",
 * Promptless WP docs/operations/DATA_PROTECTION.md). FlowMint has no settings
 * screen, so the opt-in is a constant in wp-config.php, the way WooCommerce
 * does it with WC_REMOVE_ALL_DATA:
 *
 *     define( 'FMW_REMOVE_ALL_DATA', true );
 *
 * Always removed (housekeeping, no user data): transients, pending and
 * recurring Action Scheduler jobs in the `fmw` group (nothing would handle
 * them), the connector's switch and application-password grants, the
 * capability grants, and one-time notice/bootstrap flags.
 *
 * With the constant: the three tables (workflows, runs, run steps) and every
 * FlowMint option, including stored credentials and the install nonce their
 * encryption depends on.
 *
 * Up to 0.9.0 this file dropped the tables and credentials on every deletion
 * and left the connector switch behind.
 *
 * FormEngine data is never touched (FlowMint owns no FRE resources).
 *
 * @package FlowMintWorkflows
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up after FlowMint: housekeeping always, data only with consent.
 */
function fmw_uninstall_cleanup() {
    global $wpdb;

    // --- Always: housekeeping, no user data. -------------------------------

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_fmw_%',
        '_transient_timeout_fmw_%'
    ) );

    // Jobs for a handler that is gone would only fail; reinstalling
    // re-registers schedules from the kept workflows.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( null, [], 'fmw' );
    }

    // The connector's switch and application-password grants: access, not
    // content.
    $settings_path = plugin_dir_path( __FILE__ ) . 'includes/Connectors/MCP/class-fmw-connector-settings.php';
    if ( file_exists( $settings_path ) ) {
        require_once $settings_path;
        if ( class_exists( 'FMW_Connector_Settings' ) && method_exists( 'FMW_Connector_Settings', 'delete_all' ) ) {
            FMW_Connector_Settings::delete_all();
        }
    }
    delete_option( 'fmw_connector_enabled' );

    // Capability grants track the plugin's presence; activation grants them
    // again. Iterates ALL roles — the capability may have been delegated.
    $caps_class_path = plugin_dir_path( __FILE__ ) . 'includes/Core/class-fmw-capabilities.php';
    if ( file_exists( $caps_class_path ) ) {
        require_once $caps_class_path;
        if ( class_exists( 'FMW_Capabilities' ) ) {
            FMW_Capabilities::revoke_all_capabilities();
        }
    }

    // One-time flags: rerun on reinstall.
    delete_option( 'fmw_reconciliation_bootstrapped' );
    delete_option( 'fmw_repaired_runs' );
    delete_option( 'fmw_conditions_review_dismissed' );

    // --- Only with consent: the site's data. ------------------------------

    if ( ! defined( 'FMW_REMOVE_ALL_DATA' ) || ! FMW_REMOVE_ALL_DATA ) {
        return;
    }

    foreach ( [ 'fmw_workflow_run_steps', 'fmw_workflow_runs', 'fmw_workflows' ] as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- names are fixed.
    }

    // Every FlowMint option: credentials (and the nonce their encryption
    // needs), the HTTP credential index, settings, version.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'fmw_' ) . '%'
    ) );
}

fmw_uninstall_cleanup();
