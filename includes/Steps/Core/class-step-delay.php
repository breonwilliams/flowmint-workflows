<?php
/**
 * Step: delay
 *
 * Pauses execution for N seconds.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Delay extends FMW_Step_Base {

    public static function type(): string {
        return 'delay';
    }

    public static function display_name(): string {
        return 'Delay';
    }

    public static function category(): string {
        return 'Control flow';
    }

    public static function description(): string {
        return 'Pauses workflow execution for a specified number of seconds (1-3600).';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'seconds' ],
            'properties' => [
                'seconds' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3600 ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'delayed_seconds' => [ 'type' => 'integer' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $seconds = (int) ( $this->config['seconds'] ?? 0 );
        if ( $seconds < 1 || $seconds > 3600 ) {
            throw new FMW_Step_Exception(
                'config_error',
                sprintf( "delay: 'seconds' must be between 1 and 3600. Got: %d", (int) $seconds )
            );
        }

        // Note: this blocks the current Action Scheduler worker for the duration.
        // For long delays, a future enhancement could re-enqueue the workflow
        // with a scheduled-time, but for v1 this is fine for sub-minute delays.
        sleep( $seconds );

        return [ 'delayed_seconds' => $seconds ];
    }
}
