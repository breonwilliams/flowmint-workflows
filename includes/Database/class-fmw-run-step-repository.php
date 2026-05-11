<?php
/**
 * Run-step repository — DB access for workflow_run_steps table.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Run_Step_Repository {

    /**
     * Output snapshot truncation limit (bytes). Larger outputs are truncated
     * with a marker so the table doesn't bloat with large API responses.
     */
    const OUTPUT_TRUNCATE_BYTES = 65536; // 64KB

    public static function table() {
        $tables = FMW_Schema::get_table_names();
        return $tables['workflow_run_steps'];
    }

    /**
     * List all step records for a run, in execution order.
     *
     * @param int $run_id
     * @return array
     */
    public static function list_for_run( $run_id ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() returns a $wpdb->prefix-derived table name (plugin-controlled, never user input); $run_id flows through %d.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE run_id = %d ORDER BY step_index ASC',
                (int) $run_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $rows ?: [];
    }

    /**
     * Insert a pending step record before execution begins.
     *
     * @param int    $run_id
     * @param int    $step_index 0-based position in workflow
     * @param string $step_name
     * @param string $step_type
     * @param string $config_snapshot JSON of interpolated config (pre-execution)
     * @return int|WP_Error
     */
    public static function create_pending( $run_id, $step_index, $step_name, $step_type, $config_snapshot ) {
        global $wpdb;

        $row = [
            'run_id'          => (int) $run_id,
            'step_index'      => (int) $step_index,
            'step_name'       => $step_name,
            'step_type'       => $step_type,
            'status'          => 'running',
            'started_at'      => current_time( 'mysql' ),
            'config_snapshot' => self::truncate( $config_snapshot ),
        ];

        $result = $wpdb->insert( self::table(), $row );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to insert run_step: ' . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Mark a step as completed successfully.
     *
     * @param int    $step_id
     * @param int    $duration_ms
     * @param string $output_snapshot JSON of step output (truncated if large)
     * @return bool
     */
    public static function mark_success( $step_id, $duration_ms, $output_snapshot ) {
        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'status'          => 'success',
                'completed_at'    => current_time( 'mysql' ),
                'duration_ms'     => (int) $duration_ms,
                'output_snapshot' => self::truncate( $output_snapshot ),
            ],
            [ 'id' => (int) $step_id ]
        );

        return $result !== false;
    }

    /**
     * Mark a step as failed.
     *
     * @param int    $step_id
     * @param int    $duration_ms
     * @param string $error_code
     * @param string $error_message
     * @return bool
     */
    public static function mark_failure( $step_id, $duration_ms, $error_code, $error_message ) {
        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'status'        => 'failure',
                'completed_at'  => current_time( 'mysql' ),
                'duration_ms'   => (int) $duration_ms,
                'error_code'    => $error_code,
                'error_message' => $error_message,
            ],
            [ 'id' => (int) $step_id ]
        );

        return $result !== false;
    }

    /**
     * Mark a step as skipped (e.g., skip_if evaluated truthy).
     *
     * @param int    $run_id
     * @param int    $step_index
     * @param string $step_name
     * @param string $step_type
     * @param string $reason Why it was skipped
     * @return int|WP_Error Inserted step ID
     */
    public static function record_skipped( $run_id, $step_index, $step_name, $step_type, $reason ) {
        global $wpdb;

        $row = [
            'run_id'          => (int) $run_id,
            'step_index'      => (int) $step_index,
            'step_name'       => $step_name,
            'step_type'       => $step_type,
            'status'          => 'skipped',
            'started_at'      => current_time( 'mysql' ),
            'completed_at'    => current_time( 'mysql' ),
            'duration_ms'     => 0,
            'output_snapshot' => wp_json_encode( [ 'skipped' => true, 'reason' => $reason ] ),
        ];

        $result = $wpdb->insert( self::table(), $row );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to insert skipped step: ' . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Truncate a JSON snapshot to OUTPUT_TRUNCATE_BYTES with a marker.
     *
     * @param string $json
     * @return string
     */
    private static function truncate( $json ) {
        if ( ! is_string( $json ) ) {
            $json = (string) $json;
        }

        if ( strlen( $json ) <= self::OUTPUT_TRUNCATE_BYTES ) {
            return $json;
        }

        return substr( $json, 0, self::OUTPUT_TRUNCATE_BYTES ) . '... [truncated]';
    }
}
