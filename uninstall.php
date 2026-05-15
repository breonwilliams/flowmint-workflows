<?php
/**
 * Uninstall handler for FlowMint Workflows.
 *
 * Fires when the user DELETES the plugin via the WP Plugins admin page
 * (not on simple deactivation — deactivation preserves data).
 *
 * Drops all plugin-owned tables, options, and transients. FormEngine data
 * is left untouched (FMW does not own any FRE-prefixed resources).
 *
 * @package FlowMintWorkflows
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop FMW tables.
$tables = [
    $wpdb->prefix . 'fmw_workflow_run_steps',  // child first (FK semantic)
    $wpdb->prefix . 'fmw_workflow_runs',
    $wpdb->prefix . 'fmw_workflows',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is hardcoded
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// Delete FMW options.
$option_prefixes = [
    'fmw_db_version',
    'fmw_credential_',
    'fmw_settings_',
    'fmw_notification_',
    'fmw_run_retention_days',
];

foreach ( $option_prefixes as $prefix ) {
    if ( strpos( $prefix, '_' ) === strlen( $prefix ) - 1 ) {
        // wildcard prefix — delete all options starting with this string
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
    } else {
        delete_option( $prefix );
    }
}

// Delete FMW transients.
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    '_transient_fmw_%',
    '_transient_timeout_fmw_%'
) );

// Unschedule any pending Action Scheduler jobs in the 'fmw' group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( null, [], 'fmw' );
}

// Revoke the scoped capability from every role. The autoloader doesn't run
// during uninstall (PHP's plugin-uninstall flow loads only this file), so
// we include the capability class directly. Iterates ALL roles — admins
// may have delegated the capability to custom roles via add_cap or via
// the `flowmint_default_manage_workflows_roles` filter, and uninstall
// must clean up all traces.
$caps_class_path = plugin_dir_path( __FILE__ ) . 'includes/Core/class-fmw-capabilities.php';
if ( file_exists( $caps_class_path ) ) {
    require_once $caps_class_path;
    if ( class_exists( 'FMW_Capabilities' ) ) {
        FMW_Capabilities::revoke_all_capabilities();
    }
}

// Note: this does NOT touch any wp_fre_* tables, fre_* options, or any other
// data owned by Form Runtime Engine or other plugins.
