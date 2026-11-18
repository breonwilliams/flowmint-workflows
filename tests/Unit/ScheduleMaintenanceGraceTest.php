<?php
/**
 * The ten-minute grace after a maintenance action runs applies only while
 * Action Scheduler may still be scheduling its next copy.
 *
 * Found on a local site: the sweep ran, Action Scheduler scheduled its next
 * copy, then a deactivation cancelled that copy. For ten minutes the check
 * saw "ran moments ago" and left the sweep unscheduled, although the next
 * copy had already been made and was gone. A copy created after the recent
 * run (cancelled or otherwise) means the repeat already happened, so a
 * missing action is scheduled at once.
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
class ScheduleMaintenanceGraceTest extends UnitTestCase {

	/** @var array<int,array> action_id => [hook, status, modified] */
	public static $actions = [];

	/** @var string[] hooks scheduled */
	public static $scheduled = [];

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

		self::$actions   = [];
		self::$scheduled = [];

		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args, $format = 'OBJECT' ) {
			$ids = [];
			foreach ( self::$actions as $id => $a ) {
				if ( $a['hook'] !== $args['hook'] || ! in_array( $a['status'], (array) $args['status'], true ) ) {
					continue;
				}
				// Like Action Scheduler: modified_compare defaults to '<='.
				if ( isset( $args['modified'] ) ) {
					$op = $args['modified_compare'] ?? '<=';
					$m  = (int) $args['modified'];
					$ok = '>=' === $op ? $a['modified'] >= $m : ( '>' === $op ? $a['modified'] > $m : ( '<' === $op ? $a['modified'] < $m : $a['modified'] <= $m ) );
					if ( ! $ok ) {
						continue;
					}
				}
				$ids[] = $id;
			}
			$limit = isset( $args['per_page'] ) ? (int) $args['per_page'] : 5;
			return array_slice( $ids, 0, $limit );
		} );
		Functions\when( 'as_schedule_recurring_action' )->alias( function ( ...$args ) {
			self::$scheduled[] = $args[2];
			return 500;
		} );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
	}

	private function seed( $id, $hook, $status, $modified_ago = 7200 ) {
		self::$actions[ $id ] = [ 'hook' => $hook, 'status' => $status, 'modified' => time() - $modified_ago ];
	}

	public function test_a_next_copy_that_was_made_and_cancelled_ends_the_grace() {
		$this->seed( 1, 'fmw_reconcile_scheduled_events', 'pending' );
		$this->seed( 10, 'fmw_sweep_interrupted_runs', 'complete', 120 ); // Ran two minutes ago...
		$this->seed( 11, 'fmw_sweep_interrupted_runs', 'canceled' );      // ...its next copy was made, then cancelled.

		\FMW_Schedule_Maintenance::ensure();

		$this->assertSame( [ 'fmw_sweep_interrupted_runs' ], self::$scheduled, 'nothing is about to replace it, so it is scheduled now' );
	}

	public function test_inside_the_gap_nothing_is_scheduled() {
		$this->seed( 1, 'fmw_reconcile_scheduled_events', 'pending' );
		$this->seed( 9, 'fmw_sweep_interrupted_runs', 'canceled' );      // An older cancelled copy...
		$this->seed( 10, 'fmw_sweep_interrupted_runs', 'complete', 120 ); // ...and the run whose next copy is not made yet.

		\FMW_Schedule_Maintenance::ensure();

		$this->assertSame( [], self::$scheduled, 'Action Scheduler is about to schedule the next copy itself' );
	}
}
