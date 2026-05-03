<?php
/**
 * Step: try_catch
 *
 * Runs a list of steps; on failure runs an alternative list.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Try_Catch extends FMW_Step_Base {

    public static function type(): string {
        return 'try_catch';
    }

    public static function display_name(): string {
        return 'Try / Catch';
    }

    public static function category(): string {
        return 'Control flow';
    }

    public static function description(): string {
        return 'Runs a list of steps; if any throw a matching error code, runs the catch list instead.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'try', 'catch' ],
            'properties' => [
                'try'         => [ 'type' => 'array' ],
                'catch'       => [ 'type' => 'array' ],
                'catch_codes' => [
                    'type'        => 'array',
                    'description' => 'Specific error codes to catch. Empty = catch all.',
                    'items'       => [ 'type' => 'string' ],
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'branch'     => [ 'type' => 'string', 'enum' => [ 'try', 'catch' ] ],
                'error_code' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $try_steps   = $this->config['try']   ?? [];
        $catch_steps = $this->config['catch'] ?? [];
        $catch_codes = $this->config['catch_codes'] ?? [];

        $sub_workflow_for_steps = function( $steps, $tag ) use ( $context ) {
            return new FMW_Workflow( [
                'id'                => $context->get_workflow_id() . '::' . $this->step_name . '::' . $tag,
                'title'             => 'try_catch sub-workflow',
                'form_id'           => $context->get_form_id(),
                'enabled'           => 1,
                'config'            => wp_json_encode( [ 'version' => '1.0', 'steps' => $steps ] ),
                'managed_by'        => 'inline',
                'connector_version' => 0,
            ] );
        };

        $executor = new FMW_Workflow_Executor();

        try {
            $executor->execute( $sub_workflow_for_steps( $try_steps, 'try' ), $context );
            return [ 'branch' => 'try', 'error_code' => null ];
        } catch ( FMW_Step_Exception $e ) {
            // Should we catch?
            $matches = empty( $catch_codes ) || in_array( $e->get_error_code(), $catch_codes, true );

            if ( ! $matches ) {
                throw $e; // Re-throw — outside our catch_codes filter.
            }

            FMW_Logger::info( 'try_catch caught error', [
                'step_name'  => $this->step_name,
                'error_code' => $e->get_error_code(),
            ] );

            $executor->execute( $sub_workflow_for_steps( $catch_steps, 'catch' ), $context );

            return [
                'branch'     => 'catch',
                'error_code' => $e->get_error_code(),
            ];
        }
    }
}
