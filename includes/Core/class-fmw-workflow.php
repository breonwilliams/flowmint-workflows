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

    public function form_id() {
        return $this->row['form_id'] ?? '';
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
