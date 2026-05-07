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
                'contact_id'   => [ 'type' => 'string', 'description' => 'Printavo Contact ID — pass to printavo_create_quote.contact_id' ],
                'customer_id'  => [ 'type' => 'string', 'description' => 'Printavo Customer ID (the company)' ],
                'email'        => [ 'type' => 'string' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
                'full_name'    => [ 'type' => 'string' ],
                'phone'        => [ 'type' => 'string' ],
                'company_name' => [ 'type' => 'string' ],
                'was_created'  => [ 'type' => 'boolean' ],
                'id'           => [ 'type' => 'string', 'description' => 'Legacy alias for contact_id; prefer contact_id in new workflows' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $email = (string) ( $this->config['email'] ?? '' );
        if ( $email === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_find_or_create_customer: email is required.' );
        }

        // The client's build_contact_input handles name-splitting internally;
        // we just forward the args through without preprocessing.
        $client = FMW_Printavo_Client::from_credentials();
        $result = $client->find_or_create_customer( $this->config );

        return [
            'contact_id'   => $result['contact_id'] ?? '',
            'customer_id'  => $result['customer_id'] ?? '',
            'email'        => $result['email'] ?? '',
            'first_name'   => $result['first_name'] ?? '',
            'last_name'    => $result['last_name'] ?? '',
            'full_name'    => $result['full_name'] ?? '',
            'phone'        => $result['phone'] ?? '',
            'company_name' => $result['company_name'] ?? '',
            'was_created'  => ! empty( $result['was_created'] ),
            'id'           => $result['id'] ?? '',
        ];
    }
}
