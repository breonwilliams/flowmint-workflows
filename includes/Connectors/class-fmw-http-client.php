<?php
/**
 * Generic HTTP client.
 *
 * Lightweight wrapper over wp_remote_request with structured error handling.
 * Used by http_* step types and as a building block for service-specific
 * connectors that prefer wp_remote_* over a heavy SDK (e.g., Printavo GraphQL).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Http_Client {

    /**
     * Make an HTTP request.
     *
     * @param array $args {
     *     @type string       $url
     *     @type string       $method     GET, POST, PUT, PATCH, DELETE, HEAD
     *     @type array        $headers
     *     @type mixed        $body       Array (JSON-encoded), string (raw), or array w/ form-data shape
     *     @type string       $body_format 'json' (default), 'form', 'raw'
     *     @type int          $timeout_seconds Default 30
     *     @type bool         $accept_non_2xx Default false (throws on 4xx/5xx)
     *     @type bool         $follow_redirects Default true
     *     @type bool         $verify_ssl Default true
     *     @type array        $auth       Optional { credential, scheme, header } —
     *                                    a stored credential added at send time;
     *                                    see with_credential().
     * }
     * @return array { status, headers, body, duration_ms }
     * @throws FMW_Step_Exception
     */
    public static function request( array $args ) {
        $url = (string) ( $args['url'] ?? '' );
        if ( $url === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'HTTP request: url is required.' );
        }

        $method      = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
        $body_format = (string) ( $args['body_format'] ?? 'json' );
        $headers     = (array) ( $args['headers'] ?? [] );
        $timeout     = (int) ( $args['timeout_seconds'] ?? 30 );

        if ( ! empty( $args['auth'] ) ) {
            $headers = self::with_credential( $headers, $args['auth'] );
        }

        $accept_non_2xx   = ! empty( $args['accept_non_2xx'] );
        $follow_redirects = ! isset( $args['follow_redirects'] ) || $args['follow_redirects'];
        $verify_ssl       = ! isset( $args['verify_ssl'] ) || $args['verify_ssl'];

        // Build wp_remote_request args.
        $request_args = [
            'method'    => $method,
            'headers'   => $headers,
            'timeout'   => $timeout,
            'redirection' => $follow_redirects ? 5 : 0,
            'sslverify' => $verify_ssl,
        ];

        // Encode body per body_format.
        if ( isset( $args['body'] ) && in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
            $body = $args['body'];
            switch ( $body_format ) {
                case 'json':
                    if ( ! self::header_set( $request_args['headers'], 'Content-Type' ) ) {
                        $request_args['headers']['Content-Type'] = 'application/json';
                    }
                    $request_args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
                    break;
                case 'form':
                    if ( ! self::header_set( $request_args['headers'], 'Content-Type' ) ) {
                        $request_args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                    }
                    $request_args['body'] = is_array( $body ) ? http_build_query( $body ) : (string) $body;
                    break;
                case 'raw':
                default:
                    $request_args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
                    break;
            }
        }

        $started_at = microtime( true );

        $response = wp_remote_request( $url, $request_args );

        $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $message = $response->get_error_message();
            // Network errors (DNS, timeout, etc.)
            $code = strpos( $message, 'timed out' ) !== false ? 'timeout' : 'network_error';
            throw new FMW_Step_Exception( esc_html( $code ), sprintf( 'HTTP request failed: %s', esc_html( $message ) ) );
        }

        $status        = (int) wp_remote_retrieve_response_code( $response );
        $resp_headers  = wp_remote_retrieve_headers( $response );
        $resp_body_raw = wp_remote_retrieve_body( $response );

        // Try to parse JSON if response Content-Type suggests it.
        $resp_body = $resp_body_raw;
        $content_type = is_object( $resp_headers ) ? (string) $resp_headers->offsetGet( 'content-type' ) : '';
        if ( strpos( $content_type, 'application/json' ) !== false ) {
            $decoded = json_decode( $resp_body_raw, true );
            if ( $decoded !== null || $resp_body_raw === 'null' ) {
                $resp_body = $decoded;
            }
        }

        $result = [
            'status'      => $status,
            'headers'     => is_object( $resp_headers ) ? iterator_to_array( $resp_headers->getIterator() ) : [],
            'body'        => $resp_body,
            'duration_ms' => $duration_ms,
        ];

        if ( ! $accept_non_2xx && ( $status < 200 || $status >= 300 ) ) {
            $code = self::error_code_for_status( $status );
            $excerpt = is_string( $resp_body_raw ) ? substr( $resp_body_raw, 0, 200 ) : '';
            throw new FMW_Step_Exception(
                esc_html( $code ),
                sprintf(
                    'HTTP %s %s returned %d. Body excerpt: %s',
                    esc_html( $method ),
                    esc_html( $url ),
                    (int) $status,
                    esc_html( $excerpt )
                ),
                [ 'status' => (int) $status, 'response_excerpt' => esc_html( $excerpt ) ]
            );
        }

        return $result;
    }

    /**
     * Map HTTP status to FMW error code.
     */
    private static function error_code_for_status( $status ) {
        if ( $status === 401 || $status === 403 ) return 'auth_failed';
        if ( $status === 429 ) return 'rate_limited';
        if ( $status >= 400 && $status < 500 ) return 'external_4xx';
        if ( $status >= 500 ) return 'external_5xx';
        return 'unexpected';
    }

    /**
     * Credential names a step's `auth.credential` may use: stored as
     * `http_<name>` in FMW_Credential_Store.
     */
    const CREDENTIAL_NAME_PATTERN = '/^[a-z0-9_]{1,48}$/';

    /**
     * Add a stored credential to a request's headers.
     *
     * The step names the credential; the secret is read here, at send time,
     * so it is never in the workflow config, the step's recorded config or
     * its output (response headers are recorded, request headers are not).
     * Before 0.10.0 there was no way to do this: `{{ env.* }}` holds only
     * site settings, so a token had to be written into the step's headers.
     *
     *   auth: { credential: "vendor", scheme: "bearer" }            → Authorization: Bearer <secret>
     *   auth: { credential: "vendor", scheme: "header", header: "X-API-Key" } → X-API-Key: <secret>
     *   auth: { credential: "vendor", scheme: "basic" }             → Authorization: Basic base64(<secret>)  (secret is "user:password")
     *
     * The header it sets replaces one of the same name in `headers`.
     *
     * @param array $headers
     * @param mixed $auth
     * @return array
     * @throws FMW_Step_Exception config_error for a malformed auth block,
     *                            credential_not_configured when nothing is stored.
     */
    public static function with_credential( array $headers, $auth ) {
        if ( ! is_array( $auth ) ) {
            throw new FMW_Step_Exception( 'config_error', 'HTTP request: auth must be an object { credential, scheme }.' );
        }
        $name   = (string) ( $auth['credential'] ?? '' );
        $scheme = (string) ( $auth['scheme'] ?? 'bearer' );
        if ( ! preg_match( self::CREDENTIAL_NAME_PATTERN, $name ) ) {
            throw new FMW_Step_Exception( 'config_error', 'HTTP request: auth.credential must be a credential name (lowercase letters, digits, underscores).' );
        }

        switch ( $scheme ) {
            case 'bearer':
                $header = 'Authorization';
                break;
            case 'basic':
                $header = 'Authorization';
                break;
            case 'header':
                $header = (string) ( $auth['header'] ?? '' );
                if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $header ) ) {
                    throw new FMW_Step_Exception( 'config_error', 'HTTP request: auth.scheme "header" needs auth.header, a header name such as X-API-Key.' );
                }
                break;
            default:
                throw new FMW_Step_Exception( 'config_error', sprintf( "HTTP request: auth.scheme must be bearer, header or basic (got '%s').", esc_html( $scheme ) ) );
        }

        $secret = FMW_Credential_Store::get( 'http_' . $name );
        if ( is_wp_error( $secret ) ) {
            throw new FMW_Step_Exception( 'credential_unreadable', sprintf( "HTTP request: the credential '%s' is stored but cannot be decrypted — usually the site's security keys changed. Store it again.", esc_html( $name ) ) );
        }
        if ( null === $secret || '' === $secret ) {
            throw new FMW_Step_Exception( 'credential_not_configured', sprintf( "HTTP request: no credential named '%s' is stored (credential key http_%s).", esc_html( $name ), esc_html( $name ) ) );
        }

        if ( 'bearer' === $scheme ) {
            $value = 'Bearer ' . $secret;
        } elseif ( 'basic' === $scheme ) {
            $value = 'Basic ' . base64_encode( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth encoding.
        } else {
            $value = $secret;
        }

        foreach ( array_keys( $headers ) as $existing ) {
            if ( 0 === strcasecmp( (string) $existing, $header ) ) {
                unset( $headers[ $existing ] );
            }
        }
        $headers[ $header ] = $value;
        return $headers;
    }

    /**
     * Check if a header is already set in a (possibly mixed-case) headers array.
     */
    private static function header_set( array $headers, $name ) {
        $name_lower = strtolower( $name );
        foreach ( $headers as $k => $_ ) {
            if ( strtolower( (string) $k ) === $name_lower ) {
                return true;
            }
        }
        return false;
    }
}
