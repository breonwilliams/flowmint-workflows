<?php
/**
 * Email client.
 *
 * Thin wrapper over wp_mail with structured error capture and PII-safe logging.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Email_Client {

    /**
     * Send an email via wp_mail.
     *
     * @param array $args {
     *     @type string|array $to
     *     @type string       $from_name
     *     @type string       $from_email
     *     @type string       $reply_to
     *     @type string|array $cc
     *     @type string|array $bcc
     *     @type string       $subject
     *     @type string       $body
     *     @type bool         $is_html
     * }
     * @return array { sent, recipients }
     * @throws FMW_Step_Exception On send failure.
     */
    public static function send( array $args ) {
        $to       = $args['to'] ?? '';
        $subject  = $args['subject'] ?? '';
        $body     = $args['body'] ?? '';
        $is_html  = ! empty( $args['is_html'] );

        if ( empty( $to ) ) {
            throw new FMW_Step_Exception( 'config_error', 'Email send: missing to.' );
        }
        if ( empty( $subject ) ) {
            throw new FMW_Step_Exception( 'config_error', 'Email send: missing subject.' );
        }
        if ( empty( $body ) ) {
            throw new FMW_Step_Exception( 'config_error', 'Email send: missing body.' );
        }

        // Build headers.
        $headers = [];
        $content_type = $is_html ? 'text/html' : 'text/plain';
        $headers[] = "Content-Type: {$content_type}; charset=UTF-8";

        if ( ! empty( $args['from_email'] ) ) {
            $from = $args['from_email'];
            if ( ! empty( $args['from_name'] ) ) {
                $from = sprintf( '"%s" <%s>', str_replace( '"', '', $args['from_name'] ), $args['from_email'] );
            }
            $headers[] = "From: {$from}";
        }

        if ( ! empty( $args['reply_to'] ) ) {
            $headers[] = "Reply-To: {$args['reply_to']}";
        }

        if ( ! empty( $args['cc'] ) ) {
            $cc = is_array( $args['cc'] ) ? implode( ', ', $args['cc'] ) : $args['cc'];
            $headers[] = "Cc: {$cc}";
        }

        if ( ! empty( $args['bcc'] ) ) {
            $bcc = is_array( $args['bcc'] ) ? implode( ', ', $args['bcc'] ) : $args['bcc'];
            $headers[] = "Bcc: {$bcc}";
        }

        // Capture wp_mail errors.
        $captured_error = null;
        $error_listener = function ( $error ) use ( &$captured_error ) {
            $captured_error = $error;
        };
        add_action( 'wp_mail_failed', $error_listener );

        // Set Content-Type filter for HTML if needed.
        $content_type_filter = function () use ( $content_type ) {
            return $content_type;
        };
        add_filter( 'wp_mail_content_type', $content_type_filter );

        $sent = wp_mail( $to, $subject, $body, $headers );

        remove_filter( 'wp_mail_content_type', $content_type_filter );
        remove_action( 'wp_mail_failed', $error_listener );

        if ( ! $sent ) {
            $message = $captured_error ? $captured_error->get_error_message() : 'wp_mail returned false';
            throw new FMW_Step_Exception(
                'email_send_failed',
                "Failed to send email: {$message}"
            );
        }

        $recipients = is_array( $to ) ? $to : [ $to ];

        return [
            'sent'       => true,
            'recipients' => $recipients,
        ];
    }
}
