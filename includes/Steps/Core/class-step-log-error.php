<?php
/**
 * Step: log_error
 *
 * Logs an error-level message. Also fires fmw_log action so notification
 * subscribers (Slack, email) can pick it up.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Log_Error extends FMW_Step_Base {

    public static function type(): string {
        return 'log_error';
    }

    public static function display_name(): string {
        return 'Log: Error';
    }

    public static function category(): string {
        return 'Logging';
    }

    public static function description(): string {
        return 'Writes an ERROR-level message. Triggers notification channels (Slack, email) configured in settings.';
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
        return false; // Logging is technically a side-effect but we don't gate retry on it.
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $message = (string) ( $this->config['message'] ?? '' );
        $log_context = isset( $this->config['context'] ) && is_array( $this->config['context'] ) ? $this->config['context'] : [];
        $log_context['run_id']    = $context->get_run_id();
        $log_context['step_name'] = $this->step_name;

        FMW_Logger::error( $message, $log_context );

        return [ 'logged' => true ];
    }
}
