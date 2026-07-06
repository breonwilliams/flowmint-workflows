<?php
/**
 * Failure notifier — pushes a notification when a workflow run
 * permanently fails (exhausts its retries).
 *
 * Closes the long-documented operational gap: before this class, a
 * failed run was only visible in the Run History admin page, so a
 * broken client workflow (Drive quota, Printavo schema change, SMTP
 * block — every gotcha in docs/TROUBLESHOOTING.md) could fail silently
 * for days.
 *
 * Channels, in order:
 *   1. Slack — incoming-webhook URL stored under the `slack_webhook`
 *      credential key (already provisioned in the connector credential
 *      surface + preflight; see docs/SETUP_SLACK.md).
 *   2. Email fallback — when no Slack webhook is configured, wp_mail()
 *      to the `notification_email` credential, falling back to the
 *      site admin_email. Every site gets SOME signal out of the box.
 *
 * Design constraints:
 *   - This listener must NEVER throw or otherwise affect the failure
 *     path it observes — everything is wrapped defensively and the
 *     Slack POST is non-blocking.
 *   - No per-run spam: fmw_workflow_run_failed only fires after max
 *     retries (see FMW_Workflow_Job::handle_failure()), so every event
 *     is a genuine, final failure worth a human's attention.
 *
 * Filters:
 *   - fmw_failure_notification_enabled( bool, $run_id, $workflow_id )
 *   - fmw_failure_notification_message( string, $run_id, $workflow_id, $entry_id, $error_code, $error_message )
 *
 * @package FlowMint_Workflows
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to fmw_workflow_run_failed and dispatches notifications.
 */
class FMW_Failure_Notifier {

	/**
	 * Wire the listener. Late priority so run-state bookkeeping listeners
	 * observe the failure before we report it.
	 */
	public function init() {
		add_action( 'fmw_workflow_run_failed', [ $this, 'on_run_failed' ], 100, 5 );
	}

	/**
	 * Handle a permanently-failed run.
	 *
	 * @param int    $run_id        Run row ID.
	 * @param string $workflow_id   Workflow identifier.
	 * @param int    $entry_id      FE entry ID (0 for scheduled runs).
	 * @param string $error_code    Machine error code from the failing step.
	 * @param string $error_message Human-readable error message.
	 */
	public function on_run_failed( $run_id, $workflow_id, $entry_id, $error_code, $error_message ) {
		try {
			/**
			 * Allow sites to suppress failure notifications (e.g. on staging).
			 *
			 * @param bool   $enabled     Default true.
			 * @param int    $run_id      Run ID.
			 * @param string $workflow_id Workflow ID.
			 */
			if ( ! apply_filters( 'fmw_failure_notification_enabled', true, $run_id, $workflow_id ) ) {
				return;
			}

			$message = $this->build_message( $run_id, $workflow_id, $entry_id, $error_code, $error_message );

			/**
			 * Filter the outgoing notification text (both channels).
			 *
			 * @param string $message       Plain-text message.
			 * @param int    $run_id        Run ID.
			 * @param string $workflow_id   Workflow ID.
			 * @param int    $entry_id      Entry ID (0 for scheduled runs).
			 * @param string $error_code    Error code.
			 * @param string $error_message Error message.
			 */
			$message = apply_filters( 'fmw_failure_notification_message', $message, $run_id, $workflow_id, $entry_id, $error_code, $error_message );

			if ( $this->send_slack( $message ) ) {
				return;
			}

			$this->send_email( $workflow_id, $message );
		} catch ( \Throwable $t ) {
			// A notifier must never break the failure path it observes.
			if ( class_exists( 'FMW_Logger' ) ) {
				FMW_Logger::warning( 'Failure notifier threw', [ 'error' => $t->getMessage() ] );
			}
		}
	}

	/**
	 * Compose the plain-text notification.
	 *
	 * @param int    $run_id        Run ID.
	 * @param string $workflow_id   Workflow ID.
	 * @param int    $entry_id      Entry ID.
	 * @param string $error_code    Error code.
	 * @param string $error_message Error message.
	 * @return string
	 */
	private function build_message( $run_id, $workflow_id, $entry_id, $error_code, $error_message ) {
		$workflow_name = $workflow_id;
		if ( class_exists( 'FMW_Workflow_Repository' ) ) {
			$workflow = FMW_Workflow_Repository::get( $workflow_id );
			if ( is_array( $workflow ) && ! empty( $workflow['name'] ) ) {
				$workflow_name = $workflow['name'];
			}
		}

		$run_url = admin_url( 'admin.php?page=fmw-runs&run_id=' . (int) $run_id );

		$lines   = [];
		$lines[] = sprintf(
			/* translators: 1: workflow name, 2: site name */
			__( 'FlowMint workflow FAILED after all retries: "%1$s" on %2$s', 'flowmint-workflows' ),
			$workflow_name,
			get_bloginfo( 'name' )
		);
		$lines[] = sprintf( __( 'Error: [%1$s] %2$s', 'flowmint-workflows' ), $error_code, $error_message );
		if ( (int) $entry_id > 0 ) {
			$lines[] = sprintf( __( 'Form entry: #%d', 'flowmint-workflows' ), (int) $entry_id );
		} else {
			$lines[] = __( 'Trigger: scheduled run (no form entry)', 'flowmint-workflows' );
		}
		$lines[] = sprintf( __( 'Inspect + replay: %s', 'flowmint-workflows' ), $run_url );

		return implode( "\n", $lines );
	}

	/**
	 * Post to the configured Slack incoming webhook, if any.
	 *
	 * Non-blocking: we neither wait for nor act on Slack's response —
	 * the run's failure state is already persisted, and a notification
	 * hiccup must not cascade.
	 *
	 * @param string $message Plain-text message.
	 * @return bool Whether a webhook was configured and the POST dispatched.
	 */
	private function send_slack( $message ) {
		if ( ! class_exists( 'FMW_Credential_Store' ) || ! FMW_Credential_Store::is_configured( 'slack_webhook' ) ) {
			return false;
		}

		$webhook = FMW_Credential_Store::get( 'slack_webhook' );
		if ( ! is_string( $webhook ) || strpos( $webhook, 'https://' ) !== 0 ) {
			return false;
		}

		wp_remote_post(
			$webhook,
			[
				'blocking' => false,
				'timeout'  => 3,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => wp_json_encode( [ 'text' => $message ] ),
			]
		);

		if ( class_exists( 'FMW_Logger' ) ) {
			FMW_Logger::info( 'Failure notification dispatched to Slack' );
		}

		return true;
	}

	/**
	 * Email fallback when no Slack webhook is configured.
	 *
	 * @param string $workflow_id Workflow ID (for the subject line).
	 * @param string $message     Plain-text message body.
	 */
	private function send_email( $workflow_id, $message ) {
		$to = '';
		if ( class_exists( 'FMW_Credential_Store' ) && FMW_Credential_Store::is_configured( 'notification_email' ) ) {
			$to = (string) FMW_Credential_Store::get( 'notification_email' );
		}
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: workflow id, 2: site name */
			__( '[FlowMint] Workflow failed: %1$s — %2$s', 'flowmint-workflows' ),
			$workflow_id,
			get_bloginfo( 'name' )
		);

		wp_mail( $to, $subject, $message );

		if ( class_exists( 'FMW_Logger' ) ) {
			FMW_Logger::info( 'Failure notification dispatched via email', [ 'to' => $to ] );
		}
	}
}
