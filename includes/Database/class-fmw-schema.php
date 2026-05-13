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

        // form_id is NULL-able (v0.2.0) because scheduled-trigger workflows
        // exist without a bound FE form. The trigger_type column is the
        // denormalized projection of $config['trigger']['type']; we keep it
        // in its own indexed column so FMW_Schedule_Listener can resolve
        // "all enabled scheduled workflows" with a single keyed lookup
        // instead of decoding every config JSON. Default 'form' keeps
        // pre-0.6.0 rows correctly classified after migration.
        $sql_workflows = "CREATE TABLE {$tables['workflows']} (
            id varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            form_id varchar(64) NULL,
            trigger_type varchar(32) NOT NULL DEFAULT 'form',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            config longtext NOT NULL,
            managed_by varchar(64) NOT NULL DEFAULT 'admin',
            connector_version bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_form_id (form_id),
            KEY idx_enabled_form (enabled, form_id),
            KEY idx_trigger_type (trigger_type, enabled)
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
     * Always runs dbDelta first (handles fresh installs + tables that may
     * have been dropped). Then applies incremental ALTERs based on the
     * stored version. Each ALTER step probes the current schema state via
     * the column_/index_* helpers so re-running on an already-migrated
     * database is a safe no-op.
     *
     * @param string $from Previous version (stored fmw_db_version option).
     * @param string $to   Target version (FMW_DB_VERSION constant).
     */
    public static function migrate( $from, $to ) {
        // Catches fresh installs + any table that was dropped manually.
        self::create_tables();

        // v0.1.0 → v0.2.0 — Scheduled triggers (plugin v0.6.0).
        //
        // Makes form_id nullable so scheduled workflows can exist without
        // a bound FE form. Adds trigger_type column + index so the
        // schedule listener can find enabled scheduled workflows cheaply.
        if ( version_compare( $from, '0.2.0', '<' ) ) {
            self::migrate_to_0_2_0();
        }

        FMW_Logger::info( 'Database migrated', [
            'from' => $from,
            'to'   => $to,
        ] );
    }

    /**
     * v0.1.0 → v0.2.0 — Scheduled triggers schema migration.
     *
     * Three additive, idempotent operations on wp_fmw_workflows:
     *   1. Make form_id nullable
     *   2. Add trigger_type column (default 'form' — keeps existing rows
     *      correctly classified)
     *   3. Add idx_trigger_type composite index on (trigger_type, enabled)
     *
     * Each step probes current schema state and only ALTERs if needed,
     * so this method is safe to run repeatedly.
     *
     * Failures are logged but not thrown — partial migration is preferred
     * over a crashed plugin load. The next attempt picks up where we
     * left off.
     */
    private static function migrate_to_0_2_0() {
        global $wpdb;

        $tables          = self::get_table_names();
        $workflows_table = $tables['workflows'];

        // Step 1: form_id → nullable. dbDelta historically does NOT detect
        // a NOT NULL → NULL change reliably, hence the raw ALTER.
        if ( ! self::column_is_nullable( $workflows_table, 'form_id' ) ) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled (from FMW_Schema::get_table_names()); DDL has no user input.
            $result = $wpdb->query(
                "ALTER TABLE `{$workflows_table}` MODIFY COLUMN `form_id` VARCHAR(64) NULL"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $result === false ) {
                FMW_Logger::error( 'v0.2.0 migration: failed to make form_id nullable', [
                    'table' => $workflows_table,
                    'error' => $wpdb->last_error,
                ] );
            } else {
                FMW_Logger::info( 'v0.2.0 migration: form_id is now nullable', [
                    'table' => $workflows_table,
                ] );
            }
        }

        // Step 2: trigger_type column.
        if ( ! self::column_exists( $workflows_table, 'trigger_type' ) ) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled; DDL has no user input.
            $result = $wpdb->query(
                "ALTER TABLE `{$workflows_table}` ADD COLUMN `trigger_type` VARCHAR(32) NOT NULL DEFAULT 'form' AFTER `form_id`"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $result === false ) {
                FMW_Logger::error( 'v0.2.0 migration: failed to add trigger_type column', [
                    'table' => $workflows_table,
                    'error' => $wpdb->last_error,
                ] );
            } else {
                FMW_Logger::info( 'v0.2.0 migration: added trigger_type column', [
                    'table' => $workflows_table,
                ] );
            }
        }

        // Step 3: idx_trigger_type composite index on (trigger_type, enabled).
        if ( ! self::index_exists( $workflows_table, 'idx_trigger_type' ) ) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled; DDL has no user input.
            $result = $wpdb->query(
                "ALTER TABLE `{$workflows_table}` ADD INDEX `idx_trigger_type` (`trigger_type`, `enabled`)"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $result === false ) {
                FMW_Logger::error( 'v0.2.0 migration: failed to add idx_trigger_type index', [
                    'table' => $workflows_table,
                    'error' => $wpdb->last_error,
                ] );
            } else {
                FMW_Logger::info( 'v0.2.0 migration: added idx_trigger_type index', [
                    'table' => $workflows_table,
                ] );
            }
        }
    }

    /**
     * Check if a column exists on a table.
     *
     * @param string $table  Fully-qualified table name (plugin-controlled).
     * @param string $column Column name.
     * @return bool
     */
    private static function column_exists( $table, $column ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled; column flows through %s.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $row !== null;
    }

    /**
     * Check if a column is nullable.
     *
     * @param string $table  Fully-qualified table name (plugin-controlled).
     * @param string $column Column name.
     * @return bool True if column exists AND is nullable. False if missing or NOT NULL.
     */
    private static function column_is_nullable( $table, $column ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled; column flows through %s.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            return false;
        }

        // SHOW COLUMNS returns a row with a "Null" property of "YES" or "NO".
        return isset( $row->Null ) && strtoupper( $row->Null ) === 'YES';
    }

    /**
     * Check if a named index exists on a table.
     *
     * @param string $table      Fully-qualified table name (plugin-controlled).
     * @param string $index_name
     * @return bool
     */
    private static function index_exists( $table, $index_name ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is plugin-controlled; index name flows through %s.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index_name )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $row !== null;
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
