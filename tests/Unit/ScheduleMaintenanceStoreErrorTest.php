<?php
/**
 * A missing Action Scheduler group means "nothing scheduled yet", and no
 * store error ever escapes the check.
 *
 * After a table reset, a restore or a fresh install there is no "fmw" group
 * until FlowMint schedules its first action. Measured with Action Scheduler
 * 3.9.3 and 4.1: a query by group then returns nothing (the check schedules
 * both actions), while claiming actions by group throws "The group does not
 * exist". The check only queries, but it runs inside
 * action_scheduler_before_process_queue, so any exception a data store threw
 * there (a custom store, a future version) would abort every queue run on
 * the site, other plugins' actions included, again on every run. A
 * missing-group error is read as "no copies yet"; any other error is logged
 * and the check retried at the next run.
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
class ScheduleMaintenanceStoreErrorTest extends UnitTestCase {

	/** @var string[] hooks scheduled */
	public static $scheduled = [];

	/** @var array[] */
	public static $enqueued = [];

	protected function set_up() {
		parent::set_up();

		foreach ( [ 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ] as $name => $value ) {
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

		self::$scheduled = [];
		self::$enqueued  = [];

		Functions\when( 'as_schedule_recurring_action' )->alias( function ( ...$args ) {
			self::$scheduled[] = $args[2];
			return 900 + count( self::$scheduled );
		} );
		Functions\when( 'as_enqueue_async_action' )->alias( function ( ...$args ) {
			self::$enqueued[] = $args;
			return 1;
		} );
	}

	public function test_a_store_that_throws_for_a_missing_group_gets_both_actions_scheduled() {
		Functions\when( 'as_get_scheduled_actions' )->alias( function () {
			throw new \InvalidArgumentException( 'The group "fmw" does not exist.' );
		} );

		$this->assertTrue( \FMW_Schedule_Maintenance::ensure() );

		sort( self::$scheduled );
		$this->assertSame( [ 'fmw_reconcile_scheduled_events', 'fmw_sweep_interrupted_runs' ], self::$scheduled, 'no group yet means nothing scheduled yet' );
		$this->assertCount( 1, self::$enqueued, 'and ticks are rebuilt now' );
	}

	public function test_a_missing_group_as_action_scheduler_reports_it_schedules_both() {
		// What 3.9.3 and 4.1 actually do: a query by a group that does not
		// exist returns nothing.
		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );

		$this->assertTrue( \FMW_Schedule_Maintenance::ensure() );
		$this->assertCount( 2, self::$scheduled );
		$this->assertCount( 1, self::$enqueued );
	}

	public function test_any_other_store_error_never_escapes_the_check() {
		Functions\when( 'as_get_scheduled_actions' )->alias( function () {
			throw new \RuntimeException( 'Deadlock found when trying to get lock' );
		} );

		$this->assertFalse( \FMW_Schedule_Maintenance::ensure(), 'the queue run goes on; the next one checks again' );
		$this->assertSame( [], self::$scheduled, 'nothing is scheduled on a guess' );
	}

	public function test_a_failed_check_after_an_update_leaves_the_stamp_for_the_next_request() {
		Functions\when( 'as_get_scheduled_actions' )->alias( function () {
			throw new \RuntimeException( 'MySQL server has gone away' );
		} );

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertArrayNotHasKey( 'fmw_maintenance_checked_version', $this->options );
		$this->assertSame( [], self::$enqueued );
	}
}
