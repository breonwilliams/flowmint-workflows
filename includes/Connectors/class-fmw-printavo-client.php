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
     * Distinguishes three states (per credential-store audit item I2):
     *   - null         → never configured        → credential_not_configured
     *   - WP_Error     → present but unreadable  → credential_unreadable
     *   - non-empty    → continue to JSON-parse
     *
     * @return self
     * @throws FMW_Step_Exception
     */
    public static function from_credentials() {
        $raw = FMW_Credential_Store::get( 'printavo_api_token' );

        if ( is_wp_error( $raw ) ) {
            throw new FMW_Step_Exception(
                'credential_unreadable',
                'Printavo credential is stored but could not be decrypted: ' . esc_html( $raw->get_error_message() )
            );
        }

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
                sprintf( 'Printavo returned %d. %s', (int) $status, esc_html( self::body_excerpt( $body ) ) )
            );
        }

        if ( $status >= 400 ) {
            throw new FMW_Step_Exception(
                'external_4xx',
                sprintf( 'Printavo returned %d. %s', (int) $status, esc_html( self::body_excerpt( $body ) ) )
            );
        }

        // GraphQL responses are 200 even for query errors. Check the body.
        if ( ! is_array( $body ) ) {
            throw new FMW_Step_Exception(
                'unexpected',
                'Printavo returned non-JSON body: ' . esc_html( self::body_excerpt( $body ) )
            );
        }

        if ( ! empty( $body['errors'] ) ) {
            $messages = array_map(
                function( $e ) { return $e['message'] ?? 'unknown error'; },
                $body['errors']
            );
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo GraphQL errors: ' . esc_html( implode( '; ', $messages ) ),
                // GraphQL errors array is structured data used by catch handlers for
                // diagnostics — never echoed directly. esc_html() doesn't apply to
                // arrays; consumers that surface specific fields must escape on display.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                [ 'errors' => $body['errors'] ]
            );
        }

        return $body['data'] ?? [];
    }

    /**
     * Find a customer (Customer + primary Contact) by email.
     *
     * Printavo's data model splits Customer (the company) from Contact (the
     * person). Quotes attach to a Contact, and the Contact links back to a
     * Customer. We search Contacts by email and traverse to Customer to
     * surface companyName for the workflow.
     *
     * @param string $email
     * @return array|null { contact_id, customer_id, company_name, email, first_name, last_name, phone, full_name } or null
     */
    public function find_customer_by_email( $email ) {
        $query = <<<'GQL'
query FindContactByEmail($email: String!) {
  contacts(query: $email, first: 5) {
    nodes {
      id
      email
      firstName
      lastName
      fullName
      phone
      customer {
        id
        companyName
      }
    }
  }
}
GQL;

        $data = $this->query( $query, [ 'email' => $email ] );

        $nodes = $data['contacts']['nodes'] ?? [];
        foreach ( $nodes as $contact ) {
            if ( strcasecmp( (string) ( $contact['email'] ?? '' ), $email ) === 0 ) {
                return self::shape_customer_result( $contact );
            }
        }

        return null;
    }

    /**
     * Create a Customer with an inline primary Contact.
     *
     * Maps onto Printavo's `customerCreate(input: CustomerCreateInput!)`
     * mutation. CustomerCreateInput requires a `primaryContact: ContactInput`
     * — so a single call creates the Customer (the company) AND its first
     * Contact (the person). This matches the form-submission shape: one
     * customer wanting to do business with us, one human contacting us.
     *
     * @param array $args { email, first_name, last_name, phone, company_name }
     * @return array Same shape as find_customer_by_email + 'was_created' = true
     */
    public function create_customer( array $args ) {
        $primary_contact = self::build_contact_input( $args );

        $input = [
            'primaryContact' => $primary_contact,
        ];
        if ( ! empty( $args['company_name'] ) ) {
            $input['companyName'] = (string) $args['company_name'];
        }

        $query = <<<'GQL'
mutation CreateCustomer($input: CustomerCreateInput!) {
  customerCreate(input: $input) {
    id
    companyName
    primaryContact {
      id
      email
      firstName
      lastName
      fullName
      phone
    }
  }
}
GQL;

        $data = $this->query( $query, [ 'input' => $input ] );
        $customer = $data['customerCreate'] ?? [];

        if ( empty( $customer['id'] ) ) {
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo customerCreate did not return a Customer id.'
            );
        }

        // Build a unified shape from the Customer + nested primaryContact.
        $contact = $customer['primaryContact'] ?? [];
        return self::shape_customer_result( [
            'id'         => $contact['id'] ?? '',
            'email'      => $contact['email'] ?? '',
            'firstName'  => $contact['firstName'] ?? '',
            'lastName'   => $contact['lastName'] ?? '',
            'fullName'   => $contact['fullName'] ?? '',
            'phone'      => $contact['phone'] ?? '',
            'customer'   => [
                'id'          => $customer['id'],
                'companyName' => $customer['companyName'] ?? '',
            ],
        ] );
    }

    /**
     * Find or create a customer by email.
     *
     * @param array $args
     * @return array { ...shape_customer_result fields, was_created }
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
     * Create a Quote.
     *
     * Maps onto Printavo's `quoteCreate(input: QuoteCreateInput!)` mutation.
     * NOTE: the schema's required fields are `contact: IDInput!`,
     * `customerDueAt: ISO8601Date!`, `dueAt: ISO8601DateTime!`. The Quote
     * attaches to a Contact (a person), not a Customer (a company) —
     * Printavo derives the Customer from the Contact's relationship.
     *
     * @param array $args {
     *     contact_id:        ID of the Contact to attach the Quote to (REQUIRED).
     *     customer_due_date: ISO8601 date (YYYY-MM-DD). Defaults to +14 days
     *                        if omitted so the required field is always satisfied.
     *     due_at:            ISO8601 datetime. Defaults to +30 days at 17:00 UTC.
     *     nickname:          Quote nickname (visible in Printavo UI).
     *     description:       Long-form text, stored as customerNote on the Quote.
     *     production_note:   Internal-only note, stored as productionNote.
     *     user_id:           Printavo User ID (sales rep / owner).
     * }
     * @return array { id, visual_id, nickname, description, url, public_url, customer_due_at, due_at }
     */
    public function create_quote( array $args ) {
        if ( empty( $args['contact_id'] ) ) {
            throw new FMW_Step_Exception(
                'invalid_input',
                'Printavo create_quote: contact_id is required (use printavo_find_or_create_customer to get one).'
            );
        }

        $input = [
            'contact' => [ 'id' => (string) $args['contact_id'] ],
        ];

        // Defaults for the two required date fields. Both are mandatory per
        // the schema, so we always provide them — falling back to sensible
        // defaults rather than letting the API 400 the request.
        $input['customerDueAt'] = ! empty( $args['customer_due_date'] )
            ? (string) $args['customer_due_date']
            : gmdate( 'Y-m-d', strtotime( '+14 days' ) );

        $input['dueAt'] = ! empty( $args['due_at'] )
            ? (string) $args['due_at']
            : gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days 17:00 UTC' ) );

        if ( ! empty( $args['nickname'] ) )        $input['nickname']        = (string) $args['nickname'];
        if ( ! empty( $args['description'] ) )     $input['customerNote']    = (string) $args['description'];
        if ( ! empty( $args['production_note'] ) ) $input['productionNote']  = (string) $args['production_note'];
        if ( ! empty( $args['user_id'] ) )         $input['owner']           = [ 'id' => (string) $args['user_id'] ];

        $query = <<<'GQL'
mutation CreateQuote($input: QuoteCreateInput!) {
  quoteCreate(input: $input) {
    id
    visualId
    nickname
    customerNote
    productionNote
    customerDueAt
    dueAt
    url
    publicUrl
  }
}
GQL;

        $data = $this->query( $query, [ 'input' => $input ] );
        $quote = $data['quoteCreate'] ?? [];

        if ( empty( $quote['id'] ) ) {
            throw new FMW_Step_Exception(
                'external_4xx',
                'Printavo quoteCreate did not return a Quote id.'
            );
        }

        return [
            'id'              => $quote['id'] ?? '',
            'visual_id'       => $quote['visualId'] ?? '',
            'nickname'        => $quote['nickname'] ?? ( $args['nickname'] ?? '' ),
            'description'     => $quote['customerNote'] ?? '',
            'url'             => $quote['url'] ?? '',
            'public_url'      => $quote['publicUrl'] ?? '',
            'customer_due_at' => $quote['customerDueAt'] ?? '',
            'due_at'          => $quote['dueAt'] ?? '',
        ];
    }

    /**
     * Build a `ContactInput` map from generic args. Splits a `name` arg into
     * `first_name` / `last_name` if those weren't supplied explicitly.
     *
     * @param array $args
     * @return array
     */
    private static function build_contact_input( array $args ) {
        $first = (string) ( $args['first_name'] ?? '' );
        $last  = (string) ( $args['last_name']  ?? '' );

        if ( $first === '' && $last === '' && ! empty( $args['name'] ) ) {
            list( $first, $last ) = self::split_name( (string) $args['name'] );
        }

        $contact = [];
        if ( ! empty( $args['email'] ) ) $contact['email']     = (string) $args['email'];
        if ( $first !== '' )             $contact['firstName'] = $first;
        if ( $last !== '' )              $contact['lastName']  = $last;
        if ( ! empty( $args['phone'] ) ) $contact['phone']     = (string) $args['phone'];

        return $contact;
    }

    /**
     * Split a "First Last" string into [ first, last ]. Single-token names
     * become [ name, '' ]. Multi-token splits at the first whitespace.
     *
     * @param string $full
     * @return array{0:string,1:string}
     */
    private static function split_name( $full ) {
        $full = trim( (string) $full );
        if ( $full === '' ) return [ '', '' ];
        $parts = preg_split( '/\s+/', $full, 2 );
        return [ $parts[0], $parts[1] ?? '' ];
    }

    /**
     * Build the unified customer result shape from a Contact-with-customer node.
     *
     * @param array $contact { id, email, firstName, lastName, fullName, phone, customer: { id, companyName } }
     * @return array
     */
    private static function shape_customer_result( array $contact ) {
        $customer = $contact['customer'] ?? [];
        return [
            // Both IDs surfaced explicitly: callers (steps, workflows) can use
            // contact_id when creating a Quote, customer_id when needing to
            // reference the company entity directly.
            'contact_id'   => (string) ( $contact['id'] ?? '' ),
            'customer_id'  => (string) ( $customer['id'] ?? '' ),
            'email'        => (string) ( $contact['email'] ?? '' ),
            'first_name'   => (string) ( $contact['firstName'] ?? '' ),
            'last_name'    => (string) ( $contact['lastName'] ?? '' ),
            'full_name'    => (string) ( $contact['fullName'] ?? '' ),
            'phone'        => (string) ( $contact['phone'] ?? '' ),
            'company_name' => (string) ( $customer['companyName'] ?? '' ),
            // Legacy alias: old workflow JSONs reference {{ steps.<x>.id }} —
            // map it to contact_id since that's what create_quote needs.
            'id'           => (string) ( $contact['id'] ?? '' ),
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
