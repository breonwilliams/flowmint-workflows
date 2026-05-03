<?php
/**
 * Run repository — DB access for workflow_runs table.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Run_Repository {

    public static function table() {
        $tables = FMW_Schema::get_table_names();
        return $tables['workflow_runs'];
    }

    /**
     * Get a single run by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function get( $id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * List runs with optional filters.
     *
     * @param array $args
     * @return array { items, total }
     */
    public static function list( array $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'workflow_id' => null,
            'form_id'     => null,
            'entry_id'    => null,
            'status'      => null,
            'date_from'   => null,
            'date_to'     => null,
            'page'        => 1,
            'per_page'    => 20,
        ] );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['workflow_id'] !== null ) {
            $where[]  = 'workflow_id = %s';
            $params[] = $args['workflow_id'];
        }
        if ( $args['form_id'] !== null ) {
            $where[]  = 'form_id = %s';
            $params[] = $args['form_id'];
        }
        if ( $args['entry_id'] !== null ) {
            $where[]  = 'entry_id = %d';
            $params[] = (int) $args['entry_id'];
        }
        if ( $args['status'] !== null ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ( $args['date_from'] !== null ) {
            $where[]  = 'created_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] !== null ) {
            $where[]  = 'created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = max( 0, ( $args['page'] - 1 ) * $args['per_page'] );

        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d';
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
     * Insert a new run row in `queued` state.
     *
     * @param string $workflow_id
     * @param string $form_id
     * @param int    $entry_id
     * @param int    $parent_run_id Optional, for replays.
     * @return int|WP_Error New run ID.
     */
    public static function create_pending( $workflow_id, $form_id, $entry_id, $parent_run_id = null ) {
        global $wpdb;

        $row = [
            'workflow_id'   => $workflow_id,
            'form_id'       => $form_id,
            'entry_id'      => (int) $entry_id,
            'status'        => 'queued',
            'retry_count'   => 0,
            'parent_run_id' => $parent_run_id ? (int) $parent_run_id : null,
            'created_at'    => current_time( 'mysql' ),
        ];

        $result = $wpdb->insert( self::table(), $row );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to insert run: ' . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a run's status and timing.
     *
     * @param int    $run_id
     * @param string $status
     * @param array  $additional Extra fields to update (started_at, completed_at, duration_ms, error_*, etc.)
     * @return bool
     */
    public static function update_status( $run_id, $status, array $additional = [] ) {
        global $wpdb;

        $update = array_merge( [ 'status' => $status ], $additional );

        $result = $wpdb->update(
            self::table(),
            $update,
            [ 'id' => (int) $run_id ]
        );

        return $result !== false;
    }

    /**
     * Mark a run as running (executor picked it up).
     *
     * @param int $run_id
     * @return bool
     */
    public static function mark_running( $run_id ) {
        return self::update_status( $run_id, 'running', [
            'started_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Mark a run as completed successfully.
     *
     * @param int    $run_id
     * @param int    $duration_ms
     * @param string $context_snapshot JSON
     * @return bool
     */
    public static function mark_completed( $run_id, $duration_ms, $context_snapshot ) {
        return self::update_status( $run_id, 'completed', [
            'completed_at'     => current_time( 'mysql' ),
            'duration_ms'      => (int) $duration_ms,
            'context_snapshot' => $context_snapshot,
        ] );
    }

    /**
     * Mark a run as failed.
     *
     * @param int    $run_id
     * @param string $error_code
     * @param string $error_message
     * @param string $failed_step
     * @param string $context_snapshot JSON
     * @return bool
     */
    public static function mark_failed( $run_id, $error_code, $error_message, $failed_step = null, $context_snapshot = null ) {
        return self::update_status( $run_id, 'failed', [
            'completed_at'     => current_time( 'mysql' ),
            'error_code'       => $error_code,
            'error_message'    => $error_message,
            'failed_step'      => $failed_step,
            'context_snapshot' => $context_snapshot,
        ] );
    }

    /**
     * Increment retry_count for a run (when re-enqueued by Action Scheduler).
     *
     * @param int $run_id
     * @return bool
     */
    public static function increment_retry_count( $run_id ) {
        global $wpdb;

        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . ' SET retry_count = retry_count + 1 WHERE id = %d',
            (int) $run_id
        ) );

        return $result !== false;
    }
}
