<?php
/**
 * Step: http_request
 *
 * Full control over HTTP method, headers, body. Use for PUT/PATCH/DELETE
 * or unusual auth schemes that http_get / http_post don't cover.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Http_Request extends FMW_Step_Base {

    public static function type(): string { return 'http_request'; }
    public static function display_name(): string { return 'HTTP: Generic Request'; }
    public static function category(): string { return 'HTTP'; }
    public static function description(): string { return 'Performs an HTTP request with full control over method, headers, body, and behavior. Use for PUT/PATCH/DELETE or unusual cases.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'url', 'method' ],
            'properties' => [
                'url'             => [ 'type' => 'string' ],
                'method'          => [
                    'type' => 'string',
                    'enum' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD' ],
                ],
                'headers'         => [ 'type' => 'object' ],
                'body'            => [],
                'body_format'     => [ 'type' => 'string', 'enum' => [ 'json', 'form', 'raw' ], 'default' => 'json' ],
                'timeout_seconds' => [ 'type' => 'integer', 'default' => 30 ],
                'accept_non_2xx'  => [ 'type' => 'boolean', 'default' => false ],
                'follow_redirects' => [ 'type' => 'boolean', 'default' => true ],
                'verify_ssl'      => [ 'type' => 'boolean', 'default' => true ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'status'      => [ 'type' => 'integer' ],
                'headers'     => [ 'type' => 'object' ],
                'body'        => [],
                'duration_ms' => [ 'type' => 'integer' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        return FMW_Http_Client::request( $this->config );
    }
}
