<?php
/**
 * Step: printavo_create_customer
 *
 * Creates a new Printavo customer. Errors if a customer with the same
 * email already exists. For idempotent behavior, use printavo_find_or_create_customer.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Printavo_Create_Customer extends FMW_Step_Base {

    public static function type(): string { return 'printavo_create_customer'; }
    public static function display_name(): string { return 'Printavo: Create Customer'; }
    public static function category(): string { return 'Printavo'; }
    public static function description(): string { return 'Creates a Printavo customer. Errors if email already exists. For idempotent behavior, use printavo_find_or_create_customer.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'email' ],
            'properties' => [
                'email'        => [ 'type' => 'string' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
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
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $email = (string) ( $this->config['email'] ?? '' );
        if ( $email === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_create_customer: email is required.' );
        }

        $client = FMW_Printavo_Client::from_credentials();
        $created = $client->create_customer( $this->config );

        return [
            'id'           => $created['id'] ?? '',
            'email'        => $created['email'] ?? '',
            'first_name'   => $created['firstName'] ?? '',
            'last_name'    => $created['lastName'] ?? '',
            'phone'        => $created['phone'] ?? '',
            'company_name' => $created['companyName'] ?? '',
        ];
    }
}
