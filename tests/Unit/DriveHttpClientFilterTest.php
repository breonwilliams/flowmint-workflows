<?php
/**
 * The fmw_drive_http_client filter routes the Drive client through a
 * supplied HTTP client — the service-account token request included.
 *
 * This is what lets a workflow be tested end to end without Google: 725
 * Print Lab's two quote workflows were run on a local site against a
 * stand-in for the Drive API through this filter.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

use Brain\Monkey\Functions;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class DriveHttpClientFilterTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-exception.php';
		require_once FMW_TEST_PLUGIN_DIR . 'includes/Connectors/class-fmw-drive-client.php';
	}

	private function service_account_json() {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		return json_encode( [
			'type'         => 'service_account',
			'client_email' => 'test@example.iam.gserviceaccount.com',
			'client_id'    => '0',
			'private_key'  => $pem,
			'token_uri'    => 'https://oauth2.googleapis.com/token',
		] );
	}

	public function test_requests_go_through_the_supplied_client() {
		$history = [];
		$mock    = new MockHandler( [
			new Response( 200, [ 'Content-Type' => 'application/json' ], json_encode( [ 'access_token' => 't', 'expires_in' => 3600, 'token_type' => 'Bearer' ] ) ),
			new Response( 200, [ 'Content-Type' => 'application/json' ], json_encode( [ 'files' => [ [ 'id' => 'f1', 'name' => '2026-09', 'webViewLink' => 'https://drive.example/f1' ] ] ] ) ),
		] );
		$stack = HandlerStack::create( $mock );
		$stack->push( Middleware::history( $history ) );
		$http = new Client( [ 'handler' => $stack ] );

		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) use ( $http ) {
			return 'fmw_drive_http_client' === $hook ? $http : $value;
		} );

		$drive  = new \FMW_Drive_Client( $this->service_account_json() );
		$folder = $drive->find_folder( 'parent-id', '2026-09' );

		$this->assertSame( 'f1', $folder['id'] );
		$this->assertCount( 2, $history, 'token + files.list, both through the supplied client' );
		$this->assertSame( 'oauth2.googleapis.com', $history[0]['request']->getUri()->getHost() );
		$this->assertSame( '/drive/v3/files', $history[1]['request']->getUri()->getPath() );
	}

	public function test_without_the_filter_the_default_client_is_kept() {
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

		$drive = new \FMW_Drive_Client( $this->service_account_json() );
		$ref   = new \ReflectionProperty( $drive, 'service' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$client = $ref->getValue( $drive )->getClient();

		// Google's own default: created lazily, not ours.
		$this->assertInstanceOf( '\GuzzleHttp\ClientInterface', $client->getHttpClient() );
	}
}
