<?php
/**
 * BUILD THE IMPORT PLAN FROM MARK'S LIST AND THE FOUR TEC EXPORTS.
 *
 * THERE ARE THREE SOURCES AND THEY RANK, most authoritative first:
 *
 *   1. WHAT MARK SUPPLIES DIRECTLY, written out below as `desc`, `time`,
 *      `pat` and `on`. Most recent, and it wins over everything.
 *   2. THE STONEWALL PROJECT GROUP INFO SHEET, current as of 2026-27. It
 *      supersedes the export for every Stonewall series.
 *   3. THE EXPORT, and only its MOST RECENT occurrence of a title.
 *
 * Where a description or a schedule appears in 1 or 2, the export's is not
 * taken. The export still supplies what neither of them mentions: a featured
 * image, and the times of the three PROP groups.
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
 * in brackets after it, "94114 (Strut)", on the venues this import needs. And
 * _VenueState is empty on some rows where _VenueStateProvince holds the state,
 * so all three spellings are tried in turn rather than the first one winning by
 * being first in the file.
 */
function venue_parts( $v ) {
    $zip   = preg_replace( '/\s*\([^)]*\)\s*$/u', '', m1( $v, '_VenueZip' ) );
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
 * rows say 09:30-11:00 and Wednesday, and its most recent says 12:30-14:00 and
 * Thursday; Express Yourself's early rows are Thursdays and its most recent is
 * a Monday. The Stonewall Project's own 2026-27 sheet agrees with the recent
 * rows in both cases, so the first row in the file is not just the oldest fact
 * in it, it is the wrong one.
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
 * Copy supplied by Mark, and by the Stonewall Project sheet.
 *
 * Verbatim. Paragraph breaks are blank lines, which is what wpautop reads and
 * what the export's own content uses.
 * ------------------------------------------------------------------------ */

/** The three Leather Labs share a sentence and differ in topic and host. */
function leather_lab( $topic, $host ) {
    return "Free pre-Folsom education series hosted by Onyx Northwest! The {$topic} event will be hosted by {$host}. "
        . 'Held at Strut, 470 Castro St., with testing and PrEP enrollment, food, and raffle prizes. '
        . 'This event is sponsored by Gilead and held in partnership with SFAF.';
}

/**
 * PROP, PROP for All and PROP Empowerment share one description on the sheet.
 *
 * THE SHEET ALSO CARRIES A CONTACT PER GROUP AND THIS DOES NOT. Tyrone Clifford
 * for PROP and PROP Empowerment, Tomas Llorence for PROP for All, both with
 * phone numbers. They are not here because what was supplied for the import is
 * the SHARED paragraph, and the contacts are the part that differs per group.
 * The export's three separate descriptions carried the same two contacts and
 * are being superseded, so dropping them here loses them twice. Reported rather
 * than added, because which one belongs on PROP Empowerment is not stated.
 */
$PROP_DESC = "Structured community, counseling, referrals, and support for all people interested in addressing their use of meth or cocaine. Any and all goals are supported.\n\n"
    . "PROP centers gay, bi and trans men and other men who have sex with men, and trans women.\n\n"
    . 'PROP for All is open to all adults.';

/** Both Coffee Socials are the same gathering at the same place. */
$COFFEE_DESC = "Join us at our Saturday Morning Coffee Social at the famous Maxfield's House of Caffeine, where Wendy and her friendly staff invite us to have a drink and a bite and spend time with other community members. It is a great way to start the weekend!";

/* ---------------------------------------------------------------------------
 * MARK'S LIST. THE AUTHORITATIVE STRUCTURE.
 *
 * Organizer, then series, then the events each series holds. Series and event
 * are not one to one: Coffee Social, PROP, Mobile Health Sites and Strut
 * community events each hold several distinct events.
 *
 * Per event:
 *   src    the export title this matches. Absent where nothing matches, and
 *          kept where the export still supplies an image or a time even though
 *          its description has been superseded.
 *   src2   a second export title, for the one series that combines two groups.
 *   from   where the description came from: mark, sheet, export, or nothing.
 *   sched  how its dates are decided:
 *            list  a day and time, or a set of dates, stated by Mark or by the
 *                  Stonewall sheet.
 *            rule  the export carries a rule with NO end date.
 *            none  no live schedule. Times and description only.
 *   pat    the plugin pattern (SFAF_Recurrence), for 'list' and 'rule'.
 *   also   a further nth-weekday rule folded in as explicit dates, for the four
 *          groups that meet on two ordinals of the month. The plugin's
 *          monthly_nth carries one ordinal, so the second arrives the way a
 *          hand-picked date does.
 *   on     fixed dates: the three Leather Labs and the two Coffee Socials.
 *   time   start and end, wherever they are stated rather than taken.
 *   venue  a venue stated rather than taken.
 *   cats   caladmin categories, each a preference order of names.
 *   online stated as virtual or on Zoom, or the export says online.
 *   desc   description text stated here rather than taken from the export.
 * ------------------------------------------------------------------------ */

$SUPPORT   = array( 'Support Groups', 'Support groups' );
$PROGRAM   = array( 'Program Groups', 'Program groups' );
$COMMUNITY = array( 'Community events', 'Community Events', 'Community event' );

$HOWARD = 'SFAF Main Office';
$STRUT  = 'Strut';

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
                    'from'  => 'mark',
                    'sched' => 'list',
                    'on'    => array( '2026-09-05', '2026-09-12', '2026-09-26' ),
                    'time'  => array( '10:00', '12:00' ),
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => $COFFEE_DESC,
                ),
                array(
                    'name'  => 'Coffee On Us',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'on'    => array( '2026-09-19' ),
                    'time'  => array( '10:00', '12:00' ),
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => $COFFEE_DESC,
                ),
            ),
            'Dinner with Friends Community Meal' => array(
                array(
                    'name'  => 'Dinner with Friends Community Meal',
                    'src'   => 'Dinner with Friends',
                    'from'  => 'export',
                    'sched' => 'none',
                    'cats'  => array( $COMMUNITY ),
                ),
            ),
            'Damn, Daddy!' => array(
                array(
                    'name'   => 'Damn, Daddy!',
                    'src'    => 'Damn, Daddy! - Support Group (Online)',
                    'from'   => 'mark',
                    'sched'  => 'list',
                    'pat'    => 'weekly:1:3',
                    'time'   => array( '13:30', '15:30' ),
                    'cats'   => array( $SUPPORT ),
                    /*
                     * ONLINE IS NOT AN INFERENCE FROM THE OLD TITLE. The
                     * Stonewall sheet says "Wednesdays, 1:30 pm to 3:30 pm on
                     * zoom" and that it is transferring to another department,
                     * which is why Mark's list has it under Elizabeth Taylor
                     * 50-Plus rather than Stonewall. The sheet's contact for the
                     * transfer is Vince Crisostomo, who is also the 50-Plus
                     * contact in the export's Dinner with Friends copy.
                     */
                    'online' => true,
                    'desc'   => '"Damn, Daddy!" is a drop-in peer support group hosted by Aging Services at the San Francisco AIDS Foundation designed specifically for gay, bisexual, and transgender men aged 50 and older. The group provides a sex-positive and confidential space to discuss real-talk topics related to aging with dignity and pride, including sex, health, relationships, drugs, and family dynamics.',
                ),
            ),
            'Virtual Check-In' => array(
                array(
                    'name'   => 'Virtual Check-In',
                    'src'    => '50-Plus Wednesday Night Virtual Check-in (Members Only) - Elizabeth Taylor 50-Plus Network',
                    'from'   => 'mark',
                    'sched'  => 'list',
                    'pat'    => 'weekly:1:3',
                    'time'   => array( '18:00', '19:30' ),
                    'cats'   => array( $COMMUNITY ),
                    'online' => true,
                    'desc'   => 'Join us over Zoom as we check in with each other from the comfort of our own homes. Sometimes there is a topic of discussion, and sometimes it is a check-in to see how everyone is doing. Stay connected, and also find out what is happening in and around SFAF.',
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
                    'from'  => 'export',
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
                    'from'  => 'export',
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
                    'from'  => 'export',
                    'sched' => 'rule',
                    'pat'   => 'weekly:1:4',
                    'cats'  => array( $SUPPORT ),
                ),
            ),
            'Opioid Overdose Prevention and Reversal Training' => array(
                array(
                    'name'  => 'Opioid Overdose Prevention and Reversal Training',
                    'src'   => 'Capacitación sobre cómo revertir una sobredosis y cómo responder ante ella',
                    'from'  => 'export',
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
                    'from'  => 'export',
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
                    'from'  => 'export',
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
                    'from'  => 'export',
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
            'Brothers Who Read' => array(
                array(
                    'name'  => 'Brothers Who Read',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:3',
                    'time'  => array( '13:30', '15:30' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => "Join Black Brothers Esteem (BBE) for a book club group where we read and discuss books! Conversations are honest, community-minded, and rooted in a love of reading.\n\n"
                        . "All are welcome and encouraged to join.\n\n"
                        . 'Virtual option available, contact bbe@sfaf.org for info.',
                ),
            ),
            'Phoenix Rising' => array(
                array(
                    'name'  => 'Phoenix Rising',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:3',
                    'time'  => array( '16:00', '18:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $SUPPORT ),
                    'desc'  => "Join Black Brothers Esteem (BBE) for our weekly support group, where we build community with other members, share our concerns, discover new perspectives, and build friendships.\n\n"
                        . 'Our group on the last Wednesday of the month is open to all allies.',
                ),
            ),
            'BBE Game & Movie Night' => array(
                array(
                    'name'  => 'BBE Game & Movie Night',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'pat'   => 'monthly_nth:4:5',
                    'time'  => array( '17:00', '20:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => "Join Black Brothers Esteem (BBE) once a month to play board games, watch movies, and play cards with the goal of not only winning but having fun.\n\n"
                        . 'Our Game Night is fun and friendly for casual board gamers, but also a great place to play the heavy cardboard. We will teach you new games, or you can bring your own and teach us.',
                ),
            ),
        ),
    ),

    'HUES' => array(
        'aliases' => array(
            'Healing & Uniting Every Sista (HUES)',
            'Healing and Uniting Every Sista (HUES)',
            'Healing & Uniting Every Sista',
        ),
        'series' => array(
            'HUES Sista Circles' => array(
                array(
                    'name'   => 'HUES Sista Circles',
                    'from'   => 'mark',
                    'sched'  => 'list',
                    'pat'    => 'monthly_nth:2:6',
                    'also'   => array( array( 4, 6 ) ),
                    'time'   => array( '10:00', '12:00' ),
                    'cats'   => array( $SUPPORT ),
                    'online' => true,
                    'desc'   => "At our twice-monthly Sista Circles, women living with and affected by HIV come together for support, connection, and meaningful conversation in a space rooted in sisterhood and healing.\n\n"
                        . 'Contact Ebony at egordon@sfaf.org for more information or to join.',
                ),
            ),
            'Soul Sessions' => array(
                array(
                    'name'  => 'Soul Sessions',
                    'src'   => 'Soul Sessions',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'pat'   => 'monthly_nth:2:4',
                    'also'  => array( array( 4, 4 ) ),
                    'time'  => array( '16:00', '18:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => "Join HUES (Healing & Uniting Every Sista) for a twice-monthly gathering for Black women. We offer a warm and welcoming space to connect, unwind, and thrive, with engaging discussion, fun games, creative crafts, and shared experiences.\n\n"
                        . "We foster joy, wellness, and community connection.\n\n"
                        . 'Virtual option available. Contact Ebony at egordon@sfaf.org for more info or to join virtually.',
                ),
            ),
        ),
    ),

    'Stonewall Project' => array(
        'aliases' => array( 'The Stonewall Project' ),
        'series'  => array(
            'Express Yourself' => array(
                array(
                    'name'  => 'Express Yourself',
                    'src'   => 'Express Yourself - Support Group',
                    'from'  => 'sheet',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:1',
                    'time'  => array( '14:30', '16:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $SUPPORT ),
                    'desc'  => 'Join us in this art therapy group and Express Yourself! All you need is an open mind and willingness to play.',
                ),
            ),
            'Off Mute' => array(
                array(
                    'name'  => 'Off Mute',
                    'src'   => 'Off Mute - Support Group',
                    'from'  => 'sheet',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:2',
                    'time'  => array( '14:30', '16:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $SUPPORT ),
                    'desc'  => 'A substance use support group focusing on sharing and reconnecting after much time apart; open to folks with all substance use goals.',
                ),
            ),
            'Stay on Target' => array(
                array(
                    'name'  => 'Stay on Target',
                    'from'  => 'sheet',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:3',
                    'time'  => array( '18:00', '19:30' ),
                    'venue' => $STRUT,
                    'cats'  => array( $SUPPORT ),
                    'desc'  => "Navigating substances requires balance. This group is for those members who would like to stay clear of some substances but still enjoy others, and also for those who would like to use any substance and focus on reducing the negative consequences of their use.\n\n"
                        . 'Topics include goal setting, triggers, navigating relationships and sex-drug linked behavior, exploring emotions, and also nutrition, hydration, day-after recovery and general health and well-being.',
                ),
            ),
            'Abstinence Support Group' => array(
                array(
                    'name'  => 'Abstinence Support Group',
                    'src'   => 'Abstinence Support Group',
                    'from'  => 'sheet',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:3',
                    'time'  => array( '18:00', '19:30' ),
                    'venue' => $STRUT,
                    'cats'  => array( $SUPPORT ),
                    'desc'  => 'A safe space for people with abstinence goals to support one another, share knowledge, and learn skills. We help you achieve and maintain abstinence from all substances.',
                ),
            ),
            'Skills and Thrills' => array(
                array(
                    'name'   => 'Skills and Thrills',
                    'from'   => 'sheet',
                    'sched'  => 'list',
                    'pat'    => 'weekly:1:4',
                    'time'   => array( '18:00', '19:30' ),
                    'cats'   => array( $SUPPORT ),
                    'online' => true,
                    'desc'   => "Join us for a discussion on what actually works, and what does not. We will talk skills, coping, managing emotions, distress tolerance, drug linked sexual behavior, how to make a plan to meet your goals, and much more.\n\n"
                        . 'Take the confusion out of recovery. This group can help harm reduction veterans or newbies process where they feel stuck or challenged.',
                ),
            ),
            'El Salón' => array(
                array(
                    'name'  => 'El Salón',
                    'src'   => 'El Salón - Grupo de apoyo',
                    'from'  => 'sheet',
                    'sched' => 'list',
                    'pat'   => 'weekly:1:4',
                    'time'  => array( '12:30', '14:00' ),
                    'venue' => $HOWARD,
                    'cats'  => array( $SUPPORT ),
                    /*
                     * THE SHEET HAS ONE MORE LINE THAN THIS, and it is left out
                     * on purpose: "Para otros servicios en español por favor
                     * llame a Claudia Figallo 415-487-8013 cfigallo@sfaf.or".
                     * The address in it is missing its last letter in the
                     * source document, so importing it would publish a broken
                     * one. Reported rather than corrected.
                     */
                    'desc'  => 'Reúnete con nosotros para hablar de tu relación con alcohol y drogas y como usar de manera más saludable. Este será un espacio relajado y libre de juicios para conversar acerca de varios temas que nos afectan incluyendo las relaciones personales, familia, inmigración, cultura y más.',
                ),
            ),
            'PROP' => array(
                array(
                    'name'  => 'PROP',
                    'src'   => 'PROP Contingency Management',
                    'from'  => 'sheet',
                    'sched' => 'none',
                    'cats'  => array( $PROGRAM, $SUPPORT ),
                    'desc'  => $PROP_DESC,
                ),
                array(
                    'name'  => 'PROP for All',
                    'src'   => 'PROP for All - Contingency Management',
                    'from'  => 'sheet',
                    'sched' => 'none',
                    'cats'  => array( $PROGRAM ),
                    'desc'  => $PROP_DESC,
                ),
                array(
                    'name'  => 'PROP Empowerment',
                    'src'   => 'PROP Empowerment Program',
                    'from'  => 'sheet',
                    'sched' => 'none',
                    'cats'  => array( $SUPPORT ),
                    'desc'  => $PROP_DESC,
                ),
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
                    'from'  => 'mark',
                    'sched' => 'list',
                    'on'    => array( '2026-09-05' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => $STRUT,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => leather_lab( 'Safe and Fun Impact Play', 'Master Hines' ),
                ),
                array(
                    'name'  => 'Leather Lab: Harm Reduction & Chemsex',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'on'    => array( '2026-09-12' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => $STRUT,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => leather_lab( 'Harm Reduction & Chemsex', 'Daddius ONYX' ),
                ),
                array(
                    'name'  => 'Leather Lab: Kink and Spirituality',
                    'from'  => 'mark',
                    'sched' => 'list',
                    'on'    => array( '2026-09-19' ),
                    'time'  => array( '12:00', '14:00' ),
                    'venue' => $STRUT,
                    'cats'  => array( $COMMUNITY ),
                    'desc'  => leather_lab( 'Kink and Spirituality', 'Sir Dion ONYX' ),
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

            // A stated venue wins; an online event has none at all.
            $venue = '';
            if ( empty( $e['online'] ) ) {
                $venue = isset( $e['venue'] ) ? $e['venue'] : ( $d ? $d['venue'] : '' );
            }
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

            $from = isset( $e['from'] ) ? $e['from'] : '';
            if ( '' === $content && '' !== $from ) {
                $problems[] = "event \"{$e['name']}\" claims a description from {$from} and has none";
            }

            $out_events[] = array(
                'title'   => $e['name'],
                'source'  => trim( $src . ( ! empty( $e['src2'] ) ? ' + ' . $e['src2'] : '' ) ),
                'from'    => $from,
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
    . " * Mark's list is the structure. Descriptions and schedules come from what\n"
    . " * Mark supplied, then from the Stonewall Project sheet, then from the\n"
    . " * export's most recent occurrence. Each event records which, in 'from'.\n"
    . " */\n"
    . 'return ' . var_export( $plan, true ) . ";\n";

file_put_contents( __DIR__ . '/plan.php', $out );

$n = 0;
$by_from = array( 'mark' => 0, 'sheet' => 0, 'export' => 0, '' => 0 );
foreach ( $plan_series as $s ) {
    foreach ( $s['events'] as $e ) {
        $n++;
        $by_from[ $e['from'] ]++;
    }
}
echo 'series: ' . count( $plan_series ) . "\n";
echo "events: {$n}\n";
printf( "descriptions: %d from Mark, %d from the Stonewall sheet, %d from the export, %d none\n",
    $by_from['mark'], $by_from['sheet'], $by_from['export'], $by_from[''] );
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
