<?php
/**
 * Phase 1 smoke test for v0.6.0 scheduled triggers.
 *
 * Run via WP-CLI from the plugin directory:
 *     wp eval-file bin/smoke-test-phase1.php
 *
 * The test loads under full WordPress context so the v0.2.0 DB migration
 * fires on plugins_loaded BEFORE any test runs. All test fixtures use
 * obvious __phase1_test_* IDs and are cleaned up at the end (or on
 * fatal error — try/finally).
 *
 * Exit code is the number of failures. 0 = clean.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-phase1.php must run via wp eval-file\n" );
    exit( 99 );
}

// Force PHP to surface every error to stderr/stdout. WP-CLI's default
// behavior is to swallow fatals and print "critical error on this
// website" — useless for debugging. This block makes errors visible.
ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
error_reporting( E_ALL );

// Wrap the entire smoke test in a top-level try/catch so that any
// uncaught Throwable (PHP 7+ Error or Exception) prints a clean
// diagnostic instead of leaving us with "critical error on this
// website" and no stack trace.
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

// IDs we'll create and clean up.
$SCHEDULED_ID = '__phase1_test_scheduled';
$LEGACY_ID    = '__phase1_test_form_legacy';
$NEW_FORM_ID  = '__phase1_test_form_new';

// ============================================================
// 1. Plugin lifecycle: migration ran, classes loaded
// ============================================================

smoke_section( '1. Plugin lifecycle' );

smoke_assert(
    'FMW_DB_VERSION constant is 0.2.0',
    defined( 'FMW_DB_VERSION' ) && FMW_DB_VERSION === '0.2.0',
    'Got: ' . ( defined( 'FMW_DB_VERSION' ) ? FMW_DB_VERSION : '(undefined)' )
);

$stored = get_option( 'fmw_db_version', '(unset)' );
smoke_assert(
    'fmw_db_version option is 0.2.0 (migration ran)',
    $stored === '0.2.0',
    "Got stored version: $stored"
);

smoke_assert(
    'FMW_Schedule_Listener class autoloaded',
    class_exists( 'FMW_Schedule_Listener' ),
    'Autoloader map may be missing the FMW_Schedule prefix'
);

smoke_assert(
    'fmw()->schedule_listener wired into bootstrap',
    function_exists( 'fmw' ) && fmw()->schedule_listener instanceof FMW_Schedule_Listener,
    'init_components() must instantiate FMW_Schedule_Listener and call ->init()'
);

// ============================================================
// 2. Database schema
// ============================================================

smoke_section( '2. Database schema' );

global $wpdb;
$workflows_table = $wpdb->prefix . 'fmw_workflows';

$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$workflows_table}`", OBJECT_K );

smoke_assert(
    'wp_fmw_workflows.trigger_type column exists',
    isset( $columns['trigger_type'] ),
    'Migration did not add trigger_type column'
);

if ( isset( $columns['trigger_type'] ) ) {
    smoke_assert(
        'trigger_type column has DEFAULT \'form\'',
        $columns['trigger_type']->Default === 'form',
        'Got Default: ' . var_export( $columns['trigger_type']->Default, true )
    );

    smoke_assert(
        'trigger_type column type is VARCHAR(32)',
        stripos( $columns['trigger_type']->Type, 'varchar(32)' ) !== false,
        'Got Type: ' . $columns['trigger_type']->Type
    );
}

smoke_assert(
    'form_id column is nullable (NULL=YES)',
    isset( $columns['form_id'] ) && strtoupper( $columns['form_id']->Null ) === 'YES',
    'Got Null: ' . ( isset( $columns['form_id'] ) ? $columns['form_id']->Null : '(no column)' )
);

$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$workflows_table}` WHERE Key_name = 'idx_trigger_type'" );
smoke_assert(
    'idx_trigger_type index exists',
    ! empty( $indexes ),
    'Migration did not add the (trigger_type, enabled) composite index'
);

// Re-run the migration idempotency check: if we run migrate() again,
// it should be a no-op (no errors logged, schema unchanged).
FMW_Schema::migrate( '0.2.0', '0.2.0' );
$columns_after = $wpdb->get_results( "SHOW COLUMNS FROM `{$workflows_table}`", OBJECT_K );
smoke_assert(
    'Re-running migrate() is idempotent (no extra columns added)',
    count( $columns ) === count( $columns_after ),
    'Column count changed: ' . count( $columns ) . ' → ' . count( $columns_after )
);

// ============================================================
// 3. Validator — normalization + trigger validation
// ============================================================

smoke_section( '3. Validator: normalization + trigger validation' );

// Legacy shape: top-level form_id, no trigger block. Should normalize.
$legacy = [
    'form_id' => 'some-form',
    'steps'   => [
        [ 'name' => 'log_start', 'type' => 'log_info', 'config' => [ 'message' => 'hello' ] ],
    ],
];
$normalized = FMW_Workflow_Validator::normalize( $legacy );
smoke_assert(
    'normalize() converts legacy top-level form_id → trigger.type=form',
    isset( $normalized['trigger']['type'] ) && $normalized['trigger']['type'] === 'form'
    && isset( $normalized['trigger']['form_id'] ) && $normalized['trigger']['form_id'] === 'some-form',
    'normalized: ' . wp_json_encode( $normalized['trigger'] ?? null )
);

// Already-normalized shape: should be returned unchanged.
$already = [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'daily' ],
    'steps'   => [],
];
$result = FMW_Workflow_Validator::normalize( $already );
smoke_assert(
    'normalize() is idempotent on already-normalized configs',
    $result === $already
);

// Valid scheduled trigger validates clean.
$scheduled_config = [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'daily', 'hour' => 2, 'minute' => 0 ],
    'steps'   => [
        [ 'name' => 'log_start', 'type' => 'log_info', 'config' => [ 'message' => 'tick' ] ],
    ],
];
$validation = FMW_Workflow_Validator::validate( $scheduled_config );
smoke_assert(
    'Valid scheduled trigger validates as valid',
    $validation['valid'] === true,
    'Errors: ' . wp_json_encode( $validation['errors'] )
);

// Unknown trigger type rejected.
$bad_type = [
    'trigger' => [ 'type' => 'webhook' ],
    'steps'   => [],
];
$validation = FMW_Workflow_Validator::validate( $bad_type );
smoke_assert(
    'Unknown trigger.type is rejected',
    $validation['valid'] === false,
    'Should have rejected trigger.type=webhook'
);

// Invalid schedule interval rejected.
$bad_interval = [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'every-five-minutes' ],
    'steps'   => [],
];
$validation = FMW_Workflow_Validator::validate( $bad_interval );
smoke_assert(
    'Invalid schedule interval is rejected',
    $validation['valid'] === false,
    'Should have rejected interval=every-five-minutes'
);

// Out-of-range hour rejected.
$bad_hour = [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'daily', 'hour' => 99 ],
    'steps'   => [],
];
$validation = FMW_Workflow_Validator::validate( $bad_hour );
smoke_assert(
    'Out-of-range trigger.hour is rejected',
    $validation['valid'] === false,
    'Should have rejected hour=99'
);

// Scheduled workflow referencing data.* warns (does not error).
$entry_ref = [
    'trigger' => [ 'type' => 'schedule', 'interval' => 'daily' ],
    'steps'   => [
        [
            'name'   => 'send_thing',
            'type'   => 'log_info',
            'config' => [ 'message' => 'Hello {{ data.full_name }}' ],
        ],
    ],
];
$validation = FMW_Workflow_Validator::validate( $entry_ref );
smoke_assert(
    'Scheduled workflow with {{ data.* }} ref produces a warning (not an error)',
    $validation['valid'] === true && ! empty( $validation['warnings'] ),
    'valid=' . var_export( $validation['valid'], true ) . ' warnings=' . wp_json_encode( $validation['warnings'] )
);

// ============================================================
// 4. Repository — create/retrieve scheduled + legacy workflows
// ============================================================

smoke_section( '4. Repository: scheduled + legacy create/retrieve' );

// Clean any leftovers from prior runs FIRST.
foreach ( [ $SCHEDULED_ID, $LEGACY_ID, $NEW_FORM_ID ] as $id ) {
    FMW_Workflow_Repository::delete( $id, true );
}

try {
    // (a) Scheduled workflow via new shape.
    $config_scheduled = wp_json_encode( [
        'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
        'steps'   => [
            [ 'name' => 'tick', 'type' => 'log_info', 'config' => [ 'message' => 'hi' ] ],
        ],
    ] );
    $created = FMW_Workflow_Repository::create( [
        'id'      => $SCHEDULED_ID,
        'title'   => 'Phase 1 test — scheduled',
        'enabled' => true,
        'config'  => $config_scheduled,
    ] );

    smoke_assert(
        'Create scheduled workflow (no form_id wrapper) succeeds',
        is_array( $created ) && ! is_wp_error( $created ),
        is_wp_error( $created ) ? $created->get_error_message() : 'unexpected return'
    );

    if ( is_array( $created ) ) {
        smoke_assert(
            'Scheduled workflow row has trigger_type=schedule',
            $created['trigger_type'] === 'schedule',
            'Got trigger_type: ' . $created['trigger_type']
        );

        smoke_assert(
            'Scheduled workflow row has form_id = NULL',
            $created['form_id'] === null,
            'Got form_id: ' . var_export( $created['form_id'], true )
        );

        // Wrap as FMW_Workflow value object and verify accessors.
        $w = new FMW_Workflow( $created );
        smoke_assert(
            'FMW_Workflow::trigger_type() returns schedule',
            $w->trigger_type() === 'schedule'
        );
        smoke_assert(
            'FMW_Workflow::schedule_interval() returns hourly',
            $w->schedule_interval() === 'hourly'
        );
    }

    // (b) Legacy form-triggered shape: form_id at the wrapper, no trigger
    // block in config. Should still work — backwards compat.
    //
    // Pick a form_id we can be sure exists by reading FE's registry; fall
    // back to a placeholder string if no forms exist on this install
    // (validator will warn but the repo accepts the raw value).
    $candidate_form_id = 'phase1-fake-form';
    if ( function_exists( 'fre' ) && pforms()->registry ) {
        $forms = pforms()->registry->get_all();
        if ( ! empty( $forms ) ) {
            $candidate_form_id = array_keys( $forms )[0];
        }
    }

    $config_legacy = wp_json_encode( [
        'steps' => [
            [ 'name' => 'log_start', 'type' => 'log_info', 'config' => [ 'message' => 'legacy' ] ],
        ],
    ] );
    $created_legacy = FMW_Workflow_Repository::create( [
        'id'      => $LEGACY_ID,
        'title'   => 'Phase 1 test — legacy form',
        'form_id' => $candidate_form_id,
        'enabled' => false,
        'config'  => $config_legacy,
    ] );

    smoke_assert(
        'Create legacy form-triggered workflow (wrapper form_id, no trigger in config) succeeds',
        is_array( $created_legacy ) && ! is_wp_error( $created_legacy ),
        is_wp_error( $created_legacy ) ? $created_legacy->get_error_message() : 'unexpected'
    );

    if ( is_array( $created_legacy ) ) {
        smoke_assert(
            'Legacy workflow row has trigger_type=form',
            $created_legacy['trigger_type'] === 'form'
        );
        smoke_assert(
            'Legacy workflow row has populated form_id',
            $created_legacy['form_id'] === $candidate_form_id,
            'Got: ' . var_export( $created_legacy['form_id'], true )
        );

        // Verify stored config was normalized — trigger block now in JSON.
        $stored_cfg = json_decode( $created_legacy['config'], true );
        smoke_assert(
            'Legacy workflow stored config got normalized (trigger block injected)',
            isset( $stored_cfg['trigger']['type'] ) && $stored_cfg['trigger']['type'] === 'form'
            && $stored_cfg['trigger']['form_id'] === $candidate_form_id,
            'Stored trigger: ' . wp_json_encode( $stored_cfg['trigger'] ?? null )
        );
    }

    // (c) get_all_by_trigger_type
    $scheduled_rows = FMW_Workflow_Repository::get_all_by_trigger_type( 'schedule', [ 'enabled' => true ] );
    $found = false;
    foreach ( $scheduled_rows as $row ) {
        if ( $row['id'] === $SCHEDULED_ID ) {
            $found = true;
            break;
        }
    }
    smoke_assert(
        'get_all_by_trigger_type(\'schedule\', enabled=true) returns our scheduled workflow',
        $found
    );

    // (d) Belt-and-suspenders: get_for_form must NOT pick up scheduled
    // workflows even if someone hand-corrupts a row with a stray form_id.
    $bogus_row = FMW_Workflow_Repository::get_for_form( 'definitely-not-a-real-form-id-xyz' );
    smoke_assert(
        'get_for_form() returns null for unknown form_id',
        $bogus_row === null
    );

} finally {
    // ALWAYS clean up — even if asserts threw above.
    foreach ( [ $SCHEDULED_ID, $LEGACY_ID, $NEW_FORM_ID ] as $id ) {
        FMW_Workflow_Repository::delete( $id, true );
    }
}

// ============================================================
// 5. Action hooks fire on save/disable/delete
// ============================================================

smoke_section( '5. Action hooks: fmw_workflow_saved / disabled / deleted' );

$hook_log = [];

$saved_listener = function ( $workflow ) use ( &$hook_log ) {
    $hook_log[] = 'saved:' . ( $workflow instanceof FMW_Workflow ? $workflow->id() : '?' );
};
$disabled_listener = function ( $id ) use ( &$hook_log ) {
    $hook_log[] = 'disabled:' . $id;
};
$deleted_listener = function ( $id, $row ) use ( &$hook_log ) {
    $hook_log[] = 'deleted:' . $id;
};

add_action( 'fmw_workflow_saved', $saved_listener );
add_action( 'fmw_workflow_disabled', $disabled_listener );
add_action( 'fmw_workflow_deleted', $deleted_listener, 10, 2 );

try {
    $hook_test_id = '__phase1_test_hooks';
    FMW_Workflow_Repository::delete( $hook_test_id, true );

    FMW_Workflow_Repository::create( [
        'id'      => $hook_test_id,
        'title'   => 'Hook test',
        'enabled' => true,
        'config'  => wp_json_encode( [
            'trigger' => [ 'type' => 'schedule', 'interval' => 'hourly' ],
            'steps'   => [],
        ] ),
    ] );

    smoke_assert(
        'fmw_workflow_saved fires on create',
        in_array( "saved:$hook_test_id", $hook_log, true )
    );

    // Disable it.
    FMW_Workflow_Repository::update( $hook_test_id, [ 'enabled' => false ] );
    smoke_assert(
        'fmw_workflow_disabled fires on enabled 1→0 transition',
        in_array( "disabled:$hook_test_id", $hook_log, true )
    );

    // Delete.
    FMW_Workflow_Repository::delete( $hook_test_id, true );
    smoke_assert(
        'fmw_workflow_deleted fires on delete',
        in_array( "deleted:$hook_test_id", $hook_log, true )
    );

} finally {
    remove_action( 'fmw_workflow_saved', $saved_listener );
    remove_action( 'fmw_workflow_disabled', $disabled_listener );
    remove_action( 'fmw_workflow_deleted', $deleted_listener, 10 );
    FMW_Workflow_Repository::delete( '__phase1_test_hooks', true );
}

// ============================================================
// 6. Regression: schedule listener is a no-op in Phase 1
// ============================================================

smoke_section( '6. Schedule listener stub (Phase 1 no-op verification)' );

// The stub should NOT have registered any Action Scheduler recurring
// events. Phase 2 will. If anything's already scheduled in the 'fmw'
// group with hook fmw_scheduled_workflow_tick, that's a stale event
// from a previous Phase 2 dev run — log it but don't fail.
if ( function_exists( 'as_get_scheduled_actions' ) ) {
    $scheduled = as_get_scheduled_actions( [
        'hook'   => FMW_Schedule_Listener::TICK_HOOK,
        'status' => 'pending',
        'group'  => FMW_Schedule_Listener::ACTION_GROUP,
    ] );
    smoke_assert(
        'No fmw_scheduled_workflow_tick actions registered yet (Phase 1 stub is a no-op)',
        count( $scheduled ) === 0,
        'Found ' . count( $scheduled ) . ' pending tick actions — Phase 2 work may have already wired these'
    );
} else {
    smoke_assert(
        'Action Scheduler functions available',
        false,
        'as_get_scheduled_actions() missing — AS may not be loaded'
    );
}

// ============================================================
// Report
// ============================================================

echo "\n";
echo "FlowMint Workflows v0.6.0 — Phase 1 smoke test\n";
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

// End of try block.
} catch ( \Throwable $e ) {
    fwrite( STDERR, "\n" );
    fwrite( STDERR, "================================================\n" );
    fwrite( STDERR, " UNCAUGHT ERROR IN SMOKE TEST\n" );
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
