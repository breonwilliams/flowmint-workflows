<?php
/**
 * Phase 3 smoke test for v0.6.0 scheduled triggers.
 *
 * Verifies the two new step types — fre_list_entries and
 * fre_delete_entries — across every documented filter combination,
 * idempotency, and the end-to-end retention workflow scenario.
 *
 * Run via WP-CLI from the plugin directory:
 *     wp eval-file bin/smoke-test-phase3.php
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-phase3.php must run via wp eval-file\n" );
    exit( 99 );
}

ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
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

function smoke_section( $title ) {
    global $smoke_results;
    $smoke_results[] = "";
    $smoke_results[] = "\033[1m== $title ==\033[0m";
}

/**
 * Insert a test FRE entry with a controlled created_at timestamp.
 *
 * PForms_Entry::create() always uses current_time('mysql'), so to
 * simulate an old entry we insert normally and then UPDATE
 * created_at via direct SQL.
 *
 * @return int Entry ID.
 */
function smoke_make_entry( $form_id, $days_old = 0, $status = 'unread' ) {
    global $wpdb;

    $repo = new PForms_Entry();
    $id   = $repo->create( $form_id, [ 'note' => "phase3 fixture: $days_old days old" ] );

    if ( ! is_int( $id ) || $id <= 0 ) {
        throw new Exception( 'Failed to create test FRE entry: ' . var_export( $id, true ) );
    }

    $entries_table = $wpdb->prefix . 'fre_entries';

    // Compute the desired created_at in site-local time (matches how
    // FRE writes the column originally).
    $tz   = wp_timezone();
    $when = ( new DateTimeImmutable( 'now', $tz ) )
        ->modify( '-' . (int) $days_old . ' days' )
        ->format( 'Y-m-d H:i:s' );

    $wpdb->update(
        $entries_table,
        [
            'created_at' => $when,
            'updated_at' => $when,
            'status'     => $status,
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );

    return $id;
}

// Use a form_id no workflow will ever match against, so creating
// entries doesn't accidentally trigger a real workflow run via the
// FRE submission listener path.
$FORM_A = '__phase3_form_alpha';
$FORM_B = '__phase3_form_beta';

// Track every entry id we create so the finally block can purge them.
$all_created_ids = [];

// Pre-test cleanup: drop any leftover __phase3_* entries from a prior
// run via the same form_ids. Use direct SQL because the test entries
// don't have a workflow attached.
function smoke_cleanup_forms( $form_ids ) {
    global $wpdb, $all_created_ids;
    $entries_table = $wpdb->prefix . 'fre_entries';
    foreach ( $form_ids as $fid ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$entries_table}` WHERE form_id = %s",
            $fid
        ) );
        foreach ( $ids as $id ) {
            $repo = new PForms_Entry();
            $repo->delete( (int) $id );
        }
    }
    $all_created_ids = [];
}

smoke_cleanup_forms( [ $FORM_A, $FORM_B ] );

// Test workflow ids — cleaned up in the finally block.
$WORKFLOW_RETENTION = '__phase3_workflow_retention';

try {

// ============================================================
// 1. Step types registered
// ============================================================

smoke_section( '1. Step types registered' );

$registry = FMW_Step_Registry::instance();

smoke_assert(
    'fre_list_entries registered in step registry',
    $registry->exists( 'fre_list_entries' )
);
smoke_assert(
    'fre_delete_entries registered in step registry',
    $registry->exists( 'fre_delete_entries' )
);

$list_meta = $registry->describe( 'fre_list_entries' );
smoke_assert(
    'fre_list_entries describes itself with category FormEngine',
    is_array( $list_meta ) && ( $list_meta['category'] ?? '' ) === 'FormEngine'
);
smoke_assert(
    'fre_list_entries has_side_effects = false (it\'s a read)',
    is_array( $list_meta ) && $list_meta['has_side_effects'] === false
);

$del_meta = $registry->describe( 'fre_delete_entries' );
smoke_assert(
    'fre_delete_entries has_side_effects = true (it mutates)',
    is_array( $del_meta ) && $del_meta['has_side_effects'] === true
);

// ============================================================
// 2. fre_list_entries — filter combinations
// ============================================================

smoke_section( '2. fre_list_entries: filter combinations' );

// Fixture: 6 entries spanning two forms, varying ages, varying status.
//   id_a_old      — FORM_A, 60 days old,  status 'unread'
//   id_a_mid      — FORM_A, 35 days old,  status 'unread'
//   id_a_recent   — FORM_A,  5 days old,  status 'read'
//   id_b_old      — FORM_B, 90 days old,  status 'read'
//   id_b_mid      — FORM_B, 31 days old,  status 'archived'
//   id_b_recent   — FORM_B,  0 days old,  status 'unread'
$id_a_old    = smoke_make_entry( $FORM_A, 60, 'unread' );
$id_a_mid    = smoke_make_entry( $FORM_A, 35, 'unread' );
$id_a_recent = smoke_make_entry( $FORM_A,  5, 'read' );
$id_b_old    = smoke_make_entry( $FORM_B, 90, 'read' );
$id_b_mid    = smoke_make_entry( $FORM_B, 31, 'archived' );
$id_b_recent = smoke_make_entry( $FORM_B,  0, 'unread' );
$all_created_ids = [ $id_a_old, $id_a_mid, $id_a_recent, $id_b_old, $id_b_mid, $id_b_recent ];

/** Helper: instantiate and run a list step with the given config. */
function smoke_run_list_step( $config ) {
    $context = new FMW_Workflow_Context(
        0,    // run_id (synthetic — not persisted)
        0,    // entry_id (scheduled-style)
        '__phase3_step_test',
        ''
    );
    $step = new FMW_Step_Fre_List_Entries( [
        'name'   => 'list',
        'config' => $config,
    ] );
    return $step->execute( $context );
}

// 2a. No filters → returns everything (subject to limit)
$out = smoke_run_list_step( [ 'limit' => 100 ] );
$returned_ids = array_column( $out['entries'], 'id' );
smoke_assert(
    'No filters: returns at least the 6 fixture entries',
    count( array_intersect( $all_created_ids, $returned_ids ) ) === 6,
    'Got: ' . wp_json_encode( $returned_ids )
);

// 2b. form_id filter
$out = smoke_run_list_step( [ 'form_id' => $FORM_A, 'limit' => 100 ] );
$returned_ids = array_column( $out['entries'], 'id' );
$form_a_set   = [ $id_a_old, $id_a_mid, $id_a_recent ];
smoke_assert(
    'form_id filter: returns only FORM_A entries',
    count( array_diff( $returned_ids, $form_a_set ) ) === 0
    && count( array_diff( $form_a_set, $returned_ids ) ) === 0,
    'Got: ' . wp_json_encode( $returned_ids )
);

// 2c. form_id = '*' → all forms
$out = smoke_run_list_step( [ 'form_id' => '*', 'limit' => 100 ] );
$returned_ids = array_column( $out['entries'], 'id' );
smoke_assert(
    'form_id = "*" wildcard returns entries from all forms (>= 6 fixtures)',
    count( array_intersect( $all_created_ids, $returned_ids ) ) === 6
);

// 2d. status filter (single string)
$out = smoke_run_list_step( [ 'status' => 'unread', 'limit' => 100 ] );
$returned_ids = array_column( $out['entries'], 'id' );
$unread_fixtures = [ $id_a_old, $id_a_mid, $id_b_recent ];
smoke_assert(
    'status=unread filter: returns all unread fixtures',
    count( array_intersect( $unread_fixtures, $returned_ids ) ) === 3
);
smoke_assert(
    'status=unread filter: excludes "read" fixtures',
    ! in_array( $id_a_recent, $returned_ids, true )
    && ! in_array( $id_b_old, $returned_ids, true )
);

// 2e. status filter (array of multiple)
$out = smoke_run_list_step( [
    'status' => [ 'read', 'archived' ],
    'limit'  => 100,
] );
$returned_ids = array_column( $out['entries'], 'id' );
$expected_ra  = [ $id_a_recent, $id_b_old, $id_b_mid ];
smoke_assert(
    'status filter array: returns "read" or "archived" entries',
    count( array_intersect( $expected_ra, $returned_ids ) ) === 3
);

// 2f. older_than_days filter
$out = smoke_run_list_step( [
    'form_id'         => $FORM_A,
    'older_than_days' => 30,
    'limit'           => 100,
] );
$returned_ids = array_column( $out['entries'], 'id' );
smoke_assert(
    'older_than_days=30 for FORM_A: returns 60-day and 35-day entries',
    in_array( $id_a_old, $returned_ids, true )
    && in_array( $id_a_mid, $returned_ids, true )
);
smoke_assert(
    'older_than_days=30 for FORM_A: excludes the 5-day-old entry',
    ! in_array( $id_a_recent, $returned_ids, true )
);

// 2g. older_than_date filter (explicit cutoff wins over older_than_days
// when both supplied — design doc §5.5.1).
$cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )
    ->modify( '-45 days' )
    ->format( 'Y-m-d' );
$out = smoke_run_list_step( [
    'form_id'         => $FORM_A,
    'older_than_date' => $cutoff,
    'older_than_days' => 1, // would normally include almost everything
    'limit'           => 100,
] );
$returned_ids = array_column( $out['entries'], 'id' );
smoke_assert(
    'older_than_date wins over older_than_days when both are set (only 60-day entry returned)',
    in_array( $id_a_old, $returned_ids, true )
    && ! in_array( $id_a_mid, $returned_ids, true )
    && ! in_array( $id_a_recent, $returned_ids, true )
);

// 2h. limit cap enforcement
$out = smoke_run_list_step( [ 'limit' => 99999 ] );
smoke_assert(
    'limit > MAX_LIMIT (1000) is capped',
    isset( $out['limit'] ) && $out['limit'] === FMW_Step_Fre_List_Entries::MAX_LIMIT
);

// 2i. hit_limit flag
$out = smoke_run_list_step( [ 'form_id' => $FORM_A, 'limit' => 2 ] );
smoke_assert(
    'hit_limit flag: true when count == limit',
    isset( $out['hit_limit'] ) && $out['hit_limit'] === true && $out['count'] === 2
);

$out = smoke_run_list_step( [ 'form_id' => $FORM_A, 'limit' => 100 ] );
smoke_assert(
    'hit_limit flag: false when result is smaller than limit',
    isset( $out['hit_limit'] ) && $out['hit_limit'] === false
);

// 2j. Output shape — id, form_id, status, created_at on each entry
$out = smoke_run_list_step( [ 'form_id' => $FORM_A, 'limit' => 100 ] );
$first = $out['entries'][0] ?? null;
smoke_assert(
    'Returned entries have id, form_id, status, created_at keys',
    is_array( $first )
    && isset( $first['id'], $first['form_id'], $first['status'], $first['created_at'] )
);

// 2k. Ordering: oldest first (created_at ASC)
$out = smoke_run_list_step( [ 'form_id' => $FORM_A, 'limit' => 100 ] );
$created_ats = array_column( $out['entries'], 'created_at' );
$sorted_asc  = $created_ats;
sort( $sorted_asc );
smoke_assert(
    'Entries returned in created_at ASC order (oldest first)',
    $created_ats === $sorted_asc
);

// ============================================================
// 3. fre_delete_entries — basics, idempotency, mixed input
// ============================================================

smoke_section( '3. fre_delete_entries: basics + idempotency' );

/** Helper: instantiate and run a delete step with the given config. */
function smoke_run_delete_step( $config ) {
    $context = new FMW_Workflow_Context( 0, 0, '__phase3_step_test', '' );
    $step    = new FMW_Step_Fre_Delete_Entries( [
        'name'   => 'delete',
        'config' => $config,
    ] );
    return $step->execute( $context );
}

// Re-fixture some entries we can safely delete in this section.
$del_1 = smoke_make_entry( $FORM_B, 200, 'unread' );
$del_2 = smoke_make_entry( $FORM_B, 200, 'unread' );
$del_3 = smoke_make_entry( $FORM_B, 200, 'unread' );
$all_created_ids = array_merge( $all_created_ids, [ $del_1, $del_2, $del_3 ] );

// 3a. Delete by bare-id array.
$out = smoke_run_delete_step( [ 'entries' => [ $del_1, $del_2 ] ] );
smoke_assert(
    'Delete by bare ids: deleted_count = 2',
    $out['deleted_count'] === 2 && $out['failed_count'] === 0
);
smoke_assert(
    'Delete by bare ids: entries actually gone from DB',
    ( new PForms_Entry() )->get( $del_1 ) === null
    && ( new PForms_Entry() )->get( $del_2 ) === null
);

// 3b. Delete by entry-object array (output shape from fre_list_entries).
$out = smoke_run_delete_step( [
    'entries' => [
        [ 'id' => $del_3, 'form_id' => $FORM_B, 'status' => 'unread', 'created_at' => '...' ],
    ],
] );
smoke_assert(
    'Delete by entry objects: deleted_count = 1',
    $out['deleted_count'] === 1
);

// 3c. Idempotent re-delete: all three IDs already gone.
$out = smoke_run_delete_step( [ 'entries' => [ $del_1, $del_2, $del_3 ] ] );
smoke_assert(
    'Re-delete same ids: already_gone_count = 3, deleted_count = 0',
    $out['already_gone_count'] === 3 && $out['deleted_count'] === 0 && $out['failed_count'] === 0
);

// 3d. Empty input is a normal no-op (not an error).
$out = smoke_run_delete_step( [ 'entries' => [] ] );
smoke_assert(
    'Empty entries array: clean no-op, no error',
    $out['requested_count'] === 0
    && $out['deleted_count'] === 0
    && $out['already_gone_count'] === 0
    && $out['failed_count'] === 0
);

// 3e. Mixed input: ints + entry objects + bogus values (silently dropped).
$del_4 = smoke_make_entry( $FORM_B, 100, 'unread' );
$all_created_ids[] = $del_4;
$out = smoke_run_delete_step( [
    'entries' => [
        $del_4,                                            // int
        [ 'id' => 999999999, 'note' => 'fake' ],           // object with bogus id
        [ 'not_an_id' => 'oops' ],                          // garbage shape
        'totally-bogus',                                    // garbage string
        null,                                               // null
    ],
] );
smoke_assert(
    'Mixed input: real id deleted, bogus shapes silently dropped (no failures)',
    $out['deleted_count'] === 1
    && $out['already_gone_count'] === 1  // id 999999999 reads as "already_gone"
    && $out['failed_count'] === 0
);

// 3f. Per-id failure tolerance: the step must NOT throw even on
// a partial failure. We can't easily force a real delete failure
// without monkey-patching PForms_Entry. Skip the explicit failure
// case here — the failed[] array is exercised at the type level
// and via the empty case.

// ============================================================
// 4. End-to-end: scheduled retention workflow
// ============================================================

smoke_section( '4. End-to-end: scheduled retention workflow' );

// Set up a retention workflow that purges FORM_A entries older than 30 days.
// Plain config — no real schedule arithmetic; we'll fire the tick manually.
FMW_Workflow_Repository::delete( $WORKFLOW_RETENTION, true );

$retention_config = wp_json_encode( [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'daily', 'hour' => 2 ],
    'steps'   => [
        [
            'name'   => 'find_old',
            'type'   => 'fre_list_entries',
            'config' => [
                'form_id'         => $FORM_A,
                'older_than_days' => 30,
                'limit'           => 100,
            ],
        ],
        [
            'name'     => 'purge',
            'type'     => 'fre_delete_entries',
            'on_error' => 'continue',
            'config'   => [
                'entries' => '{{ steps.find_old.entries }}',
            ],
        ],
        [
            'name'   => 'log_done',
            'type'   => 'log_info',
            'config' => [
                'message' => 'Retention sweep complete. Deleted {{ steps.purge.deleted_count }} entries, {{ steps.purge.already_gone_count }} already gone, {{ steps.purge.failed_count }} failed.',
            ],
        ],
    ],
] );

$workflow = FMW_Workflow_Repository::create( [
    'id'      => $WORKFLOW_RETENTION,
    'title'   => 'Phase 3 — retention workflow test',
    'enabled' => true,
    'config'  => $retention_config,
] );

smoke_assert(
    'Retention workflow created with all three steps',
    is_array( $workflow ) && ! is_wp_error( $workflow )
);

// Snapshot which FORM_A entries should be deleted (>= 30 days old).
// From the fixture: id_a_old (60d), id_a_mid (35d). Not id_a_recent (5d).
$pre_delete_present = (bool) ( new PForms_Entry() )->get( $id_a_old );
smoke_assert(
    'Pre-tick: 60-day FORM_A entry exists',
    $pre_delete_present
);

// Fire the tick → run row created → workflow_job runs → executor runs
// list step → executor runs delete step → executor runs log step.
do_action( FMW_Schedule_Listener::TICK_HOOK, $WORKFLOW_RETENTION );

global $wpdb;
$runs_table = $wpdb->prefix . 'fmw_workflow_runs';
$run = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM `{$runs_table}` WHERE workflow_id = %s ORDER BY id DESC LIMIT 1",
        $WORKFLOW_RETENTION
    ),
    ARRAY_A
);

smoke_assert(
    'Tick created a queued run row for the retention workflow',
    ! empty( $run ) && $run['status'] === 'queued'
);

// Force the workflow_job to run synchronously.
if ( ! empty( $run ) ) {
    do_action( 'fmw_run_workflow', (int) $run['id'] );

    $final = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM `{$runs_table}` WHERE id = %d", (int) $run['id'] ),
        ARRAY_A
    );

    smoke_assert(
        'Retention workflow run completed successfully',
        $final && $final['status'] === 'completed',
        'Got status: ' . ( $final['status'] ?? 'null' )
            . ', error: ' . ( $final['error_message'] ?? 'none' )
    );

    smoke_assert(
        '60-day FORM_A entry was deleted by the retention sweep',
        ( new PForms_Entry() )->get( $id_a_old ) === null
    );
    smoke_assert(
        '35-day FORM_A entry was deleted by the retention sweep',
        ( new PForms_Entry() )->get( $id_a_mid ) === null
    );
    smoke_assert(
        '5-day FORM_A entry was NOT deleted (under the threshold)',
        ( new PForms_Entry() )->get( $id_a_recent ) !== null
    );
    smoke_assert(
        'FORM_B entries were NOT touched (workflow only targeted FORM_A)',
        ( new PForms_Entry() )->get( $id_b_old ) !== null
            && ( new PForms_Entry() )->get( $id_b_recent ) !== null
    );

    // Inspect the run's context snapshot to confirm the delete step's
    // outputs flowed through correctly.
    if ( ! empty( $final['context_snapshot'] ) ) {
        $ctx = json_decode( $final['context_snapshot'], true );
        smoke_assert(
            'Context snapshot: steps.find_old.count == 2',
            isset( $ctx['steps']['find_old']['count'] )
            && $ctx['steps']['find_old']['count'] === 2
        );
        smoke_assert(
            'Context snapshot: steps.purge.deleted_count == 2',
            isset( $ctx['steps']['purge']['deleted_count'] )
            && $ctx['steps']['purge']['deleted_count'] === 2
        );
    }
}

// ============================================================
// Cleanup
// ============================================================

} finally {
    // Drop test entries (any that survived).
    smoke_cleanup_forms( [ $FORM_A, $FORM_B ] );

    // Drop test workflows.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions(
            FMW_Schedule_Listener::TICK_HOOK,
            [ $WORKFLOW_RETENTION ],
            FMW_Schedule_Listener::ACTION_GROUP
        );
    }
    FMW_Workflow_Repository::delete( $WORKFLOW_RETENTION, true );
}

// ============================================================
// Report
// ============================================================

echo "\n";
echo "FlowMint Workflows v0.6.0 — Phase 3 smoke test\n";
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
    fwrite( STDERR, " UNCAUGHT ERROR IN PHASE 3 SMOKE TEST\n" );
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
