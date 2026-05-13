<?php
/**
 * Workflow repository — DB access for the workflows table.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Repository {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        $tables = FMW_Schema::get_table_names();
        return $tables['workflows'];
    }

    /**
     * Get a workflow by ID.
     *
     * @param string $id
     * @return array|null Associative array with workflow fields, or null if not found.
     */
    public static function get( $id ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() returns a $wpdb->prefix-derived table name (plugin-controlled); $id flows through %s.
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %s', $id ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $row ?: null;
    }

    /**
     * Get the first ENABLED form-triggered workflow for a given form_id.
     *
     * v1 supports one workflow per form. If multiple exist with the same
     * form_id, returns the one that was most recently updated.
     *
     * Belt-and-suspenders: explicitly filters `trigger_type = 'form'` so
     * a misconfigured scheduled workflow that somehow has a non-NULL
     * form_id can never be picked up by the FRE submission listener as
     * if it were form-triggered.
     *
     * @param string $form_id
     * @return array|null
     */
    public static function get_for_form( $form_id ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() returns a $wpdb->prefix-derived table name (plugin-controlled); $form_id flows through %s.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . " WHERE form_id = %s AND trigger_type = 'form' AND enabled = 1 ORDER BY updated_at DESC LIMIT 1",
                $form_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $row ?: null;
    }

    /**
     * List all workflows of a given trigger type.
     *
     * Used by FMW_Schedule_Listener to find every workflow that needs an
     * Action Scheduler recurring event registered. Filter by `enabled`
     * to get only workflows that should currently be firing.
     *
     * @param string $trigger_type 'form' or 'schedule' (or any custom
     *                             type registered later).
     * @param array  $args         Optional filters.
     *     @type bool $enabled If true, only return enabled workflows.
     * @return array[] Array of DB rows. Empty array if no matches.
     */
    public static function get_all_by_trigger_type( $trigger_type, array $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'enabled' => null,
        ] );

        $where  = [ 'trigger_type = %s' ];
        $params = [ $trigger_type ];

        if ( $args['enabled'] !== null ) {
            $where[]  = 'enabled = %d';
            $params[] = $args['enabled'] ? 1 : 0;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() is plugin-controlled; $where_sql is a fixed allow-list of column predicates using %s/%d placeholders; user values flow through $params via prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY updated_at DESC',
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $rows ?: [];
    }

    /**
     * List workflows with optional filters.
     *
     * @param array $args {
     *     @type string $form_id      Filter by form_id
     *     @type string $trigger_type Filter by trigger_type ('form' or 'schedule')
     *     @type bool   $enabled      Filter by enabled state
     *     @type string $managed_by   Filter by origin
     *     @type int    $page         Page number (1-based)
     *     @type int    $per_page     Items per page
     * }
     * @return array {
     *     @type array $items
     *     @type int   $total
     * }
     */
    public static function list( array $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'form_id'      => null,
            'trigger_type' => null,
            'enabled'      => null,
            'managed_by'   => null,
            'page'         => 1,
            'per_page'     => 20,
        ] );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['form_id'] !== null ) {
            $where[]  = 'form_id = %s';
            $params[] = $args['form_id'];
        }

        if ( $args['trigger_type'] !== null ) {
            $where[]  = 'trigger_type = %s';
            $params[] = $args['trigger_type'];
        }

        if ( $args['enabled'] !== null ) {
            $where[]  = 'enabled = %d';
            $params[] = $args['enabled'] ? 1 : 0;
        }

        if ( $args['managed_by'] !== null ) {
            $where[]  = 'managed_by = %s';
            $params[] = $args['managed_by'];
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = max( 0, ( $args['page'] - 1 ) * $args['per_page'] );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() is a plugin-controlled table name; $where_sql is composed from a fixed allow-list of column predicates above (form_id, enabled, managed_by) using %s/%d placeholders; user values flow through $params via prepare().
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
        $items = $wpdb->get_results(
            $params
                ? $wpdb->prepare( $sql, array_merge( $params, [ (int) $args['per_page'], $offset ] ) )
                : $wpdb->prepare( $sql, (int) $args['per_page'], $offset ),
            ARRAY_A
        );

        $count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where_sql;
        $total = (int) $wpdb->get_var(
            $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Create a new workflow.
     *
     * Derives `trigger_type` (and `form_id` for form-triggered workflows)
     * from the normalized config. The stored config JSON is the normalized
     * shape — new workflows always have an explicit `trigger` block so
     * future reads don't depend on legacy-shape inference.
     *
     * @param array $data {
     *     @type string $id          Required. Workflow slug.
     *     @type string $title       Optional. Defaults to id.
     *     @type string $form_id     Optional. Legacy convenience — if set
     *                               and config has no trigger block, treated
     *                               as `trigger.type = form`.
     *     @type bool   $enabled     Optional. Defaults to false.
     *     @type string|array $config Required. Workflow config (JSON string
     *                               or already-decoded array).
     *     @type string $managed_by  Optional. Defaults to 'admin'.
     * }
     * @return WP_Error|array Created workflow row on success.
     */
    public static function create( array $data ) {
        global $wpdb;

        if ( empty( $data['id'] ) || ! isset( $data['config'] ) ) {
            return new WP_Error( 'missing_fields', 'id and config are required.' );
        }

        if ( ! preg_match( '/^[a-z0-9\-_]+$/', $data['id'] ) ) {
            return new WP_Error( 'invalid_workflow_id', 'Workflow ID must match ^[a-z0-9\\-_]+$' );
        }

        if ( self::get( $data['id'] ) ) {
            return new WP_Error( 'already_exists', 'A workflow with this ID already exists.' );
        }

        $resolved = self::resolve_trigger_from_data( $data );
        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }

        $now = current_time( 'mysql' );

        $row = [
            'id'                => $data['id'],
            'title'             => isset( $data['title'] ) ? (string) $data['title'] : $data['id'],
            'form_id'           => $resolved['form_id'], // NULL for scheduled
            'trigger_type'      => $resolved['trigger_type'],
            'enabled'           => isset( $data['enabled'] ) && $data['enabled'] ? 1 : 0,
            'config'            => $resolved['config_json'],
            'managed_by'        => isset( $data['managed_by'] ) ? $data['managed_by'] : 'admin',
            'connector_version' => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $result = $wpdb->insert( self::table(), $row );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to insert workflow: ' . $wpdb->last_error );
        }

        $created = self::get( $data['id'] );

        // Fire save hook so listeners (Phase 2 schedule listener) can
        // react to the new workflow's lifecycle.
        if ( $created && class_exists( 'FMW_Workflow' ) ) {
            /**
             * Fires after a workflow is created or updated.
             *
             * Subscribers (Phase 2 schedule listener) inspect the
             * workflow's trigger_type and enabled state to decide
             * whether to register / unregister Action Scheduler
             * recurring events.
             *
             * @param FMW_Workflow $workflow The created workflow.
             */
            do_action( 'fmw_workflow_saved', new FMW_Workflow( $created ) );
        }

        return $created;
    }

    /**
     * Update an existing workflow.
     *
     * If `config` is among the changes, the new config is normalized and
     * the row's `trigger_type` and `form_id` columns are re-derived from
     * the normalized trigger block. The stored JSON is the normalized
     * shape.
     *
     * @param string $id
     * @param array  $changes
     * @return WP_Error|array Updated workflow on success.
     */
    public static function update( $id, array $changes ) {
        global $wpdb;

        $existing = self::get( $id );
        if ( ! $existing ) {
            return new WP_Error( 'workflow_not_found', "Workflow {$id} not found." );
        }

        $updatable_fields = [ 'title', 'enabled', 'config', 'form_id' ];
        $update           = [];

        foreach ( $updatable_fields as $field ) {
            if ( array_key_exists( $field, $changes ) ) {
                if ( $field === 'enabled' ) {
                    $update[ $field ] = $changes[ $field ] ? 1 : 0;
                } else {
                    $update[ $field ] = $changes[ $field ];
                }
            }
        }

        if ( empty( $update ) ) {
            return $existing;
        }

        // If the config is being updated, re-derive trigger_type and
        // form_id from the normalized config. We pull the proposed
        // changes into a synthetic "data" array with the existing
        // values as fallbacks, then resolve as if creating.
        if ( array_key_exists( 'config', $update ) ) {
            $resolution_input = [
                'config'  => $update['config'],
                'form_id' => array_key_exists( 'form_id', $update )
                    ? $update['form_id']
                    : $existing['form_id'],
            ];
            $resolved = self::resolve_trigger_from_data( $resolution_input );
            if ( is_wp_error( $resolved ) ) {
                return $resolved;
            }
            $update['config']       = $resolved['config_json'];
            $update['form_id']      = $resolved['form_id']; // NULL for scheduled
            $update['trigger_type'] = $resolved['trigger_type'];
        }

        $update['connector_version'] = (int) $existing['connector_version'] + 1;
        $update['updated_at']        = current_time( 'mysql' );

        $was_enabled = ! empty( $existing['enabled'] );

        $result = $wpdb->update( self::table(), $update, [ 'id' => $id ] );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to update workflow: ' . $wpdb->last_error );
        }

        $updated = self::get( $id );
        $now_enabled = ! empty( $updated['enabled'] );

        // Always fire fmw_workflow_saved so listeners can reconcile
        // state (re-register, re-schedule, etc).
        if ( $updated && class_exists( 'FMW_Workflow' ) ) {
            do_action( 'fmw_workflow_saved', new FMW_Workflow( $updated ) );

            // Distinct hook for the enabled→disabled transition. Lets
            // listeners that ONLY care about "stop firing" subscribe
            // without parsing the full state diff.
            if ( $was_enabled && ! $now_enabled ) {
                /**
                 * Fires when a workflow transitions from enabled to disabled.
                 *
                 * @param string $workflow_id
                 */
                do_action( 'fmw_workflow_disabled', $id );
            }
        }

        return $updated;
    }

    /**
     * Resolve the canonical trigger_type / form_id / normalized config
     * JSON from create/update input.
     *
     * @internal Shared helper for create() and update() so the
     *           normalization logic lives in exactly one place.
     *
     * @param array $data {
     *     @type string|array $config  Required.
     *     @type string|null  $form_id Optional legacy wrapper field.
     * }
     * @return array|WP_Error On success: [ 'trigger_type' => str,
     *                        'form_id' => str|null, 'config_json' => str ].
     */
    private static function resolve_trigger_from_data( array $data ) {
        $config = $data['config'] ?? null;

        if ( is_string( $config ) ) {
            $decoded = json_decode( $config, true );
            if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error(
                    'invalid_config',
                    'config is not valid JSON: ' . json_last_error_msg()
                );
            }
            $config = $decoded;
        }

        if ( ! is_array( $config ) ) {
            return new WP_Error( 'invalid_config', 'config must be a JSON object/array.' );
        }

        // Wrapper-level convenience fields propagate INTO config when
        // the inner config has no trigger block. Precedence matches
        // FMW_Workflow_Validator::validate_full:
        //   1. Inner $config['trigger']     — wins.
        //   2. Wrapper $data['trigger']     — new v0.6.0 REST shape.
        //   3. Wrapper $data['form_id']     — legacy REST shape.
        if ( ! isset( $config['trigger'] ) ) {
            if ( ! empty( $data['trigger'] ) && is_array( $data['trigger'] ) ) {
                $config['trigger'] = $data['trigger'];
            } elseif ( empty( $config['form_id'] ) && ! empty( $data['form_id'] ) ) {
                $config['form_id'] = $data['form_id'];
            }
        }

        $config = FMW_Workflow_Validator::normalize( $config );

        $trigger      = $config['trigger'] ?? [];
        $trigger_type = isset( $trigger['type'] ) ? (string) $trigger['type'] : null;

        if ( ! $trigger_type ) {
            return new WP_Error(
                'missing_trigger',
                'config must contain a trigger block, or a top-level form_id (legacy shape).'
            );
        }

        $form_id_to_store = null;
        if ( $trigger_type === 'form' ) {
            $form_id_to_store = $trigger['form_id'] ?? $data['form_id'] ?? null;
            if ( empty( $form_id_to_store ) ) {
                return new WP_Error(
                    'missing_form_id',
                    'form-triggered workflows require a form_id (set trigger.form_id or pass form_id at the top level).'
                );
            }
            $form_id_to_store = (string) $form_id_to_store;
        }

        return [
            'trigger_type' => $trigger_type,
            'form_id'      => $form_id_to_store,
            'config_json'  => wp_json_encode( $config ),
        ];
    }

    /**
     * Delete a workflow.
     *
     * @param string $id
     * @param bool   $cascade If true, also delete all run history for this workflow.
     * @return bool|WP_Error
     */
    public static function delete( $id, $cascade = false ) {
        global $wpdb;

        $existing = self::get( $id );
        if ( ! $existing ) {
            return new WP_Error( 'workflow_not_found', "Workflow {$id} not found." );
        }

        if ( $cascade ) {
            // Delete child rows first.
            $tables = FMW_Schema::get_table_names();
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tables[*] values are plugin-controlled table names from FMW_Schema::get_table_names(); $id flows through %s.
            $run_ids = $wpdb->get_col(
                $wpdb->prepare( 'SELECT id FROM ' . $tables['workflow_runs'] . ' WHERE workflow_id = %s', $id )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $run_ids ) {
                $placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$tables['workflow_run_steps']} WHERE run_id IN ({$placeholders})",
                    $run_ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$tables['workflow_runs']} WHERE workflow_id = %s",
                    $id
                ) );
            }
        }

        $deleted = $wpdb->delete( self::table(), [ 'id' => $id ] );

        if ( $deleted !== false ) {
            /**
             * Fires after a workflow is deleted.
             *
             * Distinct from fmw_workflow_disabled — disabled workflows
             * still exist in the DB and may be re-enabled; deleted
             * workflows are gone. Phase 2's schedule listener handles
             * both, but external code may want to distinguish them.
             *
             * @param string $workflow_id
             * @param array  $row The full row that was deleted (snapshot).
             */
            do_action( 'fmw_workflow_deleted', $id, $existing );
        }

        return $deleted !== false;
    }

    /**
     * Check if a workflow exists.
     *
     * @param string $id
     * @return bool
     */
    public static function exists( $id ) {
        return self::get( $id ) !== null;
    }
}
