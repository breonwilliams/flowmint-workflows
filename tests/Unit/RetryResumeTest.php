<?php
/**
 * Retries that run, and resume at the step that failed.
 *
 * Before 0.10.0 a retryable failure with retries left set the run to
 * `queued` and rethrew into Action Scheduler, which never retries a one-off
 * action: the run sat in `queued` for good, with no alert and no replay.
 * And every retryable error was "retried", although ARCHITECTURE.md has
 * always said only `on_error: "retry"` retries — `fail`, the default, fails.
 *
 * Now: the retry rule follows the documented contract, the retry is its own
 * scheduled action, and it resumes from a checkpoint the executor reports
 * after each top-level step, so the steps that already ran (emails,
 * records, quotes) are not run again.
 *
 * Runs in separate processes: it needs the REAL FMW_Step_Registry, and
 * WorkflowValidatorTest defines a stub of that class when it is not yet
 * loaded — sharing one process, whichever loads first wins.
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
class RetryResumeTest extends UnitTestCase {

	/** @var string[] step types executed, in order */
	public static $ran = [];

	protected function set_up() {
		parent::set_up();
		foreach ( [
			'includes/Core/class-fmw-logger.php',
			'includes/Core/class-fmw-workflow-context.php',
			'includes/Core/class-fmw-interpolator.php',
			'includes/Core/class-fmw-expression.php',
			'includes/Core/class-fmw-step-exception.php',
			'includes/Core/class-fmw-step-base.php',
			'includes/Core/class-fmw-step-registry.php',
			'includes/Core/class-fmw-workflow.php',
			'includes/Core/class-fmw-workflow-executor.php',
			'includes/Core/class-fmw-workflow-job.php',
			'includes/Database/class-fmw-schema.php',
			'includes/Database/class-fmw-run-step-repository.php',
		] as $file ) {
			require_once FMW_TEST_PLUGIN_DIR . $file;
		}
		require_once __DIR__ . '/Fixtures/RetryResumeSteps.php';

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'esc_html' )->returnArg( 1 );

		// Step-run records go nowhere; the executor only needs an id back.
		$GLOBALS['wpdb'] = new class {
			public $prefix    = 'wp_';
			public $insert_id = 0;
			public $last_error = '';
			public function insert( $t, $r ) { $this->insert_id++; return 1; }
			public function update( $t, $r, $w ) { return 1; }
		};

		$registry = \FMW_Step_Registry::instance();
		$registry->register( 'FMW_Test_Step_Record' );
		$registry->register( 'FMW_Test_Step_Flaky' );
		self::$ran = [];
		\FMW_Test_Step_Flaky::$fail = true;
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	private function workflow( $on_error = 'retry' ) {
		return new \FMW_Workflow( [
			'id'     => 'wf',
			'title'  => 'Retry probe',
			'config' => json_encode( [
				'steps' => [
					[ 'name' => 'mail_office', 'type' => 'test_record', 'config' => [ 'label' => 'mail_office' ] ],
					[ 'name' => 'remember', 'type' => 'test_record', 'config' => [ 'label' => 'remember', 'set_var' => 'quote_ref' ] ],
					[ 'name' => 'push_crm', 'type' => 'test_flaky', 'on_error' => $on_error, 'config' => [] ],
					[ 'name' => 'thank_you', 'type' => 'test_record', 'config' => [ 'label' => 'thank_you' ] ],
				],
			] ),
		] );
	}

	private function context() {
		return new \FMW_Workflow_Context( 7, 0, 'wf', '' );
	}

	/* ---- the retry rule --------------------------------------------- */

	public function test_only_on_error_retry_is_retried() {
		$this->assertTrue( \FMW_Workflow_Job::should_retry( 'retry', true, 0, 3 ) );
		$this->assertFalse( \FMW_Workflow_Job::should_retry( 'fail', true, 0, 3 ), 'fail — the default — fails the run, as documented' );
		$this->assertFalse( \FMW_Workflow_Job::should_retry( 'continue', true, 0, 3 ) );
	}

	public function test_a_non_retryable_error_or_no_retries_left_fails() {
		$this->assertFalse( \FMW_Workflow_Job::should_retry( 'retry', false, 0, 3 ) );
		$this->assertFalse( \FMW_Workflow_Job::should_retry( 'retry', true, 3, 3 ) );
		$this->assertFalse( \FMW_Workflow_Job::should_retry( 'retry', true, 0, 0 ), 'max_retries 0 turns retries off' );
	}

	public function test_bugs_and_edited_workflows_are_not_retryable() {
		$this->assertFalse( ( new \FMW_Step_Exception( 'php_error', 'x' ) )->is_retryable() );
		$this->assertFalse( ( new \FMW_Step_Exception( 'workflow_changed', 'x' ) )->is_retryable() );
		$this->assertTrue( ( new \FMW_Step_Exception( 'network_error', 'x' ) )->is_retryable() );
	}

	public function test_retry_delays_grow_and_then_hold() {
		$this->assertSame( 60, \FMW_Workflow_Job::retry_delay( 1 ) );
		$this->assertSame( 300, \FMW_Workflow_Job::retry_delay( 2 ) );
		$this->assertSame( 900, \FMW_Workflow_Job::retry_delay( 3 ) );
		$this->assertSame( 900, \FMW_Workflow_Job::retry_delay( 9 ) );
	}

	/* ---- the executor: checkpoints and resume ----------------------- */

	public function test_the_executor_reports_the_failed_step_and_checkpoints_before_it() {
		$checkpoints = [];
		$executor    = new \FMW_Workflow_Executor();
		try {
			$executor->execute( $this->workflow(), $this->context(), [
				'on_step_done' => function ( $next ) use ( &$checkpoints ) { $checkpoints[] = $next; },
			] );
			$this->fail( 'the flaky step should throw' );
		} catch ( \FMW_Step_Exception $e ) {
			$this->assertSame( 'network_error', $e->get_error_code() );
		}

		$this->assertSame( [ 1, 2 ], $checkpoints, 'a checkpoint after each completed step, none after the failure' );
		$this->assertSame( [ 'index' => 2, 'name' => 'push_crm', 'type' => 'test_flaky', 'on_error' => 'retry' ], $executor->failed_step() );
		$this->assertSame( [ 'mail_office', 'remember', 'flaky' ], self::$ran );
	}

	public function test_a_resumed_run_skips_the_completed_steps_and_keeps_their_context() {
		// First attempt: fails at push_crm; keep the context as checkpointed.
		$first = $this->context();
		try {
			( new \FMW_Workflow_Executor() )->execute( $this->workflow(), $first );
		} catch ( \FMW_Step_Exception $e ) {
			// expected
		}
		$checkpoint = $first->snapshot();

		// Retry: the upstream recovered.
		\FMW_Test_Step_Flaky::$fail = false;
		self::$ran                  = [];
		$retry                      = $this->context();
		$retry->restore( $checkpoint );
		( new \FMW_Workflow_Executor() )->execute( $this->workflow(), $retry, [ 'start_index' => 2 ] );

		$this->assertSame( [ 'flaky', 'thank_you' ], self::$ran, 'mail_office is NOT sent again' );
		$this->assertSame( 'Q-1', $retry->get_var( 'quote_ref' ), 'variables set before the failure survive' );
		$this->assertSame( [ 'label' => 'mail_office' ], $retry->get_step_output( 'mail_office' ), 'outputs of completed steps survive' );
	}

	public function test_a_php_error_in_a_step_is_caught_as_php_error() {
		\FMW_Test_Step_Flaky::$fail = 'error';
		$executor = new \FMW_Workflow_Executor();
		try {
			$executor->execute( $this->workflow(), $this->context() );
			$this->fail( 'should throw' );
		} catch ( \FMW_Step_Exception $e ) {
			$this->assertSame( 'php_error', $e->get_error_code() );
			$this->assertFalse( $e->is_retryable() );
		}
		$this->assertSame( 'push_crm', $executor->failed_step()['name'] );
	}

	public function test_restore_does_not_touch_the_run_or_env() {
		$ctx = $this->context();
		$ctx->restore( [ 'vars' => [ 'a' => 1 ], 'run' => [ 'id' => 999 ], 'env' => [ 'site_name' => 'evil' ] ] );
		$snap = $ctx->snapshot();
		$this->assertSame( 7, $snap['run']['id'] );
		$this->assertSame( 1, $ctx->get_var( 'a' ) );
	}
}
