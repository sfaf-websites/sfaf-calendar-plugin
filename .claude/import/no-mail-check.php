<?php
/**
 * THE IMPORT MUST NOT PUT AN ADDRESS ANYWHERE A SEND PATH READS.
 *
 * The calendar has not rolled out. An address on an event's notification list
 * means a real person starts receiving registration alerts and pre-event
 * summaries the moment that event is published, and the import creates 273
 * drafts for somebody to publish in bulk. So this is a build-time check, not a
 * thing to remember while editing.
 *
 * WHAT IT PROVES, over .claude/import/sfaf-tec-import.php:
 *
 *   1. It writes no meta key that any send path reads.
 *   2. sfaf_import_silence() sets the author opt-out, clears both notification
 *      lists, and ENDS BY ASKING THE PLUGIN'S OWN RESOLVER whether anybody is
 *      left. An assertion about which keys are written is not the same claim.
 *   3. It is called on the seed AND on every generated occurrence, because
 *      SFAF_Recurrence copies post_author onto an occurrence and its
 *      $copied_meta carries none of the notification keys, so the opt-out does
 *      not travel.
 *   4. The keys this file calls a send path are the keys the plugin actually
 *      reads. A list that has drifted from the plugin proves nothing, so the
 *      three constants are read out of class-sfaf-reminders.php rather than
 *      typed here.
 *
 * A SELF-TEST FIRST, because a checker that cannot fail is not evidence.
 * --self-test plants each violation in turn and requires every one to be
 * caught.
 *
 *     php .claude/import/no-mail-check.php --self-test
 *     php .claude/import/no-mail-check.php
 */

$self = in_array( '--self-test', array_slice( $argv, 1 ), true );
$root = dirname( dirname( __DIR__ ) );

/* ---------------------------------------------------------------------------
 * The keys a send path reads, taken from the plugin rather than typed here.
 * ------------------------------------------------------------------------ */
$reminders = file_get_contents( $root . '/includes/class-sfaf-reminders.php' );
if ( false === $reminders ) {
    echo "cannot read includes/class-sfaf-reminders.php\n";
    exit( 1 );
}
$mail_keys = array();
foreach ( array( 'NOTIFY_USERS_META', 'NOTIFY_EMAILS_META' ) as $const ) {
    if ( preg_match( '/const\s+' . $const . '\s*=\s*\'([^\']+)\'/', $reminders, $m ) ) {
        $mail_keys[ $m[1] ] = $const;
    }
}
if ( preg_match( '/const\s+NOTIFY_AUTHOR_OPTOUT_META\s*=\s*\'([^\']+)\'/', $reminders, $m ) ) {
    $optout_key = $m[1];
} else {
    $optout_key = '';
}
// Two more the plugin reads at send time: the reply-to on an event's own
// message, and the meeting link, which is a credential rather than a list.
$mail_keys['_uc_email_replyto'] = 'the reply-to on an event message';
$mail_keys['_uc_meeting_url']   = 'the meeting link';
$mail_keys['_uc_organizer_email'] = 'the legacy organizer address';

if ( count( $mail_keys ) < 5 || '' === $optout_key ) {
    echo "could not read the notification constants out of the plugin; refusing to pass\n";
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * The checks, over one importer source.
 * ------------------------------------------------------------------------ */
function check_importer( $src, $mail_keys, $optout_key ) {
    $findings = array();

    // 1. Every meta key written, by a tokenizer rather than a regex over prose.
    $tokens = token_get_all( $src );
    $n      = count( $tokens );
    for ( $i = 0; $i < $n; $i++ ) {
        $t = $tokens[ $i ];
        if ( ! is_array( $t ) || T_STRING !== $t[0] ) {
            continue;
        }
        if ( 'update_post_meta' !== $t[1] && 'add_post_meta' !== $t[1] ) {
            continue;
        }
        // The key is the second argument: skip "(", then the first argument up
        // to its comma at depth 1.
        $depth = 0;
        $args  = array();
        $cur   = '';
        for ( $j = $i + 1; $j < $n; $j++ ) {
            $tok = $tokens[ $j ];
            $s   = is_array( $tok ) ? $tok[1] : $tok;
            if ( '(' === $s ) {
                $depth++;
                if ( 1 === $depth ) {
                    continue;
                }
            } elseif ( ')' === $s ) {
                $depth--;
                if ( 0 === $depth ) {
                    $args[] = trim( $cur );
                    break;
                }
            } elseif ( ',' === $s && 1 === $depth ) {
                $args[] = trim( $cur );
                $cur    = '';
                continue;
            }
            if ( $depth >= 1 ) {
                $cur .= $s;
            }
        }
        if ( ! isset( $args[1] ) ) {
            continue;
        }
        $key = trim( $args[1], " \t\n'\"" );
        if ( isset( $mail_keys[ $key ] ) && $key !== $optout_key ) {
            $findings[] = "writes {$key}, which is " . $mail_keys[ $key ];
        }
    }

    // 2. The silencer does all four things.
    if ( ! preg_match( '/function\s+sfaf_import_silence\s*\(.*?\n\}/s', $src, $m ) ) {
        $findings[] = 'sfaf_import_silence() is not defined';
    } else {
        $body = $m[0];
        if ( ! preg_match( '/update_post_meta\s*\([^;]*NOTIFY_AUTHOR_OPTOUT_META/', $body ) ) {
            $findings[] = 'sfaf_import_silence() does not set the author opt-out';
        }
        if ( ! preg_match( '/delete_post_meta\s*\([^;]*NOTIFY_USERS_META/', $body ) ) {
            $findings[] = 'sfaf_import_silence() does not clear the chosen-users list';
        }
        if ( ! preg_match( '/delete_post_meta\s*\([^;]*NOTIFY_EMAILS_META/', $body ) ) {
            $findings[] = 'sfaf_import_silence() does not clear the typed-addresses list';
        }
        if ( ! preg_match( '/SFAF_Reminders::notify_list\s*\(/', $body ) ) {
            $findings[] = 'sfaf_import_silence() does not ask notify_list() who is left, so it asserts rather than checks';
        }
    }

    // 3. Called on the seed and on every generated occurrence.
    if ( ! preg_match( '/sfaf_import_silence\(\s*\$post_id\s*\)/', $src ) ) {
        $findings[] = 'the seed event is never silenced';
    }
    if ( ! preg_match( '/foreach\s*\(\s*\$gen\[\s*\'created\'\s*\]\s*as[^)]*\)\s*\{[^}]*sfaf_import_silence/s', $src ) ) {
        $findings[] = 'generated occurrences are never silenced, and the opt-out does not travel to them';
    }

    return $findings;
}

$file = __DIR__ . '/sfaf-tec-import.php';
$src  = file_get_contents( $file );
if ( false === $src ) {
    echo "cannot read {$file}\n";
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * The self-test. Each planted violation must be caught.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    $plants = array(
        'an address written onto the typed-addresses list' => function ( $s ) {
            return str_replace(
                "update_post_meta( \$post_id, '_uc_event_date', \$d['seed'] );",
                "update_post_meta( \$post_id, '_uc_notify_emails', array( 'someone@sfaf.org' ) );\n        update_post_meta( \$post_id, '_uc_event_date', \$d['seed'] );",
                $s
            );
        },
        'the meeting link written' => function ( $s ) {
            return str_replace(
                "update_post_meta( \$post_id, '_uc_start_time', \$e['start'] );",
                "update_post_meta( \$post_id, '_uc_meeting_url', 'https://zoom.example/x' );",
                $s
            );
        },
        'the silencer stops asking notify_list()' => function ( $s ) {
            return str_replace(
                '$list = SFAF_Reminders::notify_list( $post_id );',
                '$list = array();',
                $s
            );
        },
        'the author opt-out is no longer set' => function ( $s ) {
            return str_replace(
                'update_post_meta( $post_id, SFAF_Reminders::NOTIFY_AUTHOR_OPTOUT_META, \'1\' );',
                '',
                $s
            );
        },
        'occurrences stop being silenced' => function ( $s ) {
            return preg_replace(
                '/foreach \( \$gen\[\'created\'\] as \$occ_id \) \{.*?\n            \}/s',
                '',
                $s
            );
        },
    );

    $missed = 0;
    foreach ( $plants as $label => $plant ) {
        $broken = $plant( $src );
        if ( $broken === $src ) {
            printf( "%-52s PLANT DID NOT APPLY\n", $label );
            $missed++;
            continue;
        }
        $found = check_importer( $broken, $mail_keys, $optout_key );
        printf( "%-52s %s\n", $label, $found ? 'caught' : 'MISSED' );
        if ( ! $found ) {
            $missed++;
        }
    }

    // And a false positive check: the real file must pass.
    $clean = check_importer( $src, $mail_keys, $optout_key );
    printf( "%-52s %s\n", 'the real importer, unmodified', $clean ? 'FALSE POSITIVE' : 'passes' );
    if ( $clean ) {
        $missed++;
    }

    echo "\n" . ( $missed ? "FAILED: {$missed}\n" : "self-test passed: every planted violation caught, no false positive.\n" );
    exit( $missed ? 1 : 0 );
}

$findings = check_importer( $src, $mail_keys, $optout_key );
echo "No-mail check\n";
echo 'send-path keys read from the plugin: ' . implode( ', ', array_keys( $mail_keys ) ) . "\n";
echo 'author opt-out key: ' . $optout_key . "\n\n";
if ( $findings ) {
    foreach ( $findings as $f ) {
        echo "  ! {$f}\n";
    }
    echo "\n" . count( $findings ) . " finding(s).\n";
    exit( 1 );
}
echo "the import writes no address any send path reads, and every post it creates is checked.\n";
