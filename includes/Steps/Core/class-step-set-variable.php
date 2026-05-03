<?php
/**
 * Step: set_variable
 *
 * Sets a value into the run context for downstream steps to reference.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Set_Variable extends FMW_Step_Base {

    public static function type(): string {
        return 'set_variable';
    }

    public static function display_name(): string {
        return 'Set Variable';
    }

    public static function category(): string {
        return 'Control flow';
    }

    public static function description(): string {
        return 'Stores a value into the run context. Accessible from downstream steps as {{ vars.<name> }}.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'name', 'value' ],
            'properties' => [
                'name'  => [ 'type' => 'string', 'description' => 'Variable name.' ],
                'value' => [ 'description' => 'Value to store. Variables in the value are interpolated before storage.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'value' => [ 'description' => 'The stored value.' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( empty( $this->config['name'] ) ) {
            throw new FMW_Step_Exception( 'config_error', "set_variable: 'name' is required." );
        }
        if ( ! array_key_exists( 'value', $this->config ) ) {
            throw new FMW_Step_Exception( 'config_error', "set_variable: 'value' is required." );
        }

        $context->set_var( $this->config['name'], $this->config['value'] );

        return [ 'value' => $this->config['value'] ];
    }
}
