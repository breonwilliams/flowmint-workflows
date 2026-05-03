<?php
/**
 * REST API root.
 *
 * Registers all REST routes under the /wp-json/flowmint/v1/connector/ namespace.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Api {

    public function register_routes() {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register() {
        ( new FMW_REST_Preflight() )->register();
        ( new FMW_REST_Workflows() )->register();
        ( new FMW_REST_Runs() )->register();
        ( new FMW_REST_Step_Types() )->register();
        ( new FMW_REST_Credentials() )->register();
        ( new FMW_REST_Templates() )->register();
    }

    /**
     * Build the full route prefix.
     *
     * @param string $route Route path starting with '/'.
     * @return string
     */
    public static function ns() {
        return FMW_REST_NAMESPACE;
    }

    public static function base() {
        return FMW_REST_BASE;
    }
}
