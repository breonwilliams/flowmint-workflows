<?php
/**
 * Step types for RetryResumeTest: one that records it ran (and can set a
 * variable), one that fails with a retryable error until told not to.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

class FMW_Test_Step_Record extends FMW_Step_Base {
	public static function type(): string { return 'test_record'; }
	public static function display_name(): string { return 'Test record'; }
	public static function description(): string { return 'Records that it ran.'; }
	public static function config_schema(): array { return []; }
	public static function output_schema(): array { return []; }
	public static function has_side_effects(): bool { return true; }

	public function execute( FMW_Workflow_Context $context ): array {
		$config                          = $this->get_config();
		\FMW\Tests\Unit\RetryResumeTest::$ran[] = $config['label'];
		if ( ! empty( $config['set_var'] ) ) {
			$context->set_var( $config['set_var'], 'Q-1' );
		}
		return [ 'label' => $config['label'] ];
	}
}

class FMW_Test_Step_Flaky extends FMW_Step_Base {
	/** @var bool|string true: network_error; 'error': a PHP TypeError; false: succeed. */
	public static $fail = true;

	public static function type(): string { return 'test_flaky'; }
	public static function display_name(): string { return 'Test flaky'; }
	public static function description(): string { return 'Fails until told not to.'; }
	public static function config_schema(): array { return []; }
	public static function output_schema(): array { return []; }
	public static function has_side_effects(): bool { return true; }

	public function execute( FMW_Workflow_Context $context ): array {
		\FMW\Tests\Unit\RetryResumeTest::$ran[] = 'flaky';
		if ( 'error' === self::$fail ) {
			throw new TypeError( 'Argument #1 must be of type array, null given' );
		}
		if ( self::$fail ) {
			throw new FMW_Step_Exception( 'network_error', 'Connection timed out' );
		}
		return [ 'ok' => true ];
	}
}
