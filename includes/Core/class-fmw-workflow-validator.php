<?php
/**
 * Workflow JSON schema validator.
 *
 * Used on workflow create/update to enforce the contract specified in
 * docs/STEP_LIBRARY.md. Validates:
 *
 *   - Top-level structure (steps array exists, settings is object, etc.)
 *   - Each step has name, type, config
 *   - Step types are registered
 *   - Step names are unique within the workflow
 *   - on_error values are valid
 *   - form_id exists in FormEngine
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Validator {

    /**
     * Allowed values for on_error.
     */
    private static $allowed_on_error = [ 'fail', 'continue', 'retry' ];

    /**
     * Validate a workflow definition (already-decoded array).
     *
     * @param array $config The decoded config object.
     * @return array {
     *     @type bool  $valid
     *     @type array $errors  list of error messages
     *     @type array $warnings list of non-fatal warnings
     * }
     */
    public static function validate( array $config ) {
        $errors   = [];
        $warnings = [];

        // 1. Required: steps array.
        if ( ! isset( $config['steps'] ) || ! is_array( $config['steps'] ) ) {
            $errors[] = 'Missing required field: steps (must be an array).';
            return self::result( $errors, $warnings );
        }

        // 2. Optional: settings object.
        if ( isset( $config['settings'] ) && ! is_array( $config['settings'] ) ) {
            $errors[] = 'settings must be an object.';
        }

        // 3. Validate each step.
        $registry  = FMW_Step_Registry::instance();
        $seen_names = [];
        $has_delete_entry_at = null;
        $file_step_after_delete = null;

        foreach ( $config['steps'] as $idx => $step ) {
            $prefix = "Step #{$idx}";

            if ( ! is_array( $step ) ) {
                $errors[] = "{$prefix} must be an object.";
                continue;
            }

            // Required: name + type.
            if ( empty( $step['name'] ) || ! is_string( $step['name'] ) ) {
                $errors[] = "{$prefix}: missing or invalid 'name' (must be a non-empty string).";
                continue;
            }
            if ( empty( $step['type'] ) || ! is_string( $step['type'] ) ) {
                $errors[] = "{$prefix} ({$step['name']}): missing or invalid 'type'.";
                continue;
            }

            $name = $step['name'];
            $type = $step['type'];

            // Name uniqueness.
            if ( in_array( $name, $seen_names, true ) ) {
                $errors[] = "Duplicate step name: '{$name}'. Names must be unique within a workflow.";
            }
            $seen_names[] = $name;

            // Type must be registered.
            if ( ! $registry->exists( $type ) ) {
                $errors[] = "{$prefix} ({$name}): unknown step type '{$type}'.";
                continue;
            }

            // on_error is valid.
            if ( isset( $step['on_error'] ) && ! in_array( $step['on_error'], self::$allowed_on_error, true ) ) {
                $errors[] = "{$prefix} ({$name}): invalid on_error '{$step['on_error']}'. Must be one of: " . implode( ', ', self::$allowed_on_error );
            }

            // config must be an object (or omitted, defaulting to {}).
            if ( isset( $step['config'] ) && ! is_array( $step['config'] ) ) {
                $errors[] = "{$prefix} ({$name}): 'config' must be an object.";
            }

            // Track delete_entry placement for ordering check.
            if ( $type === 'fre_delete_entry' ) {
                $has_delete_entry_at = $idx;
            }

            // Warn if a file-reading step appears AFTER fre_delete_entry.
            if ( $has_delete_entry_at !== null && in_array( $type, [ 'drive_upload_file', 'fre_get_file' ], true ) ) {
                $file_step_after_delete = $name;
            }
        }

        if ( $file_step_after_delete !== null ) {
            $warnings[] = "Step '{$file_step_after_delete}' reads files but appears AFTER fre_delete_entry. The entry's files will be deleted before this step runs.";
        }

        // 4. Validate form_id exists in FormEngine (if provided in the wrapper, handled by repo).
        // Note: form_id validation is done in the connector layer, not here, since
        // this validator only sees the config — not the wrapper {id, form_id, config}.

        return self::result( $errors, $warnings );
    }

    /**
     * Validate a workflow + verify form_id exists in FormEngine.
     *
     * @param array $workflow_data Full workflow data including form_id and config.
     * @return array
     */
    public static function validate_full( array $workflow_data ) {
        $errors   = [];
        $warnings = [];

        // Check id format.
        if ( ! empty( $workflow_data['id'] ) ) {
            if ( ! preg_match( '/^[a-z0-9\-_]+$/', $workflow_data['id'] ) ) {
                $errors[] = "Invalid workflow id: must match ^[a-z0-9\\-_]+\\$.";
            }
        }

        // form_id must exist in FormEngine.
        if ( ! empty( $workflow_data['form_id'] ) ) {
            if ( function_exists( 'fre' ) && fre()->registry ) {
                if ( ! fre()->registry->exists( $workflow_data['form_id'] ) ) {
                    // It might be a DB-stored form rather than registered. Check forms manager.
                    $db_form = function_exists( 'fre_get_db_form' ) ? fre_get_db_form( $workflow_data['form_id'] ) : null;
                    if ( ! $db_form ) {
                        $errors[] = "form_id '{$workflow_data['form_id']}' does not exist in FormEngine.";
                    }
                }
            } else {
                $warnings[] = 'FormEngine not loaded — could not verify form_id existence.';
            }
        }

        // Validate config structure.
        if ( isset( $workflow_data['config'] ) ) {
            $config = $workflow_data['config'];
            if ( is_string( $config ) ) {
                $decoded = json_decode( $config, true );
                if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
                    $errors[] = 'config is not valid JSON: ' . json_last_error_msg();
                } else {
                    $config = $decoded;
                }
            }

            if ( is_array( $config ) ) {
                $config_validation = self::validate( $config );
                $errors   = array_merge( $errors, $config_validation['errors'] );
                $warnings = array_merge( $warnings, $config_validation['warnings'] );
            } else {
                $errors[] = 'config must be a JSON object/array.';
            }
        }

        return self::result( $errors, $warnings );
    }

    /**
     * Build the validation result.
     *
     * @param array $errors
     * @param array $warnings
     * @return array
     */
    private static function result( array $errors, array $warnings ) {
        return [
            'valid'    => empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }
}
