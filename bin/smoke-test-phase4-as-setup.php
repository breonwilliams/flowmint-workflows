<?php
/**
 * Phase 4 — Compressed-time AS dispatch test, SETUP step.
 *
 * Creates a scheduled workflow and schedules a one-shot single AS action
 * (NOT a recurring event) with a past timestamp so AS will pick it up on
 * its next runner pass.
 *
 * The shell wrapper then runs `wp action-scheduler run` to force AS to
 * process its queue, then runs smoke-test-phase4-as-verify.php to check
 * the workflow actually ran end-to-end via the AS dispatcher.
 *
 * This complements the Phase 2 tests that use do_action() to simulate
 * the tick — those exercise the same listener code path, but THIS test
 * verifies that AS itself can dispatch a tick action through to a
 * completed workflow run.
 *
 * The workflow_id is left in the DB for the verify step to look up;
 * cleanup happens in smoke-test-phase4-as-verify.php's finally block.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-phase4-as-setup.php must run via wp eval-file\n" );
    exit( 99 );
}

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

try {

$workflow_id = '__phase4_as_dispatch_test';

// Clean any leftover from a previous attempt.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions(
        FMW_Schedule_Listener::TICK_HOOK,
        [ $workflow_id ],
        FMW_Schedule_Listener::ACTION_GROUP
    );
}
FMW_Workflow_Repository::delete( $workflow_id, true );

// Create the test workflow — a single log_info step is enough; the
// point is to verify the dispatch path, not the step library.
$created = FMW_Workflow_Repository::create( [
    'id'      => $workflow_id,
    'title'   => 'Phase 4 — AS dispatch smoke test',
    'enabled' => true,
    'config'  => wp_json_encode( [
        'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
        'steps'   => [
            [
                'name'   => 'log_start',
                'type'   => 'log_info',
                'config' => [
                    'message' => 'Phase 4 AS dispatch test fired at {{ now(\'Y-m-d H:i:s\') }} via real Action Scheduler runner',
                ],
            ],
        ],
    ] ),
] );

if ( is_wp_error( $created ) ) {
    fwrite( STDERR, "FATAL: Could not create test workflow: " . $created->get_error_message() . "\n" );
    exit( 1 );
}

// Schedule a ONE-SHOT immediate tick. Using a past timestamp guarantees
// AS treats it as "ready to run" the moment the queue runner fires.
//
// IMPORTANT: this is `as_schedule_single_action`, not `_recurring_action`
// — we want exactly one dispatch, not a recurrence we'd have to clean up.
$single_action_id = as_schedule_single_action(
    time() - 60,
    FMW_Schedule_Listener::TICK_HOOK,
    [ $workflow_id ],
    FMW_Schedule_Listener::ACTION_GROUP
);

if ( ! $single_action_id ) {
    fwrite( STDERR, "FATAL: as_schedule_single_action returned 0\n" );
    exit( 2 );
}

// Snapshot the run-count BEFORE the dispatcher fires so the verify
// step can detect the new run. Store in an option (transients can be
// cleared at unpredictable times by other plugins).
global $wpdb;
$runs_table = $wpdb->prefix . 'fmw_workflow_runs';
$baseline_max_run_id = (int) $wpdb->get_var( "SELECT IFNULL( MAX(id), 0 ) FROM `{$runs_table}`" );

update_option( '__phase4_as_baseline_max_run_id', $baseline_max_run_id );
update_option( '__phase4_as_workflow_id', $workflow_id );
update_option( '__phase4_as_single_action_id', $single_action_id );

echo "Setup complete:\n";
echo "  workflow_id:        $workflow_id\n";
echo "  single_action_id:   $single_action_id\n";
echo "  baseline max run:   $baseline_max_run_id\n";
echo "\n";
echo "Shell will now run `wp action-scheduler run` to force the dispatcher.\n";

exit( 0 );

} catch ( \Throwable $e ) {
    fwrite( STDERR, "UNCAUGHT ERROR IN SETUP: " . get_class( $e ) . " — " . $e->getMessage() . "\n" );
    fwrite( STDERR, "  File: " . $e->getFile() . ":" . $e->getLine() . "\n" );
    exit( 99 );
}
