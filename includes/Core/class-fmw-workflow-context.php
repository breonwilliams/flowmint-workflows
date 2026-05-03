<?php
/**
 * Workflow execution context.
 *
 * Carries all the runtime state during a workflow run: form data, entry
 * metadata, file attachments, step outputs, and ad-hoc variables set by
 * set_variable steps.
 *
 * Variables are scoped:
 *   data      - submitted form field values
 *   entry     - FE entry metadata
 *   form      - form metadata (id, title)
 *   workflow  - workflow metadata (id, title)
 *   run       - run metadata (id, started_at)
 *   steps     - per-step outputs, keyed by step name
 *   vars      - ad-hoc variables set during the run
 *   env       - safe environment values (whitelist)
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Context {

    /**
     * @var int Run ID
     */
    private $run_id;

    /**
     * @var int Entry ID
     */
    private $entry_id;

    /**
     * @var string Workflow ID
     */
    private $workflow_id;

    /**
     * @var string Form ID
     */
    private $form_id;

    /**
     * Submitted form data (sanitized field values).
     *
     * @var array
     */
    private $data = [];

    /**
     * FE entry metadata.
     *
     * @var array
     */
    private $entry = [];

    /**
     * Files attached to the entry, indexed by field_key.
     *
     * @var array
     */
    private $entry_files = [];

    /**
     * Form metadata (id, title).
     *
     * @var array
     */
    private $form = [];

    /**
     * Workflow metadata (id, title).
     *
     * @var array
     */
    private $workflow = [];

    /**
     * Run metadata (id, started_at).
     *
     * @var array
     */
    private $run = [];

    /**
     * Step outputs, keyed by step name.
     *
     * @var array<string, array>
     */
    private $steps = [];

    /**
     * Ad-hoc variables.
     *
     * @var array<string, mixed>
     */
    private $vars = [];

    /**
     * Safe environment values (whitelist).
     *
     * @var array<string, mixed>
     */
    private $env = [];

    /**
     * Constructor.
     *
     * @param int    $run_id
     * @param int    $entry_id
     * @param string $workflow_id
     * @param string $form_id
     */
    public function __construct( $run_id, $entry_id, $workflow_id, $form_id ) {
        $this->run_id      = (int) $run_id;
        $this->entry_id    = (int) $entry_id;
        $this->workflow_id = $workflow_id;
        $this->form_id     = $form_id;

        // Populate metadata sections.
        $this->run = [
            'id'         => $this->run_id,
            'started_at' => current_time( 'mysql' ),
        ];

        $this->workflow = [
            'id' => $workflow_id,
        ];

        $this->form = [
            'id' => $form_id,
        ];

        // Populate env with safe defaults.
        $this->env = [
            'site_name' => get_bloginfo( 'name' ),
            'site_url'  => home_url(),
            'admin_email' => get_option( 'admin_email' ),
        ];
    }

    /**
     * Set the submitted form data.
     *
     * @param array $data
     */
    public function set_data( array $data ) {
        $this->data = $data;
    }

    /**
     * Set the entry metadata + files from FRE_Entry record.
     *
     * @param array $entry_record FE entry as returned by FRE_Entry::get()
     */
    public function set_entry( array $entry_record ) {
        // Strip the fields/files arrays out — those become data and entry_files.
        $this->entry = [
            'id'         => isset( $entry_record['id'] ) ? (int) $entry_record['id'] : 0,
            'form_id'    => $entry_record['form_id'] ?? '',
            'status'     => $entry_record['status'] ?? '',
            'created_at' => $entry_record['created_at'] ?? '',
            'updated_at' => $entry_record['updated_at'] ?? '',
            'ip_address' => $entry_record['ip_address'] ?? '',
        ];

        // Files: FE provides as array of file rows. Normalize into a map by field_key.
        $this->entry_files = [];
        if ( ! empty( $entry_record['files'] ) && is_array( $entry_record['files'] ) ) {
            foreach ( $entry_record['files'] as $file_row ) {
                if ( ! is_array( $file_row ) ) {
                    continue;
                }
                $field_key = $file_row['field_key'] ?? null;
                if ( $field_key === null ) {
                    continue;
                }
                // For multi-file fields we keep an array; for single-file fields we keep one object.
                if ( isset( $this->entry_files[ $field_key ] ) ) {
                    if ( ! isset( $this->entry_files[ $field_key ][0] ) ) {
                        $this->entry_files[ $field_key ] = [ $this->entry_files[ $field_key ] ];
                    }
                    $this->entry_files[ $field_key ][] = $file_row;
                } else {
                    $this->entry_files[ $field_key ] = $file_row;
                }
            }
        }
    }

    /**
     * Set the workflow metadata.
     *
     * @param array $workflow_record
     */
    public function set_workflow_metadata( array $workflow_record ) {
        $this->workflow = [
            'id'    => $workflow_record['id'] ?? $this->workflow_id,
            'title' => $workflow_record['title'] ?? '',
        ];
    }

    /**
     * Set form metadata (called when form details are loaded from FE).
     *
     * @param array $form_record
     */
    public function set_form_metadata( array $form_record ) {
        $this->form = [
            'id'    => $form_record['id'] ?? $this->form_id,
            'title' => $form_record['title'] ?? '',
        ];
    }

    /**
     * Record a step's output.
     *
     * @param string $step_name
     * @param array  $output
     */
    public function set_step_output( $step_name, array $output ) {
        $this->steps[ $step_name ] = $output;
    }

    /**
     * Get a step's output.
     *
     * @param string $step_name
     * @return array|null
     */
    public function get_step_output( $step_name ) {
        return $this->steps[ $step_name ] ?? null;
    }

    /**
     * Set an ad-hoc variable.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function set_var( $name, $value ) {
        $this->vars[ $name ] = $value;
    }

    /**
     * Get an ad-hoc variable.
     *
     * @param string $name
     * @return mixed
     */
    public function get_var( $name ) {
        return $this->vars[ $name ] ?? null;
    }

    public function get_run_id() {
        return $this->run_id;
    }

    public function get_entry_id() {
        return $this->entry_id;
    }

    public function get_workflow_id() {
        return $this->workflow_id;
    }

    public function get_form_id() {
        return $this->form_id;
    }

    public function get_data() {
        return $this->data;
    }

    public function get_entry() {
        return $this->entry;
    }

    public function get_entry_files() {
        return $this->entry_files;
    }

    /**
     * Resolve a dot-notation path like "data.email" or "steps.customer.id" against
     * the context. Used by FMW_Interpolator.
     *
     * @param string $path
     * @return mixed|null Resolved value, or null if path doesn't resolve.
     */
    public function resolve_path( $path ) {
        $segments = explode( '.', $path );
        if ( empty( $segments ) ) {
            return null;
        }

        // Top-level scope.
        $root = array_shift( $segments );
        $current = null;
        switch ( $root ) {
            case 'data':        $current = $this->data; break;
            case 'entry':       $current = $this->entry; break;
            case 'entry_files': $current = $this->entry_files; break;
            case 'form':        $current = $this->form; break;
            case 'workflow':    $current = $this->workflow; break;
            case 'run':         $current = $this->run; break;
            case 'steps':       $current = $this->steps; break;
            case 'vars':        $current = $this->vars; break;
            case 'env':         $current = $this->env; break;
            default:
                return null;
        }

        foreach ( $segments as $segment ) {
            if ( is_array( $current ) ) {
                if ( array_key_exists( $segment, $current ) ) {
                    $current = $current[ $segment ];
                } else {
                    return null;
                }
            } elseif ( is_object( $current ) && isset( $current->{$segment} ) ) {
                $current = $current->{$segment};
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Snapshot the entire context as an associative array for run history.
     *
     * @return array
     */
    public function snapshot() {
        return [
            'run'         => $this->run,
            'workflow'    => $this->workflow,
            'form'        => $this->form,
            'entry'       => $this->entry,
            'entry_files' => $this->entry_files,
            'data'        => $this->data,
            'steps'       => $this->steps,
            'vars'        => $this->vars,
        ];
    }
}
