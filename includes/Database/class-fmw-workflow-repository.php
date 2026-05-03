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

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %s', $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get the first ENABLED workflow for a given form_id.
     *
     * v1 supports one workflow per form. If multiple exist with the same form_id,
     * returns the one that was most recently updated.
     *
     * @param string $form_id
     * @return array|null
     */
    public static function get_for_form( $form_id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE form_id = %s AND enabled = 1 ORDER BY updated_at DESC LIMIT 1',
                $form_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * List workflows with optional filters.
     *
     * @param array $args {
     *     @type string $form_id     Filter by form_id
     *     @type bool   $enabled     Filter by enabled state
     *     @type string $managed_by  Filter by origin
     *     @type int    $page        Page number (1-based)
     *     @type int    $per_page    Items per page
     * }
     * @return array {
     *     @type array $items
     *     @type int   $total
     * }
     */
    public static function list( array $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'form_id'    => null,
            'enabled'    => null,
            'managed_by' => null,
            'page'       => 1,
            'per_page'   => 20,
        ] );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['form_id'] !== null ) {
            $where[]  = 'form_id = %s';
            $params[] = $args['form_id'];
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

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Create a new workflow.
     *
     * @param array $data {
     *     @type string $id
     *     @type string $title
     *     @type string $form_id
     *     @type bool   $enabled
     *     @type string $config       JSON string
     *     @type string $managed_by   Optional, defaults to 'admin'
     * }
     * @return WP_Error|array Created workflow on success.
     */
    public static function create( array $data ) {
        global $wpdb;

        if ( empty( $data['id'] ) || empty( $data['form_id'] ) || ! isset( $data['config'] ) ) {
            return new WP_Error( 'missing_fields', 'id, form_id, and config are required.' );
        }

        if ( ! preg_match( '/^[a-z0-9\-_]+$/', $data['id'] ) ) {
            return new WP_Error( 'invalid_workflow_id', 'Workflow ID must match ^[a-z0-9\\-_]+$' );
        }

        if ( self::get( $data['id'] ) ) {
            return new WP_Error( 'already_exists', 'A workflow with this ID already exists.' );
        }

        $now = current_time( 'mysql' );

        $row = [
            'id'                => $data['id'],
            'title'             => isset( $data['title'] ) ? (string) $data['title'] : $data['id'],
            'form_id'           => $data['form_id'],
            'enabled'           => isset( $data['enabled'] ) && $data['enabled'] ? 1 : 0,
            'config'            => $data['config'],
            'managed_by'        => isset( $data['managed_by'] ) ? $data['managed_by'] : 'admin',
            'connector_version' => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $result = $wpdb->insert( self::table(), $row );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to insert workflow: ' . $wpdb->last_error );
        }

        return self::get( $data['id'] );
    }

    /**
     * Update an existing workflow.
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

        $update['connector_version'] = (int) $existing['connector_version'] + 1;
        $update['updated_at']        = current_time( 'mysql' );

        $result = $wpdb->update( self::table(), $update, [ 'id' => $id ] );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to update workflow: ' . $wpdb->last_error );
        }

        return self::get( $id );
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
            $run_ids = $wpdb->get_col(
                $wpdb->prepare( 'SELECT id FROM ' . $tables['workflow_runs'] . ' WHERE workflow_id = %s', $id )
            );
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
