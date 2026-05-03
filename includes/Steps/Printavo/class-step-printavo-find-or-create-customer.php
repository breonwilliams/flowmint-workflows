<?php
/**
 * Step: printavo_find_or_create_customer
 *
 * Returns existing Printavo customer if found by email, otherwise creates one.
 * Idempotent.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Printavo_Find_Or_Create_Customer extends FMW_Step_Base {

    public static function type(): string { return 'printavo_find_or_create_customer'; }
    public static function display_name(): string { return 'Printavo: Find or Create Customer'; }
    public static function category(): string { return 'Printavo'; }
    public static function description(): string { return 'Returns existing Printavo customer if found by email, otherwise creates one. Idempotent across retries.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'email' ],
            'properties' => [
                'email'        => [ 'type' => 'string' ],
                'name'         => [ 'type' => 'string', 'description' => 'Full name; auto-split into first_name/last_name.' ],
                'first_name'   => [ 'type' => 'string', 'description' => 'Overrides name-splitting if provided.' ],
                'last_name'    => [ 'type' => 'string', 'description' => 'Overrides name-splitting if provided.' ],
                'phone'        => [ 'type' => 'string' ],
                'company_name' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'           => [ 'type' => 'string' ],
                'email'        => [ 'type' => 'string' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
                'company_name' => [ 'type' => 'string' ],
                'was_created'  => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $email = (string) ( $this->config['email'] ?? '' );
        if ( $email === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_find_or_create_customer: email is required.' );
        }

        // Auto-split full name if first/last not provided.
        $args = $this->config;
        if ( empty( $args['first_name'] ) && empty( $args['last_name'] ) && ! empty( $args['name'] ) ) {
            $name  = trim( $args['name'] );
            $parts = preg_split( '/\s+/', $name, 2 );
            $args['first_name'] = $parts[0] ?? $name;
            $args['last_name']  = $parts[1] ?? '';
        }

        $client = FMW_Printavo_Client::from_credentials();
        $result = $client->find_or_create_customer( $args );

        return [
            'id'           => $result['id'] ?? '',
            'email'        => $result['email'] ?? '',
            'first_name'   => $result['firstName'] ?? '',
            'last_name'    => $result['lastName'] ?? '',
            'phone'        => $result['phone'] ?? '',
            'company_name' => $result['companyName'] ?? '',
            'was_created'  => ! empty( $result['was_created'] ),
        ];
    }
}
