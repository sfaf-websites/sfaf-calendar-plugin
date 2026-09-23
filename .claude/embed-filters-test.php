<?php
/**
 * THE EMBED HAS ITS OWN SCRIPT AND ITS OWN ROUTE (3.88.0).
 *
 *     php .claude/embed-filters-test.php
 *
 * WHY THIS FILE EXISTS, AND IT IS THE THIRD TIME. A correct change reached the
 * shortcode and not the embed three times now: the list card was rebuilt into a
 * renderer only the shortcode used, the toggle rendered into a panel the
 * stacked layout hid, and 3.86.0 taught SEARCH to redraw the month grid on the
 * admin-ajax path while the embed went on asking its own REST route for
 * mode=items and nothing else.
 *
 * THE RENDERERS ARE SHARED AND THE SCRIPTS ARE NOT. calendar.js and embed.js are
 * two files with two sets of handlers over one set of markup, and nothing makes
 * them agree. So this file asserts the PAIRS: for each behaviour the filter bar
 * has, both scripts must have it.
 *
 * WHAT WAS FOUND BY WRITING IT. embed.js had no handler for the merged
 * Organizers and Groups control at all. It still listened for
 * `[data-uc-organizer]` and `[data-uc-group]`, the two controls 3.85.0 replaced,
 * so on an embed the dropdown opened and did nothing whatever was ticked.
 */
$root  = dirname( __DIR__ );
$fails = array();

$cal = file_get_contents( $root . '/public/js/calendar.js' );
$emb = file_get_contents( $root . '/public/js/embed.js' );

/*
 * COMMENTS STRIPPED FROM THE PHP BEFORE ANY MATCH. Every docblock in
 * class-sfaf-embed.php names the hooks and the headers it is describing, so a
 * raw match reads prose: removing the upgrader_process_complete REGISTRATION
 * left the word in three comments and the check stayed green. Caught by
 * planting it, which is the third time this exact trap has been met here.
 */
$php = '';
foreach ( token_get_all( file_get_contents( $root . '/includes/class-sfaf-embed.php' ) ) as $tok ) {
    if ( is_array( $tok ) ) {
        if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
        $php .= $tok[1];
    } else {
        $php .= $tok;
    }
}

echo "Both scripts, one filter bar\n";

/* ---------------------------------------------------------------------------
 * 1. THE MERGED CONTROL IS HANDLED IN BOTH.
 * ------------------------------------------------------------------------ */
foreach ( array( 'calendar.js' => $cal, 'embed.js' => $emb ) as $name => $src ) {
    if ( false === strpos( $src, 'data-uc-who-organizer' ) ) {
        $fails[] = $name . ' has no handler for the merged Organizers and Groups control, so ticking an organizer does nothing there';
    }
    if ( false === strpos( $src, 'data-uc-who-group-orgs' ) ) {
        $fails[] = $name . ' does not narrow the groups by the selected organizers';
    }
    /* THE TWO RULES THAT ARE EASIEST TO "TIDY" AWAY, asserted in both because
     * they are decisions rather than implementation. */
    if ( false === strpos( $src, '!mine.length' ) ) {
        $fails[] = $name . ' no longer always shows a group with no organizered events, which is about a third of them';
    }
    if ( false === strpos( $src, 'box.checked' ) ) {
        $fails[] = $name . ' can hide a group that is ticked, leaving a filter running with nothing to clear it by';
    }
    if ( false === strpos( $src, 'Organizers and groups' ) ) {
        $fails[] = $name . ' does not label the closed trigger';
    }
}

/* DECLARED IS NOT WIRED. The checks above find the handler's CODE, and renaming
 * the function that holds it leaves every one of those strings in place while
 * nothing runs. Both scripts must actually call their binder, which is the
 * difference between the control working and the control existing. */
if ( ! preg_match( '#\n\s*bindWho\(\s*container\s*\);#', $emb ) ) {
    $fails[] = 'embed.js declares the merged control handler and never calls it, so the dropdown does nothing on an embed';
}
if ( ! preg_match( "#run\(\s*'who',\s*initWhoPicker\s*\)#", $cal ) ) {
    $fails[] = 'calendar.js declares the merged control handler and never runs it';
}

/* ---------------------------------------------------------------------------
 * 1b. THE PILL HANDLER TAKES THE SUBMIT AWAY, IN BOTH (3.98.1).
 *
 * The pills sit inside the filter bar's real GET form, which is what makes them
 * work with script off. With script on, a handler that filters in place and
 * does not prevent the default gets both: the block redraws and then the page
 * reloads on top of it. That was live on sfaf.org from 3.85.0.
 *
 * ASSERTED ON THE HANDLER, NOT THE FILE. Both scripts call preventDefault in a
 * dozen places, so a search for the word proves nothing; what matters is that
 * the pill handler takes an event and prevents it, which is what these two
 * patterns pin. filter-submit-live.php then presses the pills in a browser and
 * watches whether anything moves, which is the half no source read can do.
 * ------------------------------------------------------------------------ */
if ( ! preg_match( "#addEventListener\('click', function \(e\) \{\s*(?:/\*.*?\*/\s*)?e\.preventDefault\(\);#s", $emb ) ) {
    $fails[] = 'embed.js binds the category pills with a handler that does not prevent the default, so a press filters the block and then reloads the page on top of it';
}
if ( ! preg_match( "#on\('click', '\.uc-filter-btn', function \(e\) \{\s*(?:/\*.*?\*/\s*)?e\.preventDefault\(\);#s", $cal ) ) {
    $fails[] = 'calendar.js binds the category pills with a handler that does not prevent the default, so a press filters the block and then reloads the page on top of it';
}

/* ---------------------------------------------------------------------------
 * 2. SEARCH REDRAWS THE MONTH IN BOTH.
 *
 * THIS IS THE FAULT MARK FOUND IN THE NETWORK TAB. The embed asked for
 * mode=items, which is the list, and the list in calendar view is hidden or far
 * below, so the grid never moved and the box looked dead.
 * ------------------------------------------------------------------------ */
if ( ! preg_match( '#loadMonth\(\s*\$block#', $cal ) ) {
    $fails[] = 'calendar.js no longer redraws the month when somebody searches';
}
if ( ! preg_match( '#loadMonth\(\s*container#', $emb ) ) {
    $fails[] = 'embed.js no longer redraws the month when somebody searches, so search does nothing in calendar view on an embed';
}
/* And the redraw has to be IN the search handler rather than merely present in
 * the file, which is the difference between the fault and the fix. */
if ( ! preg_match( '#data-active-search[^;]*;\s*(?:/\*.*?\*/\s*)?var grid = gridOf\( *container *\);#s', $emb ) ) {
    $fails[] = 'embed.js sets the search term but does not load the month from the same handler';
}

/* ---------------------------------------------------------------------------
 * 3. EVERY NARROWING IS IN BOTH MONTH CACHE KEYS.
 *
 * A client-side month cache keyed on less than the query means searching, or
 * ticking an organizer, is answered from the grid cached for the other state.
 * ------------------------------------------------------------------------ */
if ( ! preg_match( '#function monthKey\([^)]*\)\s*\{(.*?)\n    \}#s', $cal, $m_cal ) ) {
    $fails[] = 'calendar.js has no monthKey(); this check is blind';
} else {
    foreach ( array( 'data-filter-s' => 'the search term', 'activeGroups' => 'the chosen groups' ) as $needle => $what ) {
        if ( false === strpos( $m_cal[1], $needle ) ) {
            $fails[] = 'calendar.js month cache key does not include ' . $what;
        }
    }
}
if ( ! preg_match( '#function monthCacheKey\([^)]*\)\s*\{(.*?)\n    \}#s', $emb, $m_emb ) ) {
    $fails[] = 'embed.js has no monthCacheKey(); this check is blind';
} else {
    foreach ( array(
        'data-active-search'    => 'the search term',
        'data-active-groups'    => 'the chosen groups',
        'data-active-organizer' => 'the chosen organizers',
    ) as $needle => $what ) {
        if ( false === strpos( $m_emb[1], $needle ) ) {
            $fails[] = 'embed.js month cache key does not include ' . $what;
        }
    }
}

/* ---------------------------------------------------------------------------
 * 3b. THE PANEL SURVIVES THE REDRAW, IN BOTH (3.89.0).
 *
 * WHY THIS FILE PASSED WHILE THE BEHAVIOUR WAS MISSING FROM ONE SCRIPT, which
 * is worth writing down because it is the limit of the whole idea. A pair test
 * only covers the pairs SOMEBODY ENUMERATED. When this file was written in
 * 3.88.0 it listed the behaviours being fixed that day: the merged control, the
 * narrowing, the month redraw, the cache keys. Carrying the open state across a
 * redraw was added to calendar.js in 3.87.0, a release earlier, and was never
 * in the list, so its absence from embed.js was not something the file could
 * notice. It was green and it was blind.
 *
 * THE REMEDY IS NOT "WRITE A BETTER LIST". It is that anything touching the
 * filter bar in ONE script adds its pair here in the SAME release, which is now
 * recorded in PROJECT.md rather than left to memory.
 *
 * TWO CLAIMS PER SCRIPT, because they fail separately and look identical:
 *   . the open state is READ BEFORE the swap and PUT BACK after it;
 *   . the narrowing is REAPPLIED after it, because the hiding is an attribute
 *     on rows the swap has just replaced.
 * ------------------------------------------------------------------------ */
if ( ! preg_match( '#var whoWasOpen#', $cal ) || ! preg_match( "#\\\$fresh\.find\(\s*'\[data-uc-who\]'\s*\)\.prop\(\s*'open',\s*true\s*\)#", $cal ) ) {
    $fails[] = 'calendar.js no longer carries the open filter panel across a redraw, so every tick shuts it';
}
if ( ! preg_match( '#var whoOpen#', $emb ) || ! preg_match( '#who\.open = true;#', $emb ) ) {
    $fails[] = 'embed.js no longer carries the open filter panel across a redraw, so every tick shuts it on an embed';
}
if ( ! preg_match( '#function narrowWho#', $cal ) ) {
    $fails[] = 'calendar.js narrowing is not reachable from the redraw, so the hidden groups come back after a tick';
}
if ( ! preg_match( '#function narrowWho#', $emb ) ) {
    $fails[] = 'embed.js narrowing is not reachable from the redraw, so the hidden groups come back after a tick';
}
/* AND IT IS ACTUALLY CALLED FROM THE SWAP. Declaring it is not calling it,
 * which is the trap that let a planted rename pass this file's first draft. */
/* MATCHED ON WHAT IT OPERATES ON, NOT ON PROXIMITY. The first draft looked for
 * narrowWho() anywhere after replaceWith(), with a lazy match that ran to the
 * end of the file, so deleting the call still found a later mention and the
 * plant escaped. Narrowing $fresh is inherently after the swap, because $fresh
 * is what the swap put there. */
if ( ! preg_match( "#\\\$fresh\.find\(\s*'\[data-uc-who\]'\s*\)\.each\([^)]*\)\s*\{\s*narrowWho\(#", $cal ) ) {
    $fails[] = 'calendar.js does not reapply the narrowing to the block it just swapped in';
}
if ( ! preg_match( '#container\.innerHTML = data\.html;(.|\n)*?narrowWho\(\s*container\s*\)#', $emb ) ) {
    $fails[] = 'embed.js does not reapply the narrowing after replacing the block';
}

echo "The route and its caching\n";

/* ---------------------------------------------------------------------------
 * 4. THE ROUTE READS WHAT THE FILTER BAR CAN SEND. It always did; this holds it
 *    so, because a parameter silently dropped here is a filter that does
 *    nothing with no error anywhere.
 * ------------------------------------------------------------------------ */
foreach ( array( 's', 'active_organizer', 'active_groups', 'active_category', 'series', 'venue', 'month', 'view', 'mode' ) as $p ) {
    if ( ! preg_match( "#get_param\(\s*'" . preg_quote( $p, '#' ) . "'\s*\)#", $php ) ) {
        $fails[] = 'the embed route no longer reads the ' . $p . ' parameter';
    }
}

/* 5. AND THE SERVER CACHE KEY CARRIES THEM. */
if ( ! preg_match( '#private function cache_identity\([^)]*\)\s*\{(.*?)\n    \}#s', $php, $ident ) ) {
    $fails[] = 'cache_identity() could not be read; this check is blind';
} else {
    foreach ( array( "'s'", "'active_organizer'", "'active_groups'", "'active_category'", "'series'", "'venue'", "'mode'" ) as $key ) {
        if ( false === strpos( $ident[1], $key ) ) {
            $fails[] = 'the embed cache identity does not include ' . $key . ', so one query can be answered with another query\'s payload';
        }
    }
}

/* 6. A NARROWED ANSWER IS NOT PUBLICLY CACHEABLE BY THE BROWSER.
 *
 * `public, max-age=60` on a response built for one visitor's search is a
 * filtered calendar a shared proxy may keep, and is what put a 304 on a search
 * request in the Network tab. */
if ( false === strpos( $php, 'private, no-cache' ) ) {
    $fails[] = 'a narrowed embed response is still publicly cacheable, so a filter can be answered from a copy built before it';
}

/* 7. AND THE WHOLE CACHE RETIRES ON A PLUGIN UPDATE.
 *
 * Every other hook on it is a CONTENT event, so nothing told it the code that
 * builds the markup had changed. Flagged twice before it was built. */
if ( false === strpos( $php, 'upgrader_process_complete' ) ) {
    $fails[] = 'the embed cache does not flush on a plugin update, so a release can be served from a payload built before it';
}
if ( false === strpos( $php, 'sfaf_calendar_activated' ) ) {
    $fails[] = 'the embed cache does not flush on activation';
}
$boot = file_get_contents( $root . '/sfaf-calendar.php' );
if ( false === strpos( $boot, "do_action( 'sfaf_calendar_activated' )" ) ) {
    $fails[] = 'nothing fires sfaf_calendar_activated, so the activation flush never runs';
}

/* ---------------------------------------------------------------------------
 * 8. A STALE embed.js SAYS SO (3.89.0).
 *
 * Two releases of correct work were invisible until a hard refresh, because the
 * browser was running a cached copy of that file. The script URL is deliberately
 * UNVERSIONED and must stay so: a version in a pasted snippet pins it rather
 * than busting it, which was a real defect in 2.10.1. So the version travels in
 * the payload instead and the mismatch is named in the console.
 *
 * THE CONSTANT CANNOT DRIFT, which is the only thing that makes this worth
 * having: if it were bumped by hand at release time it would be forgotten once
 * and then report nonsense forever. The build fails instead.
 * ------------------------------------------------------------------------ */
if ( ! preg_match( "#var EMBED_JS_VERSION = '([0-9.]+)';#", $emb, $js_v ) ) {
    $fails[] = 'embed.js no longer records the version it was shipped as, so a cached copy cannot be detected';
} else {
    $plugin_v = '';
    if ( preg_match( "#define\(\s*'SFAF_VERSION',\s*'([0-9.]+)'\s*\)#", file_get_contents( $root . '/sfaf-calendar.php' ), $pv ) ) {
        $plugin_v = $pv[1];
    }
    if ( $js_v[1] !== $plugin_v ) {
        $fails[] = 'embed.js says it is ' . $js_v[1] . ' and the plugin is ' . $plugin_v
            . '; the constant is bumped with the version or it reports nonsense';
    }
}
/* THE CALL, NOT THE DECLARATION. Deleting the call left the function defined
 * and a name check still found it, which is the third time this trap has been
 * met in this file. It has to be invoked with what the payload carries. */
if ( ! preg_match( '#reportIfStale\(\s*data\.js_version\s*\)#', $emb ) ) {
    $fails[] = 'nothing compares the running script against the site, so a cached embed.js stays invisible';
}
if ( false === strpos( $php, 'js_version' ) ) {
    $fails[] = 'the payload no longer carries the version, so the script has nothing to compare itself against';
}
/* AND IT DOES NOT RELOAD ITSELF. A script that refetches when it dislikes a
 * number can loop against a CDN serving two versions from two edges. */
if ( preg_match( '#reportIfStale[\s\S]{0,600}?location\.reload#', $emb ) ) {
    $fails[] = 'embed.js reloads the page when it finds itself stale, which can loop on a page this plugin does not own';
}
/* THE SCRIPT URL STAYS UNVERSIONED. Reversing 2.10.1 would pin every snippet
 * already pasted to whatever the plugin was on the day it was copied. */
if ( preg_match( "#function script_url\(\)[^}]*embed\.js'\s*\.\s*'\?ver#", $php )
    || preg_match( "#embed\.js\?ver#", $php ) ) {
    $fails[] = 'the embed script URL carries a version again, which PINS every pasted snippet rather than busting its cache';
}

/* ------------------------------------------------------------------------ */
echo "\nThe embed's own script and its own route\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked: that BOTH scripts handle the merged Organizers and Groups control, narrow the\n";
echo "         groups the same way, keep a group with no organizered events and never hide a\n";
echo "         ticked one; that BOTH redraw the month when somebody searches, and that the\n";
echo "         embed does it from the search handler rather than merely owning the function;\n";
echo "         that BOTH month cache keys carry every narrowing; that the route still reads\n";
echo "         every parameter the bar can send and its cache identity carries them; that a\n";
echo "         narrowed response is not publicly cacheable; and that the cache retires on a\n";
echo "         plugin update and on activation\n\n";

if ( empty( $fails ) ) {
    echo "the embed does what the shortcode does.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
