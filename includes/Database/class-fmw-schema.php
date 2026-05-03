<?php
/**
 * Database schema management.
 *
 * Creates and migrates DDL for FlowMint Workflows tables.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Schema {

    /**
     * Get fully-qualified table names.
     *
     * @return array
     */
    public static function get_table_names() {
        global $wpdb;
        return [
            'workflows'        => $wpdb->prefix . 'fmw_workflows',
            'workflow_runs'    => $wpdb->prefix . 'fmw_workflow_runs',
            'workflow_run_steps' => $wpdb->prefix . 'fmw_workflow_run_steps',
        ];
    }

    /**
     * Create all FMW tables. Idempotent.
     *
     * Uses dbDelta for upsert behavior — adds tables if missing, alters
     * columns if changed. dbDelta has known quirks: requires PRIMARY KEY
     * on its own line, exact spacing, etc.
     */
    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tables          = self::get_table_names();

        $sql_workflows = "CREATE TABLE {$tables['workflows']} (
            id varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            form_id varchar(64) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            config longtext NOT NULL,
            managed_by varchar(64) NOT NULL DEFAULT 'admin',
            connector_version bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_form_id (form_id),
            KEY idx_enabled_form (enabled, form_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_runs = "CREATE TABLE {$tables['workflow_runs']} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id varchar(64) NOT NULL,
            form_id varchar(64) NOT NULL,
            entry_id bigint(20) UNSIGNED NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            started_at datetime NULL,
            completed_at datetime NULL,
            duration_ms int(11) NULL,
            error_code varchar(64) NULL,
            error_message text NULL,
            failed_step varchar(64) NULL,
            retry_count smallint(5) UNSIGNED NOT NULL DEFAULT 0,
            parent_run_id bigint(20) UNSIGNED NULL,
            context_snapshot longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_workflow_created (workflow_id, created_at),
            KEY idx_status_created (status, created_at),
            KEY idx_entry (entry_id),
            KEY idx_form_created (form_id, created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_run_steps = "CREATE TABLE {$tables['workflow_run_steps']} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id bigint(20) UNSIGNED NOT NULL,
            step_index smallint(5) UNSIGNED NOT NULL,
            step_name varchar(64) NOT NULL,
            step_type varchar(64) NOT NULL,
            status varchar(32) NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            duration_ms int(11) NULL,
            config_snapshot longtext NULL,
            output_snapshot longtext NULL,
            error_code varchar(64) NULL,
            error_message text NULL,
            PRIMARY KEY  (id),
            KEY idx_run_index (run_id, step_index)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta( $sql_workflows );
        dbDelta( $sql_runs );
        dbDelta( $sql_run_steps );
    }

    /**
     * Migrate from a previous DB version.
     *
     * Currently a no-op (we're at v0.1.0 — first version). Future migrations
     * compare $from to $to and apply incremental ALTERs.
     *
     * @param string $from Previous version
     * @param string $to   Target version
     */
    public static function migrate( $from, $to ) {
        // First-run migration: just create tables.
        self::create_tables();

        FMW_Logger::info( 'Database migrated', [
            'from' => $from,
            'to'   => $to,
        ] );
    }

    /**
     * Drop all FMW tables. Used by uninstall.php.
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = self::get_table_names();

        // Drop child first (FK semantic).
        foreach ( [ 'workflow_run_steps', 'workflow_runs', 'workflows' ] as $key ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS `{$tables[ $key ]}`" );
        }
    }

    /**
     * Quick health check — are all tables present?
     *
     * @return array Empty if healthy, array of missing table names if not.
     */
    public static function check_health() {
        global $wpdb;

        $tables  = self::get_table_names();
        $missing = [];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                $missing[] = $table;
            }
        }

        return $missing;
    }
}
