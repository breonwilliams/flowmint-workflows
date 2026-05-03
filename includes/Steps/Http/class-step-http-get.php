<?php
/**
 * Step: http_get
 *
 * Convenience wrapper for HTTP GET requests.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Http_Get extends FMW_Step_Base {

    public static function type(): string { return 'http_get'; }
    public static function display_name(): string { return 'HTTP: GET'; }
    public static function category(): string { return 'HTTP'; }
    public static function description(): string { return 'Performs an HTTP GET request. Returns parsed JSON body if Content-Type is application/json, otherwise raw string.'; }
    public static function has_side_effects(): bool { return false; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'url' ],
            'properties' => [
                'url'             => [ 'type' => 'string' ],
                'headers'         => [ 'type' => 'object' ],
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
        return FMW_Http_Client::request( array_merge( $this->config, [ 'method' => 'GET' ] ) );
    }
}
