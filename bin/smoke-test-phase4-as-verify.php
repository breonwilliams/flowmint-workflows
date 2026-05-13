<?php
/**
 * Phase 4 — Compressed-time AS dispatch test, VERIFY step.
 *
 * Runs AFTER `wp action-scheduler run` has processed the single-action
 * the setup step scheduled. Verifies:
 *
 *   1. The single AS action transitioned to status "complete".
 *   2. The schedule listener's tick handler created a new run row.
 *   3. The workflow_job dispatched it to the executor.
 *   4. The run completed with status="completed".
 *   5. The run's context snapshot shows the log step ran.
 *
 * Cleans up the test workflow regardless of pass/fail.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-phase4-as-verify.php must run via wp eval-file\n" );
    exit( 99 );
}

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

try {

global $smoke_pass, $smoke_fail, $smoke_results;
$smoke_pass    = 0;
$smoke_fail    = 0;
$smoke_results = [];

function smoke_assert( $name, $cond, $detail = '' ) {
    global $smoke_pass, $smoke_fail, $smoke_results;
    if ( $cond ) {
        $smoke_pass++;
        $smoke_results[] = "  \033[32mPASS\033[0m  $name";
    } else {
        $smoke_fail++;
        $line = "  \033[31mFAIL\033[0m  $name";
        if ( $detail !== '' ) {
            $line .= "\n         \033[33m$detail\033[0m";
        }
        $smoke_results[] = $line;
    }
}

$workflow_id         = get_option( '__phase4_as_workflow_id', '' );
$baseline_max_run_id = (int) get_option( '__phase4_as_baseline_max_run_id', 0 );
$single_action_id    = (int) get_option( '__phase4_as_single_action_id', 0 );

if ( ! $workflow_id || ! $single_action_id ) {
    fwrite( STDERR, "FATAL: phase4 setup options missing — did setup run?\n" );
    exit( 1 );
}

try {

global $wpdb;
$runs_table = $wpdb->prefix . 'fmw_workflow_runs';

// 1. Confirm the single AS action ran (status = complete).
//
// Action status lives on the STORE singleton, not the Action object —
// the store is what manages persisted state. ActionScheduler::store()
// returns the configured backend (DB store by default), and its
// get_status(int) method returns the canonical status string.
$as_status = class_exists( 'ActionScheduler' )
    ? ActionScheduler::store()->get_status( $single_action_id )
    : 'unknown';

smoke_assert(
    'AS single-action status is "complete" after wp action-scheduler run',
    $as_status === 'complete',
    "single_action_id=$single_action_id, status=$as_status"
);

// 2. A new run row was created (id > baseline).
$new_run = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM `{$runs_table}` WHERE workflow_id = %s AND id > %d ORDER BY id DESC LIMIT 1",
    $workflow_id,
    $baseline_max_run_id
), ARRAY_A );

smoke_assert(
    'Tick handler created a new run row after AS dispatched',
    ! empty( $new_run ),
    "baseline=$baseline_max_run_id, workflow_id=$workflow_id"
);

if ( ! empty( $new_run ) ) {
    smoke_assert(
        'New run row has scheduled-trigger sentinels (form_id="", entry_id=0)',
        $new_run['form_id'] === '' && (int) $new_run['entry_id'] === 0,
        'form_id=' . var_export( $new_run['form_id'], true )
            . ', entry_id=' . var_export( $new_run['entry_id'], true )
    );

    smoke_assert(
        'Run reached status="completed" via the AS-dispatched path',
        $new_run['status'] === 'completed',
        'status=' . $new_run['status'] . ', error=' . ( $new_run['error_message'] ?? 'none' )
    );

    smoke_assert(
        'Run has a non-null duration_ms',
        $new_run['duration_ms'] !== null
    );

    // Inspect context snapshot to confirm executor really ran.
    if ( ! empty( $new_run['context_snapshot'] ) ) {
        $ctx = json_decode( $new_run['context_snapshot'], true );
        smoke_assert(
            'Context snapshot: log_start step logged truthy',
            is_array( $ctx )
                && isset( $ctx['steps']['log_start']['logged'] )
                && $ctx['steps']['log_start']['logged']
        );
    }
}

} finally {
    // Always clean up — test fixture + persisted setup options.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions(
            FMW_Schedule_Listener::TICK_HOOK,
            [ $workflow_id ],
            FMW_Schedule_Listener::ACTION_GROUP
        );
    }
    FMW_Workflow_Repository::delete( $workflow_id, true );

    delete_option( '__phase4_as_baseline_max_run_id' );
    delete_option( '__phase4_as_workflow_id' );
    delete_option( '__phase4_as_single_action_id' );
}

echo "\n";
echo "Phase 4 — Real AS dispatch (compressed-time) results\n";
echo "====================================================\n";
foreach ( $smoke_results as $r ) {
    echo "$r\n";
}
echo "\n";
echo "Total: \033[32m{$smoke_pass} passed\033[0m";
if ( $smoke_fail > 0 ) {
    echo ", \033[31m{$smoke_fail} failed\033[0m";
} else {
    echo ", 0 failed";
}
echo "\n";

exit( $smoke_fail );

} catch ( \Throwable $e ) {
    fwrite( STDERR, "UNCAUGHT ERROR IN VERIFY: " . get_class( $e ) . " — " . $e->getMessage() . "\n" );
    fwrite( STDERR, "  File: " . $e->getFile() . ":" . $e->getLine() . "\n" );
    exit( 99 );
}
