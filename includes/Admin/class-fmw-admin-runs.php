<?php
/**
 * Admin: run history list + run detail views.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Admin_Runs {

    /**
     * Render the run list view.
     */
    public function render_list() {
        $page          = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : null;
        $workflow_filter = isset( $_GET['workflow_id'] ) ? sanitize_text_field( wp_unslash( $_GET['workflow_id'] ) ) : null;

        $args = [
            'page'     => $page,
            'per_page' => 30,
        ];
        if ( $status_filter )   $args['status']   = $status_filter;
        if ( $workflow_filter ) $args['workflow_id'] = $workflow_filter;

        $list = FMW_Run_Repository::list( $args );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'FlowMint Workflows — Run History', 'flowmint-workflows' ) . '</h1>';

        // Filter form.
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="fmw-runs" />';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__( 'All statuses', 'flowmint-workflows' ) . '</option>';
        foreach ( [ 'queued', 'running', 'completed', 'failed', 'cancelled' ] as $s ) {
            $selected = $status_filter === $s ? 'selected' : '';
            echo "<option value=\"{$s}\" {$selected}>" . esc_html( ucfirst( $s ) ) . '</option>';
        }
        echo '</select> ';
        echo '<input type="text" name="workflow_id" placeholder="Workflow ID" value="' . esc_attr( $workflow_filter ?? '' ) . '" /> ';
        echo '<input type="submit" class="button" value="Filter" />';
        echo '</form>';

        if ( empty( $list['items'] ) ) {
            echo '<p>' . esc_html__( 'No workflow runs yet.', 'flowmint-workflows' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Run #</th><th>Workflow</th><th>Form</th><th>Entry</th><th>Status</th><th>Duration</th><th>Created</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ( $list['items'] as $row ) {
            $detail_url = add_query_arg( [
                'page'   => 'fmw-runs',
                'run_id' => $row['id'],
            ], admin_url( 'admin.php' ) );

            $status_color = self::status_color( $row['status'] );

            echo '<tr>';
            echo '<td><a href="' . esc_url( $detail_url ) . '">#' . (int) $row['id'] . '</a></td>';
            echo '<td><code>' . esc_html( $row['workflow_id'] ) . '</code></td>';
            echo '<td><code>' . esc_html( $row['form_id'] ) . '</code></td>';
            echo '<td>#' . (int) $row['entry_id'] . '</td>';
            echo '<td><span style="color: ' . esc_attr( $status_color ) . '; font-weight: bold;">' . esc_html( strtoupper( $row['status'] ) ) . '</span></td>';
            echo '<td>' . ( $row['duration_ms'] !== null ? esc_html( $row['duration_ms'] ) . ' ms' : '—' ) . '</td>';
            echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
            echo '<td><a href="' . esc_url( $detail_url ) . '">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Pagination.
        $total_pages = (int) ceil( $list['total'] / $args['per_page'] );
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( [
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $page,
                'total'     => $total_pages,
            ] );
            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Render the detail view for a single run.
     *
     * @param int $run_id
     */
    public function render_detail( $run_id ) {
        $run = FMW_Run_Repository::get( $run_id );
        if ( ! $run ) {
            echo '<div class="wrap"><h1>Run not found</h1></div>';
            return;
        }

        $steps = FMW_Run_Step_Repository::list_for_run( $run_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Run', 'flowmint-workflows' ) . ' #' . (int) $run['id'] . '</h1>';

        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=fmw-runs' ) ) . '">&larr; Back to runs</a></p>';

        $status_color = self::status_color( $run['status'] );

        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Status</th><td><span style="color: ' . esc_attr( $status_color ) . '; font-weight: bold;">' . esc_html( strtoupper( $run['status'] ) ) . '</span></td></tr>';
        echo '<tr><th>Workflow</th><td><code>' . esc_html( $run['workflow_id'] ) . '</code></td></tr>';
        echo '<tr><th>Form</th><td><code>' . esc_html( $run['form_id'] ) . '</code></td></tr>';
        echo '<tr><th>Entry</th><td>#' . (int) $run['entry_id'] . '</td></tr>';
        echo '<tr><th>Created</th><td>' . esc_html( $run['created_at'] ) . '</td></tr>';
        if ( $run['started_at'] )   echo '<tr><th>Started</th><td>' . esc_html( $run['started_at'] ) . '</td></tr>';
        if ( $run['completed_at'] ) echo '<tr><th>Completed</th><td>' . esc_html( $run['completed_at'] ) . '</td></tr>';
        if ( $run['duration_ms'] !== null ) echo '<tr><th>Duration</th><td>' . esc_html( $run['duration_ms'] ) . ' ms</td></tr>';
        echo '<tr><th>Retry count</th><td>' . (int) $run['retry_count'] . '</td></tr>';
        if ( $run['error_code'] ) {
            echo '<tr><th>Error code</th><td><code>' . esc_html( $run['error_code'] ) . '</code></td></tr>';
            echo '<tr><th>Error message</th><td><pre>' . esc_html( $run['error_message'] ) . '</pre></td></tr>';
        }
        echo '</tbody></table>';

        // Replay button.
        if ( in_array( $run['status'], [ 'failed', 'completed', 'cancelled' ], true ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'fmw_replay_run' );
            echo '<input type="hidden" name="action" value="fmw_replay_run" />';
            echo '<input type="hidden" name="run_id" value="' . (int) $run['id'] . '" />';
            echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Replay this run', 'flowmint-workflows' ) . '" />';
            echo '</form>';
        }

        // Steps table.
        echo '<h2>' . esc_html__( 'Steps', 'flowmint-workflows' ) . '</h2>';

        if ( empty( $steps ) ) {
            echo '<p>' . esc_html__( 'No steps recorded yet (run may still be queued).', 'flowmint-workflows' ) . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>#</th><th>Name</th><th>Type</th><th>Status</th><th>Duration</th><th>Output / Error</th></tr></thead><tbody>';
            foreach ( $steps as $step ) {
                $color = self::status_color( $step['status'] );
                echo '<tr>';
                echo '<td>' . (int) $step['step_index'] . '</td>';
                echo '<td><code>' . esc_html( $step['step_name'] ) . '</code></td>';
                echo '<td><code>' . esc_html( $step['step_type'] ) . '</code></td>';
                echo '<td><span style="color: ' . esc_attr( $color ) . ';">' . esc_html( strtoupper( $step['status'] ) ) . '</span></td>';
                echo '<td>' . ( $step['duration_ms'] !== null ? esc_html( $step['duration_ms'] ) . ' ms' : '—' ) . '</td>';
                echo '<td><details><summary>show</summary><pre style="max-height: 300px; overflow:auto; background: #f5f5f5; padding: 8px;">';
                if ( $step['error_code'] ) {
                    echo esc_html( '[' . $step['error_code'] . '] ' . $step['error_message'] );
                } else {
                    echo esc_html( $step['output_snapshot'] ?? '' );
                }
                echo '</pre></details></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Context snapshot.
        if ( $run['context_snapshot'] ) {
            echo '<h2>' . esc_html__( 'Context snapshot', 'flowmint-workflows' ) . '</h2>';
            echo '<details><summary>show</summary>';
            echo '<pre style="max-height: 400px; overflow:auto; background: #f5f5f5; padding: 8px;">';
            $decoded = json_decode( $run['context_snapshot'], true );
            echo esc_html( $decoded ? wp_json_encode( $decoded, JSON_PRETTY_PRINT ) : $run['context_snapshot'] );
            echo '</pre></details>';
        }

        echo '</div>';
    }

    private static function status_color( $status ) {
        $colors = [
            'queued'    => '#999',
            'running'   => '#0073aa',
            'completed' => '#46b450',
            'success'   => '#46b450',
            'failed'    => '#dc3232',
            'failure'   => '#dc3232',
            'skipped'   => '#999',
            'cancelled' => '#999',
        ];
        return $colors[ $status ] ?? '#000';
    }
}

// Stub for FMW_Admin_Replay since FMW_Admin handles replay directly via admin_post.
// Kept as a separate file slot for future expansion of replay UI.
class FMW_Admin_Replay {}
