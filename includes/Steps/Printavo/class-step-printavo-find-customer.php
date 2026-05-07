<?php
/**
 * Step: printavo_find_customer
 *
 * Looks up a Printavo customer by email. Returns metadata or { found: false }.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Printavo_Find_Customer extends FMW_Step_Base {

    public static function type(): string { return 'printavo_find_customer'; }
    public static function display_name(): string { return 'Printavo: Find Customer'; }
    public static function category(): string { return 'Printavo'; }
    public static function description(): string { return 'Looks up a Printavo customer by email. Read-only — returns metadata or { found: false }.'; }
    public static function has_side_effects(): bool { return false; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'email' ],
            'properties' => [
                'email' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'found'        => [ 'type' => 'boolean' ],
                'contact_id'   => [ 'type' => 'string' ],
                'customer_id'  => [ 'type' => 'string' ],
                'email'        => [ 'type' => 'string' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
                'full_name'    => [ 'type' => 'string' ],
                'phone'        => [ 'type' => 'string' ],
                'company_name' => [ 'type' => 'string' ],
                'id'           => [ 'type' => 'string', 'description' => 'Legacy alias for contact_id' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $email = (string) ( $this->config['email'] ?? '' );
        if ( $email === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_find_customer: email is required.' );
        }

        $client   = FMW_Printavo_Client::from_credentials();
        $customer = $client->find_customer_by_email( $email );

        if ( ! $customer ) {
            return [ 'found' => false ];
        }

        return [
            'found'        => true,
            'contact_id'   => $customer['contact_id'] ?? '',
            'customer_id'  => $customer['customer_id'] ?? '',
            'email'        => $customer['email'] ?? '',
            'first_name'   => $customer['first_name'] ?? '',
            'last_name'    => $customer['last_name'] ?? '',
            'full_name'    => $customer['full_name'] ?? '',
            'phone'        => $customer['phone'] ?? '',
            'company_name' => $customer['company_name'] ?? '',
            'id'           => $customer['id'] ?? '',
        ];
    }
}
