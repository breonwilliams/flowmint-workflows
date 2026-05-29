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
     * Allowed values for trigger.interval (v0.6.0+).
     *
     * Enum kept deliberately small. Full cron expressions are out of
     * scope until concrete demand surfaces — see
     * docs/DESIGN_SCHEDULED_TRIGGERS.md §12.3 for the rationale.
     */
    private static $allowed_schedule_intervals = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

    /**
     * Allowed top-level trigger types.
     */
    private static $allowed_trigger_types = [ 'form', 'schedule' ];

    /**
     * Normalize a workflow config to the canonical v0.6.0 shape.
     *
     * Pre-v0.6.0 configs had `form_id` at the top level and no
     * `trigger` block. This method converts that legacy shape into
     * the canonical v0.6.0 shape:
     *
     *     { "trigger": { "type": "form", "form_id": "…" }, … }
     *
     * Operates on a copy — caller's array is not mutated. Idempotent:
     * a config already in the v0.6.0 shape (with a trigger block) is
     * returned unchanged.
     *
     * Configs that have neither a trigger block nor a top-level
     * form_id are returned unchanged; downstream validation will
     * surface the missing-trigger error.
     *
     * @param array $config Raw config (may be legacy or v0.6.0 shape).
     * @return array Normalized config.
     */
    public static function normalize( array $config ) {
        if ( isset( $config['trigger'] ) && is_array( $config['trigger'] ) ) {
            return $config;
        }

        if ( isset( $config['form_id'] ) && is_string( $config['form_id'] ) && $config['form_id'] !== '' ) {
            $config['trigger'] = [
                'type'    => 'form',
                'form_id' => $config['form_id'],
            ];
        }

        return $config;
    }

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

        // Normalize legacy → v0.6.0 shape on a local copy. The caller's
        // array is not mutated; we only operate on the normalized view.
        $config = self::normalize( $config );

        // 1. Required: steps array.
        if ( ! isset( $config['steps'] ) || ! is_array( $config['steps'] ) ) {
            $errors[] = 'Missing required field: steps (must be an array).';
            return self::result( $errors, $warnings );
        }

        // 2. Optional: settings object.
        if ( isset( $config['settings'] ) && ! is_array( $config['settings'] ) ) {
            $errors[] = 'settings must be an object.';
        }

        // 2b. Required (post-v0.6.0): trigger block.
        //
        // After normalize(), the only way trigger can be missing is if
        // the config has neither a `trigger` block NOR a legacy top-level
        // `form_id`. That's a bona fide validation error — every workflow
        // must declare what fires it.
        if ( ! isset( $config['trigger'] ) || ! is_array( $config['trigger'] ) ) {
            $errors[] = "Missing required field: trigger. Every workflow must declare a trigger block — { type: 'form', form_id: '…' } or { type: 'schedule', interval: '…' }.";
        } else {
            $trigger_errors = self::validate_trigger_block( $config['trigger'] );
            $errors         = array_merge( $errors, $trigger_errors );
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

        // 4. Scheduled-workflow sanity check: warn (not error) if any
        // step interpolates `data.*`, `entry.*`, or `entry_files.*` —
        // scheduled runs have no FE entry context, so those references
        // silently resolve to empty string at runtime. Per
        // docs/DESIGN_SCHEDULED_TRIGGERS.md §11.3 (Risk mitigation).
        if ( isset( $config['trigger']['type'] ) && $config['trigger']['type'] === 'schedule' ) {
            $entry_ref_warnings = self::check_for_entry_refs_in_scheduled_workflow( $config );
            $warnings           = array_merge( $warnings, $entry_ref_warnings );
        }

        // 5. Validate form_id exists in FormEngine (if provided in the wrapper, handled by validate_full).
        // Note: this validator only sees the config — not the wrapper {id, form_id, config}.

        return self::result( $errors, $warnings );
    }

    /**
     * Validate a single trigger block.
     *
     * @param array $trigger Decoded trigger block.
     * @return string[] Array of error messages (empty if valid).
     */
    private static function validate_trigger_block( array $trigger ) {
        $errors = [];

        if ( ! isset( $trigger['type'] ) || ! is_string( $trigger['type'] ) ) {
            $errors[] = "trigger: missing or invalid 'type' (must be a string, one of: " . implode( ', ', self::$allowed_trigger_types ) . ').';
            return $errors;
        }

        $type = $trigger['type'];

        if ( ! in_array( $type, self::$allowed_trigger_types, true ) ) {
            $errors[] = "trigger.type: unknown value '{$type}'. Supported types: " . implode( ', ', self::$allowed_trigger_types ) . '.';
            return $errors;
        }

        if ( $type === 'form' ) {
            if ( empty( $trigger['form_id'] ) || ! is_string( $trigger['form_id'] ) ) {
                $errors[] = "trigger: form-triggered workflows require a non-empty trigger.form_id (string).";
            }
        }

        if ( $type === 'schedule' ) {
            if ( empty( $trigger['interval'] ) || ! is_string( $trigger['interval'] ) ) {
                $errors[] = "trigger: schedule-triggered workflows require trigger.interval. Allowed: " . implode( ', ', self::$allowed_schedule_intervals ) . '.';
            } elseif ( ! in_array( $trigger['interval'], self::$allowed_schedule_intervals, true ) ) {
                $errors[] = "trigger.interval: unknown value '{$trigger['interval']}'. Allowed: " . implode( ', ', self::$allowed_schedule_intervals ) . '.';
            }

            if ( isset( $trigger['hour'] ) ) {
                if ( ! is_numeric( $trigger['hour'] ) || (int) $trigger['hour'] < 0 || (int) $trigger['hour'] > 23 ) {
                    $errors[] = "trigger.hour: must be an integer in [0, 23].";
                }
            }

            if ( isset( $trigger['minute'] ) ) {
                if ( ! is_numeric( $trigger['minute'] ) || (int) $trigger['minute'] < 0 || (int) $trigger['minute'] > 59 ) {
                    $errors[] = "trigger.minute: must be an integer in [0, 59].";
                }
            }

            if ( isset( $trigger['day_of_week'] ) ) {
                if ( ! is_numeric( $trigger['day_of_week'] ) || (int) $trigger['day_of_week'] < 1 || (int) $trigger['day_of_week'] > 7 ) {
                    $errors[] = "trigger.day_of_week: must be an integer in [1, 7] (1 = Monday, 7 = Sunday — ISO 8601).";
                }
            }
        }

        return $errors;
    }

    /**
     * Scan a scheduled workflow's step configs for references to entry
     * data that won't exist at runtime.
     *
     * Per docs/DESIGN_SCHEDULED_TRIGGERS.md §11.3, scheduled runs have
     * no FE entry context. The interpolator silently resolves missing
     * variables to empty string, so a typo like `{{ data.email }}` in
     * a scheduled workflow would NOT surface as an error — it would
     * just produce surprising blank output. We flag these at validate
     * time so the author catches it before the workflow runs.
     *
     * @param array $config Normalized config.
     * @return string[] Array of warning messages (empty if clean).
     */
    private static function check_for_entry_refs_in_scheduled_workflow( array $config ) {
        $warnings = [];

        if ( ! isset( $config['steps'] ) || ! is_array( $config['steps'] ) ) {
            return $warnings;
        }

        // Matches {{ data.* }}, {{ entry.* }}, {{ entry_files.* }} —
        // with optional whitespace around the braces.
        $pattern = '/\{\{\s*(data|entry|entry_files)\.[^}]+\}\}/';

        foreach ( $config['steps'] as $idx => $step ) {
            if ( ! is_array( $step ) ) {
                continue;
            }

            $step_name = $step['name'] ?? "#{$idx}";

            // Serialize the step's config + on_error/skip_if so we
            // catch references anywhere they could legitimately
            // appear in a step definition.
            $haystack = wp_json_encode( [
                'config'  => $step['config'] ?? null,
                'skip_if' => $step['skip_if'] ?? null,
            ] );

            if ( $haystack && preg_match( $pattern, $haystack, $matches ) ) {
                $warnings[] = "Step '{$step_name}' references `{$matches[1]}.*` — scheduled workflows have no form entry context, so this will resolve to an empty string at runtime. Use `vars.*`, `env.*`, or previous-step outputs (`steps.*`) instead.";
            }
        }

        return $warnings;
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

        // Decode config if it arrived as a JSON string.
        $config = $workflow_data['config'] ?? null;
        if ( is_string( $config ) ) {
            $decoded = json_decode( $config, true );
            if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
                $errors[] = 'config is not valid JSON: ' . json_last_error_msg();
                return self::result( $errors, $warnings );
            }
            $config = $decoded;
        }

        if ( ! is_array( $config ) ) {
            $errors[] = 'config must be a JSON object/array.';
            return self::result( $errors, $warnings );
        }

        // Wrapper-level convenience fields propagate INTO config when
        // the inner config has no trigger block. The precedence is:
        //
        //   1. Inner config['trigger']  — explicit, wins.
        //   2. Wrapper $workflow_data['trigger']  — new v0.6.0 REST shape.
        //   3. Wrapper $workflow_data['form_id']  — legacy REST shape;
        //      synthesizes form-triggered.
        //
        // Keeps the existing REST contract working AND lets new clients
        // put trigger alongside id/title rather than burying it inside
        // a stringified config JSON.
        if ( ! isset( $config['trigger'] ) ) {
            if ( ! empty( $workflow_data['trigger'] ) && is_array( $workflow_data['trigger'] ) ) {
                $config['trigger'] = $workflow_data['trigger'];
            } elseif ( empty( $config['form_id'] ) && ! empty( $workflow_data['form_id'] ) ) {
                $config['form_id'] = $workflow_data['form_id'];
            }
        }

        $config = self::normalize( $config );

        // Validate the (now-normalized) config — this is where trigger
        // block validation, step validation, and the data/entry warning
        // all happen.
        $config_validation = self::validate( $config );
        $errors            = array_merge( $errors, $config_validation['errors'] );
        $warnings          = array_merge( $warnings, $config_validation['warnings'] );

        // For form-triggered workflows: ensure the bound form actually
        // exists in FormEngine. For schedule-triggered workflows: skip
        // this check entirely — they have no form binding.
        $trigger_type = $config['trigger']['type'] ?? 'form';

        if ( $trigger_type === 'form' ) {
            $form_id_to_check = $config['trigger']['form_id']
                ?? $workflow_data['form_id']
                ?? '';

            if ( ! empty( $form_id_to_check ) ) {
                if ( function_exists( 'fre' ) && pforms()->registry ) {
                    if ( ! pforms()->registry->exists( $form_id_to_check ) ) {
                        // It might be a DB-stored form rather than registered. Check forms manager.
                        $db_form = function_exists( 'pforms_get_db_form' ) ? pforms_get_db_form( $form_id_to_check ) : null;
                        if ( ! $db_form ) {
                            $errors[] = "form_id '{$form_id_to_check}' does not exist in FormEngine.";
                        }
                    }
                } else {
                    $warnings[] = 'FormEngine not loaded — could not verify form_id existence.';
                }
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
