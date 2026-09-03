<?php
/**
 * WHERE A GENERATED SCHEDULE STARTS.
 *
 * SFAF_Recurrence::generate() takes a seed event that already sits on a date
 * and produces the dates AFTER it. Nothing in the plugin answers "what is the
 * first date this pattern produces on or after today", because every existing
 * caller has a seed already: somebody filled in a date on a form. An import
 * has no such person, and it must not create one past date, so the seed date
 * is worked out here.
 *
 * These functions touch no WordPress and no database. They are shared by the
 * importer and by the local dry run so that the dates reported and the dates
 * created are produced by the same code.
 *
 * Weekdays are 0 for Sunday through 6 for Saturday, matching date('w') and
 * SFAF_Recurrence::parse_pattern().
 */

/**
 * A day in seconds, under a name that cannot collide with WordPress's own
 * DAY_IN_SECONDS when this file is loaded inside WordPress.
 */
if ( ! defined( 'SFAF_IMPORT_DAY' ) ) {
    define( 'SFAF_IMPORT_DAY', 86400 );
}

if ( ! function_exists( 'sfaf_import_nth_of_month' ) ) {

    /**
     * The nth given weekday of one month, or '' when the month has no such day.
     *
     * @param int $year
     * @param int $month
     * @param int $nth   1..5, or -1 for last.
     * @param int $dow   0 Sunday .. 6 Saturday.
     * @return string Y-m-d, or ''.
     */
    function sfaf_import_nth_of_month( $year, $month, $nth, $dow ) {
        $year  = (int) $year;
        $month = (int) $month;
        $nth   = (int) $nth;
        $dow   = (int) $dow;
        if ( $dow < 0 || $dow > 6 ) {
            return '';
        }
        $days_in = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

        if ( -1 === $nth ) {
            for ( $d = $days_in; $d >= 1; $d-- ) {
                if ( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $d, $year ) ) === $dow ) {
                    return sprintf( '%04d-%02d-%02d', $year, $month, $d );
                }
            }
            return '';
        }
        if ( $nth < 1 || $nth > 5 ) {
            return '';
        }
        $first_dow = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, $year ) );
        $day       = 1 + ( ( $dow - $first_dow + 7 ) % 7 ) + ( 7 * ( $nth - 1 ) );
        if ( $day > $days_in ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    /**
     * Every nth-weekday-of-month date in a range, inclusive.
     *
     * @param int    $nth
     * @param int    $dow
     * @param string $from  Y-m-d
     * @param string $until Y-m-d
     * @return string[]
     */
    function sfaf_import_nth_series( $nth, $dow, $from, $until ) {
        $out = array();
        if ( '' === $from || '' === $until || $until < $from ) {
            return $out;
        }
        $y = (int) substr( $from, 0, 4 );
        $m = (int) substr( $from, 5, 2 );
        $guard = 0;
        while ( $guard++ < 240 ) {
            $d = sfaf_import_nth_of_month( $y, $m, $nth, $dow );
            if ( '' !== $d && $d >= $from && $d <= $until ) {
                $out[] = $d;
            }
            if ( sprintf( '%04d-%02d-01', $y, $m ) > $until ) {
                break;
            }
            $m++;
            if ( $m > 12 ) {
                $m = 1;
                $y++;
            }
        }
        return $out;
    }

    /**
     * The first date a pattern produces on or after a given day.
     *
     * Only the pattern shapes this import uses are answered: weekly with an
     * explicit weekday list, and monthly_nth with an explicit ordinal and
     * weekday. Anything else returns '' rather than a guess, because a guess
     * here creates a post on a day nobody chose.
     *
     * @param string $pattern
     * @param string $from Y-m-d
     * @return string Y-m-d, or ''.
     */
    function sfaf_import_first_date( $pattern, $from ) {
        $pattern = strtolower( trim( (string) $pattern ) );
        $from    = (string) $from;
        if ( '' === $pattern || '' === $from ) {
            return '';
        }
        $bits = explode( ':', $pattern );

        if ( 'weekly' === $bits[0] && isset( $bits[2] ) ) {
            $days = array();
            foreach ( explode( ',', $bits[2] ) as $d ) {
                $d = (int) $d;
                if ( $d >= 0 && $d <= 6 ) {
                    $days[ $d ] = true;
                }
            }
            if ( ! $days ) {
                return '';
            }
            $ts = strtotime( $from . ' 12:00:00' );
            for ( $i = 0; $i < 7; $i++ ) {
                if ( isset( $days[ (int) gmdate( 'w', $ts ) ] ) ) {
                    return gmdate( 'Y-m-d', $ts );
                }
                $ts += SFAF_IMPORT_DAY;
            }
            return '';
        }

        if ( 'monthly_nth' === $bits[0] && isset( $bits[1], $bits[2] ) ) {
            $nth = (int) $bits[1];
            $dow = (int) $bits[2];
            $y   = (int) substr( $from, 0, 4 );
            $m   = (int) substr( $from, 5, 2 );
            for ( $i = 0; $i < 24; $i++ ) {
                $d = sfaf_import_nth_of_month( $y, $m, $nth, $dow );
                if ( '' !== $d && $d >= $from ) {
                    return $d;
                }
                $m++;
                if ( $m > 12 ) {
                    $m = 1;
                    $y++;
                }
            }
            return '';
        }

        return '';
    }

    /**
     * Every date one planned event would occupy, the seed first.
     *
     * THE SEED IS THE EARLIEST DATE ANY OF THE EVENT'S RULES PRODUCES, not the
     * earliest the main pattern produces. Two of these groups meet on the first
     * AND the third Wednesday, and the plugin's monthly_nth carries one ordinal,
     * so the second rule arrives as chosen dates. If the seed were taken from
     * the first-Wednesday rule alone, a third Wednesday falling before it would
     * be dropped: merge_dates() discards every explicit date on or before the
     * start, and it is right to. Anchoring on the earliest of the two keeps
     * both.
     *
     * The pattern is unaffected by which date the seed lands on, because
     * monthly_nth here always carries an explicit ordinal and weekday and only
     * infers them from the seed when it does not.
     *
     * WHAT `extra` AND `gen_pattern` ARE FOR ON A FIXED-DATE EVENT.
     *
     * SFAF_Recurrence::generate() creates the dates after the seed, and it
     * takes them from two places: the pattern, and the explicit list. A set of
     * dates chosen by hand has no pattern, so **the explicit list is the only
     * thing carrying them**, and returning it empty here would have created
     * Coffee Social's first date and silently dropped the other two. That was
     * the fault, and it was in `extra`, not in the pattern.
     *
     * `gen_pattern` is 'custom' rather than '' for clarity, not for necessity:
     * generate() normalises an empty pattern to 'custom' by itself, and the
     * self-test in dryrun.php asserts both halves of that so neither claim
     * rests on a reading of the engine.
     *
     * @param array  $e       One planned event from plan.php.
     * @param string $today   Y-m-d. Nothing before this is ever produced.
     * @param string $horizon Y-m-d, inclusive.
     * @return array{seed:string,dates:string[],extra:string[],gen_pattern:string,note:string}
     */
    function sfaf_import_plan_dates( $e, $today, $horizon ) {
        $out = array( 'seed' => '', 'dates' => array(), 'extra' => array(), 'gen_pattern' => '', 'note' => '' );

        // Fixed dates: the three Leather Labs and the two Coffee Socials.
        if ( ! empty( $e['on'] ) ) {
            $on = $e['on'];
            sort( $on );
            $out['seed']        = $on[0];
            $out['dates']       = array_slice( $on, 1 );
            $out['extra']       = $out['dates'];
            $out['gen_pattern'] = 'custom';
            return $out;
        }
        if ( '' === (string) $e['pattern'] ) {
            return $out;
        }
        $out['gen_pattern'] = (string) $e['pattern'];

        $starts = array();
        $first  = sfaf_import_first_date( $e['pattern'], $today );
        if ( '' !== $first ) {
            $starts[] = $first;
        }
        foreach ( (array) $e['also'] as $rule ) {
            $d = sfaf_import_first_date( 'monthly_nth:' . (int) $rule[0] . ':' . (int) $rule[1], $today );
            if ( '' !== $d ) {
                $starts[] = $d;
            }
        }
        if ( ! $starts ) {
            $out['note'] = 'no seed date: the pattern was not understood';
            return $out;
        }
        sort( $starts );
        $seed         = $starts[0];
        $out['seed']  = $seed;

        $extra = array();
        foreach ( (array) $e['also'] as $rule ) {
            foreach ( sfaf_import_nth_series( (int) $rule[0], (int) $rule[1], $seed, $horizon ) as $d ) {
                $extra[] = $d;
            }
        }
        sort( $extra );
        $extra = array_values( array_unique( $extra ) );

        // The plugin's own engine, so the report and the import cannot disagree.
        $out['dates'] = SFAF_Recurrence::dates( $seed, $horizon, $e['pattern'], 0, $extra );
        $out['extra'] = array_values( array_intersect( $extra, $out['dates'] ) );
        return $out;
    }
}
