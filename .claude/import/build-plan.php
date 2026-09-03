<?php
/**
 * BUILD THE IMPORT PLAN FROM MARK'S LIST AND THE FOUR TEC EXPORTS.
 *
 * Mark's list is the structure and is written out below by hand. The export is
 * a source of detail for the rows that match it, and nothing else: description,
 * times, venue, organizer and featured image. Where they disagree the list
 * wins; where the list has no match the export row is dropped.
 *
 * The output is .claude/import/plan.php, a plain data array. Generating it
 * rather than typing it is what keeps every description byte-exact and lets the
 * whole mapping be re-derived if the export is taken again.
 *
 *     php .claude/import/build-plan.php
 */
require __DIR__ . '/lib.php';

chdir( dirname( dirname( __DIR__ ) ) );

/* ---------------------------------------------------------------------------
 * The export, indexed.
 * ------------------------------------------------------------------------ */
$items = wxr_items( 'SFAF Calendar Export.xml' );
$vens  = array();
foreach ( wxr_items( 'recintos.xml' ) as $v ) {
    $vens[ $v['id'] ] = $v;
}
$att = array();
foreach ( $items as $it ) {
    if ( 'attachment' === $it['type'] ) {
        $att[ $it['id'] ] = $it['attach'];
    }
}

/** "Calendar Events: Strut" is Strut. The prefix is the old system's filing. */
function venue_name( $raw ) {
    return trim( preg_replace( '/^Calendar Events:\s*/u', '', (string) $raw ) );
}

/**
 * The address parts of one export venue, keyed as SFAF_Venues::save() wants.
 *
 * TWO THINGS IN THE OLD DATA NEED UNDOING. The zip carries the venue's own name
 * in brackets after it, "94114 (Strut)", on the three venues this import needs.
 * And _VenueState is empty on some rows where _VenueStateProvince holds the
 * state, so all three spellings are tried in turn rather than the first one
 * winning by being first in the file.
 */
function venue_parts( $v ) {
    $zip = preg_replace( '/\s*\([^)]*\)\s*$/u', '', m1( $v, '_VenueZip' ) );
    $state = '';
    foreach ( array( '_VenueState', '_VenueStateProvince', '_VenueProvince' ) as $k ) {
        $state = trim( m1( $v, $k ) );
        if ( '' !== $state ) {
            break;
        }
    }
    return array(
        'street' => trim( m1( $v, '_VenueAddress' ) ),
        'city'   => trim( m1( $v, '_VenueCity' ) ),
        'state'  => $state,
        'zip'    => trim( (string) $zip ),
    );
}

/** Published occurrences of one title, oldest first. */
function rows_for( $title, $items ) {
    $out = array();
    foreach ( $items as $it ) {
        if ( 'tribe_events' !== $it['type'] || 'publish' !== $it['status'] ) {
            continue;
        }
        if ( $it['title'] !== $title ) {
            continue;
        }
        $out[] = $it;
    }
    usort( $out, function ( $a, $b ) {
        return strcmp( m1( $a, '_EventStartDate' ), m1( $b, '_EventStartDate' ) );
    } );
    return $out;
}

/**
 * What the export says about one title, taken from its LATEST occurrence.
 *
 * THE LATEST, NOT THE FIRST, and this is not a detail. El Salon's first twelve
 * rows say 09:30-11:00 and its most recent says 12:30-14:00; Express Yourself's
 * early rows are Thursdays and its most recent is a Monday. The first row in
 * the file is the oldest fact in it.
 */
function detail( $title, $items, $vens, $att ) {
    $rows = rows_for( $title, $items );
    if ( ! $rows ) {
        return null;
    }
    $last  = $rows[ count( $rows ) - 1 ];
    $vid   = (int) m1( $last, '_EventVenueID' );
    $tid   = (int) m1( $last, '_thumbnail_id' );
    $venue = '';
    $parts = array();
    if ( $vid && isset( $vens[ $vid ] ) ) {
        $venue = venue_name( $vens[ $vid ]['title'] );
        $parts = venue_parts( $vens[ $vid ] );
    }
    return array(
        'rows'    => count( $rows ),
        'start'   => substr( m1( $last, '_EventStartDate' ), 11, 5 ),
        'end'     => substr( m1( $last, '_EventEndDate' ), 11, 5 ),
        'venue'   => $venue,
        'parts'   => $parts,
        'image'   => ( $tid && isset( $att[ $tid ] ) ) ? $att[ $tid ] : '',
        'content' => trim( $last['content'] ),
        'cats'    => isset( $last['cats']['tribe_events_cat'] ) ? $last['cats']['tribe_events_cat'] : array(),
    );
}

/* ---------------------------------------------------------------------------
 * The three Leather Lab descriptions.
 *
 * WRITTEN FROM THE FACTS ON MARK'S LIST, NOT COPIED FROM IT. What reached this
 * build was a summary of what the three descriptions say, not the descriptions
 * themselves, and the host each one names was not among the facts. So the body
 * below carries only what was actually stated, and all three are reported as
 * wanting Mark's own copy before they are published.
 * ------------------------------------------------------------------------ */
$LEATHER_LAB = "Onyx Northwest presents a free pre-Folsom education series at Strut.\n\n"
    . "HIV and STI testing and PrEP enrollment are available on site. Food and raffle prizes are provided.\n\n"
    . "Sponsored by Gilead, in partnership with San Francisco AIDS Foundation.";

/* ---------------------------------------------------------------------------
 * MARK'S LIST. THE AUTHORITATIVE STRUCTURE.
 *
 * Organizer, then series, then the events each series holds. Series and event
 * are not one to one: Coffee Social, PROP, Mobile Health Sites and Strut
 * community events each hold several distinct events.
 *
 * Per event:
 *   src    the export title this matches, absent where nothing matches.
 *   src2   a second export title, for the one series that combines two groups.
 *   sched  how its dates are decided:
 *            list  Mark's list states the day and time, or the dates.
 *            rule  the export carries a rule with NO end date.
 *            none  no live schedule. Times and description only; Mark fills
 *                  the schedule in.
 *   pat    the plugin pattern (SFAF_Recurrence), for 'list' and 'rule'.
 *   also   further nth-weekday rules folded in as explicit dates, for the two
 *          groups that meet on the first AND third Wednesday. The plugin's
 *          monthly_nth carries one ordinal, so the second arrives the way a
 *          hand-picked date does.
 *   on     fixed dates, for the three dated Leather Labs.
 *   time   start and end where the list states them, or where nothing matched.
 *   venue  a venue the list states, where nothing matched.
 *   cats   caladmin categories, each a preference order of names.
 *   online the export event has no venue and says it is online.
 *   desc   description text stated here rather than taken from the export.
 * ------------------------------------------------------------------------ */

$SUPPORT   = array( 'Support Groups', 'Support groups' );
$PROGRAM   = array( 'Program Groups', 'Program groups' );
$COMMUNITY = array( 'Community events', 'Community Events', 'Community event' );

$LIST = array(

    'Elizabeth Taylor 50-Plus Network' => array(
        'aliases' => array(
            'The Elizabeth Taylor 50-Plus Network for Gay, Bi & Trans Men',
            'Elizabeth Taylor 50-Plus Network for Gay, Bi & Trans Men',
            'The Elizabeth Taylor 50-Plus Network',
            '50-Plus Network',
        ),
        'series' => array(
            'Coffee Social' => array(
                array(
                    'name'  => 'Coffee Social',
                    'src'   => 'Community Coffee Social (Elizabeth Taylor 50-Plus Network)',
                    'sched' => 'rule',
                    'pat'   => 'weekly:1:6',
                    'cats'  => array( $COMMUNITY ),
                ),
                array( 'name' => 'Coffee On Us', 'sched' => 'none', 'cats' => array() ),
            ),
            'Dinner with Friends Community Meal' => array(
                array(
                    'name'  => 'Dinner with Friends Community Meal',
                    'src'   => 'Dinner with Friends',
                    'sched' => 'none',
                    'cats'  => array( $COMMUNITY ),
                ),
            ),
            'Damn, Daddy!' => array(
                array(
                    'name'   => 'Damn, Daddy!',
                    'src'    => 'Damn, Daddy! - Support Group (Online)',
                    'sched'  => 'list',
                    'pat'    => 'weekly:1:3',
                    'time'   => array( '13:30', '15:30' ),
                    'cats'   => array( $SUPPORT ),
                    'online' => true,
                ),
            ),
            'Virtual Check-In' => array(
                array(
                    'name'   => 'Virtual Check-In',
                    'src'    => '50-Plus Wednesday Night Virtual Check-in (Members Only) - Elizabeth Taylor 50-Plus Network',
                    'sched'  => 'list',
                    'pat'    => 'weekly:1:3',
                    'time'   => array( '18:00', '19:30' ),
                    'cats'   => array( $COMMUNITY ),
                    'online' => true,
                ),
            ),
            'Game Night' => array(
                array( 'name' => 'Game Night', 'sched' => 'none', 'cats' => array() ),
            ),
        ),
    ),

    'Aging Services' => array(
        'aliases' => array(),
        'series'  => array(
            'Transformaciones' => array(
                array(
                    'name'  => 'Transformaciones',
                    'src'   => 'Transformaciones',
                    'sched' => 'rule',
                    'pat'   => 'monthly_nth:1:3',
                    'also'  => array( array( 3, 3 ) ),
                    'cats'  => array( $SUPPORT ),
                ),
            ),
            "Glitter 'n Go" => array(
                array( 'name' => "Glitter 'n Go", 'sched' => 'none', 'cats' => array() ),
            ),
            'Beyond the Binary' => array(
                array(
                    'name'  => 'Beyond the Binary',
                    'src'   => 'Beyond the Binary',
                    'sched' => 'rule',
                    'pat'   => 'monthly_nth:3:1',
                    'cats'  => array( $SUPPORT ),
                ),
            ),
            'Intergenerational TGI Brunch' => array(
                array( 'name' => 'Intergenerational TGI Brunch', 'sched' => 'none', 'cats' => array() ),
            ),
        ),
    ),

    'Programa Latino' => array(
        'aliases' => array(),
        'series'  => array(
            'El Grupo de Apoyo Latino' => array(
                array(
                    'name'  => 'El Grupo de Apoyo Latino',
                    'src'   => 'El Grupo de Apoyo Latino',
                    'sched' => 'rule',
                    'pat'   => 'weekly:1:4',
                    'cats'  => array( $SUPPORT ),
                ),
            ),
            'Opioid Overdose Prevention and Reversal Training' => array(
                array(
                    'name'  => 'Opioid Overdose Prevention and Reversal Training',
                    'src'   => 'Capacitación sobre cómo revertir una sobredosis y cómo responder ante ella',
                    'sched' => 'rule',
                    'pat'   => 'monthly_nth:3:2',
                    'cats'  => array( $PROGRAM ),
                ),
            ),
            'Health Education & Legal Assistance Group' => array(
                array(
                    'name'  => 'Health Education & Legal Assistance Group',
                    'src'   => 'Grupos de Educación sobre Salud del Programa Latino',
                    'src2'  => 'Grupo de Asistencia Legal del Programa Latino',
                    'sched' => 'rule',
                    'pat'   => 'weekly:1:2',
                    'cats'  => array( $PROGRAM ),
                ),
            ),
        ),
    ),

    'TransLife' => array(
        'aliases' => array(),
        'series'  => array(
            'Trans Galaxy' => array(
                array(
                    'name'  => 'Trans Galaxy',
                    'src'   => 'TransLife Galaxy Mental Health Series',
                    'sched' => 'rule',
                    'pat'   => 'monthly_nth:1:3',
                    'also'  => array( array( 3, 3 ) ),
                    'cats'  => array( $SUPPORT ),
                ),
            ),
            'TransLife Drop-In' => array(
                array(
                    'name'  => 'TransLife Drop-In',
                    'src'   => 'TransLife Drop-In',
                    'sched' => 'rule',
                    'pat'   => 'weekly:1:3',
                    'cats'  => array( $COMMUNITY ),
                ),
            ),
        ),
    ),

    'Black Brothers Esteem' => array(
        'aliases' => array( 'Black Brothers Esteem (BBE)', 'BBE' ),
        'series'  => array(
            'Brothers Who Read' => array( array( 'name' => 'Brothers Who Read', 'sched' => 'none', 'cats' => array() ) ),
            'Phoenix Rising'    => array( array( 'name' => 'Phoenix Rising', 'sched' => 'none', 'cats' => array() ) ),
            'BBE Game Night'    => array( array( 'name' => 'BBE Game Night', 'sched' => 'none', 'cats' => array() ) ),
        ),
    ),

    'HUES' => array(
        'aliases' => array(
            'Healing & Uniting Every Sista (HUES)',
            'Healing and Uniting Every Sista (HUES)',
            'Healing & Uniting Every Sista',
        ),
        'series' => array(
            'Sista Circle'  => array( array( 'name' => 'Sista Circle', 'sched' => 'none', 'cats' => array() ) ),
            'Soul Sessions' => array(
                array( 'name' => 'Soul Sessions', 'src' => 'Soul Sessions', 'sched' => 'none', 'cats' => array( $COMMUNITY ) ),
            ),
        ),
    ),

    'Stonewall Project' => array(
        'aliases' => array( 'The Stonewall Project' ),
        'series'  => array(
            'Express Yourself' => array(
                array( 'name' => 'Express Yourself', 'src' => 'Express Yourself - Support Group', 'sched' => 'none', 'cats' => array( $SUPPORT ) ),
            ),
            'Off Mute' => array(
                array( 'name' => 'Off Mute', 'src' => 'Off Mute - Support Group', 'sched' => 'none', 'cats' => array( $SUPPORT ) ),
            ),
            'Stay on Target' => array( array( 'name' => 'Stay on Target', 'sched' => 'none', 'cats' => array() ) ),
            'Abstinence Support Group' => array(
                array( 'name' => 'Abstinence Support Group', 'src' => 'Abstinence Support Group', 'sched' => 'none', 'cats' => array( $SUPPORT ) ),
            ),
            'Skills and Thrills' => array( array( 'name' => 'Skills and Thrills', 'sched' => 'none', 'cats' => array() ) ),
            'El Salon' => array(
                array( 'name' => 'El Salon', 'src' => 'El Salón - Grupo de apoyo', 'sched' => 'none', 'cats' => array( $SUPPORT ) ),
            ),
            'PROP' => array(
                array( 'name' => 'PROP', 'src' => 'PROP Contingency Management', 'sched' => 'none', 'cats' => array( $PROGRAM, $SUPPORT ) ),
                array( 'name' => 'PROP for All', 'src' => 'PROP for All - Contingency Management', 'sched' => 'none', 'cats' => array( $PROGRAM ) ),
                array( 'name' => 'PROP Empowerment', 'src' => 'PROP Empowerment Program', 'sched' => 'none', 'cats' => array( $SUPPORT ) ),
                array( 'name' => 'PROP at 6th Street', 'sched' => 'none', 'cats' => array() ),
            ),
        ),
    ),

    'PWUD Health' => array(
        'aliases' => array(),
        'series'  => array(
            'Treatment Readiness and Recovery Capital' => array(
                array( 'name' => 'Treatment Readiness and Recovery Capital', 'sched' => 'none', 'cats' => array() ),
            ),
            'Mindfulness-based Relapse Prevention' => array(
                array( 'name' => 'Mindfulness-based Relapse Prevention', 'sched' => 'none', 'cats' => array() ),
            ),
            'Crafts Brunch' => array( array( 'name' => 'Crafts Brunch', 'sched' => 'none', 'cats' => array() ) ),
            'Art Group'     => array( array( 'name' => 'Art Group', 'sched' => 'none', 'cats' => array() ) ),
            'Mobile Health Sites' => array(
                array( 'name' => 'Mission District - Martin de Porres House of Hospitality', 'sched' => 'none', 'cats' => array() ),
                array( 'name' => 'Mission District - Mission Neighborhood Resource Center', 'sched' => 'none', 'cats' => array() ),
            ),
        ),
    ),

    'Onyx Northwest' => array(
        'aliases' => array(),
        'series'  => array(
            'Strut community events' => array(
                array(
                    'name'  => 'Leather Lab: Safe and Fun Impact Play',
                    'sched' => 'list',
                    'on'    => array( '2026-09-05' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => 'Strut',
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => $LEATHER_LAB,
                ),
                array(
                    'name'  => 'Leather Lab: Harm Reduction & Chemsex',
                    'sched' => 'list',
                    'on'    => array( '2026-09-12' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => 'Strut',
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => $LEATHER_LAB,
                ),
                array(
                    'name'  => 'Leather Lab: Kink and Spirituality',
                    'sched' => 'list',
                    'on'    => array( '2026-09-19' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => 'Strut',
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => $LEATHER_LAB,
                ),
            ),
        ),
    ),

);

/* ---------------------------------------------------------------------------
 * Assemble.
 * ------------------------------------------------------------------------ */
$venues_used = array();
$plan_series = array();
$problems    = array();

foreach ( $LIST as $org_name => $org ) {
    foreach ( $org['series'] as $series_name => $events ) {
        $out_events = array();

        foreach ( $events as $e ) {
            $src = isset( $e['src'] ) ? $e['src'] : '';
            $d   = ( '' !== $src ) ? detail( $src, $items, $vens, $att ) : null;

            if ( '' !== $src && null === $d ) {
                $problems[] = "no published export row titled: {$src}";
            }

            $content = '';
            if ( isset( $e['desc'] ) ) {
                $content = $e['desc'];
            } elseif ( $d ) {
                $content = $d['content'];
                if ( ! empty( $e['src2'] ) ) {
                    $d2 = detail( $e['src2'], $items, $vens, $att );
                    if ( $d2 && '' !== $d2['content'] ) {
                        $content = trim( $content . "\n\n" . $d2['content'] );
                    } else {
                        $problems[] = "no published export row titled: {$e['src2']}";
                    }
                }
            }

            $start = isset( $e['time'][0] ) ? $e['time'][0] : ( $d ? $d['start'] : '' );
            $end   = isset( $e['time'][1] ) ? $e['time'][1] : ( $d ? $d['end'] : '' );

            $venue = isset( $e['venue'] ) ? $e['venue'] : ( $d ? $d['venue'] : '' );
            $parts = array();
            if ( '' !== $venue ) {
                if ( $d && $d['venue'] === $venue ) {
                    $parts = $d['parts'];
                } else {
                    foreach ( $vens as $v ) {
                        if ( venue_name( $v['title'] ) === $venue ) {
                            $parts = venue_parts( $v );
                            break;
                        }
                    }
                }
                if ( ! $parts ) {
                    $problems[] = "no address in the export for venue: {$venue}";
                }
                $venues_used[ $venue ] = $parts;
            }

            $out_events[] = array(
                'title'   => $e['name'],
                'source'  => trim( $src . ( ! empty( $e['src2'] ) ? ' + ' . $e['src2'] : '' ) ),
                'case'    => $e['sched'],
                'pattern' => isset( $e['pat'] ) ? $e['pat'] : '',
                'also'    => isset( $e['also'] ) ? $e['also'] : array(),
                'on'      => isset( $e['on'] ) ? $e['on'] : array(),
                'start'   => $start,
                'end'     => $end,
                'venue'   => $venue,
                'online'  => ! empty( $e['online'] ),
                'cats'    => $e['cats'],
                'image'   => $d ? $d['image'] : '',
                'content' => $content,
            );
        }

        $plan_series[] = array(
            'name'      => $series_name,
            'organizer' => $org_name,
            'events'    => $out_events,
        );
    }
}

$organizers = array();
foreach ( $LIST as $org_name => $org ) {
    $organizers[ $org_name ] = $org['aliases'];
}

ksort( $venues_used );

$plan = array(
    'generated'  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
    'horizon'    => '2026-12-31',
    'organizers' => $organizers,
    'venues'     => $venues_used,
    'series'     => $plan_series,
);

$out = "<?php\n"
    . "/**\n"
    . " * THE IMPORT PLAN. GENERATED BY .claude/import/build-plan.php. DO NOT EDIT.\n"
    . " *\n"
    . " * Mark's list is the structure; the TEC export supplied the detail. Every\n"
    . " * description here is byte-exact from the export except the three Leather\n"
    . " * Labs, which were written from the facts on Mark's list.\n"
    . " */\n"
    . 'return ' . var_export( $plan, true ) . ";\n";

file_put_contents( __DIR__ . '/plan.php', $out );

echo 'series: ' . count( $plan_series ) . "\n";
$n = 0;
foreach ( $plan_series as $s ) {
    $n += count( $s['events'] );
}
echo "events: {$n}\n";
echo 'venues: ' . implode( ', ', array_keys( $venues_used ) ) . "\n";
echo 'organizers: ' . count( $organizers ) . "\n";
if ( $problems ) {
    echo "\nPROBLEMS:\n";
    foreach ( array_unique( $problems ) as $p ) {
        echo "  {$p}\n";
    }
} else {
    echo "\nno problems.\n";
}
