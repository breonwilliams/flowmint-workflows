<?php
/**
 * An error while queueing the after-update pass never escapes, and leaves
 * the version stamp unwritten so the next request tries again.
 *
 * maybe_ensure_after_update() runs on action_scheduler_init, during every
 * request's load. ensure() already catches store errors; the enqueue and
 * the stamp write that follow it did not, so an exception there would have
 * been thrown into the page load.
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
class ScheduleMaintenanceAfterUpdateErrorTest extends UnitTestCase {

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

		// Both maintenance actions present: ensure() succeeds and changes nothing.
		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args ) {
			return in_array( 'pending', (array) $args['status'], true ) ? [ 7 ] : [];
		} );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 0 );
	}

	public function test_a_failing_enqueue_does_not_escape_and_leaves_the_stamp() {
		$this->options['fmw_maintenance_checked_version'] = '0.12.2';
		Functions\when( 'as_enqueue_async_action' )->alias( function () {
			throw new \RuntimeException( 'Deadlock found when trying to get lock' );
		} );

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertSame( '0.12.2', $this->options['fmw_maintenance_checked_version'], 'the next request tries again' );
	}

	public function test_without_an_error_the_stamp_is_written() {
		$this->options['fmw_maintenance_checked_version'] = '0.12.2';
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertSame( FMW_VERSION, $this->options['fmw_maintenance_checked_version'] );
	}
}
