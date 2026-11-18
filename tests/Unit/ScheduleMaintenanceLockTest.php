<?php
/**
 * One process checks the maintenance actions at a time, and WP-CLI loads
 * always check.
 *
 * Found on a local site with Action Scheduler 4.1: eight requests arriving
 * together right after a reactivation each ran the once-per-version check
 * and scheduled their own copies (up to eight of one action). A MySQL named
 * lock, not waited for, lets one process check while the others skip.
 *
 * `wp action-scheduler run` fires action_scheduler_before_process_queue only
 * when it has claimed due actions, so on a host whose only queue runner is
 * that command, a site with nothing due was never checked. A WP-CLI load
 * now always checks.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ScheduleMaintenanceLockTest extends UnitTestCase {

	/** @var array[] every as_* call: [function, args] */
	public static $calls = [];

	/** @var array<int,array> action_id => [hook, status] */
	public static $actions = [];

	protected function set_up() {
		parent::set_up();

		foreach ( [ 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'DB_NAME' => 'site_db' ] as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
		if ( ! class_exists( 'ActionScheduler_Store', false ) ) {
			eval( 'abstract class ActionScheduler_Store { const STATUS_COMPLETE = "complete"; const STATUS_PENDING = "pending"; const STATUS_RUNNING = "in-progress"; const STATUS_FAILED = "failed"; const STATUS_CANCELED = "canceled"; }' );
		}
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			eval( 'class ActionScheduler { public static function is_initialized( $function_name = null ) { return true; } public static function store() { return null; } }' );
		}

		self::$calls   = [];
		self::$actions = [];

		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args, $format = 'OBJECT' ) {
			self::$calls[] = [ 'as_get_scheduled_actions', $args ];
			$ids           = [];
			foreach ( self::$actions as $id => $a ) {
				if ( $a['hook'] === $args['hook'] && in_array( $a['status'], (array) $args['status'], true ) ) {
					$ids[] = $id;
				}
			}
			return $ids;
		} );
		Functions\when( 'as_schedule_recurring_action' )->alias( function ( ...$args ) {
			self::$calls[] = [ 'as_schedule_recurring_action', $args ];
			$id            = 100 + count( self::$actions );
			self::$actions[ $id ] = [ 'hook' => $args[2], 'status' => 'pending' ];
			return $id;
		} );
		Functions\when( 'as_enqueue_async_action' )->alias( function ( ...$args ) {
			self::$calls[] = [ 'as_enqueue_async_action', $args ];
			return 1;
		} );

		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public $lock   = '1';
			public $sql    = [];
			public function prepare( $sql, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
			public function get_var( $sql ) {
				$this->sql[] = $sql;
				if ( 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ) {
					return $this->lock;
				}
				return '1';
			}
		};
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	private function seed_one_each() {
		self::$actions = [
			1 => [ 'hook' => 'fmw_sweep_interrupted_runs', 'status' => 'pending' ],
			2 => [ 'hook' => 'fmw_reconcile_scheduled_events', 'status' => 'pending' ],
		];
	}

	private function lock_queries( $kind ) {
		return array_values( array_filter( $GLOBALS['wpdb']->sql, function ( $s ) use ( $kind ) {
			return 0 === strpos( $s, "SELECT {$kind}(" );
		} ) );
	}

	public function test_a_process_that_cannot_take_the_lock_skips_the_check() {
		$GLOBALS['wpdb']->lock = '0';

		$this->assertFalse( \FMW_Schedule_Maintenance::ensure() );
		$this->assertSame( [], self::$calls, 'another process is checking; nothing is scheduled twice' );
		$this->assertSame( [], $this->lock_queries( 'RELEASE_LOCK' ), 'a lock it never took is not released' );

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();
		$this->assertArrayNotHasKey( 'fmw_maintenance_checked_version', $this->options, 'the stamp waits for a process that actually checked' );
	}

	public function test_the_lock_holder_checks_and_releases_the_lock() {
		$this->assertTrue( \FMW_Schedule_Maintenance::ensure() );

		$this->assertCount( 2, array_filter( self::$calls, function ( $c ) { return 'as_schedule_recurring_action' === $c[0]; } ) );
		$taken    = $this->lock_queries( 'GET_LOCK' );
		$released = $this->lock_queries( 'RELEASE_LOCK' );
		$this->assertCount( 1, $taken );
		$this->assertCount( 1, $released );
		$this->assertStringContainsString( ', 0)', $taken[0], 'never waits for the lock' );

		preg_match( "/GET_LOCK\\('([^']+)'/", $taken[0], $m );
		$this->assertNotEmpty( $m[1] );
		$this->assertLessThanOrEqual( 64, strlen( $m[1] ), "MySQL's lock-name limit" );
		$this->assertStringContainsString( "'{$m[1]}'", $released[0], 'the same lock is released' );

		$this->assertTrue( \FMW_Schedule_Maintenance::ensure(), 'released, so the next check runs' );
	}

	public function test_the_lock_name_differs_per_site() {
		\FMW_Schedule_Maintenance::ensure();
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		\FMW_Schedule_Maintenance::ensure();

		$taken = $this->lock_queries( 'GET_LOCK' );
		$this->assertCount( 2, $taken );
		$this->assertNotSame( $taken[0], $taken[1], 'sites sharing a database server do not block each other' );
	}

	public function test_without_lock_support_the_check_still_runs() {
		$GLOBALS['wpdb']->lock = null;
		$this->seed_one_each();

		$this->assertTrue( \FMW_Schedule_Maintenance::ensure() );
		$this->assertNotEmpty( self::$calls );
	}

	public function test_a_wp_cli_load_checks_even_with_the_stamp_current() {
		$this->options['fmw_maintenance_checked_version'] = FMW_VERSION;
		$this->seed_one_each();

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();
		$this->assertSame( [], self::$calls, 'a page view with the stamp current does not check' );

		define( 'WP_CLI', true );
		\FMW_Schedule_Maintenance::maybe_ensure_after_update();
		$this->assertNotEmpty( self::$calls, 'a WP-CLI load does' );
	}
}
