<?php
/**
 * Step: conditional
 *
 * Runs one of two branches based on an expression.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Conditional extends FMW_Step_Base {

    public static function type(): string {
        return 'conditional';
    }

    public static function display_name(): string {
        return 'Conditional Branch';
    }

    public static function category(): string {
        return 'Control flow';
    }

    public static function description(): string {
        return 'Evaluates an expression and runs one of two nested step lists based on the result.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'if', 'then' ],
            'properties' => [
                'if'   => [ 'type' => 'string', 'description' => 'Expression to evaluate (truthy/falsy).' ],
                'then' => [ 'type' => 'array',  'description' => 'Steps to execute if true.' ],
                'else' => [ 'type' => 'array',  'description' => 'Steps to execute if false.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'branch'         => [ 'type' => 'string', 'enum' => [ 'then', 'else' ] ],
                'steps_executed' => [ 'type' => 'integer' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true; // Depends on inner steps; safest to assume yes.
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! isset( $this->config['if'] ) ) {
            throw new FMW_Step_Exception( 'config_error', "conditional: 'if' is required." );
        }

        $interp = new FMW_Interpolator( $context );
        $expr   = new FMW_Expression( $interp );

        // 'if' was already interpolated by the executor — but the interpolator
        // may have stringified the result. We re-evaluate by passing the raw
        // expression text through the expression evaluator.
        // To do this, we need the RAW expression text. The executor passed us
        // the interpolated config. So we read the raw expression from the
        // step definition pre-interpolation. Workaround: evaluate against the
        // already-interpolated string as a simple truthy check.
        $raw_if = $this->config['if'];
        $cond_result = $expr->evaluate( $raw_if );

        $branch_steps = $cond_result
            ? ( $this->config['then'] ?? [] )
            : ( $this->config['else'] ?? [] );

        if ( ! is_array( $branch_steps ) ) {
            $branch_steps = [];
        }

        // Inline-execute the branch steps. We construct a temporary "sub-workflow".
        // The subexecutor uses the same context.
        $sub_workflow_config = [
            'version' => '1.0',
            'steps'   => $branch_steps,
        ];

        $sub_workflow = new FMW_Workflow( [
            'id'                => $context->get_workflow_id() . '::' . $this->step_name,
            'title'             => 'Conditional sub-workflow',
            'form_id'           => $context->get_form_id(),
            'enabled'           => 1,
            'config'            => wp_json_encode( $sub_workflow_config ),
            'managed_by'        => 'inline',
            'connector_version' => 0,
        ] );

        $executor = new FMW_Workflow_Executor();
        $executor->execute( $sub_workflow, $context );

        return [
            'branch'         => $cond_result ? 'then' : 'else',
            'steps_executed' => count( $branch_steps ),
        ];
    }
}
