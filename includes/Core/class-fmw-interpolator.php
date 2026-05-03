<?php
/**
 * Variable interpolator.
 *
 * Substitutes {{ ... }} placeholders in step config values against the
 * workflow context. Supports:
 *
 *   {{ data.email }}                       — context path
 *   {{ steps.customer.id }}                — nested context path
 *   {{ data.company || data.full_name }}   — fallback (first truthy)
 *   {{ data.email | upper }}               — filters (Phase 5+)
 *   {{ now('Y-m') }}                       — function call
 *   {{ template('name') }}                 — template render
 *
 * Recursively interpolates entire arrays of config values.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Interpolator {

    /**
     * @var FMW_Workflow_Context
     */
    private $context;

    /**
     * Names that emitted warnings during this interpolation pass.
     *
     * @var array
     */
    private $missing_paths = [];

    /**
     * @param FMW_Workflow_Context $context
     */
    public function __construct( FMW_Workflow_Context $context ) {
        $this->context = $context;
    }

    /**
     * Recursively interpolate any value (string, array, scalar).
     *
     * @param mixed $value
     * @return mixed
     */
    public function interpolate( $value ) {
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $out[ $k ] = $this->interpolate( $v );
            }
            return $out;
        }

        if ( is_string( $value ) ) {
            return $this->interpolate_string( $value );
        }

        return $value;
    }

    /**
     * Interpolate a string. Returns the original type if the entire string is
     * a single {{ expr }} resolving to a non-string scalar/array — otherwise
     * returns a string.
     *
     * Examples:
     *   "{{ data.email }}"             → string (the email)
     *   "Hi {{ data.full_name }}"      → string
     *   "{{ steps.customer }}"         → array (the whole customer object)
     *   "{{ data.tax_exempt }}"        → bool/int/whatever data.tax_exempt was
     *
     * @param string $str
     * @return mixed
     */
    public function interpolate_string( $str ) {
        // Special case: entire string is a single {{ expr }} — preserve the
        // resolved value's native type instead of stringifying.
        $whole_match_pattern = '/^\s*\{\{\s*(.*?)\s*\}\}\s*$/s';
        if ( preg_match( $whole_match_pattern, $str, $m ) ) {
            return $this->resolve_expression( $m[1] );
        }

        // Otherwise, replace each {{ expr }} occurrence with stringified value.
        return preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            function ( $matches ) {
                $value = $this->resolve_expression( $matches[1] );
                return $this->stringify( $value );
            },
            $str
        );
    }

    /**
     * Resolve an expression like "data.email" or "data.company || data.full_name".
     *
     * @param string $expr
     * @return mixed
     */
    public function resolve_expression( $expr ) {
        $expr = trim( $expr );

        // Function calls: name(args)
        if ( preg_match( '/^([a-z_][a-z0-9_]*)\s*\((.*)\)\s*$/i', $expr, $m ) ) {
            return $this->resolve_function( $m[1], $m[2] );
        }

        // Logical OR (fallback): a || b || c
        // Note: simple parser, doesn't handle parens in left/right operands.
        if ( strpos( $expr, '||' ) !== false ) {
            $parts = array_map( 'trim', explode( '||', $expr ) );
            foreach ( $parts as $part ) {
                $value = $this->resolve_expression( $part );
                if ( ! $this->is_falsy( $value ) ) {
                    return $value;
                }
            }
            return ''; // All operands falsy.
        }

        // String literal: 'foo' or "foo"
        if ( preg_match( '/^[\'"](.*)[\'"]$/s', $expr, $m ) ) {
            return $m[1];
        }

        // Numeric literal
        if ( is_numeric( $expr ) ) {
            return strpos( $expr, '.' ) !== false ? (float) $expr : (int) $expr;
        }

        // Boolean / null literals
        if ( $expr === 'true' )  return true;
        if ( $expr === 'false' ) return false;
        if ( $expr === 'null' )  return null;

        // Path resolution against context.
        $value = $this->context->resolve_path( $expr );
        if ( $value === null ) {
            $this->missing_paths[] = $expr;
            return ''; // Missing → empty string.
        }
        return $value;
    }

    /**
     * Resolve a function call.
     *
     * @param string $name
     * @param string $args_string Raw arguments string
     * @return mixed
     */
    private function resolve_function( $name, $args_string ) {
        $args = $this->parse_args( $args_string );

        switch ( $name ) {
            case 'now':
                $format = $args[0] ?? 'Y-m-d H:i:s';
                return current_time( $format );

            case 'template':
                $template_name = $args[0] ?? '';
                return $this->render_template( $template_name );

            case 'has_file':
                // has_file(entry, 'field_key')
                $field_key = $args[1] ?? '';
                $files     = $this->context->get_entry_files();
                return ! empty( $files[ $field_key ] );

            case 'is_empty':
                $value = $args[0] ?? null;
                return $this->is_falsy( $value );

            case 'length':
                $value = $args[0] ?? null;
                if ( is_array( $value ) ) return count( $value );
                if ( is_string( $value ) ) return strlen( $value );
                return 0;

            case 'contains':
                $haystack = $args[0] ?? '';
                $needle   = $args[1] ?? '';
                if ( is_array( $haystack ) ) return in_array( $needle, $haystack, false );
                if ( is_string( $haystack ) ) return $needle !== '' && strpos( $haystack, $needle ) !== false;
                return false;

            case 'equals_ci':
                return strcasecmp( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) ) === 0;

            default:
                FMW_Logger::warning( 'Unknown function in interpolation', [ 'name' => $name ] );
                return '';
        }
    }

    /**
     * Parse a function argument string into an array of resolved values.
     *
     * Handles: literals (quoted strings, numbers, booleans), context paths,
     * and other comma-separated values. Doesn't handle nested function calls
     * or complex expressions in arguments yet.
     *
     * @param string $args_string
     * @return array
     */
    private function parse_args( $args_string ) {
        $args_string = trim( $args_string );
        if ( $args_string === '' ) {
            return [];
        }

        // Simple split by comma at depth 0. Doesn't handle nested commas.
        // Sufficient for v1 use cases like has_file(entry, 'design_file').
        $parts = [];
        $depth = 0;
        $in_string = false;
        $string_char = '';
        $current = '';
        for ( $i = 0; $i < strlen( $args_string ); $i++ ) {
            $c = $args_string[ $i ];
            if ( $in_string ) {
                if ( $c === $string_char ) {
                    $in_string = false;
                }
                $current .= $c;
            } elseif ( $c === '"' || $c === "'" ) {
                $in_string = true;
                $string_char = $c;
                $current .= $c;
            } elseif ( $c === '(' ) {
                $depth++;
                $current .= $c;
            } elseif ( $c === ')' ) {
                $depth--;
                $current .= $c;
            } elseif ( $c === ',' && $depth === 0 ) {
                $parts[] = trim( $current );
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if ( $current !== '' ) {
            $parts[] = trim( $current );
        }

        // Resolve each argument.
        return array_map( [ $this, 'resolve_expression' ], $parts );
    }

    /**
     * Render a named template. Templates live at wp-content/uploads/fmw-templates/<name>.txt or .html.
     * The template's content goes through interpolate_string() before returning.
     *
     * @param string $name
     * @return string
     */
    private function render_template( $name ) {
        // Sanitize template name.
        $name = preg_replace( '/[^a-z0-9\-_]/', '', strtolower( $name ) );
        if ( $name === '' ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        $base       = trailingslashit( $upload_dir['basedir'] ) . 'fmw-templates/';

        foreach ( [ '.html', '.txt' ] as $ext ) {
            $path = $base . $name . $ext;
            if ( file_exists( $path ) && is_readable( $path ) ) {
                $contents = file_get_contents( $path );
                if ( $contents !== false ) {
                    return $this->interpolate_string( $contents );
                }
            }
        }

        FMW_Logger::warning( 'Template not found', [ 'name' => $name, 'searched' => $base ] );
        return '';
    }

    /**
     * Stringify a resolved value for inline-string interpolation.
     *
     * @param mixed $value
     * @return string
     */
    private function stringify( $value ) {
        if ( is_string( $value ) ) return $value;
        if ( is_bool( $value ) )   return $value ? 'true' : 'false';
        if ( is_null( $value ) )   return '';
        if ( is_scalar( $value ) ) return (string) $value;
        if ( is_array( $value ) )  return implode( ', ', array_map( [ $this, 'stringify' ], $value ) );
        return '';
    }

    /**
     * Check if a value is falsy in the workflow sense.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_falsy( $value ) {
        if ( $value === null )  return true;
        if ( $value === '' )    return true;
        if ( $value === 0 )     return true;
        if ( $value === '0' )   return true;
        if ( $value === false ) return true;
        if ( is_array( $value ) && empty( $value ) ) return true;
        return false;
    }

    /**
     * Get paths that didn't resolve during interpolation.
     *
     * @return array
     */
    public function get_missing_paths() {
        return $this->missing_paths;
    }
}
