<?php
/**
 * Workflow value object.
 *
 * Wraps a workflow definition (loaded from DB) with convenience accessors.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow {

    /**
     * @var array DB row from wp_fmw_workflows
     */
    private $row;

    /**
     * @var array Decoded config JSON
     */
    private $config;

    /**
     * @param array $row DB row
     */
    public function __construct( array $row ) {
        $this->row = $row;
        $config_json = $row['config'] ?? '{}';
        $decoded = is_string( $config_json ) ? json_decode( $config_json, true ) : $config_json;
        $this->config = is_array( $decoded ) ? $decoded : [];
    }

    public function id() {
        return $this->row['id'] ?? '';
    }

    public function title() {
        return $this->row['title'] ?? '';
    }

    /**
     * Bound FormEngine form id.
     *
     * For form-triggered workflows this is the form whose
     * `fre_submission_complete` action runs this workflow. For
     * schedule-triggered workflows this column is NULL — the accessor
     * returns an empty string so existing callers that do truthy
     * checks (`if ( $w->form_id() ) { … }`) continue to behave
     * correctly without having to learn about scheduled workflows.
     *
     * @return string Form id, or '' for scheduled workflows.
     */
    public function form_id() {
        return $this->row['form_id'] ?? '';
    }

    /**
     * Trigger type for this workflow.
     *
     * Source-of-truth ordering:
     *   1. The denormalized `trigger_type` column (post-v0.2.0 schema).
     *   2. $config['trigger']['type'] from the JSON (in case the row
     *      was written by older code that didn't populate the column).
     *   3. 'form' default — matches the implicit pre-v0.6.0 behavior
     *      so legacy rows whose JSON has neither a trigger block nor
     *      a column value are still correctly classified.
     *
     * @return string One of 'form' or 'schedule'.
     */
    public function trigger_type() {
        if ( ! empty( $this->row['trigger_type'] ) ) {
            return (string) $this->row['trigger_type'];
        }
        if ( isset( $this->config['trigger']['type'] ) && is_string( $this->config['trigger']['type'] ) ) {
            return $this->config['trigger']['type'];
        }
        return 'form';
    }

    /**
     * Full trigger config block.
     *
     * For form-triggered workflows:
     *     [ 'type' => 'form', 'form_id' => '…' ]
     * For schedule-triggered workflows:
     *     [ 'type' => 'schedule', 'interval' => '…', 'hour' => …, 'minute' => …, 'day_of_week' => … ]
     *
     * Always returns a populated array. For legacy rows (pre-v0.6.0
     * JSON with no `trigger` block but a populated form_id column),
     * reconstructs the trigger block from the row data so callers
     * downstream don't have to special-case legacy rows.
     *
     * @return array
     */
    public function trigger_config() {
        if ( isset( $this->config['trigger'] ) && is_array( $this->config['trigger'] ) ) {
            return $this->config['trigger'];
        }

        // Legacy fallback — synthesize from row data.
        if ( $this->trigger_type() === 'form' ) {
            return [
                'type'    => 'form',
                'form_id' => $this->row['form_id'] ?? '',
            ];
        }

        // Trigger type is non-'form' but the JSON has no trigger block.
        // Shouldn't happen after validator normalization, but return a
        // safe shape so callers don't blow up on missing keys.
        return [ 'type' => $this->trigger_type() ];
    }

    /**
     * Schedule interval for schedule-triggered workflows.
     *
     * @return string|null One of 'hourly', 'twicedaily', 'daily', 'weekly',
     *                     or null if this isn't a scheduled workflow or no
     *                     interval is set.
     */
    public function schedule_interval() {
        if ( $this->trigger_type() !== 'schedule' ) {
            return null;
        }
        $trigger = $this->trigger_config();
        return isset( $trigger['interval'] ) ? (string) $trigger['interval'] : null;
    }

    /**
     * Schedule hour (0–23). Default 2 (2am site-local). Only meaningful
     * for `daily` and `weekly` intervals.
     *
     * @return int
     */
    public function schedule_hour() {
        $trigger = $this->trigger_config();
        if ( ! isset( $trigger['hour'] ) ) {
            return 2;
        }
        return max( 0, min( 23, (int) $trigger['hour'] ) );
    }

    /**
     * Schedule minute (0–59). Default 0. Used by `daily` and `weekly`
     * intervals; ignored by `hourly` and `twicedaily`.
     *
     * @return int
     */
    public function schedule_minute() {
        $trigger = $this->trigger_config();
        if ( ! isset( $trigger['minute'] ) ) {
            return 0;
        }
        return max( 0, min( 59, (int) $trigger['minute'] ) );
    }

    /**
     * Day of week for weekly schedules. ISO-style: 1=Monday … 7=Sunday.
     * Default 1 (Monday). Only meaningful for `weekly` interval.
     *
     * @return int
     */
    public function schedule_day_of_week() {
        $trigger = $this->trigger_config();
        if ( ! isset( $trigger['day_of_week'] ) ) {
            return 1;
        }
        return max( 1, min( 7, (int) $trigger['day_of_week'] ) );
    }

    public function is_enabled() {
        return ! empty( $this->row['enabled'] );
    }

    public function managed_by() {
        return $this->row['managed_by'] ?? 'admin';
    }

    public function connector_version() {
        return (int) ( $this->row['connector_version'] ?? 0 );
    }

    /**
     * Decoded config object.
     *
     * @return array
     */
    public function config() {
        return $this->config;
    }

    /**
     * Workflow JSON schema version (defaults to 1.0).
     *
     * @return string
     */
    public function schema_version() {
        return $this->config['version'] ?? '1.0';
    }

    /**
     * Workflow-level settings.
     *
     * @return array
     */
    public function settings() {
        return $this->config['settings'] ?? [];
    }

    /**
     * Workflow steps array.
     *
     * @return array
     */
    public function steps() {
        return $this->config['steps'] ?? [];
    }

    /**
     * Get the underlying DB row.
     *
     * @return array
     */
    public function row() {
        return $this->row;
    }
}
