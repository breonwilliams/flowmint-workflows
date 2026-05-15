<?php
/**
 * Top-level admin menu.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_fmw_replay_run', [ $this, 'handle_replay' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Enqueue admin assets on FMW pages.
     *
     * Includes wp-api so wpApiSettings.nonce is available for client-side
     * REST calls (used by the future MCP-friendly admin UI + dev testing).
     */
    public function enqueue_admin_assets( $hook ) {
        // Only on our pages.
        if ( strpos( (string) $hook, 'fmw-' ) === false && strpos( (string) $hook, 'flowmint' ) === false ) {
            return;
        }
        wp_enqueue_script( 'wp-api' );
    }

    public function register_menu() {
        add_menu_page(
            __( 'FlowMint Workflows', 'flowmint-workflows' ),
            __( 'FlowMint Workflows', 'flowmint-workflows' ),
            FMW_Capabilities::MANAGE_WORKFLOWS,
            'fmw-runs',
            [ $this, 'render_runs_page' ],
            'dashicons-randomize',
            56
        );

        add_submenu_page(
            'fmw-runs',
            __( 'Run History', 'flowmint-workflows' ),
            __( 'Run History', 'flowmint-workflows' ),
            FMW_Capabilities::MANAGE_WORKFLOWS,
            'fmw-runs',
            [ $this, 'render_runs_page' ]
        );

        add_submenu_page(
            'fmw-runs',
            __( 'Workflows', 'flowmint-workflows' ),
            __( 'Workflows', 'flowmint-workflows' ),
            FMW_Capabilities::MANAGE_WORKFLOWS,
            'fmw-workflows',
            [ $this, 'render_workflows_page' ]
        );
    }

    public function render_runs_page() {
        if ( isset( $_GET['run_id'] ) ) {
            ( new FMW_Admin_Runs() )->render_detail( (int) $_GET['run_id'] );
            return;
        }
        ( new FMW_Admin_Runs() )->render_list();
    }

    public function render_workflows_page() {
        $list = FMW_Workflow_Repository::list( [ 'per_page' => 100 ] );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Workflows', 'flowmint-workflows' ) . '</h1>';
        echo '<p>' . esc_html__( 'Workflows are created via the REST API or MCP. This page is read-only — for debugging.', 'flowmint-workflows' ) . '</p>';

        if ( empty( $list['items'] ) ) {
            echo '<p>' . esc_html__( 'No workflows registered yet.', 'flowmint-workflows' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Title</th><th>Form</th><th>Enabled</th><th>Managed By</th><th>Version</th><th>Updated</th></tr></thead><tbody>';

        foreach ( $list['items'] as $row ) {
            echo '<tr>';
            echo '<td><code>' . esc_html( $row['id'] ) . '</code></td>';
            echo '<td>' . esc_html( $row['title'] ) . '</td>';
            echo '<td><code>' . esc_html( $row['form_id'] ) . '</code></td>';
            echo '<td>' . ( $row['enabled'] ? '✓' : '✗' ) . '</td>';
            echo '<td>' . esc_html( $row['managed_by'] ) . '</td>';
            echo '<td>v' . esc_html( $row['connector_version'] ) . '</td>';
            echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Handle a manual replay request from the admin UI.
     */
    public function handle_replay() {
        if ( ! current_user_can( FMW_Capabilities::MANAGE_WORKFLOWS ) ) {
            wp_die( 'Permission denied' );
        }

        check_admin_referer( 'fmw_replay_run' );

        $run_id = isset( $_POST['run_id'] ) ? (int) $_POST['run_id'] : 0;
        if ( $run_id <= 0 ) {
            wp_die( 'Invalid run_id' );
        }

        $run = FMW_Run_Repository::get( $run_id );
        if ( ! $run ) {
            wp_die( 'Run not found' );
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            wp_die( 'Action Scheduler not available' );
        }

        $new_run_id = FMW_Run_Repository::create_pending(
            $run['workflow_id'],
            $run['form_id'],
            (int) $run['entry_id'],
            $run_id
        );

        if ( is_wp_error( $new_run_id ) ) {
            wp_die( esc_html( $new_run_id->get_error_message() ) );
        }

        as_enqueue_async_action( 'fmw_run_workflow', [ $new_run_id ], 'fmw' );

        wp_safe_redirect( add_query_arg( [
            'page'        => 'fmw-runs',
            'run_id'      => $new_run_id,
            'replay_from' => $run_id,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
