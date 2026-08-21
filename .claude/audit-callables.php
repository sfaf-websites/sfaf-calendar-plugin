<?php
/**
 * The callable audit.
 *
 * WHAT IT IS FOR. The linter proves each file PARSES. It cannot prove that
 * what a file CALLS exists, and three undefined-callable fatals shipped in a
 * row, 2.0.0 to 2.0.2, precisely because activating the plugin never exercises
 * a REST route. A call to a method that is not there is a valid PHP file right
 * up to the moment somebody presses the button.
 *
 * WHAT IT CHECKS
 *   1. sfaf_* / uc_* global function calls resolve to a definition.
 *   2. $this->method() exists on the class, or on an ancestor of it.
 *   3. self:: / static:: / parent:: / Class:: methods exist.
 *   4. Arity: no call passes fewer than the required parameters or more than
 *      the declared ones, for anything defined in this plugin.
 *   5. add_action / add_filter callbacks exist, and the callback can actually
 *      accept the accepted_args the hook promises it.
 *
 * TOKENIZER, NOT GREP, and the reason is written down because both traps have
 * produced false results here before:
 *   - `function &name()` hands the ampersand back as its own token, so the
 *     name is not the next token after T_FUNCTION.
 *   - `"{$a}"` opens a brace the tokenizer returns as T_CURLY_OPEN, not as a
 *     '{' string, so brace depth must count it or every class boundary after
 *     the first interpolated string is wrong.
 *
 * Usage:  php .claude/audit-callables.php [root]
 *         php .claude/audit-callables.php --self-test
 * Exit 0 clean, 1 on any finding.
 */

/* ---------------------------------------------------------------------------
 * Collection
 * ------------------------------------------------------------------------- */

function ac_php_files( $root ) {
    $out = array();
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $it as $file ) {
        $path = str_replace( '\\', '/', $file->getPathname() );
        if ( 'php' !== strtolower( $file->getExtension() ) ) {
            continue;
        }
        /*
         * Out of scope: vendored code, and "Old Calendar Files", which holds
         * the extracted previous releases. Those are shipped history and are
         * not the build; auditing them buried the live tree's findings under
         * fourteen from plugin versions nobody is going to edit.
         */
        if ( preg_match( '#/(vendor|node_modules|\.git|\.build-stage)/#', $path ) ) {
            continue;
        }
        if ( false !== stripos( $path, '/Old Calendar Files/' ) ) {
            continue;
        }
        /*
         * AND THE BUILD TOOLS THEMSELVES, INCLUDING THIS ONE.
         *
         * .claude/ holds the checkers, and two of them stub WordPress with an
         * in-memory store so the engine can be run without a site. Those stubs
         * are deliberately narrower than core: group-ops-test.php declares
         * wp_set_object_terms() with the three arguments it needs, and core
         * takes four. Auditing them therefore did BOTH kinds of damage at once.
         * A false positive, because the stub became the definition every caller
         * in the plugin was checked against, and class-sfaf-series.php was
         * reported for passing four arguments to a real function that accepts
         * them. And, more quietly, a false NEGATIVE waiting to happen: a stub is
         * a definition, so a plugin file calling a core function that is not
         * declared anywhere would look resolved as soon as some harness happened
         * to stub it.
         *
         * The audit's subject is the plugin that ships. These are not it.
         */
        if ( preg_match( '#/\.claude/#', $path ) ) {
            continue;
        }
        $out[] = $path;
    }
    sort( $out );
    return $out;
}

/**
 * Significant tokens only: whitespace and comments never carry meaning here,
 * and dropping them makes every lookahead below a fixed offset instead of a
 * loop that has to remember to skip.
 */
function ac_tokens( $code ) {
    $raw = token_get_all( $code );
    $out = array();
    foreach ( $raw as $t ) {
        if ( is_array( $t ) ) {
            if ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
                continue;
            }
            $out[] = array( 'id' => $t[0], 'text' => $t[1], 'line' => $t[2] );
        } else {
            $out[] = array( 'id' => -1, 'text' => $t, 'line' => 0 );
        }
    }
    // Carry a line number onto the plain-character tokens so findings can point
    // somewhere real.
    $line = 0;
    foreach ( $out as $i => $t ) {
        if ( $t['line'] > 0 ) {
            $line = $t['line'];
        } else {
            $out[ $i ]['line'] = $line;
        }
    }
    return $out;
}

function ac_is( $tok, $id ) {
    return isset( $tok ) && $tok['id'] === $id;
}

function ac_text( $tokens, $i ) {
    return isset( $tokens[ $i ] ) ? $tokens[ $i ]['text'] : '';
}

/**
 * Every brace that OPENS a scope. T_CURLY_OPEN is `"{$a}"` and
 * T_DOLLAR_OPEN_CURLY_BRACES is `"${a}"`; both are closed by a plain '}' and
 * both must be counted or class and function boundaries drift.
 */
function ac_opens_brace( $tok ) {
    if ( -1 === $tok['id'] ) {
        return '{' === $tok['text'];
    }
    return T_CURLY_OPEN === $tok['id']
        || ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $tok['id'] );
}

function ac_closes_brace( $tok ) {
    return -1 === $tok['id'] && '}' === $tok['text'];
}

/**
 * The ampersand in `function &name()`.
 *
 * PHP 8.1 SPLIT THIS INTO NAMED TOKENS. Before 8.1 it came back as the plain
 * character '&'; from 8.1 it is T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG or
 * T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, which are arrays with real ids.
 * Testing only for the character therefore silently stops matching, and the
 * first version of this file did exactly that: it indexed neither
 * sfaf_embed_context_flag() nor sfaf_rsvp_count_store(), then reported both of
 * their DEFINITIONS as undefined calls. Six findings, all false, on the one
 * trap the project had already written down.
 */
function ac_is_amp( $tok ) {
    if ( ! $tok ) {
        return false;
    }
    if ( -1 === $tok['id'] ) {
        return '&' === $tok['text'];
    }
    return ( defined( 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ) && T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $tok['id'] )
        || ( defined( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) && T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === $tok['id'] );
}

/**
 * Read a parameter list starting at the '(' index. Returns
 * [required, total, variadic, endIndex].
 */
function ac_read_params( $tokens, $open ) {
    $depth    = 0;
    $required = 0;
    $total    = 0;
    $variadic = false;
    $seen     = false;   // a parameter has begun at this nesting level
    $optional = false;   // ...and it has an `=` default
    $i        = $open;
    $n        = count( $tokens );

    for ( ; $i < $n; $i++ ) {
        $t    = $tokens[ $i ];
        $text = $t['text'];

        if ( -1 === $t['id'] && ( '(' === $text || '[' === $text ) ) {
            $depth++;
            continue;
        }
        if ( -1 === $t['id'] && ( ')' === $text || ']' === $text ) ) {
            $depth--;
            if ( 0 === $depth ) {
                if ( $seen ) {
                    $total++;
                    if ( ! $optional ) {
                        $required++;
                    }
                }
                break;
            }
            continue;
        }

        if ( 1 !== $depth ) {
            continue; // inside a default value's own parentheses
        }

        if ( T_VARIABLE === $t['id'] && ! $seen ) {
            $seen = true;
            continue;
        }
        if ( defined( 'T_ELLIPSIS' ) && T_ELLIPSIS === $t['id'] ) {
            $variadic = true;
            continue;
        }
        if ( -1 === $t['id'] && '=' === $text ) {
            $optional = true;
            continue;
        }
        if ( -1 === $t['id'] && ',' === $text ) {
            if ( $seen ) {
                $total++;
                if ( ! $optional ) {
                    $required++;
                }
            }
            $seen     = false;
            $optional = false;
            continue;
        }
    }

    if ( $variadic ) {
        $total = PHP_INT_MAX;
    }

    return array( $required, $total, $variadic, $i );
}

/* ---------------------------------------------------------------------------
 * Pass 1: what exists
 * ------------------------------------------------------------------------- */

function ac_index( $files ) {
    $functions = array(); // name => [req,total,file,line]
    $classes   = array(); // name => ['parent'=>..,'methods'=>[lower=>[req,total,static,name]]]

    foreach ( $files as $file ) {
        $tokens = ac_tokens( file_get_contents( $file ) );
        $n      = count( $tokens );

        $class      = null;
        $classDepth = 0;
        $depth      = 0;

        for ( $i = 0; $i < $n; $i++ ) {
            $t = $tokens[ $i ];

            if ( ac_opens_brace( $t ) ) {
                $depth++;
                continue;
            }
            if ( ac_closes_brace( $t ) ) {
                $depth--;
                if ( null !== $class && $depth < $classDepth ) {
                    $class = null;
                }
                continue;
            }

            if ( T_CLASS === $t['id'] || T_INTERFACE === $t['id'] || T_TRAIT === $t['id'] ) {
                // `Foo::class` is T_CLASS too, and names nothing.
                if ( $i > 0 && T_DOUBLE_COLON === $tokens[ $i - 1 ]['id'] ) {
                    continue;
                }
                if ( ! ac_is( $tokens[ $i + 1 ] ?? null, T_STRING ) ) {
                    continue; // anonymous class
                }
                $class = $tokens[ $i + 1 ]['text'];
                if ( ! isset( $classes[ $class ] ) ) {
                    $classes[ $class ] = array( 'parent' => null, 'methods' => array(), 'file' => $file );
                }
                // extends / implements
                for ( $j = $i + 2; $j < $n && ! ac_opens_brace( $tokens[ $j ] ); $j++ ) {
                    if ( T_EXTENDS === $tokens[ $j ]['id'] && ac_is( $tokens[ $j + 1 ] ?? null, T_STRING ) ) {
                        $classes[ $class ]['parent'] = $tokens[ $j + 1 ]['text'];
                    }
                }
                $classDepth = $depth + 1;
                continue;
            }

            if ( T_FUNCTION !== $t['id'] ) {
                continue;
            }

            // `function &name()` — the ampersand is its own token. See ac_is_amp().
            $k = $i + 1;
            if ( ac_is_amp( $tokens[ $k ] ?? null ) ) {
                $k++;
            }
            if ( ! ac_is( $tokens[ $k ] ?? null, T_STRING ) ) {
                continue; // closure or arrow function: nothing to index
            }
            $name = $tokens[ $k ]['text'];

            // Find the '(' that opens the parameter list.
            $open = $k + 1;
            if ( '(' !== ac_text( $tokens, $open ) ) {
                continue;
            }
            list( $req, $tot ) = ac_read_params( $tokens, $open );

            if ( null !== $class && $depth >= $classDepth ) {
                $static = false;
                for ( $j = $i - 1; $j >= 0 && $j > $i - 6; $j-- ) {
                    if ( T_STATIC === $tokens[ $j ]['id'] ) {
                        $static = true;
                    }
                    if ( -1 === $tokens[ $j ]['id'] && in_array( $tokens[ $j ]['text'], array( ';', '{', '}' ), true ) ) {
                        break;
                    }
                }
                $classes[ $class ]['methods'][ strtolower( $name ) ] = array(
                    'req' => $req, 'tot' => $tot, 'static' => $static,
                    'name' => $name, 'file' => $file, 'line' => $t['line'],
                );
            } else {
                $functions[ strtolower( $name ) ] = array(
                    'req' => $req, 'tot' => $tot, 'name' => $name,
                    'file' => $file, 'line' => $t['line'],
                );
            }
        }
    }

    return array( $functions, $classes );
}

/** Walk the inheritance chain for a method. Returns the method or null. */
function ac_find_method( $classes, $class, $method ) {
    $seen = array();
    $m    = strtolower( $method );
    while ( $class && isset( $classes[ $class ] ) && ! isset( $seen[ $class ] ) ) {
        $seen[ $class ] = true;
        if ( isset( $classes[ $class ]['methods'][ $m ] ) ) {
            return $classes[ $class ]['methods'][ $m ];
        }
        $class = $classes[ $class ]['parent'];
    }
    return null;
}

/** True when the chain reaches a class this plugin does not define. */
function ac_chain_is_complete( $classes, $class ) {
    $seen = array();
    while ( $class && ! isset( $seen[ $class ] ) ) {
        $seen[ $class ] = true;
        if ( ! isset( $classes[ $class ] ) ) {
            return false;
        }
        $class = $classes[ $class ]['parent'];
    }
    return true;
}

/** Count the arguments in a call whose '(' is at $open. */
function ac_count_args( $tokens, $open, &$end = null ) {
    $depth = 0;
    $args  = 0;
    $seen  = false;
    $n     = count( $tokens );
    $spread = false;

    for ( $i = $open; $i < $n; $i++ ) {
        $t    = $tokens[ $i ];
        $text = $t['text'];

        if ( -1 === $t['id'] && in_array( $text, array( '(', '[' ), true ) ) {
            $depth++;
            if ( $depth > 1 ) { $seen = true; }
            continue;
        }
        if ( T_ARRAY === $t['id'] || ( defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $t['id'] ) ) {
            $seen = true;
            continue;
        }
        if ( -1 === $t['id'] && in_array( $text, array( ')', ']' ), true ) ) {
            $depth--;
            if ( 0 === $depth ) {
                if ( $seen ) {
                    $args++;
                }
                $end = $i;
                break;
            }
            continue;
        }
        if ( 1 !== $depth ) {
            continue;
        }
        if ( defined( 'T_ELLIPSIS' ) && T_ELLIPSIS === $t['id'] ) {
            $spread = true;
            continue;
        }
        if ( -1 === $t['id'] && ',' === $text ) {
            $args++;
            $seen = false;
            continue;
        }
        $seen = true;
    }

    return $spread ? -1 : $args; // -1: argument unpacking, arity unknowable
}

/* ---------------------------------------------------------------------------
 * Pass 2: what is called
 * ------------------------------------------------------------------------- */

function ac_audit( $files, $functions, $classes ) {
    $findings = array();
    $internal = array_flip( array_map( 'strtolower', get_defined_functions()['internal'] ) );

    foreach ( $files as $file ) {
        $tokens = ac_tokens( file_get_contents( $file ) );
        $n      = count( $tokens );

        $class      = null;
        $classDepth = 0;
        $depth      = 0;

        for ( $i = 0; $i < $n; $i++ ) {
            $t = $tokens[ $i ];

            if ( ac_opens_brace( $t ) ) {
                $depth++;
                continue;
            }
            if ( ac_closes_brace( $t ) ) {
                $depth--;
                if ( null !== $class && $depth < $classDepth ) {
                    $class = null;
                }
                continue;
            }

            if ( ( T_CLASS === $t['id'] || T_TRAIT === $t['id'] ) && ac_is( $tokens[ $i + 1 ] ?? null, T_STRING )
                && ! ( $i > 0 && T_DOUBLE_COLON === $tokens[ $i - 1 ]['id'] ) ) {
                $class      = $tokens[ $i + 1 ]['text'];
                $classDepth = $depth + 1;
                continue;
            }

            /* ---- $this->method(...) ---- */
            if ( T_VARIABLE === $t['id'] && '$this' === $t['text']
                && T_OBJECT_OPERATOR === ( $tokens[ $i + 1 ]['id'] ?? 0 )
                && T_STRING === ( $tokens[ $i + 2 ]['id'] ?? 0 )
                && '(' === ac_text( $tokens, $i + 3 ) ) {

                $method = $tokens[ $i + 2 ]['text'];
                if ( $class && isset( $classes[ $class ] ) ) {
                    $found = ac_find_method( $classes, $class, $method );
                    if ( ! $found && ac_chain_is_complete( $classes, $class ) ) {
                        $findings[] = sprintf( '%s:%d  $this->%s() is not defined on %s', $file, $t['line'], $method, $class );
                    } elseif ( $found ) {
                        $args = ac_count_args( $tokens, $i + 3 );
                        if ( $args >= 0 && ( $args < $found['req'] || $args > $found['tot'] ) ) {
                            $findings[] = sprintf(
                                '%s:%d  $this->%s() called with %d arg(s); %s::%s takes %d-%s',
                                $file, $t['line'], $method, $args, $class, $found['name'],
                                $found['req'], ( PHP_INT_MAX === $found['tot'] ? 'many' : $found['tot'] )
                            );
                        }
                    }
                }
                continue;
            }

            /* ---- Class::method(...) / self:: / static:: / parent:: ---- */
            if ( T_DOUBLE_COLON === $t['id']
                && T_STRING === ( $tokens[ $i + 1 ]['id'] ?? 0 )
                && '(' === ac_text( $tokens, $i + 2 ) ) {

                $prev = $tokens[ $i - 1 ] ?? null;
                if ( ! $prev ) {
                    continue;
                }
                $owner = $prev['text'];
                if ( T_STRING !== $prev['id'] && T_STATIC !== $prev['id'] ) {
                    continue; // $var::method() — not resolvable here
                }

                $lower = strtolower( $owner );
                if ( 'self' === $lower || 'static' === $lower ) {
                    $owner = $class;
                } elseif ( 'parent' === $lower ) {
                    $owner = ( $class && isset( $classes[ $class ] ) ) ? $classes[ $class ]['parent'] : null;
                }
                if ( ! $owner || ! isset( $classes[ $owner ] ) ) {
                    continue; // a WordPress or PHP class: not ours to verify
                }

                $method = $tokens[ $i + 1 ]['text'];
                $found  = ac_find_method( $classes, $owner, $method );
                if ( ! $found ) {
                    if ( ac_chain_is_complete( $classes, $owner ) ) {
                        $findings[] = sprintf( '%s:%d  %s::%s() is not defined', $file, $t['line'], $owner, $method );
                    }
                    continue;
                }
                $args = ac_count_args( $tokens, $i + 2 );
                if ( $args >= 0 && ( $args < $found['req'] || $args > $found['tot'] ) ) {
                    $findings[] = sprintf(
                        '%s:%d  %s::%s() called with %d arg(s); takes %d-%s',
                        $file, $t['line'], $owner, $found['name'], $args,
                        $found['req'], ( PHP_INT_MAX === $found['tot'] ? 'many' : $found['tot'] )
                    );
                }
                continue;
            }

            /* ---- global function calls ---- */
            if ( T_STRING === $t['id'] && '(' === ac_text( $tokens, $i + 1 ) ) {
                $prevId = $tokens[ $i - 1 ]['id'] ?? 0;
                if ( in_array( $prevId, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS ), true ) ) {
                    continue;
                }
                // `function &name()` again: here the token before the name is
                // the ampersand, so T_FUNCTION is two back, and without this a
                // by-reference DEFINITION reads as a call to itself.
                if ( ac_is_amp( $tokens[ $i - 1 ] ?? null )
                    && T_FUNCTION === ( $tokens[ $i - 2 ]['id'] ?? 0 ) ) {
                    continue;
                }
                if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prevId ) {
                    continue;
                }

                $name  = $t['text'];
                $lower = strtolower( $name );

                if ( isset( $functions[ $lower ] ) ) {
                    $args = ac_count_args( $tokens, $i + 1 );
                    $f    = $functions[ $lower ];
                    if ( $args >= 0 && ( $args < $f['req'] || $args > $f['tot'] ) ) {
                        $findings[] = sprintf(
                            '%s:%d  %s() called with %d arg(s); takes %d-%s',
                            $file, $t['line'], $f['name'], $args,
                            $f['req'], ( PHP_INT_MAX === $f['tot'] ? 'many' : $f['tot'] )
                        );
                    }
                } elseif ( preg_match( '/^(sfaf|uc)_/i', $name ) && ! isset( $internal[ $lower ] ) ) {
                    // The plugin's own namespace. Anything else is WordPress's
                    // and cannot be resolved without loading WordPress.
                    $findings[] = sprintf( '%s:%d  %s() is called and never defined', $file, $t['line'], $name );
                }

                /* ---- hook callbacks ---- */
                if ( in_array( $lower, array( 'add_action', 'add_filter' ), true ) ) {
                    $findings = array_merge( $findings, ac_check_hook( $tokens, $i + 1, $file, $class, $functions, $classes ) );
                }
            }
        }
    }

    return $findings;
}

/**
 * add_action( 'hook', <callback>, priority, accepted_args ).
 *
 * Only the shapes that can be resolved statically are checked: a string name,
 * array( $this, 'm' ), array( __CLASS__, 'm' ), array( 'Class', 'm' ) and
 * 'Class::method'. A variable callback is left alone rather than guessed at.
 */
function ac_check_hook( $tokens, $open, $file, $class, $functions, $classes ) {
    $findings = array();

    // Split the top-level arguments into token ranges.
    $depth  = 0;
    $args   = array();
    $start  = null;
    $n      = count( $tokens );
    for ( $i = $open; $i < $n; $i++ ) {
        $t    = $tokens[ $i ];
        $text = $t['text'];
        if ( -1 === $t['id'] && in_array( $text, array( '(', '[' ), true ) ) {
            $depth++;
            if ( 1 === $depth ) { $start = $i + 1; }
            continue;
        }
        if ( -1 === $t['id'] && in_array( $text, array( ')', ']' ), true ) ) {
            $depth--;
            if ( 0 === $depth ) {
                $args[] = array( $start, $i - 1 );
                break;
            }
            continue;
        }
        if ( 1 === $depth && -1 === $t['id'] && ',' === $text ) {
            $args[] = array( $start, $i - 1 );
            $start  = $i + 1;
        }
    }

    if ( count( $args ) < 2 ) {
        return $findings;
    }

    list( $cbStart, $cbEnd ) = $args[1];
    $line = $tokens[ $cbStart ]['line'] ?? 0;

    $owner  = null;
    $method = null;

    // array( $this, 'name' ) / array( __CLASS__, 'name' ) / [ 'Class', 'name' ]
    $slice = array();
    for ( $i = $cbStart; $i <= $cbEnd; $i++ ) {
        $slice[] = $tokens[ $i ];
    }
    $texts = array_map( function ( $t ) { return $t['text']; }, $slice );

    $strings = array();
    foreach ( $slice as $t ) {
        if ( T_CONSTANT_ENCAPSED_STRING === $t['id'] ) {
            $strings[] = trim( $t['text'], "'\"" );
        }
    }

    $isArray = in_array( 'array', array_map( 'strtolower', $texts ), true ) || in_array( '[', $texts, true );

    if ( $isArray && $strings ) {
        $method = end( $strings );
        if ( in_array( '$this', $texts, true ) ) {
            $owner = $class;
        } elseif ( in_array( '__CLASS__', $texts, true ) || in_array( 'self', array_map( 'strtolower', $texts ), true ) ) {
            $owner = $class;
        } elseif ( count( $strings ) >= 2 ) {
            $owner = $strings[0];
            $method = $strings[1];
        } else {
            foreach ( $slice as $t ) {
                if ( T_STRING === $t['id'] && isset( $classes[ $t['text'] ] ) ) {
                    $owner = $t['text'];
                }
            }
        }
    } elseif ( 1 === count( $strings ) && ! $isArray ) {
        $one = $strings[0];
        if ( false !== strpos( $one, '::' ) ) {
            list( $owner, $method ) = explode( '::', $one, 2 );
        } else {
            // A plain function name.
            $lower = strtolower( $one );
            if ( preg_match( '/^(sfaf|uc)_/i', $one ) && ! isset( $functions[ $lower ] ) ) {
                $findings[] = sprintf( '%s:%d  hook callback %s() is not defined', $file, $line, $one );
            } elseif ( isset( $functions[ $lower ] ) ) {
                $findings = array_merge(
                    $findings,
                    ac_check_hook_arity( $args, $tokens, $functions[ $lower ], $one, $file, $line )
                );
            }
            return $findings;
        }
    }

    if ( ! $owner || ! $method || ! isset( $classes[ $owner ] ) ) {
        return $findings;
    }

    $found = ac_find_method( $classes, $owner, $method );
    if ( ! $found ) {
        if ( ac_chain_is_complete( $classes, $owner ) ) {
            $findings[] = sprintf( '%s:%d  hook callback %s::%s() is not defined', $file, $line, $owner, $method );
        }
        return $findings;
    }

    return array_merge( $findings, ac_check_hook_arity( $args, $tokens, $found, $owner . '::' . $method, $file, $line ) );
}

/**
 * A hook promising N arguments to a callback that declares fewer is a fatal
 * the moment the hook fires with them all.
 */
function ac_check_hook_arity( $args, $tokens, $target, $label, $file, $line ) {
    if ( count( $args ) < 4 ) {
        return array(); // accepted_args defaults to 1
    }
    list( $s, $e ) = $args[3];
    if ( $s !== $e || ! isset( $tokens[ $s ] ) || T_LNUMBER !== $tokens[ $s ]['id'] ) {
        return array();
    }
    $accepted = (int) $tokens[ $s ]['text'];
    if ( PHP_INT_MAX !== $target['tot'] && $accepted > $target['tot'] ) {
        return array( sprintf(
            '%s:%d  hook passes %d arg(s) to %s(), which accepts at most %d',
            $file, $line, $accepted, $label, $target['tot']
        ) );
    }
    return array();
}

/* ---------------------------------------------------------------------------
 * Self test. A checker nobody has watched fail is a checker nobody knows the
 * shape of: this plants one of each fault and requires every one to be found.
 * ------------------------------------------------------------------------- */

function ac_self_test() {
    $dir = sys_get_temp_dir() . '/ac-selftest-' . getmypid();
    @mkdir( $dir, 0777, true );

    $good = <<<'PHP'
<?php
class AC_Base {
    public function inherited() { return 1; }
}
class AC_Thing extends AC_Base {
    // The ampersand is its own token: the indexer must still see the name.
    public function &by_ref() { $x = 1; return $x; }
    public static function two( $a, $b = 2 ) { return $a . $b; }
    public function ok() {
        // An interpolated brace the tokenizer returns as T_CURLY_OPEN. If it
        // is not counted, this class appears to end here.
        $a = 'x';
        $s = "{$a} and ${a}";
        $this->inherited();
        self::two( 1 );
        self::two( 1, 2 );
        return $s;
    }
}
function sfaf_defined( $a, $b = null ) { return array( $a, $b ); }

// THE BY-REFERENCE TRAP, AT GLOBAL SCOPE AND UNDER THE AUDITED PREFIX. This is
// the shape that actually shipped (sfaf_rsvp_count_store), and the first
// version of this checker reported its definition AND its two call sites as
// undefined. The earlier fixture only had a by-ref method with an unaudited
// name, so nothing was ever asserted about it.
function &sfaf_ref_store() {
    static $s = array();
    return $s;
}
function sfaf_ref_user() {
    $s =& sfaf_ref_store();
    return $s;
}

add_action( 'init', array( 'AC_Thing', 'ok' ) );
add_filter( 'the_title', 'sfaf_defined', 10, 2 );
sfaf_defined( 1 );
PHP;

    $bad = <<<'PHP'
<?php
class AC_Broken {
    public function go() {
        $this->does_not_exist();          // FAULT 1
        AC_Thing::not_there();            // FAULT 2
        sfaf_never_defined( 1 );          // FAULT 3
        sfaf_defined( 1, 2, 3 );          // FAULT 4 arity
        AC_Thing::two();                  // FAULT 5 arity
    }
}
add_action( 'init', array( 'AC_Broken', 'missing_cb' ) );   // FAULT 6
add_filter( 'x', 'sfaf_defined', 10, 5 );                   // FAULT 7 hook arity
PHP;

    file_put_contents( $dir . '/good.php', $good );
    file_put_contents( $dir . '/bad.php', $bad );

    $files = ac_php_files( $dir );
    list( $fn, $cl ) = ac_index( $files );
    $found = ac_audit( $files, $fn, $cl );

    $expect = array(
        'does_not_exist'      => '$this-> on a method that is not there',
        'AC_Thing::not_there' => 'static call to a missing method',
        'sfaf_never_defined'  => 'undefined sfaf_ function',
        'sfaf_defined() called with 3' => 'too many arguments',
        'AC_Thing::two() called with 0' => 'too few arguments',
        'missing_cb'          => 'hook callback that does not exist',
        'accepts at most 2'   => 'hook promising more args than the callback takes',
    );

    $blob   = implode( "\n", $found );
    $failed = array();
    foreach ( $expect as $needle => $what ) {
        if ( false === strpos( $blob, $needle ) ) {
            $failed[] = $what . "  (looked for: $needle)";
        }
    }

    // Nothing in good.php may be reported.
    foreach ( $found as $f ) {
        if ( false !== strpos( $f, 'good.php' ) ) {
            $failed[] = 'FALSE POSITIVE on valid code: ' . $f;
        }
    }

    @unlink( $dir . '/good.php' );
    @unlink( $dir . '/bad.php' );
    @rmdir( $dir );

    echo "self-test: planted 7 faults, found " . count( $found ) . " finding(s)\n";
    foreach ( $found as $f ) {
        echo '  . ' . basename( $f ) . "\n";
    }
    if ( $failed ) {
        echo "\nSELF-TEST FAILED:\n";
        foreach ( $failed as $f ) {
            echo "  ! $f\n";
        }
        return 1;
    }
    echo "self-test passed: every planted fault found, no false positive.\n";
    return 0;
}

/* ------------------------------------------------------------------------- */

$argv = $_SERVER['argv'];
if ( in_array( '--self-test', $argv, true ) ) {
    exit( ac_self_test() );
}

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '.';
if ( ! is_dir( $root ) ) {
    fwrite( STDERR, "Not a directory: $root\n" );
    exit( 2 );
}

$files = ac_php_files( $root );
list( $functions, $classes ) = ac_index( $files );
$findings = ac_audit( $files, $functions, $classes );

printf(
    "Callable audit\nRoot: %s\nfiles: %d   functions: %d   classes: %d\n\n",
    $root, count( $files ), count( $functions ), count( $classes )
);

if ( empty( $findings ) ) {
    echo "no undefined callables, no arity mismatches.\n";
    exit( 0 );
}

foreach ( $findings as $f ) {
    echo "  ! $f\n";
}
printf( "\n%d finding(s).\n", count( $findings ) );
exit( 1 );
