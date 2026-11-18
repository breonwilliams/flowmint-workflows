<?php
/**
 * Recovery scenarios for FlowMint's two maintenance actions (the daily
 * reconcile and the hourly sweep), on a LOCAL site.
 *
 * Checks behaviour only — queries per page view, how many copies of each
 * action exist, whether workflow ticks come back — so the same file runs
 * against an older FlowMint (to show what it got wrong) and the current one.
 *
 * Run from the plugin directory, with the PHP that reaches the site's
 * database (on Local: add -d mysqli.default_socket=<the site's socket>).
 * Options are bare words, because WP-CLI keeps --flags for itself:
 *
 *     php $(which wp) --path=<site root> eval-file bin/smoke-test-schedule-maintenance.php [scenario ...] [object-cache] [quick]
 *     php $(which wp) --path=<site root> eval-file bin/smoke-test-schedule-maintenance.php restore=<snapshot file>
 *
 * Scenarios (default: all): page, herd, gap, collapse, fresh, reset,
 * ticks, nogroup, restore, cron, lowtraffic.
 *
 * object-cache installs a file-backed persistent object cache drop-in
 * (wp-content/object-cache.php) for the run and removes it afterwards. It
 * refuses to run if a drop-in already exists.
 *
 * SAFETY. Local sites only (the host must be localhost or end in .local,
 * .test or .localhost). Every scenario snapshots the Action Scheduler
 * tables, FlowMint's tables and the options it touches, writes the snapshot
 * to a file first, and restores it afterwards, even on failure. Real queue
 * runs execute whatever Action Scheduler has due on the site, as any page
 * view would; the snapshot puts the queue back, not what those actions did.
 * If a run is interrupted, restore with restore=<file> (the path is
 * printed when each snapshot is taken).
 *
 * Not shipped: bin/smoke-test-*.php is excluded from the release ZIP.
 *
 * @package FlowMintWorkflows
 * @since   0.12.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "smoke-test-schedule-maintenance.php must run via wp eval-file\n" );
    exit( 99 );
}

const FMWSM_SWEEP     = 'fmw_sweep_interrupted_runs';
const FMWSM_RECONCILE = 'fmw_reconcile_scheduled_events';
const FMWSM_TICK      = 'fmw_scheduled_workflow_tick';
const FMWSM_STAMP     = 'fmw_maintenance_checked_version';
const FMWSM_PLUGIN    = 'flowmint-workflows/flowmint-workflows.php';

$fmwsm_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! preg_match( '/(^localhost$|\.local$|\.test$|\.localhost$)/', $fmwsm_host ) ) {
    fwrite( STDERR, "Refusing to run on {$fmwsm_host}: local sites only.\n" );
    exit( 2 );
}

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------

$fmwsm_args = isset( $args ) && is_array( $args ) ? $args : [];
$fmwsm_opts = [ 'object-cache' => false, 'quick' => false, 'restore' => null ];
$fmwsm_only = [];
foreach ( $fmwsm_args as $a ) {
    // WP-CLI takes --flags for itself, so options are bare words.
    $a = ltrim( $a, '-' );
    if ( 'object-cache' === $a ) {
        $fmwsm_opts['object-cache'] = true;
    } elseif ( 'quick' === $a ) {
        $fmwsm_opts['quick'] = true;
    } elseif ( 0 === strpos( $a, 'restore=' ) ) {
        $fmwsm_opts['restore'] = substr( $a, 8 );
    } else {
        $fmwsm_only[] = $a;
    }
}

// ---------------------------------------------------------------------------
// Reporting.
// ---------------------------------------------------------------------------

$GLOBALS['fmwsm_pass'] = 0;
$GLOBALS['fmwsm_fail'] = 0;

function fmwsm_assert( $name, $cond, $detail = '' ) {
    if ( $cond ) {
        $GLOBALS['fmwsm_pass']++;
        echo "  \033[32mPASS\033[0m  $name\n";
    } else {
        $GLOBALS['fmwsm_fail']++;
        echo "  \033[31mFAIL\033[0m  $name" . ( '' !== $detail ? "\n         \033[33m$detail\033[0m" : '' ) . "\n";
    }
}

function fmwsm_section( $title ) {
    echo "\n\033[1m== $title ==\033[0m\n";
}

function fmwsm_note( $text ) {
    echo "        $text\n";
}

// ---------------------------------------------------------------------------
// Snapshot and restore.
// ---------------------------------------------------------------------------

function fmwsm_tables() {
    global $wpdb;
    return [
        $wpdb->prefix . 'actionscheduler_actions',
        $wpdb->prefix . 'actionscheduler_claims',
        $wpdb->prefix . 'actionscheduler_groups',
        $wpdb->prefix . 'actionscheduler_logs',
        $wpdb->prefix . 'fmw_workflows',
        $wpdb->prefix . 'fmw_workflow_runs',
        $wpdb->prefix . 'fmw_workflow_run_steps',
    ];
}

function fmwsm_option_where() {
    return "option_name LIKE 'fmw\\_%' OR option_name LIKE 'action\\_scheduler%' OR option_name LIKE 'schema-ActionScheduler%' OR option_name = 'cron' OR option_name LIKE '\\_transient\\_%action\\_scheduler%' OR option_name LIKE '\\_transient\\_timeout\\_%action\\_scheduler%' OR option_name LIKE '\\_transient\\_as\\_%' OR option_name LIKE '\\_transient\\_timeout\\_as\\_%'";
}

function fmwsm_snapshot() {
    global $wpdb;
    $snap = [ 'tables' => [], 'options' => [] ];
    foreach ( fmwsm_tables() as $t ) {
        $snap['tables'][ $t ] = $wpdb->get_results( "SELECT * FROM `$t`", ARRAY_A ); // phpcs:ignore
    }
    $snap['options'] = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . fmwsm_option_where(), ARRAY_A ); // phpcs:ignore
    $file = trailingslashit( sys_get_temp_dir() ) . 'fmw-schedule-snapshot-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.json';
    file_put_contents( $file, wp_json_encode( $snap ) );
    $snap['file'] = $file;
    return $snap;
}

function fmwsm_restore( array $snap ) {
    global $wpdb;
    foreach ( $snap['tables'] as $t => $rows ) {
        $wpdb->query( "DELETE FROM `$t`" ); // phpcs:ignore
        foreach ( $rows as $row ) {
            $wpdb->insert( $t, $row );
        }
    }
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE " . fmwsm_option_where() ); // phpcs:ignore
    foreach ( $snap['options'] as $row ) {
        $wpdb->insert( $wpdb->options, $row );
    }
    fmwsm_flush_caches();
}

function fmwsm_flush_caches() {
    wp_cache_flush();
    $dir = fmwsm_cache_dir();
    if ( is_dir( $dir ) ) {
        foreach ( glob( $dir . '/*' ) as $f ) {
            @unlink( $f ); // phpcs:ignore
        }
    }
}

// ---------------------------------------------------------------------------
// State.
// ---------------------------------------------------------------------------

function fmwsm_group_id() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = 'fmw'" );
}

/** Pending FlowMint actions by hook: ids. */
function fmwsm_pending() {
    global $wpdb;
    $out = [ FMWSM_SWEEP => [], FMWSM_RECONCILE => [], FMWSM_TICK => [] ];
    $gid = fmwsm_group_id();
    if ( ! $gid ) {
        return $out;
    }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d AND status = 'pending' ORDER BY action_id", $gid ), ARRAY_A );
    foreach ( $rows as $r ) {
        $out[ $r['hook'] ][] = (int) $r['action_id'];
    }
    return $out;
}

function fmwsm_counts_text( array $p ) {
    return sprintf( 'sweep=%d reconcile=%d ticks=%d', count( $p[ FMWSM_SWEEP ] ), count( $p[ FMWSM_RECONCILE ] ), count( $p[ FMWSM_TICK ] ) );
}

function fmwsm_enabled_scheduled() {
    $n = 0;
    foreach ( FMW_Workflow_Repository::get_all_by_trigger_type( 'schedule' ) as $row ) {
        if ( ! empty( $row['enabled'] ) ) {
            $n++;
        }
    }
    return $n;
}

/** Exactly $k enabled scheduled workflows: disable the others, add harness ones. */
function fmwsm_set_workflows( $k ) {
    foreach ( FMW_Workflow_Repository::get_all_by_trigger_type( 'schedule' ) as $row ) {
        if ( ! empty( $row['enabled'] ) ) {
            FMW_Workflow_Repository::update( $row['id'], [ 'enabled' => false ] );
        }
    }
    for ( $i = 1; $i <= $k; $i++ ) {
        $created = FMW_Workflow_Repository::create( [
            'id'      => 'harness-schedule-' . $i,
            'title'   => 'Harness schedule ' . $i,
            'enabled' => true,
            'config'  => [
                'trigger' => [ 'type' => 'schedule', 'interval' => 'daily', 'hour' => 3, 'minute' => 0 ],
                'steps'   => [ [ 'name' => 'note', 'type' => 'log_info', 'config' => [ 'message' => 'harness' ] ] ],
            ],
        ] );
        if ( is_wp_error( $created ) ) {
            fmwsm_note( 'could not create harness workflow: ' . $created->get_error_message() );
        }
    }
    fmwsm_flush_caches();
}

function fmwsm_sql_cancel( array $hooks ) {
    global $wpdb;
    $gid = fmwsm_group_id();
    if ( ! $gid ) {
        return;
    }
    $in = "'" . implode( "','", array_map( 'esc_sql', $hooks ) ) . "'";
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET status = 'canceled' WHERE group_id = %d AND status = 'pending' AND hook IN ($in)", $gid ) ); // phpcs:ignore
    fmwsm_flush_caches();
}

function fmwsm_truncate_action_scheduler() {
    global $wpdb;
    foreach ( [ 'actions', 'claims', 'groups', 'logs' ] as $t ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_{$t}" ); // phpcs:ignore
    }
    fmwsm_flush_caches();
}

/** Make Action Scheduler's WP-Cron event due now. */
function fmwsm_make_queue_cron_due() {
    wp_clear_scheduled_hook( 'action_scheduler_run_queue', [ 'WP Cron' ] );
    wp_schedule_event( time() - 60, 'every_minute', 'action_scheduler_run_queue', [ 'WP Cron' ] );
    delete_transient( 'doing_cron' );
    fmwsm_flush_caches();
}

// ---------------------------------------------------------------------------
// Child processes: each one is a separate request.
// ---------------------------------------------------------------------------

function fmwsm_child_script() {
    static $file = null;
    if ( $file ) {
        return $file;
    }
    $file = trailingslashit( sys_get_temp_dir() ) . 'fmw-schedule-child-' . getmypid() . '.php';
    file_put_contents( $file, <<<'CHILD'
<?php
$o = json_decode( $argv[1], true );
$_SERVER['HTTP_HOST']      = $o['host'];
$_SERVER['SERVER_NAME']    = $o['host'];
$_SERVER['REQUEST_URI']    = $o['uri'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS']          = 'on';
define( 'SAVEQUERIES', true );
// Unless a scenario asks for it, a child spawns no WP-Cron loopback and no
// async queue request: those are further requests that would outlive the
// scenario and change the site after its snapshot is restored.
if ( ! empty( $o['disable_wp_cron'] ) || empty( $o['spawn_cron'] ) ) {
    define( 'DISABLE_WP_CRON', true );
}
if ( 'fmw-admin' === $o['mode'] ) {
    define( 'WP_ADMIN', true );
}
require_once $o['abspath'] . 'wp-includes/plugin.php';
if ( empty( $o['spawn_cron'] ) ) {
    add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
}
if ( ! empty( $o['keep'] ) ) {
    $keep = $o['keep'];
    add_filter( 'option_active_plugins', function ( $plugins ) use ( $keep ) {
        return array_values( array_filter( (array) $plugins, function ( $p ) use ( $keep ) {
            return in_array( dirname( $p ), $keep, true );
        } ) );
    } );
    add_filter( 'stylesheet', function () { return 'twentytwentyfive'; } );
    add_filter( 'template', function () { return 'twentytwentyfive'; } );
} elseif ( empty( $o['woo'] ) ) {
    add_filter( 'option_active_plugins', function ( $plugins ) {
        return array_values( array_filter( (array) $plugins, function ( $p ) {
            return 0 !== strpos( $p, 'woocommerce' );
        } ) );
    } );
}
register_shutdown_function( function () {
    global $wpdb;
    $fmw = 0;
    $all = 0;
    if ( isset( $wpdb ) && is_array( $wpdb->queries ) ) {
        $all = count( $wpdb->queries );
        foreach ( $wpdb->queries as $q ) {
            if ( false !== stripos( $q[0], 'actionscheduler' ) && ( false !== strpos( $q[2], 'FMW_' ) || false !== strpos( $q[2], 'FlowMint_Workflows' ) ) ) {
                $fmw++;
            }
        }
    }
    $err   = error_get_last();
    $fatal = $err && in_array( $err['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ? $err['message'] : null;
    echo "\n@@FMWSM@@" . json_encode( [
        'fmw_as_queries' => $fmw,
        'queries'        => $all,
        'peak_mb'        => round( memory_get_peak_usage() / 1048576, 1 ),
        'as_version'     => class_exists( 'ActionScheduler_Versions' ) ? ActionScheduler_Versions::instance()->latest_version() : null,
        'woo_active'     => function_exists( 'WC' ),
        'ext_cache'      => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
        'fatal'          => $fatal,
    ] ) . "@@\n";
} );
if ( 'cron-system' === $o['mode'] ) {
    // What a system cron does: request wp-cron.php directly, with no
    // doing_wp_cron value (wp-cron.php runs only when that value matches its
    // own lock, which only a page view's spawn sets), and WP-Cron's
    // page-view spawning switched off.
    require $o['abspath'] . 'wp-cron.php';
    return;
}
define( 'WP_USE_THEMES', false );
require $o['abspath'] . 'wp-load.php';
switch ( $o['mode'] ) {
    case 'page':
        wp();
        break;
    case 'fmw-admin':
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        set_current_screen( 'toplevel_page_fmw-runs' );
        break;
    case 'queue-start':
        do_action( 'action_scheduler_before_process_queue' );
        break;
    case 'queue':
        ActionScheduler_QueueRunner::instance()->run( 'Harness' );
        break;
    case 'reconcile':
        do_action( 'fmw_reconcile_scheduled_events' );
        break;
    case 'reconcile-now':
        do_action( 'fmw_reconcile_scheduled_events_now' );
        break;
    case 'hold-sweep':
        $ids = as_get_scheduled_actions( [ 'hook' => 'fmw_sweep_interrupted_runs', 'group' => 'fmw', 'status' => 'pending', 'per_page' => 1 ], 'ids' );
        if ( $ids ) {
            $id = (int) $ids[0];
            add_action( 'action_scheduler_completed_action', function ( $done ) use ( $id, $o ) {
                if ( (int) $done === $id ) {
                    file_put_contents( $o['flag'], 'in-gap' );
                    sleep( (int) $o['hold'] );
                }
            } );
            ActionScheduler::runner()->process_action( $id, 'Harness' );
        }
        break;
}
CHILD
    );
    return $file;
}

function fmwsm_child_cmd( array $o ) {
    $o = array_merge( [
        'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
        'uri'     => '/?fmw-harness=' . wp_generate_password( 8, false ),
        'abspath' => ABSPATH,
        'woo'     => true,
        'mode'    => 'page',
        'memory'  => null,
    ], $o );
    $cmd = [ PHP_BINARY ];
    $sock = ini_get( 'mysqli.default_socket' );
    if ( $sock ) {
        $cmd[] = '-d';
        $cmd[] = 'mysqli.default_socket=' . $sock;
    }
    $cmd[] = '-d';
    $cmd[] = 'error_reporting=' . ( E_ALL & ~E_DEPRECATED );
    if ( $o['memory'] ) {
        $cmd[] = '-d';
        $cmd[] = 'memory_limit=' . $o['memory'];
    }
    if ( ! empty( $o['opcache'] ) ) {
        $cmd[] = '-d';
        $cmd[] = 'opcache.enable_cli=1';
    }
    $cmd[] = fmwsm_child_script();
    $cmd[] = wp_json_encode( $o );
    return $cmd;
}

function fmwsm_parse_child( $out ) {
    if ( preg_match( '/@@FMWSM@@(.*?)@@/s', $out, $m ) ) {
        return json_decode( $m[1], true );
    }
    return [ 'fatal' => 'no report: ' . substr( trim( $out ), -300 ) ];
}

/** Start one child; returns [process, pipes]. */
function fmwsm_child_start( array $o ) {
    $pipes = [];
    $proc  = proc_open( fmwsm_child_cmd( $o ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
    return [ $proc, $pipes ];
}

function fmwsm_child_wait( array $started ) {
    list( $proc, $pipes ) = $started;
    $out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    proc_close( $proc );
    return fmwsm_parse_child( $out );
}

function fmwsm_child( array $o ) {
    return fmwsm_child_wait( fmwsm_child_start( $o ) );
}

/** $n children started back to back, then all awaited: concurrent requests. */
function fmwsm_children( $n, array $o ) {
    $started = [];
    for ( $i = 0; $i < $n; $i++ ) {
        $started[] = fmwsm_child_start( $o );
    }
    return array_map( 'fmwsm_child_wait', $started );
}

function fmwsm_wp_cli( array $wp_args ) {
    $cmd  = fmwsm_child_cmd( [] );
    $base = array_slice( $cmd, 0, -2 ); // PHP binary and its -d flags.
    $wp   = realpath( $_SERVER['argv'][0] );
    $full = array_merge( $base, [ $wp, '--path=' . ABSPATH ], $wp_args );
    $pipes = [];
    $proc  = proc_open( $full, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
    $out   = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    return [ proc_close( $proc ), $out ];
}

// ---------------------------------------------------------------------------
// Persistent object cache drop-in (file-backed), for the object-cache option.
// ---------------------------------------------------------------------------

function fmwsm_cache_dir() {
    return trailingslashit( sys_get_temp_dir() ) . 'fmw-harness-object-cache';
}

function fmwsm_install_object_cache() {
    $target = WP_CONTENT_DIR . '/object-cache.php';
    if ( file_exists( $target ) ) {
        fwrite( STDERR, "A persistent object cache drop-in already exists; not replacing it.\n" );
        exit( 3 );
    }
    wp_mkdir_p( fmwsm_cache_dir() );
    $dir = var_export( fmwsm_cache_dir(), true );
    file_put_contents( $target, <<<DROPIN
<?php
/* FlowMint harness: temporary file-backed persistent object cache. Delete if a run was interrupted. */
class FMWSM_Object_Cache {
    public \$runtime = [];
    public \$global = [];
    public \$np = [];
    public \$blog = 1;
    private function k( \$key, \$group ) { \$group = \$group ?: 'default'; return ( isset( \$this->global[ \$group ] ) ? 0 : \$this->blog ) . ':' . \$group . ':' . \$key; }
    private function f( \$k ) { return $dir . '/' . md5( \$k ); }
    private function persistent( \$group ) { return ! isset( \$this->np[ \$group ?: 'default' ] ); }
    public function get( \$key, \$group = '', \$force = false, &\$found = null ) {
        \$k = \$this->k( \$key, \$group );
        if ( ! \$force && array_key_exists( \$k, \$this->runtime ) ) { \$found = true; return is_object( \$this->runtime[ \$k ] ) ? clone \$this->runtime[ \$k ] : \$this->runtime[ \$k ]; }
        if ( \$this->persistent( \$group ) && is_file( \$this->f( \$k ) ) ) {
            \$v = unserialize( file_get_contents( \$this->f( \$k ) ) );
            if ( is_array( \$v ) && ( 0 === \$v[1] || \$v[1] > time() ) ) { \$this->runtime[ \$k ] = \$v[0]; \$found = true; return \$v[0]; }
        }
        \$found = false; return false;
    }
    public function set( \$key, \$data, \$group = '', \$expire = 0 ) {
        \$k = \$this->k( \$key, \$group ); \$this->runtime[ \$k ] = is_object( \$data ) ? clone \$data : \$data;
        if ( \$this->persistent( \$group ) ) { @file_put_contents( \$this->f( \$k ), serialize( [ \$data, \$expire ? time() + (int) \$expire : 0 ] ), LOCK_EX ); }
        return true;
    }
    public function add( \$key, \$data, \$group = '', \$expire = 0 ) { \$this->get( \$key, \$group, false, \$found ); return \$found ? false : \$this->set( \$key, \$data, \$group, \$expire ); }
    public function replace( \$key, \$data, \$group = '', \$expire = 0 ) { \$this->get( \$key, \$group, false, \$found ); return \$found ? \$this->set( \$key, \$data, \$group, \$expire ) : false; }
    public function delete( \$key, \$group = '' ) { \$k = \$this->k( \$key, \$group ); unset( \$this->runtime[ \$k ] ); @unlink( \$this->f( \$k ) ); return true; }
    public function incr( \$key, \$n = 1, \$group = '' ) { \$v = \$this->get( \$key, \$group, false, \$found ); if ( ! \$found ) { return false; } \$v = max( 0, (int) \$v + \$n ); \$this->set( \$key, \$v, \$group ); return \$v; }
    public function flush() { \$this->runtime = []; foreach ( glob( $dir . '/*' ) as \$f ) { @unlink( \$f ); } return true; }
}
function wp_cache_init() { \$GLOBALS['wp_object_cache'] = new FMWSM_Object_Cache(); }
function wp_cache_get( \$k, \$g = '', \$force = false, &\$found = null ) { return \$GLOBALS['wp_object_cache']->get( \$k, \$g, \$force, \$found ); }
function wp_cache_get_multiple( \$keys, \$g = '', \$force = false ) { \$o = []; foreach ( \$keys as \$k ) { \$o[ \$k ] = wp_cache_get( \$k, \$g, \$force ); } return \$o; }
function wp_cache_set( \$k, \$d, \$g = '', \$e = 0 ) { return \$GLOBALS['wp_object_cache']->set( \$k, \$d, \$g, \$e ); }
function wp_cache_set_multiple( array \$data, \$g = '', \$e = 0 ) { \$o = []; foreach ( \$data as \$k => \$d ) { \$o[ \$k ] = wp_cache_set( \$k, \$d, \$g, \$e ); } return \$o; }
function wp_cache_add( \$k, \$d, \$g = '', \$e = 0 ) { return \$GLOBALS['wp_object_cache']->add( \$k, \$d, \$g, \$e ); }
function wp_cache_add_multiple( array \$data, \$g = '', \$e = 0 ) { \$o = []; foreach ( \$data as \$k => \$d ) { \$o[ \$k ] = wp_cache_add( \$k, \$d, \$g, \$e ); } return \$o; }
function wp_cache_replace( \$k, \$d, \$g = '', \$e = 0 ) { return \$GLOBALS['wp_object_cache']->replace( \$k, \$d, \$g, \$e ); }
function wp_cache_delete( \$k, \$g = '' ) { return \$GLOBALS['wp_object_cache']->delete( \$k, \$g ); }
function wp_cache_delete_multiple( array \$keys, \$g = '' ) { \$o = []; foreach ( \$keys as \$k ) { \$o[ \$k ] = wp_cache_delete( \$k, \$g ); } return \$o; }
function wp_cache_incr( \$k, \$n = 1, \$g = '' ) { return \$GLOBALS['wp_object_cache']->incr( \$k, \$n, \$g ); }
function wp_cache_decr( \$k, \$n = 1, \$g = '' ) { return \$GLOBALS['wp_object_cache']->incr( \$k, -\$n, \$g ); }
function wp_cache_flush() { return \$GLOBALS['wp_object_cache']->flush(); }
function wp_cache_flush_runtime() { \$GLOBALS['wp_object_cache']->runtime = []; return true; }
function wp_cache_flush_group( \$g ) { return wp_cache_flush(); }
function wp_cache_supports( \$feature ) { return in_array( \$feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ], true ); }
function wp_cache_close() { return true; }
function wp_cache_add_global_groups( \$groups ) { foreach ( (array) \$groups as \$g ) { \$GLOBALS['wp_object_cache']->global[ \$g ] = true; } }
function wp_cache_add_non_persistent_groups( \$groups ) { foreach ( (array) \$groups as \$g ) { \$GLOBALS['wp_object_cache']->np[ \$g ] = true; } }
function wp_cache_switch_to_blog( \$id ) { \$GLOBALS['wp_object_cache']->blog = (int) \$id; }
function wp_cache_reset() { \$GLOBALS['wp_object_cache']->runtime = []; }
DROPIN
    );
    $GLOBALS['fmwsm_dropin'] = $target;
    register_shutdown_function( 'fmwsm_remove_object_cache' );
}

function fmwsm_remove_object_cache() {
    if ( ! empty( $GLOBALS['fmwsm_dropin'] ) && file_exists( $GLOBALS['fmwsm_dropin'] ) ) {
        $head = (string) file_get_contents( $GLOBALS['fmwsm_dropin'], false, null, 0, 200 );
        if ( false !== strpos( $head, 'FlowMint harness' ) ) {
            unlink( $GLOBALS['fmwsm_dropin'] );
        }
        $GLOBALS['fmwsm_dropin'] = null;
    }
    $dir = fmwsm_cache_dir();
    if ( is_dir( $dir ) ) {
        foreach ( glob( $dir . '/*' ) as $f ) {
            @unlink( $f ); // phpcs:ignore
        }
        @rmdir( $dir ); // phpcs:ignore
    }
}

// ---------------------------------------------------------------------------
// Scenario wrapper.
// ---------------------------------------------------------------------------

/**
 * Wait for any wp-cron.php request in flight to finish (it holds the
 * doing_cron lock while it runs), so nothing changes the site after a
 * check or a restore.
 */
function fmwsm_settle( $max_seconds = 90 ) {
    global $wpdb;
    $deadline = time() + $max_seconds;
    $clear    = 0;
    sleep( 1 );
    while ( time() < $deadline ) {
        $lock = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '_transient_doing_cron'" );
        $clear = $lock ? 0 : $clear + 1;
        if ( $clear >= 3 ) {
            break;
        }
        usleep( 500000 );
    }
    fmwsm_flush_caches();
}

function fmwsm_scenario( $title, callable $body ) {
    fmwsm_section( $title );
    $snap = fmwsm_snapshot();
    fmwsm_note( 'snapshot: ' . $snap['file'] );
    try {
        $body();
    } catch ( Throwable $e ) {
        fmwsm_assert( 'scenario completed', false, get_class( $e ) . ': ' . $e->getMessage() );
    } finally {
        fmwsm_settle();
        fmwsm_restore( $snap );
        @unlink( $snap['file'] ); // phpcs:ignore
    }
}

/** Assert exactly one of each maintenance action and the expected ticks. */
function fmwsm_assert_state( $label, $ticks_expected ) {
    $p = fmwsm_pending();
    fmwsm_assert( "$label: exactly one sweep and one reconcile", 1 === count( $p[ FMWSM_SWEEP ] ) && 1 === count( $p[ FMWSM_RECONCILE ] ), fmwsm_counts_text( $p ) );
    if ( null !== $ticks_expected ) {
        fmwsm_assert( "$label: one tick per enabled scheduled workflow ($ticks_expected)", count( $p[ FMWSM_TICK ] ) === $ticks_expected, fmwsm_counts_text( $p ) );
    }
}

function fmwsm_woo_variants() {
    return is_plugin_active( 'woocommerce/woocommerce.php' ) ? [ true, false ] : [ false ];
}

function fmwsm_label( $woo ) {
    return $woo ? 'with WooCommerce' : 'without WooCommerce';
}

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------

require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( $fmwsm_opts['restore'] ) {
    $snap = json_decode( (string) file_get_contents( $fmwsm_opts['restore'] ), true );
    if ( ! is_array( $snap ) || empty( $snap['tables'] ) ) {
        fwrite( STDERR, "Not a snapshot file.\n" );
        exit( 4 );
    }
    fmwsm_restore( $snap );
    echo "Restored from {$fmwsm_opts['restore']}\n";
    return;
}

$fmwsm_all = [ 'page', 'herd', 'gap', 'collapse', 'fresh', 'reset', 'ticks', 'nogroup', 'restore', 'cron', 'lowtraffic' ];
$fmwsm_run = $fmwsm_only ? array_values( array_intersect( $fmwsm_all, $fmwsm_only ) ) : $fmwsm_all;

if ( $fmwsm_opts['object-cache'] ) {
    fmwsm_install_object_cache();
}

$fmwsm_start = fmwsm_pending();
echo "\033[1mFlowMint maintenance-action scenarios\033[0m on {$fmwsm_host}, FlowMint " . FMW_VERSION;
echo $fmwsm_opts['object-cache'] ? ", persistent object cache (file-backed drop-in)\n" : ", no persistent object cache\n";
echo 'Start state: ' . fmwsm_counts_text( $fmwsm_start ) . ', enabled scheduled workflows: ' . fmwsm_enabled_scheduled() . "\n";

$fmwsm_workflow_sets = $fmwsm_opts['quick'] ? [ 1 ] : [ 0, 1, 5 ];

try {

if ( in_array( 'page', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'Page views make no Action Scheduler query', function () {
        foreach ( fmwsm_woo_variants() as $woo ) {
            // First a request that may do the one-time post-update check.
            fmwsm_child( [ 'woo' => $woo ] );
            $r = fmwsm_child( [ 'woo' => $woo ] );
            fmwsm_note( sprintf( '%s: Action Scheduler %s, %d queries, peak %.1f MB, persistent object cache in use: %s', fmwsm_label( $woo ), $r['as_version'] ?? '?', $r['queries'] ?? -1, $r['peak_mb'] ?? -1, empty( $r['ext_cache'] ) ? 'no' : 'yes' ) );
            fmwsm_assert( fmwsm_label( $woo ) . ': FlowMint makes 0 Action Scheduler queries on a page view', isset( $r['fmw_as_queries'] ) && 0 === $r['fmw_as_queries'] && empty( $r['fatal'] ), 'got ' . wp_json_encode( $r ) );
            $a = fmwsm_child( [ 'woo' => $woo, 'mode' => 'fmw-admin' ] );
            fmwsm_note( sprintf( '%s: a FlowMint admin screen makes %d (the check runs there by design)', fmwsm_label( $woo ), $a['fmw_as_queries'] ?? -1 ) );
        }
        // memory_limit=40M has been seen in production. Measured with only
        // Promptless Forms and FlowMint active and a default theme, so the
        // result is about FlowMint, not about whatever else this site runs.
        // With opcache, as production runs it, when the PHP here has it.
        $only     = [ 'form-runtime-engine', 'flowmint-workflows' ];
        $opcache  = extension_loaded( 'Zend OPcache' );
        fmwsm_note( '40M checks: Forms + FlowMint only, opcache ' . ( $opcache ? 'on' : 'NOT AVAILABLE in this PHP' ) );
        $m = fmwsm_child( [ 'keep' => $only, 'memory' => '40M', 'opcache' => $opcache ] );
        fmwsm_assert( 'at memory_limit=40M: page view completes', empty( $m['fatal'] ), (string) ( $m['fatal'] ?? '' ) );
        fmwsm_note( sprintf( 'peak %.1f MB', $m['peak_mb'] ?? -1 ) );
        $q = fmwsm_child( [ 'keep' => $only, 'memory' => '40M', 'opcache' => $opcache, 'mode' => 'queue' ] );
        fmwsm_assert( 'at memory_limit=40M: a queue run completes', empty( $q['fatal'] ), (string) ( $q['fatal'] ?? '' ) );
        fmwsm_assert_state( 'after the 40M queue run', fmwsm_enabled_scheduled() );
    } );
}

if ( in_array( 'herd', $fmwsm_run, true ) ) {
    foreach ( fmwsm_woo_variants() as $woo ) {
        foreach ( $fmwsm_workflow_sets as $k ) {
            fmwsm_scenario( 'Reactivation, then 8 requests at once (' . fmwsm_label( $woo ) . ", $k scheduled workflows)", function () use ( $woo, $k ) {
                fmwsm_set_workflows( $k );
                deactivate_plugins( FMWSM_PLUGIN );
                activate_plugin( FMWSM_PLUGIN );
                fmwsm_flush_caches();
                fmwsm_note( 'after reactivation: ' . fmwsm_counts_text( fmwsm_pending() ) );
                fmwsm_children( 8, [ 'woo' => $woo ] );
                fmwsm_note( 'after 8 concurrent page views: ' . fmwsm_counts_text( fmwsm_pending() ) );
                fmwsm_child( [ 'woo' => $woo, 'mode' => 'queue' ] );
                fmwsm_assert_state( 'after the next queue run', $k );
            } );
        }
    }
}

if ( in_array( 'gap', $fmwsm_run, true ) ) {
    foreach ( fmwsm_woo_variants() as $woo ) {
        fmwsm_scenario( 'Requests while the sweep is between "complete" and "next instance" (' . fmwsm_label( $woo ) . ')', function () use ( $woo ) {
            $flag = trailingslashit( sys_get_temp_dir() ) . 'fmw-harness-gap-' . getmypid();
            @unlink( $flag ); // phpcs:ignore
            $hold = fmwsm_child_start( [ 'woo' => $woo, 'mode' => 'hold-sweep', 'hold' => 4, 'flag' => $flag ] );
            for ( $i = 0; $i < 300 && ! file_exists( $flag ); $i++ ) {
                usleep( 50000 );
            }
            fmwsm_assert( 'the sweep reached the gap', file_exists( $flag ) );
            $during = array_merge(
                [ fmwsm_child_start( [ 'woo' => $woo, 'mode' => 'queue-start' ] ) ],
                array_map( function () use ( $woo ) { return fmwsm_child_start( [ 'woo' => $woo ] ); }, range( 1, 4 ) )
            );
            array_map( 'fmwsm_child_wait', $during );
            fmwsm_child_wait( $hold );
            @unlink( $flag ); // phpcs:ignore
            fmwsm_assert_state( 'after the sweep rescheduled itself', null );
            fmwsm_child( [ 'woo' => $woo, 'mode' => 'queue-start' ] );
            fmwsm_assert_state( 'after one more queue start', null );
        } );
    }
}

if ( in_array( 'collapse', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'An update collapses existing duplicates, ticks untouched', function () {
        if ( 0 === fmwsm_enabled_scheduled() ) {
            fmwsm_set_workflows( 1 );
        }
        $p = fmwsm_pending();
        // Two extra copies of each, as older versions left them.
        foreach ( [ FMWSM_SWEEP => HOUR_IN_SECONDS, FMWSM_RECONCILE => DAY_IN_SECONDS ] as $hook => $every ) {
            as_schedule_recurring_action( time() + 600, $every, $hook, [], 'fmw' );
            as_schedule_recurring_action( time() + 1200, $every, $hook, [], 'fmw' );
        }
        // And a duplicate tick, which is the reconcile's business, not ours.
        $tick = $p[ FMWSM_TICK ] ? ActionScheduler::store()->fetch_action( $p[ FMWSM_TICK ][0] ) : null;
        if ( $tick ) {
            as_schedule_recurring_action( time() + 900, DAY_IN_SECONDS, FMWSM_TICK, $tick->get_args(), 'fmw' );
        }
        $before = fmwsm_pending();
        fmwsm_note( 'seeded: ' . fmwsm_counts_text( $before ) );

        // Due soonest, per hook.
        $soonest = [];
        foreach ( [ FMWSM_SWEEP, FMWSM_RECONCILE ] as $hook ) {
            $best = null;
            foreach ( $before[ $hook ] as $id ) {
                $ts = ActionScheduler::store()->fetch_action( $id )->get_schedule()->get_date()->getTimestamp();
                if ( null === $best || [ $ts, $id ] < $best ) {
                    $best = [ $ts, $id ];
                }
            }
            $soonest[ $hook ] = $best[1];
        }

        delete_option( FMWSM_STAMP ); // No version before 0.12.3 wrote it.
        fmwsm_flush_caches();
        fmwsm_child( [] );
        $after = fmwsm_pending();
        fmwsm_assert( 'one page view after the update: one sweep, one reconcile', 1 === count( $after[ FMWSM_SWEEP ] ) && 1 === count( $after[ FMWSM_RECONCILE ] ), fmwsm_counts_text( $after ) );
        fmwsm_assert( 'the copies kept are the ones due soonest', $after[ FMWSM_SWEEP ] === [ $soonest[ FMWSM_SWEEP ] ] && $after[ FMWSM_RECONCILE ] === [ $soonest[ FMWSM_RECONCILE ] ], wp_json_encode( [ 'kept' => $after, 'expected' => $soonest ] ) );
        fmwsm_assert( 'ticks are exactly as they were', $after[ FMWSM_TICK ] === $before[ FMWSM_TICK ], wp_json_encode( [ 'before' => $before[ FMWSM_TICK ], 'after' => $after[ FMWSM_TICK ] ] ) );
    } );
}

if ( in_array( 'fresh', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'Fresh install over kept workflows (reinstall after uninstall)', function () {
        $k = fmwsm_enabled_scheduled();
        fmwsm_sql_cancel( [ FMWSM_SWEEP, FMWSM_RECONCILE, FMWSM_TICK, 'fmw_reconcile_scheduled_events_now' ] );
        delete_option( FMWSM_STAMP );
        delete_option( 'fmw_reconciliation_bootstrapped' );
        fmwsm_flush_caches();
        fmwsm_child( [] );
        $p = fmwsm_pending();
        fmwsm_assert( 'first page view: one sweep, one reconcile', 1 === count( $p[ FMWSM_SWEEP ] ) && 1 === count( $p[ FMWSM_RECONCILE ] ), fmwsm_counts_text( $p ) );
        fmwsm_child( [ 'mode' => 'queue' ] );
        fmwsm_assert_state( 'after the first queue run', $k );
    } );
}

if ( in_array( 'reset', $fmwsm_run, true ) ) {
    foreach ( fmwsm_woo_variants() as $woo ) {
        foreach ( $fmwsm_workflow_sets as $k ) {
            fmwsm_scenario( 'Action Scheduler tables reset (' . fmwsm_label( $woo ) . ", $k scheduled workflows)", function () use ( $woo, $k ) {
                fmwsm_set_workflows( $k );
                fmwsm_truncate_action_scheduler();
                fmwsm_child( [ 'woo' => $woo, 'mode' => 'queue' ] );
                fmwsm_assert_state( 'after one queue run', $k );
            } );
        }
    }
}

if ( in_array( 'ticks', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'Next-run times stay put across the daily reconcile and the one-off pass', function () {
        global $wpdb;
        fmwsm_set_workflows( 0 );
        $schedules = [
            'harness-hourly'     => [ 'interval' => 'hourly' ],
            'harness-twicedaily' => [ 'interval' => 'twicedaily' ],
            'harness-daily'      => [ 'interval' => 'daily', 'hour' => 3, 'minute' => 15 ],
            'harness-weekly'     => [ 'interval' => 'weekly', 'day_of_week' => 3, 'hour' => 4, 'minute' => 30 ],
        ];
        foreach ( $schedules as $id => $trigger ) {
            FMW_Workflow_Repository::create( [
                'id'      => $id,
                'title'   => $id,
                'enabled' => true,
                'config'  => [
                    'trigger' => array_merge( [ 'type' => 'schedule' ], $trigger ),
                    'steps'   => [ [ 'name' => 'note', 'type' => 'log_info', 'config' => [ 'message' => 'harness' ] ] ],
                ],
            ] );
        }
        fmwsm_flush_caches();
        $ticks = function () use ( $schedules ) {
            $out = [];
            foreach ( array_keys( $schedules ) as $id ) {
                foreach ( as_get_scheduled_actions( [ 'hook' => FMWSM_TICK, 'args' => [ $id ], 'group' => 'fmw', 'status' => 'pending', 'per_page' => 10 ] ) as $action_id => $action ) {
                    $out[ $id ][] = [ (int) $action_id, $action->get_schedule()->get_date()->getTimestamp(), (int) $action->get_schedule()->get_recurrence() ];
                }
            }
            return $out;
        };
        $before = $ticks();
        fmwsm_assert( 'four workflows, one tick each', 4 === count( $before ) && 4 === count( array_filter( $before, function ( $t ) { return 1 === count( $t ); } ) ), wp_json_encode( $before ) );
        foreach ( [ 'reconcile' => 'the daily reconcile', 'reconcile-now' => 'the one-off pass' ] as $mode => $label ) {
            fmwsm_child( [ 'mode' => $mode ] );
            fmwsm_flush_caches();
            $after = $ticks();
            fmwsm_assert( "after $label: every tick keeps its ID and next run", $after === $before, wp_json_encode( [ 'before' => $before, 'after' => $after ] ) );
        }
        // A schedule changed behind the save hooks: the daily reconcile
        // replaces that tick, and only that one.
        $row    = FMW_Workflow_Repository::get( 'harness-hourly' );
        $config = json_decode( $row['config'], true );
        $config['trigger']['interval'] = 'daily';
        $config['trigger']['hour']     = 5;
        $config['trigger']['minute']   = 0;
        $wpdb->update( FMW_Workflow_Repository::table(), [ 'config' => wp_json_encode( $config ) ], [ 'id' => 'harness-hourly' ] );
        fmwsm_flush_caches();
        fmwsm_child( [ 'mode' => 'reconcile' ] );
        fmwsm_flush_caches();
        $after   = $ticks();
        $changed = $after['harness-hourly'] ?? [];
        fmwsm_assert( 'a tick that no longer matches is replaced, at the new interval', 1 === count( $changed ) && $changed[0][0] !== $before['harness-hourly'][0][0] && DAY_IN_SECONDS === $changed[0][2], wp_json_encode( $changed ) );
        unset( $before['harness-hourly'], $after['harness-hourly'] );
        fmwsm_assert( 'the other ticks are untouched', $after === $before, wp_json_encode( [ 'before' => $before, 'after' => $after ] ) );
    } );
}

if ( in_array( 'nogroup', $fmwsm_run, true ) ) {
    foreach ( fmwsm_woo_variants() as $woo ) {
        foreach ( [ 'queue run', 'wp action-scheduler run --group=fmw' ] as $runner ) {
            fmwsm_scenario( 'No "fmw" group yet, healed by a ' . $runner . ' (' . fmwsm_label( $woo ) . ')', function () use ( $woo, $runner ) {
                global $wpdb;
                $k   = fmwsm_enabled_scheduled();
                $gid = fmwsm_group_id();
                // The state after a reset, a restore or a fresh install
                // until FlowMint schedules something: no group, no actions.
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d", $gid ) );
                $wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = 'fmw'" );
                fmwsm_flush_caches();
                fmwsm_assert( 'precondition: the group is gone', 0 === fmwsm_group_id() );
                if ( 'queue run' === $runner ) {
                    $r = fmwsm_child( [ 'woo' => $woo, 'mode' => 'queue' ] );
                    fmwsm_assert( 'the queue run completed', empty( $r['fatal'] ), (string) ( $r['fatal'] ?? '' ) );
                } else {
                    list( $code, $out ) = fmwsm_wp_cli( array_merge( [ 'action-scheduler', 'run', '--group=fmw' ], $woo ? [] : [ '--skip-plugins=woocommerce' ] ) );
                    fmwsm_assert( 'the command succeeded', 0 === $code && false === stripos( $out, 'does not exist' ), substr( trim( $out ), -300 ) );
                    // The load-time check queued the reconcile; this run picks it up.
                    fmwsm_wp_cli( array_merge( [ 'action-scheduler', 'run', '--group=fmw' ], $woo ? [] : [ '--skip-plugins=woocommerce' ] ) );
                }
                fmwsm_flush_caches();
                fmwsm_assert( 'the group exists again', fmwsm_group_id() > 0 );
                fmwsm_assert_state( 'after the ' . $runner, $k );
            } );
        }
    }
}

if ( in_array( 'restore', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'Restored backup with no FlowMint actions, cache left stale', function () {
        $k = fmwsm_enabled_scheduled();
        fmwsm_child( [] ); // Writes the current version stamp, as a backup taken after the update would hold.
        global $wpdb;
        $gid = fmwsm_group_id();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d", $gid ) );
        // Deliberately no cache flush: a host cache can outlive a restore.
        fmwsm_child( [ 'mode' => 'queue' ] );
        fmwsm_assert_state( 'after one queue run', $k );
    } );
    fmwsm_scenario( 'Restored backup taken while the sweep was running', function () {
        global $wpdb;
        $p = fmwsm_pending();
        $wpdb->update(
            $wpdb->prefix . 'actionscheduler_actions',
            [ 'status' => 'in-progress', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), 'last_attempt_local' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ],
            [ 'action_id' => $p[ FMWSM_SWEEP ][0] ]
        );
        fmwsm_flush_caches();
        fmwsm_child( [ 'mode' => 'queue' ] );
        fmwsm_note( 'after the first queue run (the cleaner marks the stale run failed): ' . fmwsm_counts_text( fmwsm_pending() ) );
        fmwsm_child( [ 'mode' => 'queue' ] );
        $p2 = fmwsm_pending();
        fmwsm_note( 'after the second: ' . fmwsm_counts_text( $p2 ) . ' (newer Action Scheduler stamps the failure time, which starts the grace)' );
        fmwsm_assert( 'never more than one sweep meanwhile', count( $p2[ FMWSM_SWEEP ] ) <= 1, fmwsm_counts_text( $p2 ) );
        // Let the grace pass: the failure is older than it.
        $old = gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS );
        $wpdb->update( $wpdb->prefix . 'actionscheduler_actions', [ 'last_attempt_gmt' => $old, 'last_attempt_local' => $old ], [ 'action_id' => $p[ FMWSM_SWEEP ][0] ] );
        fmwsm_flush_caches();
        fmwsm_child( [ 'mode' => 'queue' ] );
        fmwsm_assert_state( 'after a queue run once the grace has passed', null );
    } );
}

if ( in_array( 'cron', $fmwsm_run, true ) ) {
    foreach ( fmwsm_woo_variants() as $woo ) {
        fmwsm_scenario( 'System cron: DISABLE_WP_CRON, wp-cron.php requested directly (' . fmwsm_label( $woo ) . ')', function () use ( $woo ) {
            $k = fmwsm_enabled_scheduled();
            fmwsm_sql_cancel( [ FMWSM_SWEEP, FMWSM_RECONCILE, FMWSM_TICK ] );
            fmwsm_make_queue_cron_due();
            $r = fmwsm_child( [ 'woo' => $woo, 'mode' => 'cron-system', 'disable_wp_cron' => true ] );
            fmwsm_assert( 'wp-cron.php completed', empty( $r['fatal'] ), (string) ( $r['fatal'] ?? '' ) );
            fmwsm_assert_state( 'after the cron run', $k );
        } );
    }
    fmwsm_scenario( 'WP-Cron spawned over HTTP by the web server', function () {
        $k = fmwsm_enabled_scheduled();
        fmwsm_sql_cancel( [ FMWSM_SWEEP, FMWSM_RECONCILE, FMWSM_TICK ] );
        fmwsm_make_queue_cron_due();
        $res = wp_remote_get( site_url( 'wp-cron.php' ), [ 'timeout' => 60, 'sslverify' => false, 'blocking' => true ] );
        fmwsm_assert( 'wp-cron.php answered', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
        // wp-cron.php closes the connection before it runs the events.
        fmwsm_settle();
        fmwsm_assert_state( 'after the cron request', $k );
    } );
    fmwsm_scenario( 'WP-CLI: wp action-scheduler run --group=fmw', function () {
        $k = fmwsm_enabled_scheduled();
        fmwsm_sql_cancel( [ FMWSM_SWEEP, FMWSM_RECONCILE, FMWSM_TICK ] );
        list( $code, $out ) = fmwsm_wp_cli( [ 'action-scheduler', 'run', '--group=fmw' ] );
        fmwsm_assert( 'the command succeeded', 0 === $code, substr( trim( $out ), -300 ) );
        fmwsm_flush_caches();
        fmwsm_assert_state( 'after the command', $k );
    } );
}

if ( in_array( 'lowtraffic', $fmwsm_run, true ) ) {
    fmwsm_scenario( 'Low traffic: both actions three days overdue, one queue run', function () {
        global $wpdb;
        $k = fmwsm_enabled_scheduled();
        $p = fmwsm_pending();
        $past = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
        foreach ( [ FMWSM_SWEEP, FMWSM_RECONCILE ] as $hook ) {
            $wpdb->update( $wpdb->prefix . 'actionscheduler_actions', [ 'scheduled_date_gmt' => $past, 'scheduled_date_local' => $past ], [ 'action_id' => $p[ $hook ][0] ] );
        }
        fmwsm_flush_caches();
        fmwsm_child( [ 'mode' => 'queue' ] );
        $after = fmwsm_pending();
        fmwsm_assert( 'both overdue actions ran', ! in_array( $p[ FMWSM_SWEEP ][0], $after[ FMWSM_SWEEP ], true ) && ! in_array( $p[ FMWSM_RECONCILE ][0], $after[ FMWSM_RECONCILE ], true ), fmwsm_counts_text( $after ) );
        fmwsm_assert_state( 'after the queue run', $k );
    } );
    fmwsm_scenario( 'Low traffic, as it really happens: overdue actions, page views spawning WP-Cron', function () {
        global $wpdb;
        $k = fmwsm_enabled_scheduled();
        $p = fmwsm_pending();
        $past = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
        foreach ( [ FMWSM_SWEEP, FMWSM_RECONCILE ] as $hook ) {
            $wpdb->update( $wpdb->prefix . 'actionscheduler_actions', [ 'scheduled_date_gmt' => $past, 'scheduled_date_local' => $past ], [ 'action_id' => $p[ $hook ][0] ] );
        }
        fmwsm_make_queue_cron_due();
        // A queue run in one request while page views keep arriving, each
        // allowed to spawn WP-Cron as a real visit would.
        $queue = fmwsm_child_start( [ 'mode' => 'queue', 'spawn_cron' => true ] );
        for ( $i = 0; $i < 3; $i++ ) {
            fmwsm_children( 3, [ 'spawn_cron' => true ] );
        }
        fmwsm_child_wait( $queue );
        fmwsm_settle();
        fmwsm_child( [ 'mode' => 'queue-start' ] );
        fmwsm_assert_state( 'after the next queue start', $k );
    } );
}

} finally {
    if ( $fmwsm_opts['object-cache'] ) {
        fmwsm_remove_object_cache();
    }
    $end = fmwsm_pending();
    echo "\nEnd state: " . fmwsm_counts_text( $end ) . ( $end === $fmwsm_start ? " (same actions as at the start)\n" : " (DIFFERENT from the start: " . wp_json_encode( $fmwsm_start ) . ")\n" );
    echo "\n\033[1mResult:\033[0m {$GLOBALS['fmwsm_pass']} passed, {$GLOBALS['fmwsm_fail']} failed\n";
}
