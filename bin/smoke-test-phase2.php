<?php
/**
 * Phase 2 smoke test for v0.6.0 scheduled triggers.
 *
 * Run via WP-CLI from the plugin directory:
 *     wp eval-file bin/smoke-test-phase2.php
 *
 * Verifies the listener wiring, the lifecycle handlers (save / update /
 * disable / delete), the synthetic-context tick path, the full
 * end-to-end execution (schedule a workflow → fire its tick → workflow
 * job picks it up → executor completes), and the reconciliation pass.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-phase2.php must run via wp eval-file\n" );
    exit( 99 );
}

ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
error_reporting( E_ALL );

try {

// ============================================================
// Helpers
// ============================================================

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

function smoke_section( $title ) {
    global $smoke_results;
    $smoke_results[] = "";
    $smoke_results[] = "\033[1m== $title ==\033[0m";
}

/** Convenience: does an AS recurring action for this workflow exist? */
function smoke_has_tick( $workflow_id ) {
    if ( ! function_exists( 'as_has_scheduled_action' ) ) {
        return false;
    }
    return (bool) as_has_scheduled_action(
        FMW_Schedule_Listener::TICK_HOOK,
        [ $workflow_id ],
        FMW_Schedule_Listener::ACTION_GROUP
    );
}

/** Fetch all pending tick actions for a workflow. */
function smoke_get_ticks( $workflow_id ) {
    if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
        return [];
    }
    return as_get_scheduled_actions( [
        'hook'     => FMW_Schedule_Listener::TICK_HOOK,
        'args'     => [ $workflow_id ],
        'group'    => FMW_Schedule_Listener::ACTION_GROUP,
        'status'   => 'pending',
        'per_page' => 50,
    ] );
}

// Test workflow IDs — all use __phase2_test_* prefix and are cleaned
// up at the end (and on fatal — see try/finally below).
$WORKFLOW_HOURLY    = '__phase2_test_hourly';
$WORKFLOW_DAILY     = '__phase2_test_daily';
$WORKFLOW_WEEKLY    = '__phase2_test_weekly';
$WORKFLOW_LOG_ONLY  = '__phase2_test_log_only';
$WORKFLOW_DRIFT     = '__phase2_test_drift';
$ALL_TEST_IDS = [
    $WORKFLOW_HOURLY,
    $WORKFLOW_DAILY,
    $WORKFLOW_WEEKLY,
    $WORKFLOW_LOG_ONLY,
    $WORKFLOW_DRIFT,
];

// Pre-test cleanup (in case a prior run aborted mid-test).
foreach ( $ALL_TEST_IDS as $id ) {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions(
            FMW_Schedule_Listener::TICK_HOOK,
            [ $id ],
            FMW_Schedule_Listener::ACTION_GROUP
        );
    }
    FMW_Workflow_Repository::delete( $id, true );
}

try {

// ============================================================
// 1. Listener wiring
// ============================================================

smoke_section( '1. Listener wiring (hooks subscribed)' );

smoke_assert(
    'fmw_workflow_saved has a subscriber',
    has_action( 'fmw_workflow_saved' ) !== false
);
smoke_assert(
    'fmw_workflow_disabled has a subscriber',
    has_action( 'fmw_workflow_disabled' ) !== false
);
smoke_assert(
    'fmw_workflow_deleted has a subscriber',
    has_action( 'fmw_workflow_deleted' ) !== false
);
smoke_assert(
    'fmw_scheduled_workflow_tick has a subscriber',
    has_action( FMW_Schedule_Listener::TICK_HOOK ) !== false
);
smoke_assert(
    'fmw_reconcile_scheduled_events has a subscriber',
    has_action( FMW_Schedule_Listener::RECONCILE_HOOK ) !== false
);

smoke_assert(
    'fmw_reconciliation_bootstrapped option is set (daily reconciliation scheduled)',
    get_option( 'fmw_reconciliation_bootstrapped' ) === '1'
);

smoke_assert(
    'Daily reconciliation AS action is scheduled',
    function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action(
        FMW_Schedule_Listener::RECONCILE_HOOK,
        [],
        FMW_Schedule_Listener::ACTION_GROUP
    )
);

// ============================================================
// 2. Save → AS event registered
// ============================================================

smoke_section( '2. Save: scheduled workflow → AS event registered' );

$hourly_config = wp_json_encode( [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
    'steps'   => [
        [ 'name' => 'tick', 'type' => 'log_info', 'config' => [ 'message' => 'hourly tick' ] ],
    ],
] );

$created = FMW_Workflow_Repository::create( [
    'id'      => $WORKFLOW_HOURLY,
    'title'   => 'Phase 2 — hourly',
    'enabled' => true,
    'config'  => $hourly_config,
] );

smoke_assert(
    'Hourly workflow created successfully',
    is_array( $created )
);

smoke_assert(
    'AS recurring event registered for the hourly workflow',
    smoke_has_tick( $WORKFLOW_HOURLY )
);

$ticks = smoke_get_ticks( $WORKFLOW_HOURLY );
smoke_assert(
    'Exactly one pending tick for the hourly workflow (no duplicates)',
    count( $ticks ) === 1,
    'Got ' . count( $ticks ) . ' ticks'
);

// Inspect the scheduled timestamp — must be in the future.
if ( ! empty( $ticks ) ) {
    $action = reset( $ticks );
    $schedule = $action->get_schedule();
    $next     = $schedule && method_exists( $schedule, 'get_date' ) && $schedule->get_date() instanceof DateTime
        ? $schedule->get_date()->getTimestamp()
        : 0;
    smoke_assert(
        'First-tick timestamp is in the future',
        $next > time(),
        'Got ts=' . $next . ' vs now=' . time()
    );
}

// ============================================================
// 3. Update (interval change) → re-schedule
// ============================================================

smoke_section( '3. Update: interval change → AS event re-registered' );

$updated_config = wp_json_encode( [
    'trigger' => [
        'type'     => 'schedule',
        'interval' => 'daily',
        'hour'     => 2,
        'minute'   => 0,
    ],
    'steps'   => [
        [ 'name' => 'tick', 'type' => 'log_info', 'config' => [ 'message' => 'daily tick' ] ],
    ],
] );

$updated = FMW_Workflow_Repository::update( $WORKFLOW_HOURLY, [
    'config' => $updated_config,
] );

smoke_assert(
    'Workflow update returned a row (no WP_Error)',
    is_array( $updated ) && ! is_wp_error( $updated )
);

$ticks_after = smoke_get_ticks( $WORKFLOW_HOURLY );
smoke_assert(
    'Still exactly one pending tick after interval change (no duplicates)',
    count( $ticks_after ) === 1,
    'Got ' . count( $ticks_after )
);

// ============================================================
// 4. Disable → unschedule
// ============================================================

smoke_section( '4. Disable: workflow enabled→0 unschedules AS event' );

FMW_Workflow_Repository::update( $WORKFLOW_HOURLY, [ 'enabled' => false ] );

smoke_assert(
    'AS event removed after disable',
    ! smoke_has_tick( $WORKFLOW_HOURLY )
);

// ============================================================
// 5. Delete → unschedule
// ============================================================

smoke_section( '5. Delete: removes AS event' );

// Create a fresh weekly workflow, then delete it.
FMW_Workflow_Repository::create( [
    'id'      => $WORKFLOW_WEEKLY,
    'title'   => 'Phase 2 — weekly',
    'enabled' => true,
    'config'  => wp_json_encode( [
        'trigger' => [
            'type'        => 'schedule',
            'interval'    => 'weekly',
            'hour'        => 9,
            'minute'      => 0,
            'day_of_week' => 1,
        ],
        'steps' => [ [ 'name' => 'tick', 'type' => 'log_info', 'config' => [ 'message' => 'w' ] ] ],
    ] ),
] );

smoke_assert(
    'AS event registered for weekly workflow before delete',
    smoke_has_tick( $WORKFLOW_WEEKLY )
);

FMW_Workflow_Repository::delete( $WORKFLOW_WEEKLY, true );

smoke_assert(
    'AS event removed after delete',
    ! smoke_has_tick( $WORKFLOW_WEEKLY )
);

// ============================================================
// 6. End-to-end: tick → queued run → workflow_job → completed
// ============================================================

smoke_section( '6. End-to-end: tick → run completes' );

FMW_Workflow_Repository::create( [
    'id'      => $WORKFLOW_LOG_ONLY,
    'title'   => 'Phase 2 — log-only end-to-end',
    'enabled' => true,
    'config'  => wp_json_encode( [
        'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
        'steps'   => [
            [
                'name'   => 'log_start',
                'type'   => 'log_info',
                'config' => [ 'message' => 'Phase 2 smoke test tick at {{ now(\'Y-m-d H:i:s\') }}' ],
            ],
        ],
    ] ),
] );

smoke_assert(
    'End-to-end workflow created',
    FMW_Workflow_Repository::get( $WORKFLOW_LOG_ONLY ) !== null
);

// Fire the AS tick action synchronously (do_action runs the
// subscribed handler in-process — same path AS takes when its
// runner picks up the recurring event).
do_action( FMW_Schedule_Listener::TICK_HOOK, $WORKFLOW_LOG_ONLY );

// The tick should have created a queued run via
// FMW_Run_Repository::create_pending_scheduled.
global $wpdb;
$runs_table = $wpdb->prefix . 'fmw_workflow_runs';
$queued_run = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM `{$runs_table}` WHERE workflow_id = %s ORDER BY id DESC LIMIT 1",
        $WORKFLOW_LOG_ONLY
    ),
    ARRAY_A
);

smoke_assert(
    'Tick created a run row',
    ! empty( $queued_run )
);

if ( ! empty( $queued_run ) ) {
    smoke_assert(
        'Run row has form_id = "" (scheduled sentinel)',
        $queued_run['form_id'] === '',
        'Got form_id: ' . var_export( $queued_run['form_id'], true )
    );
    smoke_assert(
        'Run row has entry_id = 0 (scheduled sentinel)',
        (int) $queued_run['entry_id'] === 0,
        'Got entry_id: ' . var_export( $queued_run['entry_id'], true )
    );
    smoke_assert(
        'Run row status is "queued" before workflow_job runs',
        $queued_run['status'] === 'queued',
        'Got status: ' . $queued_run['status']
    );

    // Now fire the workflow_job. do_action runs FMW_Workflow_Job::handle
    // synchronously — same code path AS takes for the async action.
    do_action( 'fmw_run_workflow', (int) $queued_run['id'] );

    // Reload the run row.
    $final_run = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM `{$runs_table}` WHERE id = %d",
            (int) $queued_run['id']
        ),
        ARRAY_A
    );

    smoke_assert(
        'Run completed successfully (status = "completed")',
        $final_run && $final_run['status'] === 'completed',
        'Got status: ' . ( $final_run['status'] ?? 'null' )
            . ', error: ' . ( $final_run['error_message'] ?? 'none' )
    );

    smoke_assert(
        'Run has a completed_at timestamp',
        ! empty( $final_run['completed_at'] )
    );

    smoke_assert(
        'Run has a non-null duration_ms',
        $final_run && $final_run['duration_ms'] !== null
    );

    // Verify the context snapshot captured the synthetic (entry-less) state.
    if ( ! empty( $final_run['context_snapshot'] ) ) {
        $ctx = json_decode( $final_run['context_snapshot'], true );

        smoke_assert(
            'Snapshot.entry is empty (no FE entry for scheduled runs)',
            is_array( $ctx ) && empty( $ctx['entry'] )
        );
        smoke_assert(
            'Snapshot.data is empty (no submission for scheduled runs)',
            is_array( $ctx ) && empty( $ctx['data'] )
        );
        smoke_assert(
            'Snapshot.steps.log_start logged truthy (step executed)',
            is_array( $ctx )
                && isset( $ctx['steps']['log_start']['logged'] )
                && $ctx['steps']['log_start']['logged']
        );
    }
}

// ============================================================
// 7. Reconciliation: corrects drift
// ============================================================

smoke_section( '7. Reconciliation pass corrects drift' );

// Create a scheduled workflow normally — listener registers its tick.
FMW_Workflow_Repository::create( [
    'id'      => $WORKFLOW_DRIFT,
    'title'   => 'Phase 2 — drift test',
    'enabled' => true,
    'config'  => wp_json_encode( [
        'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
        'steps'   => [ [ 'name' => 'tick', 'type' => 'log_info', 'config' => [ 'message' => 'drift' ] ] ],
    ] ),
] );

smoke_assert(
    'Drift workflow has its AS event registered after save',
    smoke_has_tick( $WORKFLOW_DRIFT )
);

// Simulate drift: a direct AS-side unschedule (bypassing FMW hooks).
// This is what would happen if e.g. AS data was wiped manually or
// an external tool unscheduled the action.
as_unschedule_all_actions(
    FMW_Schedule_Listener::TICK_HOOK,
    [ $WORKFLOW_DRIFT ],
    FMW_Schedule_Listener::ACTION_GROUP
);

smoke_assert(
    'AS event removed (drift simulated)',
    ! smoke_has_tick( $WORKFLOW_DRIFT )
);

// Run reconciliation — should re-register the AS event.
fmw()->schedule_listener->ensure_recurring_events_registered();

smoke_assert(
    'Reconciliation re-registered the AS event for the enabled scheduled workflow',
    smoke_has_tick( $WORKFLOW_DRIFT )
);

// ============================================================
// 8. Regression: form-triggered workflows are not affected
// ============================================================

smoke_section( '8. Regression: form-triggered workflows are not touched' );

// Find a real form on this install (if any), else use a stub id.
$probe_form_id = 'phase2-probe-form';
if ( function_exists( 'fre' ) && fre()->registry ) {
    $forms = fre()->registry->get_all();
    if ( ! empty( $forms ) ) {
        $probe_form_id = array_keys( $forms )[0];
    }
}

$form_test_id = '__phase2_test_form_regression';
FMW_Workflow_Repository::delete( $form_test_id, true );

$form_created = FMW_Workflow_Repository::create( [
    'id'      => $form_test_id,
    'title'   => 'Phase 2 — form regression',
    'form_id' => $probe_form_id,
    'enabled' => true,
    'config'  => wp_json_encode( [
        'steps' => [ [ 'name' => 'log_start', 'type' => 'log_info', 'config' => [ 'message' => 'form' ] ] ],
    ] ),
] );

smoke_assert(
    'Form-triggered workflow can still be created after Phase 2 wiring',
    is_array( $form_created )
);

smoke_assert(
    'Form-triggered workflow has NO AS recurring event (listener correctly ignored it)',
    ! smoke_has_tick( $form_test_id )
);

FMW_Workflow_Repository::delete( $form_test_id, true );

// ============================================================
// Cleanup
// ============================================================

} finally {
    foreach ( $ALL_TEST_IDS as $id ) {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions(
                FMW_Schedule_Listener::TICK_HOOK,
                [ $id ],
                FMW_Schedule_Listener::ACTION_GROUP
            );
        }
        FMW_Workflow_Repository::delete( $id, true );
    }
    FMW_Workflow_Repository::delete( '__phase2_test_form_regression', true );
}

// ============================================================
// Report
// ============================================================

echo "\n";
echo "FlowMint Workflows v0.6.0 — Phase 2 smoke test\n";
echo "==============================================\n";
foreach ( $smoke_results as $r ) {
    echo "$r\n";
}
echo "\n";
echo "----------------------------------------------\n";
echo "Total: \033[32m{$smoke_pass} passed\033[0m";
if ( $smoke_fail > 0 ) {
    echo ", \033[31m{$smoke_fail} failed\033[0m";
} else {
    echo ", 0 failed";
}
echo "\n";

if ( $smoke_fail > 0 ) {
    exit( $smoke_fail );
}
exit( 0 );

} catch ( \Throwable $e ) {
    fwrite( STDERR, "\n" );
    fwrite( STDERR, "================================================\n" );
    fwrite( STDERR, " UNCAUGHT ERROR IN PHASE 2 SMOKE TEST\n" );
    fwrite( STDERR, "================================================\n" );
    fwrite( STDERR, " Class:    " . get_class( $e ) . "\n" );
    fwrite( STDERR, " Message:  " . $e->getMessage() . "\n" );
    fwrite( STDERR, " File:     " . $e->getFile() . "\n" );
    fwrite( STDERR, " Line:     " . $e->getLine() . "\n" );
    fwrite( STDERR, "\n" );
    fwrite( STDERR, "Stack trace:\n" );
    fwrite( STDERR, $e->getTraceAsString() . "\n" );
    fwrite( STDERR, "\n" );
    exit( 99 );
}
