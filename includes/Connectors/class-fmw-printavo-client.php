<?php
/**
 * Printavo GraphQL client.
 *
 * Wraps Printavo's GraphQL API at https://www.printavo.com/api/v2.
 * Uses FMW_Http_Client under the hood — no SDK dependency.
 *
 * Auth: Printavo's GraphQL accepts an "email" + "token" pair as headers
 * (email = the API user's email, token = the API token from Printavo
 * settings). Both are stored together in the credential JSON for portability.
 *
 * Credential format:
 *   {"email": "user@example.com", "token": "abc123..."}
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Printavo_Client {

    const API_URL = 'https://www.printavo.com/api/v2';

    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $token;

    /**
     * @param string $email API user email
     * @param string $token API token
     */
    public function __construct( $email, $token ) {
        $this->email = $email;
        $this->token = $token;
    }

    /**
     * Construct from configured credential.
     *
     * Credential value is JSON: {"email": "...", "token": "..."}
     *
     * @return self
     * @throws FMW_Step_Exception
     */
    public static function from_credentials() {
        $raw = FMW_Credential_Store::get( 'printavo_api_token' );
        if ( empty( $raw ) ) {
            throw new FMW_Step_Exception(
                'credential_not_configured',
                'Printavo credential is not configured. Set via /credentials/printavo_api_token with JSON {"email": "...", "token": "..."}'
            );
        }

        $config = json_decode( $raw, true );
        if ( ! is_array( $config ) || empty( $config['email'] ) || empty( $config['token'] ) ) {
            throw new FMW_Step_Exception(
                'config_error',
                'Printavo credential JSON must include "email" and "token" fields.'
            );
        }

        return new self( $config['email'], $config['token'] );
    }

    /**
     * Execute a GraphQL query.
     *
     * @param string $query     The GraphQL query string
     * @param array  $variables Variables map
     * @return array The "data" portion of the response
     * @throws FMW_Step_Exception
     */
    public function query( $query, array $variables = [] ) {
        $response = FMW_Http_Client::request( [
            'url'     => self::API_URL,
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                // Accept: application/json is REQUIRED on Printavo's API. Without
                // it, Printavo's web router falls back to the HTML-rendering Rails
                // app, which has no route for POST /api/v2 and crashes with a 500
                // HTML page (instead of returning a JSON GraphQL error).
                // Empirically verified 2026-05-03: same URL + same email/token,
                // request without Accept returns 500 HTML, request WITH Accept
                // returns 200 JSON. Browsers happen to send Accept by default;
                // wp_remote_request does not.
                'Accept'       => 'application/json',
                'email'        => $this->email,
                'token'        => $this->token,
            ],
            // PHP's empty array `[]` encodes to JSON `[]`, but GraphQL servers
            // (Printavo included) expect `variables` to be an OBJECT (`{}`).
            // An empty array crashes Printavo's API with a 500. Casting to
            // (object) ensures the empty case becomes `{}` while non-empty
            // associative arrays serialize the same as before. Verified
            // empirically on 2026-05-03: `"variables":[]` → 500, `"variables":{}` → 200.
            'body' => [
                'query'     => $query,
                'variables' => (object) $variables,
            ],
            'body_format'     => 'json',
            'timeout_seconds' => 30,
            'accept_non_2xx'  => true, // We handle GraphQL errors ourselves.
        ] );

        $status = $response['status'];
        $body   = $response['body'];

        if ( $status === 401 || $status === 403 ) {
            throw new FMW_Step_Exception(
                'auth_failed',
                'Printavo authentication failed. Check the API email + token.'
            );
        }

        if ( $status === 429 ) {
            throw new FMW_Step_Exception(
                'rate_limited',
                'Printavo rate limit exceeded.',
                [ 'retry_after' => 30 ]
            );
        }

        if ( $status >= 500 ) {
            throw new FMW_Step_Exception(
                'external_5xx',
                "Printavo returned {$status}. " . self::body_excerpt( $body )
            );
        }

        if ( $status >= 400 ) {
            throw new FMW_Step_Exception(
                'external_4xx',
                "Printavo returned {$status}. " . self::body_excerpt( $body )
            );
        }

        // GraphQL responses are 200 even for query errors. Check the body.
        if ( ! is_array( $body ) ) {
            throw new FMW_Step_Exception(
                'unexpected',
                'Printavo returned non-JSON body: ' . self::body_excerpt( $body )
            );
        }

        if ( ! empty( $body['errors'] ) ) {
            $messages = array_map(
                function( $e ) { return $e['message'] ?? 'unknown error'; },
                $body['errors']
            );
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo GraphQL errors: ' . implode( '; ', $messages ),
                [ 'errors' => $body['errors'] ]
            );
        }

        return $body['data'] ?? [];
    }

    /**
     * Find a customer by email.
     *
     * @param string $email
     * @return array|null Customer data, or null if not found.
     */
    public function find_customer_by_email( $email ) {
        // Note: Printavo's GraphQL schema may evolve. This query targets
        // common fields. Adjust if Printavo changes the schema.
        $query = <<<'GQL'
query FindCustomerByEmail($email: String!) {
  contacts(query: $email, first: 5) {
    nodes {
      id
      email
      firstName
      lastName
      phone
      companyName
    }
  }
}
GQL;

        $data = $this->query( $query, [ 'email' => $email ] );

        $nodes = $data['contacts']['nodes'] ?? [];
        foreach ( $nodes as $contact ) {
            if ( strcasecmp( (string) ( $contact['email'] ?? '' ), $email ) === 0 ) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Create a customer.
     *
     * @param array $args { email, first_name, last_name, phone, company_name }
     * @return array
     */
    public function create_customer( array $args ) {
        $query = <<<'GQL'
mutation CreateContact($input: ContactCreateInput!) {
  contactCreate(input: $input) {
    contact {
      id
      email
      firstName
      lastName
      phone
      companyName
    }
    errors {
      field
      message
    }
  }
}
GQL;

        $input = [
            'email' => $args['email'] ?? '',
        ];
        if ( ! empty( $args['first_name'] ) )   $input['firstName']   = $args['first_name'];
        if ( ! empty( $args['last_name'] ) )    $input['lastName']    = $args['last_name'];
        if ( ! empty( $args['phone'] ) )        $input['phone']       = $args['phone'];
        if ( ! empty( $args['company_name'] ) ) $input['companyName'] = $args['company_name'];

        $data = $this->query( $query, [ 'input' => $input ] );

        $result = $data['contactCreate'] ?? [];
        if ( ! empty( $result['errors'] ) ) {
            $messages = array_map( function( $e ) { return ( $e['field'] ?? '' ) . ': ' . ( $e['message'] ?? '' ); }, $result['errors'] );
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo customer create errors: ' . implode( '; ', $messages )
            );
        }

        return $result['contact'] ?? [];
    }

    /**
     * Find or create a customer by email.
     *
     * @param array $args
     * @return array { ...customer fields, was_created }
     */
    public function find_or_create_customer( array $args ) {
        $email = $args['email'] ?? '';
        if ( empty( $email ) ) {
            throw new FMW_Step_Exception( 'config_error', 'Printavo find_or_create_customer: email is required.' );
        }

        $existing = $this->find_customer_by_email( $email );
        if ( $existing ) {
            $existing['was_created'] = false;
            return $existing;
        }

        $created = $this->create_customer( $args );
        $created['was_created'] = true;
        return $created;
    }

    /**
     * Create a Quote (Invoice in Printavo's GraphQL schema).
     *
     * @param array $args { customer_id, user_id, invoice_status_id, nickname, description, ... }
     * @return array { id, visual_id, url, created_at }
     */
    public function create_quote( array $args ) {
        $query = <<<'GQL'
mutation CreateInvoice($input: InvoiceCreateInput!) {
  invoiceCreate(input: $input) {
    invoice {
      id
      visualId
      customerNote
      productionNote
      total
      createdAt
    }
    errors {
      field
      message
    }
  }
}
GQL;

        $input = [];
        if ( ! empty( $args['customer_id'] ) )       $input['contactId']       = $args['customer_id'];
        if ( ! empty( $args['user_id'] ) )           $input['ownerId']         = (string) $args['user_id'];
        if ( ! empty( $args['invoice_status_id'] ) ) $input['statusId']        = (string) $args['invoice_status_id'];
        if ( ! empty( $args['nickname'] ) )          $input['nickname']        = $args['nickname'];
        if ( ! empty( $args['description'] ) )       $input['customerNote']    = $args['description'];
        if ( ! empty( $args['production_note'] ) )   $input['productionNote']  = $args['production_note'];
        if ( ! empty( $args['customer_due_date'] ) ) $input['customerDueAt']   = $args['customer_due_date'];

        $data = $this->query( $query, [ 'input' => $input ] );

        $result = $data['invoiceCreate'] ?? [];
        if ( ! empty( $result['errors'] ) ) {
            $messages = array_map( function( $e ) { return ( $e['field'] ?? '' ) . ': ' . ( $e['message'] ?? '' ); }, $result['errors'] );
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo Quote create errors: ' . implode( '; ', $messages )
            );
        }

        $invoice = $result['invoice'] ?? [];
        return [
            'id'         => $invoice['id'] ?? '',
            'visual_id'  => $invoice['visualId'] ?? '',
            'nickname'   => $invoice['nickname'] ?? ( $args['nickname'] ?? '' ),
            'created_at' => $invoice['createdAt'] ?? '',
            'url'        => isset( $invoice['visualId'] ) ? "https://www.printavo.com/invoices/{$invoice['visualId']}" : '',
        ];
    }

    /**
     * Test connectivity by fetching the current account info.
     *
     * @return array
     */
    public function test() {
        // Printavo's `Account` type (verified 2026-05-03) exposes `id`,
        // `companyName`, and `companyEmail` — NOT `name` or `contactEmail`,
        // which were the names in earlier API revisions. Keep this query
        // narrow so a future schema migration only forces a one-field edit
        // instead of refactoring callers.
        $query = <<<'GQL'
query MyAccount {
  account {
    id
    companyName
    companyEmail
  }
}
GQL;

        $data = $this->query( $query );
        $account = $data['account'] ?? [];

        return [
            'account_id'    => $account['id'] ?? null,
            'account_name'  => $account['companyName'] ?? null,
            'contact_email' => $account['companyEmail'] ?? null,
        ];
    }

    /**
     * Get a body excerpt for error messages.
     */
    private static function body_excerpt( $body ) {
        if ( is_array( $body ) ) {
            return substr( wp_json_encode( $body ), 0, 200 );
        }
        return is_string( $body ) ? substr( $body, 0, 200 ) : '';
    }
}
