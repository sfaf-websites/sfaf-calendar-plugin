<?php
/**
 * QUESTIONS FOR REGISTRANTS (3.106.2).
 *
 * An event may ask up to five questions on its registration form. Each has its
 * text, an answer style (checkboxes, pick any; radio, pick one), whether it is
 * required, and on a hybrid event whether it is asked of people attending in
 * person only. Each has a list of options, and an option may take a short line
 * of additional info.
 *
 * STORED ON THE EVENT AS ONE META KEY, `_uc_questions`, with a stable id on
 * every question and option, so an answer can name what it answered while the
 * wording around it is edited. The key travels with a repeating event and
 * through the group copy like every RSVP setting, and is not a series default.
 *
 * ANSWERS ARE ROWS IN uc_rsvp_answers, one per option chosen, keyed on the
 * registration row. Each row keeps the question's and the option's words as
 * they were answered, so removing either from the event leaves the answer in
 * the table and readable under the person's Details, while the totals, which
 * read the event's current questions, no longer count it.
 *
 * A WAITLISTED PERSON ANSWERS WHEN THEY JOIN. Their answers are on their row,
 * and confirming them moves that same row in, so the answers come with them.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Questions {

    const META        = '_uc_questions';
    const MAX         = 5;
    const MAX_OPTIONS = 20;
    const MAX_TEXT    = 300;
    const MAX_MORE    = 200;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_rsvp_answers';
    }

    /** A new id for a question ('q') or an option ('o'). */
    public static function new_id( $prefix ) {
        return $prefix . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 );
    }

    /**
     * Questions in a clean shape, whatever arrived: text trimmed and capped,
     * a known style, booleans, at most five, and every question with at least
     * one option. A question or option with no text is dropped, and so is a
     * question left with no options, since nobody could answer it.
     *
     * @param mixed $raw
     * @return array[]
     */
    public static function normalize( $raw ) {
        $out  = array();
        $seen = array();
        foreach ( is_array( $raw ) ? $raw : array() as $q ) {
            if ( ! is_array( $q ) ) {
                continue;
            }
            $text = self::clip( isset( $q['text'] ) ? $q['text'] : '', self::MAX_TEXT );
            if ( '' === $text ) {
                continue;
            }
            $id = self::clean_id( isset( $q['id'] ) ? $q['id'] : '', 'q', $seen );
            $options = array();
            foreach ( ( isset( $q['options'] ) && is_array( $q['options'] ) ) ? $q['options'] : array() as $o ) {
                if ( ! is_array( $o ) ) {
                    continue;
                }
                $otext = self::clip( isset( $o['text'] ) ? $o['text'] : '', self::MAX_TEXT );
                if ( '' === $otext ) {
                    continue;
                }
                $options[] = array(
                    'id'   => self::clean_id( isset( $o['id'] ) ? $o['id'] : '', 'o', $seen ),
                    'text' => $otext,
                    'more' => ! empty( $o['more'] ),
                );
                if ( count( $options ) >= self::MAX_OPTIONS ) {
                    break;
                }
            }
            if ( ! $options ) {
                continue;
            }
            $out[] = array(
                'id'        => $id,
                'text'      => $text,
                'style'     => ( isset( $q['style'] ) && 'one' === $q['style'] ) ? 'one' : 'any',
                'required'  => ! empty( $q['required'] ),
                'in_person' => ! empty( $q['in_person'] ),
                'options'   => $options,
            );
            if ( count( $out ) >= self::MAX ) {
                break;
            }
        }
        return $out;
    }

    private static function clip( $s, $max ) {
        $s = trim( sanitize_text_field( (string) $s ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
    }

    /** A submitted id when it is one of ours and not already used, else a new one. */
    private static function clean_id( $id, $prefix, &$seen ) {
        $id = (string) $id;
        if ( ! preg_match( '/^' . $prefix . '[a-f0-9]{10}$/', $id ) || isset( $seen[ $id ] ) ) {
            $id = self::new_id( $prefix );
        }
        $seen[ $id ] = true;
        return $id;
    }

    /** The event's questions, in order. */
    public static function for_event( $event_id ) {
        return self::normalize( get_post_meta( (int) $event_id, self::META, true ) );
    }

    /**
     * The editor's save. Only when the form drew the section: the
     * registrations screen saves the other RSVP settings and never these.
     */
    public static function save_from_post( $event_id, $post ) {
        if ( ! isset( $post['uc_questions_present'] ) ) {
            return;
        }
        $clean = self::normalize( isset( $post['uc_questions'] ) ? wp_unslash( $post['uc_questions'] ) : array() );
        if ( $clean ) {
            update_post_meta( (int) $event_id, self::META, $clean );
        } else {
            delete_post_meta( (int) $event_id, self::META );
        }
    }

    /**
     * Whether a question is asked of somebody attending in this format. In
     * person only means something on a hybrid event and nowhere else.
     */
    public static function asked( $q, $event_id, $format ) {
        if ( empty( $q['in_person'] ) || ! SFAF_Online::is_hybrid( (int) $event_id ) ) {
            return true;
        }
        return SFAF_Online::MODE_IN_PERSON === (string) $format;
    }

    /**
     * What the registration form needs: the questions and the two fixed
     * labels in the event's language. Empty when there are none.
     *
     * @return array
     */
    public static function form_data( $event_id ) {
        $qs = self::for_event( $event_id );
        if ( ! $qs ) {
            return array();
        }
        $lang = sfaf_event_language( (int) $event_id );
        return array(
            'heading'  => SFAF_Messages::label( 'q_heading', $lang ),
            'more'     => SFAF_Messages::label( 'q_more', $lang ),
            'required' => SFAF_Messages::label( 'q_required', $lang ),
            'hybrid'   => SFAF_Online::is_hybrid( (int) $event_id ),
            'items'    => $qs,
        );
    }

    /**
     * Check a submission's answers against the event's questions, on the
     * server, whatever the form did.
     *
     * @param int    $event_id
     * @param string $format   the format the person registered in
     * @param mixed  $answers  question id => option id or list of option ids
     * @param mixed  $more     question id => option id => text
     * @return array{rows:array[]}|array{error:string}
     */
    public static function clean( $event_id, $format, $answers, $more ) {
        $answers = is_array( $answers ) ? $answers : array();
        $more    = is_array( $more ) ? $more : array();
        $rows    = array();
        foreach ( self::for_event( $event_id ) as $q ) {
            if ( ! self::asked( $q, $event_id, $format ) ) {
                continue;
            }
            $picked = isset( $answers[ $q['id'] ] ) ? (array) $answers[ $q['id'] ] : array();
            $picked = array_values( array_unique( array_map( 'strval', $picked ) ) );
            $by_id  = array();
            foreach ( $q['options'] as $o ) {
                $by_id[ $o['id'] ] = $o;
            }
            $picked = array_values( array_filter( $picked, function ( $id ) use ( $by_id ) { return isset( $by_id[ $id ] ); } ) );
            if ( 'one' === $q['style'] && count( $picked ) > 1 ) {
                $picked = array_slice( $picked, 0, 1 );
            }
            if ( $q['required'] && ! $picked ) {
                return array( 'error' => SFAF_Messages::label( 'q_required', sfaf_event_language( (int) $event_id ) ) );
            }
            foreach ( $picked as $oid ) {
                $o    = $by_id[ $oid ];
                $note = '';
                if ( $o['more'] && isset( $more[ $q['id'] ] ) && is_array( $more[ $q['id'] ] ) && isset( $more[ $q['id'] ][ $oid ] ) ) {
                    $note = self::clip( $more[ $q['id'] ][ $oid ], self::MAX_MORE );
                }
                $rows[] = array(
                    'question_id'   => $q['id'],
                    'option_id'     => $oid,
                    'question_text' => $q['text'],
                    'option_text'   => $o['text'],
                    'more_text'     => $note,
                );
            }
        }
        return array( 'rows' => $rows );
    }

    /** Write one registration's answers. */
    public static function store( $rsvp_id, $event_id, $rows ) {
        global $wpdb;
        foreach ( (array) $rows as $r ) {
            $wpdb->insert( self::table(), array(
                'rsvp_id'       => (int) $rsvp_id,
                'event_id'      => (int) $event_id,
                'question_id'   => (string) $r['question_id'],
                'option_id'     => (string) $r['option_id'],
                'question_text' => (string) $r['question_text'],
                'option_text'   => (string) $r['option_text'],
                'more_text'     => (string) $r['more_text'],
                'created_at'    => current_time( 'mysql' ),
            ) );
        }
    }

    /**
     * One registration's answers, grouped by question in the order answered:
     * question text => list of array( option text, additional info ).
     *
     * @return array<string,array[]>
     */
    public static function answers_for( $rsvp_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE rsvp_id = %d ORDER BY id ASC',
            (int) $rsvp_id
        ) );
        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r->question_text ][] = array( (string) $r->option_text, (string) $r->more_text );
        }
        return $out;
    }

    /**
     * answers_for() for many rows at once, for the registrations list:
     * rsvp id => question text => list of array( option text, additional info ).
     *
     * @param int[] $rsvp_ids
     * @return array
     */
    public static function answers_for_rows( $rsvp_ids ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', (array) $rsvp_ids ) ) );
        if ( ! $ids ) {
            return array();
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE rsvp_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ') ORDER BY id ASC',
            array_map( 'strval', $ids )
        ) );
        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->rsvp_id ][ (string) $r->question_text ][] = array( (string) $r->option_text, (string) $r->more_text );
        }
        return $out;
    }

    /**
     * The totals strip on the registrations list: for each current question,
     * how many confirmed registrants answered it, of how many it was asked of,
     * and the count for each current option. Confirmed only. An answer to a
     * question or option no longer on the event is not counted.
     *
     * @return array[] each { text, answered, of, options: [ [text, count] ] }
     */
    public static function totals( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        $qs = self::for_event( $event_id );
        if ( ! $qs ) {
            return array();
        }
        // Confirmed registrants only: the answers of anybody waiting, offered,
        // expired or cancelled are on the table and not counted.
        $confirmed = array();
        foreach ( SFAF_Announce::registrants( $event_id ) as $r ) {
            $confirmed[ (int) $r->id ] = true;
        }
        $rows = array_filter( (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE event_id = %d ORDER BY id ASC',
            $event_id
        ) ), function ( $r ) use ( $confirmed ) {
            return isset( $confirmed[ (int) $r->rsvp_id ] );
        } );
        $hybrid = SFAF_Online::is_hybrid( $event_id );
        $out    = array();
        foreach ( $qs as $q ) {
            $opts = array();
            foreach ( $q['options'] as $o ) {
                $opts[ $o['id'] ] = 0;
            }
            $who = array();
            foreach ( (array) $rows as $r ) {
                if ( (string) $r->question_id !== $q['id'] || ! isset( $opts[ (string) $r->option_id ] ) ) {
                    continue;
                }
                $opts[ (string) $r->option_id ]++;
                $who[ (int) $r->rsvp_id ] = true;
            }
            // Asked of the in-person registrants only, on a hybrid event.
            $of = ( $hybrid && $q['in_person'] )
                ? sfaf_get_rsvp_count_by_format( $event_id, SFAF_Online::MODE_IN_PERSON )
                : sfaf_get_rsvp_count( $event_id );
            $list = array();
            foreach ( $q['options'] as $o ) {
                $list[] = array( $o['text'], $opts[ $o['id'] ] );
            }
            $out[] = array( 'text' => $q['text'], 'answered' => count( $who ), 'of' => (int) $of, 'options' => $list );
        }
        return $out;
    }

    /** "Question: 3 of 12 answered. Option 2, Option 1" */
    public static function total_line( $t ) {
        $bits = array();
        foreach ( $t['options'] as $o ) {
            $bits[] = $o[0] . ' ' . (int) $o[1];
        }
        return $t['text'] . ': ' . (int) $t['answered'] . ' of ' . (int) $t['of'] . ' answered. ' . implode( ', ', $bits );
    }
}
