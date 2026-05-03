<?php
/**
 * Step: send_email
 *
 * Sends a plain-text or HTML email via wp_mail.
 *
 * Class is named FMW_Step_Email_Send (Phase 2 organization) but the public
 * step type identifier is `send_email` (per docs/STEP_LIBRARY.md).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Email_Send extends FMW_Step_Base {

    public static function type(): string { return 'send_email'; }
    public static function display_name(): string { return 'Email: Send'; }
    public static function category(): string { return 'Email'; }
    public static function description(): string { return 'Sends a plain-text or HTML email via wp_mail. Variables interpolated in body, subject, etc.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'to', 'subject', 'body' ],
            'properties' => [
                'to'         => [ 'description' => 'Recipient(s). String or array.' ],
                'from_name'  => [ 'type' => 'string' ],
                'from_email' => [ 'type' => 'string' ],
                'reply_to'   => [ 'type' => 'string' ],
                'cc'         => [ 'description' => 'Optional CC. String or array.' ],
                'bcc'        => [ 'description' => 'Optional BCC. String or array.' ],
                'subject'    => [ 'type' => 'string' ],
                'body'       => [ 'type' => 'string' ],
                'is_html'    => [ 'type' => 'boolean', 'default' => false ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'sent'       => [ 'type' => 'boolean' ],
                'recipients' => [ 'type' => 'array' ],
                'deduplicated' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $args = [
            'to'         => $this->config['to'] ?? '',
            'from_name'  => $this->config['from_name'] ?? '',
            'from_email' => $this->config['from_email'] ?? '',
            'reply_to'   => $this->config['reply_to'] ?? '',
            'cc'         => $this->config['cc'] ?? '',
            'bcc'        => $this->config['bcc'] ?? '',
            'subject'    => $this->config['subject'] ?? '',
            'body'       => $this->config['body'] ?? '',
            'is_html'    => ! empty( $this->config['is_html'] ),
        ];

        // Idempotency: dedupe via SHA256(run_id + recipient + subject) for 1 hour.
        // This protects against retries sending duplicate emails for the same logical send.
        $to_hash      = is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'];
        $dedupe_key   = 'fmw_email_sent:' . hash( 'sha256', $context->get_run_id() . '|' . $to_hash . '|' . $args['subject'] );

        if ( get_transient( $dedupe_key ) ) {
            FMW_Logger::info( 'Email send deduplicated', [
                'run_id'     => $context->get_run_id(),
                'subject'    => $args['subject'],
                'dedupe_key' => $dedupe_key,
            ] );
            return [
                'sent'         => true,
                'recipients'   => is_array( $args['to'] ) ? $args['to'] : [ $args['to'] ],
                'deduplicated' => true,
            ];
        }

        $result = FMW_Email_Client::send( $args );

        // Mark this send as completed for the dedup window.
        set_transient( $dedupe_key, 1, HOUR_IN_SECONDS );

        $result['deduplicated'] = false;
        return $result;
    }
}
