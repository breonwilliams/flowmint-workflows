<?php
/**
 * Mock WP_Error class for unit testing.
 *
 * Loaded by tests/Unit/UnitTestCase.php only when the real class isn't
 * present in the runtime (i.e. when running under PHPUnit without WP).
 *
 * Identical shape to the WP_Error mocks used by FRE and PRE in this
 * repo, kept consistent so cross-plugin behavior is predictable.
 *
 * @package FlowMintWorkflows\Tests\Unit\Mocks
 */

if ( class_exists( '\\WP_Error' ) ) {
    return;
}

/**
 * Minimal WP_Error mock — same shape as WordPress core's class for
 * the methods FlowMint actually uses. Add methods here if a test
 * needs one this mock doesn't yet support.
 */
class WP_Error {

    private $errors     = array();
    private $error_data = array();

    public function __construct( $code = '', $message = '', $data = '' ) {
        if ( ! empty( $code ) ) {
            $this->add( $code, $message, $data );
        }
    }

    public function add( $code, $message, $data = '' ) {
        $this->errors[ $code ][] = $message;
        if ( ! empty( $data ) ) {
            $this->error_data[ $code ] = $data;
        }
    }

    public function add_data( $data, $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        $this->error_data[ $code ] = $data;
    }

    public function get_error_code() {
        $codes = array_keys( $this->errors );
        return ! empty( $codes ) ? $codes[0] : '';
    }

    public function get_error_codes() {
        return array_keys( $this->errors );
    }

    public function get_error_message( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
    }

    public function get_error_messages( $code = '' ) {
        if ( empty( $code ) ) {
            $all = array();
            foreach ( $this->errors as $messages ) {
                $all = array_merge( $all, $messages );
            }
            return $all;
        }
        return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
    }

    public function get_error_data( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
    }

    public function has_errors() {
        return ! empty( $this->errors );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof \WP_Error;
    }
}
