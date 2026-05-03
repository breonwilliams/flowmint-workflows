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
 *   - Function calls handled by interpolator before this evaluator
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

        // Strip outer {{ }} if the entire expression is wrapped (common pattern in skip_if).
        if ( preg_match( '/^\s*\{\{\s*(.*?)\s*\}\}\s*$/s', $expression, $m ) ) {
            $inner = $m[1];

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
        // Matches: word.word.word or word(...) — no comparison/logical ops at top level
        // Easy heuristic: contains no operator characters at top level.
        $depth = 0;
        for ( $i = 0; $i < strlen( $expr ); $i++ ) {
            $c = $expr[ $i ];
            if ( $c === '(' ) $depth++;
            elseif ( $c === ')' ) $depth--;
            elseif ( $depth === 0 ) {
                if ( $c === '!' || $c === '=' || $c === '<' || $c === '>' || $c === '&' || $c === '|' ) {
                    return false;
                }
            }
        }
        return true;
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
                $resolved = $this->interpolator->resolve_expression( $m[1] );
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
