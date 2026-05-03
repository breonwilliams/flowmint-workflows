<?php
/**
 * Exception thrown by step execute() methods when something goes wrong.
 *
 * Carries an error code that the executor uses to decide retry policy:
 *   - external_4xx: NOT retried (client error, retry won't help)
 *   - external_5xx: retried (might be transient)
 *   - rate_limited: retried with extra delay
 *   - auth_failed: NOT retried (credential needs rotation)
 *   - timeout: retried
 *   - validation_failed: NOT retried (input is wrong)
 *   - unexpected: retried (unknown failure)
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Exception extends Exception {

    /**
     * Error code (string identifier).
     *
     * @var string
     */
    private $error_code;

    /**
     * Additional context.
     *
     * @var array
     */
    private $context;

    /**
     * @param string $error_code
     * @param string $message
     * @param array  $context
     */
    public function __construct( $error_code, $message, array $context = [] ) {
        parent::__construct( $message );
        $this->error_code = $error_code;
        $this->context    = $context;
    }

    public function get_error_code() {
        return $this->error_code;
    }

    public function get_context() {
        return $this->context;
    }

    /**
     * Should this error trigger a retry?
     *
     * @return bool
     */
    public function is_retryable() {
        $non_retryable = [
            'external_4xx',
            'auth_failed',
            'validation_failed',
            'config_error',
            'permission_denied',
            'credential_not_configured', // Credential won't appear on retry.
            'dependency_missing',        // Library/extension absence won't fix itself.
            'file_not_found',            // File on disk won't reappear.
            'file_not_readable',
            'template_not_found',
            'invalid_input',
        ];
        return ! in_array( $this->error_code, $non_retryable, true );
    }
}
