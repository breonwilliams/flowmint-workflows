<?php
/**
 * Tell Promptless Forms' Entries screen what this plugin did with the entry.
 *
 * When a workflow sends the team email, the sensible setup is to switch the
 * form's own notification off so the team gets one email instead of two. Forms'
 * Entries screen then has nothing of its own to report, and its Email column
 * said so in a way that reads as a failure. A real site owner saw it against a
 * genuine lead and concluded submissions were reaching nobody; the email had
 * gone out, from here.
 *
 * Forms exposes `pforms_entry_notification_status` for exactly this: whatever
 * actually sent the email can say so. Forms does not know this plugin exists,
 * and the dependency stays one-way — we read its documented filter and answer
 * with our own record.
 *
 * What we report is our own claim, and Forms renders it as such: attributed to
 * FlowMint, never as Forms' own "Sent" tick.
 *
 * WE FILL A GAP; WE DO NOT TALK OVER FORMS. If Forms has its own record for an
 * entry — it sent the notification, or tried and failed — that record stands
 * and we stay quiet. 0.12.0 claimed the column whenever a run had emailed
 * anyone, which on a form whose own notification is ON replaced a true "Forms
 * emailed your team" tick with a link to a run whose only email was the
 * customer's auto-reply. Seen live on 725 Print Lab's contact form the day
 * 0.12.0 shipped.
 *
 * @package FlowMintWorkflows\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reports a run's email outcome to Promptless Forms' Entries screen.
 */
class FMW_Entry_Notification_Status {

    /**
     * Step types that send an email to the team.
     *
     * A run that sends none of these has nothing to say about the Email
     * column, and we leave Forms' own status alone.
     */
    const EMAIL_STEP_TYPES = [ 'send_email', 'send_email_template' ];

    /**
     * What we already looked up this request, keyed by entry id.
     *
     * The Entries screen renders 20+ rows at a time.
     *
     * @var array<int, array|null>
     */
    private static $cache = [];

    /**
     * Hook in.
     *
     * Harmless on a Forms version that has no such filter: nothing calls it.
     *
     * @return void
     */
    public function init() {
        add_filter( 'pforms_entry_notification_status', [ $this, 'report' ], 10, 2 );
    }

    /**
     * Answer for one entry.
     *
     * @param array $status Forms' own status for the entry.
     * @param array $entry  The entry row.
     * @return array
     */
    public function report( $status, $entry ) {
        $entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

        if ( $entry_id <= 0 ) {
            return $status;
        }

        // Forms already knows what happened to its own notification. Its
        // record is the more direct fact and it stays; anything our run did
        // is visible in Run History. We speak only when Forms is silent
        // ('off' or 'not_sent'), which is the setup this exists for.
        $forms_own = isset( $status['state'] ) ? (string) $status['state'] : '';

        if ( in_array( $forms_own, [ 'sent', 'failed' ], true ) ) {
            return $status;
        }

        $run = $this->run_for_entry( $entry_id );

        if ( null === $run ) {
            return $status;
        }

        $url = admin_url( 'admin.php?page=fmw-runs&run_id=' . (int) $run['id'] );

        switch ( $run['state'] ) {
            case 'sent':
                return [
                    'state'       => 'external',
                    'label'       => __( 'Sent by workflow', 'flowmint-workflows' ),
                    'description' => __( 'A FlowMint workflow sent the team email for this submission.', 'flowmint-workflows' ),
                    'source'      => __( 'FlowMint Workflows', 'flowmint-workflows' ),
                    'url'         => $url,
                ];

            case 'failed':
                // The case worth shouting about: the form stored the entry,
                // and the workflow that was supposed to email the team did
                // not finish. Without this the screen says nothing at all.
                return [
                    'state'       => 'external',
                    'label'       => __( 'Workflow failed', 'flowmint-workflows' ),
                    'description' => __( 'The FlowMint workflow for this submission failed, so the team email may never have gone out. Open the run to see which step failed, and replay it.', 'flowmint-workflows' ),
                    'source'      => __( 'FlowMint Workflows', 'flowmint-workflows' ),
                    'url'         => $url,
                ];

            case 'pending':
                return [
                    'state'       => 'external',
                    'label'       => __( 'Workflow running', 'flowmint-workflows' ),
                    'description' => __( 'The FlowMint workflow for this submission has not finished yet.', 'flowmint-workflows' ),
                    'source'      => __( 'FlowMint Workflows', 'flowmint-workflows' ),
                    'url'         => $url,
                ];
        }

        return $status;
    }

    /**
     * The newest run for an entry, reduced to what the column needs.
     *
     * @param int $entry_id Entry id.
     * @return array|null { id, state } — state is sent|failed|pending — or
     *                    null when we have nothing to say.
     */
    private function run_for_entry( $entry_id ) {
        if ( array_key_exists( $entry_id, self::$cache ) ) {
            return self::$cache[ $entry_id ];
        }

        self::$cache[ $entry_id ] = null;

        if ( ! class_exists( 'FMW_Run_Repository' ) ) {
            return null;
        }

        // list() returns newest first, so one row is the latest run.
        $runs = FMW_Run_Repository::list( [ 'entry_id' => $entry_id, 'per_page' => 1 ] );
        $rows = isset( $runs['items'] ) && is_array( $runs['items'] ) ? $runs['items'] : [];
        $run  = ! empty( $rows ) ? reset( $rows ) : null;

        if ( ! $run ) {
            return null;
        }

        $run    = (array) $run;
        $run_id = isset( $run['id'] ) ? (int) $run['id'] : 0;
        $state  = isset( $run['status'] ) ? (string) $run['status'] : '';

        if ( $run_id <= 0 ) {
            return null;
        }

        if ( 'failed' === $state ) {
            // Only claim a failure when this workflow was going to email
            // anyone. A workflow that only files things in Drive failing is
            // not something the Email column should speak for.
            $result = $this->sends_email( $run_id ) ? [ 'id' => $run_id, 'state' => 'failed' ] : null;
        } elseif ( in_array( $state, [ 'queued', 'running' ], true ) ) {
            $result = $this->sends_email( $run_id ) ? [ 'id' => $run_id, 'state' => 'pending' ] : null;
        } elseif ( 'completed' === $state ) {
            $result = $this->email_step_succeeded( $run_id ) ? [ 'id' => $run_id, 'state' => 'sent' ] : null;
        } else {
            $result = null;
        }

        self::$cache[ $entry_id ] = $result;

        return $result;
    }

    /**
     * Did an email step of this run actually succeed?
     *
     * @param int $run_id Run id.
     * @return bool
     */
    private function email_step_succeeded( $run_id ) {
        foreach ( $this->email_steps( $run_id ) as $step ) {
            if ( 'success' === ( isset( $step['status'] ) ? $step['status'] : '' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this run have an email step at all, whatever its outcome?
     *
     * @param int $run_id Run id.
     * @return bool
     */
    private function sends_email( $run_id ) {
        return ! empty( $this->email_steps( $run_id ) );
    }

    /**
     * The run's email steps.
     *
     * @param int $run_id Run id.
     * @return array[]
     */
    private function email_steps( $run_id ) {
        if ( ! class_exists( 'FMW_Run_Step_Repository' ) ) {
            return [];
        }

        $steps = FMW_Run_Step_Repository::list_for_run( $run_id );

        if ( ! is_array( $steps ) ) {
            return [];
        }

        $email = [];

        foreach ( $steps as $step ) {
            $step = (array) $step;
            if ( in_array( isset( $step['step_type'] ) ? $step['step_type'] : '', self::EMAIL_STEP_TYPES, true ) ) {
                $email[] = $step;
            }
        }

        return $email;
    }

    /**
     * Forget what we looked up. For tests.
     *
     * @return void
     */
    public static function flush_cache() {
        self::$cache = [];
    }
}
