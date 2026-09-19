<?php
/**
 * Boolean expression evaluator.
 *
 * Used by `conditional` and `try_catch` step types and `skip_if` clauses to
 * evaluate truthy/falsy conditions like:
 *
 *   "{{ has_file(entry, 'design_file') }}"
 *   "{{ data.budget_range == '5000_plus' }}"
 *   "{{ length(data.notes) > 100 && !is_empty(data.full_name) }}"
 *   "{{ has_file(entry, 'a') }} && {{ data.rush == 'yes' }}"
 *
 * Since 0.10.0 all of these evaluate as written. Before, a function call
 * that shared its {{ }} with an operator was read as a path and never ran,
 * two blocks in one wrapped expression were mangled, and a comparing block
 * inside a larger expression came back empty — each giving a result that
 * did not depend on the data. legacy_result_differs() recognises those
 * shapes so the owner can review affected workflows after updating.
 *
 * Implementation: a tiny precedence-climbing parser. NOT eval. NO arbitrary
 * PHP execution.
 *
 * Supported:
 *   - Literals: string ('foo' or "foo"), int, float, bool, null
 *   - Context paths via {{ ... }} (handled by FMW_Interpolator before this runs)
 *   - Comparison operators: ==, !=, >, <, >=, <=
 *   - Logical: && (and), || (or), ! (not)
 *   - Parentheses
 *   - Function calls: name(args), resolved by the interpolator, anywhere a
 *     value can appear — nested calls and quoted arguments included
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Expression {

    /**
     * @var FMW_Interpolator
     */
    private $interpolator;

    /**
     * @param FMW_Interpolator $interpolator
     */
    public function __construct( FMW_Interpolator $interpolator ) {
        $this->interpolator = $interpolator;
    }

    /**
     * Evaluate an expression to a boolean.
     *
     * Steps:
     *   1. Pre-process: replace each {{ ... }} block with a placeholder string
     *      that holds the resolved value. This lets us tokenize what's left
     *      with simple regex.
     *   2. Tokenize
     *   3. Parse with precedence
     *   4. Evaluate
     *
     * @param string $expression
     * @return bool
     */
    public function evaluate( $expression ) {
        $expression = trim( $expression );
        if ( $expression === '' ) {
            return false;
        }

        // Strip outer {{ }} only when the expression is exactly ONE block. With
        // two ({{ a }} && {{ b }}), the old pattern matched from the first {{
        // to the last }} and handed the parser "a }} && {{ b" (before 0.10.0).
        $inner = self::single_block_inner( $expression );
        if ( null !== $inner ) {

            // If the inner expression is a simple path or function call, the
            // interpolator can resolve it directly to a value, then we coerce
            // to bool.
            if ( $this->is_simple_value_expression( $inner ) ) {
                $value = $this->interpolator->resolve_expression( $inner );
                return $this->to_bool( $value );
            }

            // Otherwise it's a logical/comparison expression — tokenize+parse.
            return $this->parse_and_evaluate( $inner );
        }

        // Expression without wrapping {{ }} — treat as raw expression.
        return $this->parse_and_evaluate( $expression );
    }

    /**
     * Is the expression a simple path/literal/function call (no operators)?
     *
     * @param string $expr
     * @return bool
     */
    private function is_simple_value_expression( $expr ) {
        return [] === self::top_level_operators( $expr );
    }

    /**
     * The operator characters that appear at the top level of an
     * expression — outside parentheses and outside quoted strings.
     *
     * @param string $expr
     * @return string[] Subset of ! = < > & |
     */
    private static function top_level_operators( $expr ) {
        $found = [];
        $depth = 0;
        $quote = null;
        $len   = strlen( $expr );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $expr[ $i ];
            if ( null !== $quote ) {
                if ( $c === $quote ) {
                    $quote = null;
                }
                continue;
            }
            if ( $c === "'" || $c === '"' ) {
                $quote = $c;
            } elseif ( $c === '(' ) {
                $depth++;
            } elseif ( $c === ')' ) {
                $depth--;
            } elseif ( 0 === $depth && false !== strpos( '!=<>&|', $c ) ) {
                $found[ $c ] = true;
            }
        }
        return array_keys( $found );
    }

    /**
     * Whether a {{ }} block inside a larger expression must be evaluated as
     * a condition of its own (it compares, negates or &&-joins), rather than
     * resolved to a value. A block of only || stays a value: the
     * interpolator reads `a || 'fallback'` as "the first non-empty value",
     * and that already worked.
     *
     * @param string $inner
     * @return bool
     */
    private static function is_condition_block( $inner ) {
        return [] !== array_diff( self::top_level_operators( $inner ), [ '|' ] );
    }

    /**
     * The inside of the expression when it is exactly one {{ }} block,
     * else null.
     *
     * @param string $expression
     * @return string|null
     */
    private static function single_block_inner( $expression ) {
        if ( preg_match( '/^\s*\{\{\s*(.*?)\s*\}\}\s*$/s', $expression, $m ) && false === strpos( $m[1], '}}' ) && false === strpos( $m[1], '{{' ) ) {
            return $m[1];
        }
        return null;
    }

    /**
     * Whether an expression evaluates differently since 0.10.0.
     *
     * Before 0.10.0 three shapes gave a result that did not depend on the
     * data at all: a function call sharing its {{ }} with an operator
     * ({{ !has_file(entry, 'x') }} — the call was read as a path, never
     * run), two or more blocks with the whole expression wrapped
     * ({{ a }} && {{ b }}), and a block that compares or negates inside a
     * larger expression ({{ x == 'a' }} && {{ y }} — resolved to empty).
     * They are now evaluated as written, so a workflow that contains one
     * may take a different branch than it used to. Used to point the owner
     * at those workflows after updating (FMW_Expression_Audit).
     *
     * @param string $expression
     * @return bool
     */
    public static function legacy_result_differs( $expression ) {
        $expression = trim( (string) $expression );
        if ( '' === $expression ) {
            return false;
        }

        preg_match_all( '/\{\{(.*?)\}\}/s', $expression, $mm );
        $blocks = $mm[1];
        $single = self::single_block_inner( $expression );

        // Two or more blocks, whole expression wrapped: the old pattern took
        // everything from the first {{ to the last }} as ONE block, and the
        // tokenizer skipped the stray braces — so it read the blocks as one
        // flat expression. That gives the same answer as evaluating each
        // block, unless a block holds a call (never ran) or &&, || or !
        // (the flattening moves the grouping).
        if ( count( $blocks ) >= 2 && preg_match( '/^\s*\{\{.*\}\}\s*$/s', $expression ) ) {
            if ( self::has_call( $expression ) ) {
                return true;
            }
            foreach ( $blocks as $inner ) {
                if ( [] !== array_intersect( self::top_level_operators( str_replace( '!=', '==', $inner ) ), [ '&', '|', '!' ] ) ) {
                    return true;
                }
            }
            return false;
        }

        if ( null !== $single ) {
            return [] !== self::top_level_operators( $single ) && self::has_call( $single );
        }

        foreach ( $blocks as $inner ) {
            if ( self::is_condition_block( trim( $inner ) ) ) {
                return true;
            }
        }

        return self::has_call( preg_replace( '/\{\{.*?\}\}/s', '', $expression ) );
    }

    /**
     * The conditions in a workflow config whose result changed in 0.10.0:
     * every step's `skip_if` and every conditional's `if`, including steps
     * nested in then / else / try / catch, that legacy_result_differs().
     *
     * @param array  $config Workflow config (decoded).
     * @param string $prefix Step path so far (recursion).
     * @return array[] Each { step, field, expression }.
     */
    public static function changed_conditions( array $config, $prefix = '' ) {
        $found = [];
        $steps = isset( $config['steps'] ) && is_array( $config['steps'] ) ? $config['steps'] : [];
        foreach ( $steps as $i => $step ) {
            if ( ! is_array( $step ) ) {
                continue;
            }
            $name = $prefix . ( isset( $step['name'] ) ? (string) $step['name'] : '#' . $i );

            if ( ! empty( $step['skip_if'] ) && is_string( $step['skip_if'] ) && self::legacy_result_differs( $step['skip_if'] ) ) {
                $found[] = [ 'step' => $name, 'field' => 'skip_if', 'expression' => $step['skip_if'] ];
            }

            $step_config = isset( $step['config'] ) && is_array( $step['config'] ) ? $step['config'] : [];
            if ( ( $step['type'] ?? '' ) === 'conditional' && ! empty( $step_config['if'] ) && is_string( $step_config['if'] )
                && self::legacy_result_differs( $step_config['if'] ) ) {
                $found[] = [ 'step' => $name, 'field' => 'if', 'expression' => $step_config['if'] ];
            }

            foreach ( [ 'then', 'else', 'try', 'catch' ] as $branch ) {
                if ( ! empty( $step_config[ $branch ] ) && is_array( $step_config[ $branch ] ) ) {
                    $found = array_merge( $found, self::changed_conditions( [ 'steps' => $step_config[ $branch ] ], $name . ' → ' . $branch . ' → ' ) );
                }
            }
        }
        return $found;
    }

    /**
     * Whether text contains a function call outside quoted strings.
     *
     * @param string $text
     * @return bool
     */
    private static function has_call( $text ) {
        $unquoted = preg_replace( '/\'[^\']*\'|"[^"]*"/', "''", $text );
        return 1 === preg_match( '/[A-Za-z_][A-Za-z0-9_]*\s*\(/', (string) $unquoted );
    }

    /**
     * Parse a complex expression (with operators) and evaluate.
     *
     * @param string $expr
     * @return bool
     */
    private function parse_and_evaluate( $expr ) {
        // Replace {{ ... }} blocks with placeholders that we'll resolve via interpolator
        // (so the tokenizer sees stable tokens).
        $values = [];
        $expr = preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            function ( $m ) use ( &$values ) {
                // A block that is itself a condition is evaluated as one;
                // before 0.10.0 it went to the value resolver and came back
                // empty, so {{ x == 'a' }} && {{ y }} was always false.
                $resolved = self::is_condition_block( $m[1] )
                    ? $this->parse_and_evaluate( $m[1] )
                    : $this->interpolator->resolve_expression( $m[1] );
                $idx = count( $values );
                $values[ $idx ] = $resolved;
                return '__FMW_VAL_' . $idx . '__';
            },
            $expr
        );

        // Tokenize.
        $tokens = $this->tokenize( $expr, $values );

        // Recursive descent parse.
        $pos = 0;
        $result = $this->parse_or( $tokens, $pos );

        return $this->to_bool( $result );
    }

    /**
     * Tokenize the expression.
     *
     * @param string $expr
     * @param array  $values Resolved {{ ... }} values keyed by integer.
     * @return array
     */
    private function tokenize( $expr, array $values ) {
        $tokens = [];
        $i = 0;
        $len = strlen( $expr );
        while ( $i < $len ) {
            $c = $expr[ $i ];
            // Whitespace
            if ( ctype_space( $c ) ) { $i++; continue; }

            // Placeholder __FMW_VAL_N__
            if ( $c === '_' && substr( $expr, $i, 10 ) === '__FMW_VAL_' ) {
                if ( preg_match( '/^__FMW_VAL_(\d+)__/', substr( $expr, $i ), $m ) ) {
                    $idx = (int) $m[1];
                    $tokens[] = [ 'type' => 'value', 'value' => $values[ $idx ] ?? null ];
                    $i += strlen( $m[0] );
                    continue;
                }
            }

            // Operators (multi-char first)
            $two = substr( $expr, $i, 2 );
            if ( $two === '==' || $two === '!=' || $two === '>=' || $two === '<=' || $two === '&&' || $two === '||' ) {
                $tokens[] = [ 'type' => 'op', 'op' => $two ];
                $i += 2;
                continue;
            }
            if ( $c === '!' || $c === '<' || $c === '>' || $c === '(' || $c === ')' ) {
                $tokens[] = [ 'type' => 'op', 'op' => $c ];
                $i++;
                continue;
            }

            // String literal
            if ( $c === "'" || $c === '"' ) {
                $end = strpos( $expr, $c, $i + 1 );
                if ( $end === false ) {
                    return $tokens; // unterminated; bail
                }
                $tokens[] = [ 'type' => 'value', 'value' => substr( $expr, $i + 1, $end - $i - 1 ) ];
                $i = $end + 1;
                continue;
            }

            // Numeric literal
            if ( ctype_digit( $c ) || ( $c === '-' && $i + 1 < $len && ctype_digit( $expr[ $i + 1 ] ) ) ) {
                $j = $i + 1;
                while ( $j < $len && ( ctype_digit( $expr[ $j ] ) || $expr[ $j ] === '.' ) ) {
                    $j++;
                }
                $num_str = substr( $expr, $i, $j - $i );
                $tokens[] = [
                    'type' => 'value',
                    'value' => strpos( $num_str, '.' ) !== false ? (float) $num_str : (int) $num_str,
                ];
                $i = $j;
                continue;
            }

            // Identifier (true/false/null or a context path like "data.email")
            if ( ctype_alpha( $c ) || $c === '_' ) {
                $j = $i;
                while ( $j < $len && ( ctype_alnum( $expr[ $j ] ) || $expr[ $j ] === '_' || $expr[ $j ] === '.' ) ) {
                    $j++;
                }
                $word = substr( $expr, $i, $j - $i );

                // A function call: the name is followed by "(". Capture up to
                // the matching ")" — nested calls and quoted strings included
                // — and let the interpolator run it. Before 0.10.0 the name
                // was resolved as a context path (empty) and the call never
                // ran, so {{ !has_file(entry, 'x') }} was always true.
                $k = $j;
                while ( $k < $len && ctype_space( $expr[ $k ] ) ) {
                    $k++;
                }
                if ( $k < $len && $expr[ $k ] === '(' && false === strpos( $word, '.' ) ) {
                    $close = self::matching_paren( $expr, $k );
                    if ( null !== $close ) {
                        $tokens[] = [ 'type' => 'value', 'value' => $this->interpolator->resolve_expression( substr( $expr, $i, $close - $i + 1 ) ) ];
                        $i = $close + 1;
                        continue;
                    }
                }

                if ( $word === 'true' )  $tokens[] = [ 'type' => 'value', 'value' => true ];
                elseif ( $word === 'false' ) $tokens[] = [ 'type' => 'value', 'value' => false ];
                elseif ( $word === 'null' )  $tokens[] = [ 'type' => 'value', 'value' => null ];
                else {
                    // Resolve as context path.
                    $tokens[] = [ 'type' => 'value', 'value' => $this->interpolator->resolve_expression( $word ) ];
                }
                $i = $j;
                continue;
            }

            // Unknown char — skip.
            $i++;
        }
        return $tokens;
    }

    /**
     * Index of the ")" that closes the "(" at $open, skipping quoted strings;
     * null when unbalanced.
     *
     * @param string $expr
     * @param int    $open
     * @return int|null
     */
    private static function matching_paren( $expr, $open ) {
        $depth = 0;
        $quote = null;
        $len   = strlen( $expr );
        for ( $i = $open; $i < $len; $i++ ) {
            $c = $expr[ $i ];
            if ( null !== $quote ) {
                if ( $c === $quote ) {
                    $quote = null;
                }
                continue;
            }
            if ( $c === "'" || $c === '"' ) {
                $quote = $c;
            } elseif ( $c === '(' ) {
                $depth++;
            } elseif ( $c === ')' ) {
                $depth--;
                if ( 0 === $depth ) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Parse OR (lowest precedence).
     */
    private function parse_or( $tokens, &$pos ) {
        $left = $this->parse_and( $tokens, $pos );
        while ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'op' && $tokens[ $pos ]['op'] === '||' ) {
            $pos++;
            $right = $this->parse_and( $tokens, $pos );
            $left  = $this->to_bool( $left ) || $this->to_bool( $right );
        }
        return $left;
    }

    /**
     * Parse AND.
     */
    private function parse_and( $tokens, &$pos ) {
        $left = $this->parse_comparison( $tokens, $pos );
        while ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'op' && $tokens[ $pos ]['op'] === '&&' ) {
            $pos++;
            $right = $this->parse_comparison( $tokens, $pos );
            $left  = $this->to_bool( $left ) && $this->to_bool( $right );
        }
        return $left;
    }

    /**
     * Parse comparison.
     */
    private function parse_comparison( $tokens, &$pos ) {
        $left = $this->parse_unary( $tokens, $pos );
        if ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'op' &&
             in_array( $tokens[ $pos ]['op'], [ '==', '!=', '>', '<', '>=', '<=' ], true ) ) {
            $op = $tokens[ $pos ]['op'];
            $pos++;
            $right = $this->parse_unary( $tokens, $pos );
            return $this->compare( $left, $op, $right );
        }
        return $left;
    }

    /**
     * Parse unary (! and parens).
     */
    private function parse_unary( $tokens, &$pos ) {
        if ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'op' && $tokens[ $pos ]['op'] === '!' ) {
            $pos++;
            $val = $this->parse_unary( $tokens, $pos );
            return ! $this->to_bool( $val );
        }
        return $this->parse_primary( $tokens, $pos );
    }

    /**
     * Parse primary (value or parenthesized expression).
     */
    private function parse_primary( $tokens, &$pos ) {
        if ( ! isset( $tokens[ $pos ] ) ) {
            return null;
        }
        $tok = $tokens[ $pos ];
        if ( $tok['type'] === 'op' && $tok['op'] === '(' ) {
            $pos++;
            $val = $this->parse_or( $tokens, $pos );
            if ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'op' && $tokens[ $pos ]['op'] === ')' ) {
                $pos++;
            }
            return $val;
        }
        if ( $tok['type'] === 'value' ) {
            $pos++;
            return $tok['value'];
        }
        $pos++;
        return null;
    }

    /**
     * Compare two values using the given operator.
     *
     * @param mixed  $left
     * @param string $op
     * @param mixed  $right
     * @return bool
     */
    private function compare( $left, $op, $right ) {
        // Numeric coercion if both look numeric.
        if ( is_numeric( $left ) && is_numeric( $right ) ) {
            $left  = (float) $left;
            $right = (float) $right;
        }
        switch ( $op ) {
            case '==': return $left == $right; // loose by design (string '5' == int 5)
            case '!=': return $left != $right;
            case '>':  return $left > $right;
            case '<':  return $left < $right;
            case '>=': return $left >= $right;
            case '<=': return $left <= $right;
        }
        return false;
    }

    /**
     * Coerce any value to a bool using workflow falsy rules.
     *
     * @param mixed $value
     * @return bool
     */
    private function to_bool( $value ) {
        if ( $value === null )  return false;
        if ( $value === '' )    return false;
        if ( $value === 0 )     return false;
        if ( $value === '0' )   return false;
        if ( $value === false ) return false;
        if ( is_array( $value ) && empty( $value ) ) return false;
        return (bool) $value;
    }
}
