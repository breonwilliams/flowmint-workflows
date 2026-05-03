<?php
/**
 * Step: http_post
 *
 * Convenience wrapper for HTTP POST requests with JSON body.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Http_Post extends FMW_Step_Base {

    public static function type(): string { return 'http_post'; }
    public static function display_name(): string { return 'HTTP: POST'; }
    public static function category(): string { return 'HTTP'; }
    public static function description(): string { return 'Performs an HTTP POST request. Default body_format is "json"; pass "form" for URL-encoded forms or "raw" for raw string.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'url' ],
            'properties' => [
                'url'             => [ 'type' => 'string' ],
                'headers'         => [ 'type' => 'object' ],
                'body'            => [],
                'body_format'     => [ 'type' => 'string', 'enum' => [ 'json', 'form', 'raw' ], 'default' => 'json' ],
                'timeout_seconds' => [ 'type' => 'integer', 'default' => 30 ],
                'accept_non_2xx'  => [ 'type' => 'boolean', 'default' => false ],
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
        return FMW_Http_Client::request( array_merge( $this->config, [ 'method' => 'POST' ] ) );
    }
}
