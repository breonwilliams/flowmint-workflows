<?php
/**
 * Step: log_warning
 *
 * Logs a warning-level message to the FMW logger.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Log_Warning extends FMW_Step_Base {

    public static function type(): string {
        return 'log_warning';
    }

    public static function display_name(): string {
        return 'Log: Warning';
    }

    public static function category(): string {
        return 'Logging';
    }

    public static function description(): string {
        return 'Writes a WARNING-level message. Variables are interpolated.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'message' ],
            'properties' => [
                'message' => [ 'type' => 'string' ],
                'context' => [ 'type' => 'object' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [ 'type' => 'object', 'properties' => [ 'logged' => [ 'type' => 'boolean' ] ] ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $message = (string) ( $this->config['message'] ?? '' );
        $log_context = isset( $this->config['context'] ) && is_array( $this->config['context'] ) ? $this->config['context'] : [];
        $log_context['run_id']    = $context->get_run_id();
        $log_context['step_name'] = $this->step_name;

        FMW_Logger::warning( $message, $log_context );

        return [ 'logged' => true ];
    }
}
