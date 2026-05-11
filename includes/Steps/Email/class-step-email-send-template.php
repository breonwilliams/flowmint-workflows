<?php
/**
 * Step: send_email_template
 *
 * Like send_email but loads body from a template file. Templates live at
 * wp-content/uploads/fmw-templates/<name>.html or .txt and support variable
 * interpolation.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Email_Send_Template extends FMW_Step_Base {

    public static function type(): string { return 'send_email_template'; }
    public static function display_name(): string { return 'Email: Send (template)'; }
    public static function category(): string { return 'Email'; }
    public static function description(): string { return 'Sends an email using a named template file from wp-content/uploads/fmw-templates/. .html templates send as HTML; .txt as plain text. Variables interpolated.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'to', 'subject', 'template' ],
            'properties' => [
                'to'         => [ 'description' => 'Recipient(s).' ],
                'from_name'  => [ 'type' => 'string' ],
                'from_email' => [ 'type' => 'string' ],
                'reply_to'   => [ 'type' => 'string' ],
                'subject'    => [ 'type' => 'string' ],
                'template'   => [
                    'type'        => 'string',
                    'description' => 'Template name (without .html/.txt suffix). File must exist in wp-content/uploads/fmw-templates/.',
                ],
                'is_html'    => [ 'type' => 'boolean', 'description' => 'Override; otherwise inferred from template extension.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'sent'         => [ 'type' => 'boolean' ],
                'recipients'   => [ 'type' => 'array' ],
                'template'     => [ 'type' => 'string' ],
                'deduplicated' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $template_name = (string) ( $this->config['template'] ?? '' );
        if ( $template_name === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'send_email_template: template is required.' );
        }

        // Sanitize.
        $template_name = preg_replace( '/[^a-z0-9\-_]/', '', strtolower( $template_name ) );

        $upload_dir   = wp_upload_dir();
        $template_dir = trailingslashit( $upload_dir['basedir'] ) . 'fmw-templates/';

        // Resolve template file. Prefer .html if both exist.
        $body         = null;
        $is_html      = false;
        $resolved_ext = null;
        foreach ( [ '.html', '.txt' ] as $ext ) {
            $path = $template_dir . $template_name . $ext;
            if ( file_exists( $path ) && is_readable( $path ) ) {
                $contents = file_get_contents( $path );
                if ( $contents !== false ) {
                    $body         = $contents;
                    $is_html      = ( $ext === '.html' );
                    $resolved_ext = $ext;
                    break;
                }
            }
        }

        if ( $body === null ) {
            throw new FMW_Step_Exception(
                'template_not_found',
                sprintf(
                    "send_email_template: template '%s' not found at %s%s.html or .txt",
                    esc_html( $template_name ),
                    esc_html( $template_dir ),
                    esc_html( $template_name )
                )
            );
        }

        // Interpolate variables in the template.
        $interp = new FMW_Interpolator( $context );
        $body   = $interp->interpolate_string( $body );

        // Allow explicit is_html override.
        if ( isset( $this->config['is_html'] ) ) {
            $is_html = (bool) $this->config['is_html'];
        }

        $args = [
            'to'         => $this->config['to'] ?? '',
            'from_name'  => $this->config['from_name'] ?? '',
            'from_email' => $this->config['from_email'] ?? '',
            'reply_to'   => $this->config['reply_to'] ?? '',
            'subject'    => $this->config['subject'] ?? '',
            'body'       => $body,
            'is_html'    => $is_html,
        ];

        // Dedup (same as send_email).
        $to_hash    = is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'];
        $dedupe_key = 'fmw_email_sent:' . hash( 'sha256', $context->get_run_id() . '|' . $to_hash . '|' . $args['subject'] );

        if ( get_transient( $dedupe_key ) ) {
            return [
                'sent'         => true,
                'recipients'   => is_array( $args['to'] ) ? $args['to'] : [ $args['to'] ],
                'template'     => $template_name,
                'deduplicated' => true,
            ];
        }

        $result = FMW_Email_Client::send( $args );
        set_transient( $dedupe_key, 1, HOUR_IN_SECONDS );

        $result['template']     = $template_name;
        $result['deduplicated'] = false;
        return $result;
    }
}
