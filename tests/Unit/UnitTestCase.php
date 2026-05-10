<?php
/**
 * Base Unit Test Case for FlowMint Workflows.
 *
 * Mirrors FRE's tests/Unit/UnitTestCase.php pattern. Pre-declares the
 * WordPress functions FlowMint touches so individual tests don't have
 * to mock them per-test, and provides an in-memory $wpdb stub plus a
 * WP_Error mock for the pure-logic surfaces.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

abstract class UnitTestCase extends TestCase {

    /**
     * In-memory option store. Reset between tests.
     *
     * @var array
     */
    protected $options = array();

    /**
     * Saved $_SERVER for restoration in tear_down.
     *
     * @var array
     */
    protected $original_server;

    protected function set_up() {
        parent::set_up();
        Monkey\setUp();

        $this->options         = array();
        $this->original_server = $_SERVER;

        $this->setup_common_mocks();
    }

    protected function tear_down() {
        $_SERVER = $this->original_server;

        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Common WordPress function mocks every test needs.
     *
     * Subclasses that want to override one of these can call
     * Functions\when() again with their own alias — Brain\Monkey
     * uses last-write-wins.
     */
    protected function setup_common_mocks() {
        // ---------------------------------------------------------------
        // Sanitization functions.
        // ---------------------------------------------------------------
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );

        Functions\when( 'sanitize_text_field' )->alias( function ( $str ) {
            return trim( strip_tags( (string) $str ) );
        } );

        Functions\when( 'sanitize_textarea_field' )->alias( function ( $str ) {
            return trim( strip_tags( (string) $str ) );
        } );

        Functions\when( 'sanitize_email' )->alias( function ( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
        } );

        Functions\when( 'wp_unslash' )->alias( function ( $value ) {
            if ( is_array( $value ) ) {
                return array_map( 'stripslashes_deep', $value );
            }
            if ( is_string( $value ) ) {
                return stripslashes( $value );
            }
            return $value;
        } );

        Functions\when( 'esc_html' )->alias( function ( $str ) {
            return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
        } );

        Functions\when( 'esc_attr' )->alias( function ( $str ) {
            return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
        } );

        Functions\when( 'esc_url' )->alias( function ( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        } );

        Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        } );

        // ---------------------------------------------------------------
        // Translation — return the string as-is.
        // ---------------------------------------------------------------
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( '_x' )->returnArg( 1 );
        Functions\when( '_n' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'esc_attr__' )->returnArg( 1 );

        // ---------------------------------------------------------------
        // Option API — backed by the in-memory $this->options store.
        // The closure captures $this so it sees the current store
        // for each test.
        // ---------------------------------------------------------------
        $self = $this;
        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) use ( $self ) {
            return array_key_exists( $option, $self->options ) ? $self->options[ $option ] : $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value, $autoload = null ) use ( $self ) {
            $self->options[ $option ] = $value;
            return true;
        } );

        Functions\when( 'add_option' )->alias( function ( $option, $value, $deprecated = '', $autoload = 'yes' ) use ( $self ) {
            if ( array_key_exists( $option, $self->options ) ) {
                return false;
            }
            $self->options[ $option ] = $value;
            return true;
        } );

        Functions\when( 'delete_option' )->alias( function ( $option ) use ( $self ) {
            if ( ! array_key_exists( $option, $self->options ) ) {
                return false;
            }
            unset( $self->options[ $option ] );
            return true;
        } );

        // ---------------------------------------------------------------
        // wp_salt — used by the credential store. Tests that exercise
        // salt rotation override this per-test.
        // ---------------------------------------------------------------
        Functions\when( 'wp_salt' )->alias( function ( $scheme = 'auth' ) {
            return 'fmw-test-salt-' . $scheme;
        } );

        // ---------------------------------------------------------------
        // get_bloginfo / home_url — used by FMW_Workflow_Context to
        // populate the {{ env }} variables. Stable test values so
        // assertions don't depend on whatever the dev's WP install
        // happens to be configured with.
        // ---------------------------------------------------------------
        Functions\when( 'get_bloginfo' )->alias( function ( $what = 'name' ) {
            $defaults = array(
                'name'        => 'FMW Test Site',
                'description' => 'Test description',
                'url'         => 'http://example.test',
                'version'     => '6.5.0',
            );
            return $defaults[ $what ] ?? '';
        } );

        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'http://example.test' . $path;
        } );

        Functions\when( 'site_url' )->alias( function ( $path = '' ) {
            return 'http://example.test' . $path;
        } );

        Functions\when( 'current_time' )->alias( function ( $format = 'mysql' ) {
            if ( $format === 'mysql' ) {
                return '2024-05-10 12:00:00';
            }
            return time();
        } );

        // ---------------------------------------------------------------
        // Hook system — silent stubs so plugin code that wires hooks at
        // construction time doesn't blow up under test. Tests that
        // verify hook registration use Functions\expect() directly.
        // ---------------------------------------------------------------
        Functions\when( 'add_action' )->justReturn();
        Functions\when( 'add_filter' )->justReturn();
        Functions\when( 'remove_action' )->justReturn();
        Functions\when( 'remove_filter' )->justReturn();
        Functions\when( 'do_action' )->justReturn();
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        // ---------------------------------------------------------------
        // wp_json_encode / wp_parse_args / etc. — utilities the plugin
        // touches in many places. Mock as PHP-native equivalents.
        // ---------------------------------------------------------------
        Functions\when( 'wp_json_encode' )->alias( function ( $value, $flags = 0, $depth = 512 ) {
            return json_encode( $value, $flags, $depth );
        } );

        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            if ( is_object( $args ) ) {
                $args = get_object_vars( $args );
            }
            if ( ! is_array( $args ) ) {
                $args = array();
            }
            return array_merge( (array) $defaults, $args );
        } );

        Functions\when( 'absint' )->alias( function ( $value ) {
            return abs( intval( $value ) );
        } );

        // ---------------------------------------------------------------
        // $wpdb global — minimal stand-in. Some FMW classes touch
        // $wpdb->prefix at construction. Real DB queries happen at
        // integration level, not here.
        // ---------------------------------------------------------------
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            $wpdb = (object) array(
                'prefix'  => 'wptests_',
                'options' => 'wptests_options',
            );
        }

        // ---------------------------------------------------------------
        // WP_Error mock.
        // ---------------------------------------------------------------
        if ( ! class_exists( '\\WP_Error' ) ) {
            require_once __DIR__ . '/Mocks/WP_Error.php';
        }

        // ---------------------------------------------------------------
        // FMW_Logger no-op stub so classes that call it during
        // construction or in error paths don't crash. Tests that
        // need to verify log output should override this with
        // Functions\expect() or a custom mock.
        // ---------------------------------------------------------------
        if ( ! class_exists( '\\FMW_Logger' ) ) {
            eval( 'class FMW_Logger {
                public static function debug( $m, $ctx = array() ) {}
                public static function info( $m, $ctx = array() ) {}
                public static function warning( $m, $ctx = array() ) {}
                public static function error( $m, $ctx = array() ) {}
            }' );
        }
    }
}
