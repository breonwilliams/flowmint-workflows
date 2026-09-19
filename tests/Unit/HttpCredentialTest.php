<?php
/**
 * HTTP steps authenticate with a stored credential, named — not written in.
 *
 * Before 0.10.0 an HTTP step had no way to use a stored secret: `{{ env.* }}`
 * holds only site settings, so an API token had to be written into the
 * step's `headers`, where it sat in the workflow config and in every run's
 * recorded step config. `auth: { credential, scheme }` names a secret stored
 * as `http_<name>`; FMW_Http_Client adds it at send time.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

use Brain\Monkey\Functions;

class HttpCredentialTest extends UnitTestCase {

	/** @var array|null args of the last wp_remote_request() */
	private $sent;

	/** @var mixed what FMW_Credential_Store::get() returns for http_vendor */
	private $secret = 's3cr3t-token';

	protected function set_up() {
		parent::set_up();
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-exception.php';
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-logger.php';
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Database/class-fmw-credential-store.php';
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Connectors/class-fmw-http-client.php';

		// The store's own filter hook is its test seam.
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
			if ( 'fmw_credential' === $tag ) {
				return 'http_vendor' === $args[0] ? $this->secret : null;
			}
			return $value;
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) {
			$this->sent = $args;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}', 'headers' => [] ];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	private function headers_for( $auth, array $headers = [] ) {
		return \FMW_Http_Client::with_credential( $headers, $auth );
	}

	public function test_bearer() {
		$this->assertSame( [ 'Authorization' => 'Bearer s3cr3t-token' ], $this->headers_for( [ 'credential' => 'vendor', 'scheme' => 'bearer' ] ) );
		$this->assertSame( [ 'Authorization' => 'Bearer s3cr3t-token' ], $this->headers_for( [ 'credential' => 'vendor' ] ), 'bearer is the default' );
	}

	public function test_named_header() {
		$this->assertSame( [ 'X-API-Key' => 's3cr3t-token' ], $this->headers_for( [ 'credential' => 'vendor', 'scheme' => 'header', 'header' => 'X-API-Key' ] ) );
	}

	public function test_basic() {
		$this->secret = 'user:pass';
		$this->assertSame( [ 'Authorization' => 'Basic ' . base64_encode( 'user:pass' ) ], $this->headers_for( [ 'credential' => 'vendor', 'scheme' => 'basic' ] ) );
	}

	public function test_replaces_a_header_of_the_same_name_in_any_case() {
		$out = $this->headers_for( [ 'credential' => 'vendor' ], [ 'authorization' => 'Bearer PLACEHOLDER', 'Accept' => 'application/json' ] );
		$this->assertSame( [ 'Accept' => 'application/json', 'Authorization' => 'Bearer s3cr3t-token' ], $out );
	}

	/**
	 * @dataProvider bad_auth
	 */
	public function test_bad_auth_is_a_config_error( $auth ) {
		try {
			$this->headers_for( $auth );
			$this->fail( 'expected config_error' );
		} catch ( \FMW_Step_Exception $e ) {
			$this->assertSame( 'config_error', $e->get_error_code() );
			$this->assertFalse( $e->is_retryable() );
		}
	}

	public function bad_auth() {
		return [
			'not an object'      => [ 'vendor' ],
			'bad name'           => [ [ 'credential' => 'Vendor Key!' ] ],
			'unknown scheme'     => [ [ 'credential' => 'vendor', 'scheme' => 'digest' ] ],
			'header without name' => [ [ 'credential' => 'vendor', 'scheme' => 'header' ] ],
		];
	}

	public function test_missing_credential_is_not_configured() {
		try {
			$this->headers_for( [ 'credential' => 'other' ] );
			$this->fail( 'expected credential_not_configured' );
		} catch ( \FMW_Step_Exception $e ) {
			$this->assertSame( 'credential_not_configured', $e->get_error_code() );
			$this->assertStringContainsString( 'http_other', $e->getMessage() );
		}
	}

	public function test_undecryptable_credential_is_unreadable() {
		$this->secret = new \WP_Error( 'decrypt_failed', 'x' );
		try {
			$this->headers_for( [ 'credential' => 'vendor' ] );
			$this->fail( 'expected credential_unreadable' );
		} catch ( \FMW_Step_Exception $e ) {
			$this->assertSame( 'credential_unreadable', $e->get_error_code() );
		}
	}

	public function test_the_secret_reaches_the_request_and_not_the_result() {
		$config = [ 'url' => 'https://api.example.test/x', 'method' => 'GET', 'auth' => [ 'credential' => 'vendor', 'scheme' => 'bearer' ] ];
		$result = \FMW_Http_Client::request( $config );

		$this->assertSame( 'Bearer s3cr3t-token', $this->sent['headers']['Authorization'] );
		$this->assertStringNotContainsString( 's3cr3t-token', json_encode( $config ), 'the step config — what run history records — holds only the name' );
		$this->assertStringNotContainsString( 's3cr3t-token', json_encode( $result ), 'the step output holds the response, not the request headers' );
	}
}
