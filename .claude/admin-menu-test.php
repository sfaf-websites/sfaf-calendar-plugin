<?php
/**
 * WHAT IS LEFT IN THE WORDPRESS EVENTS MENU, AND WHAT IS STILL REACHABLE.
 *
 * 3.27.0 left the WordPress admin holding administrator concerns only and moved
 * everything an event manager does to /caladmin, because two forms over one
 * record drift. Three different mechanisms did that, and each fails in a
 * different way:
 *
 *   not registered at all      RSVPs, Series, Series migration
 *   registered then unlisted   Add New, Shortcode Generator
 *   show_in_menu => false      Categories, Organizers
 *
 * The third is the one worth a test on its own. show_ui => false would also
 * have removed the menu entry, and would have taken the metabox off the event
 * editor and the term screens with it. The flags look interchangeable and are
 * not, so this asserts which one is set.
 *
 *     php .claude/admin-menu-test.php
 *
 * WHAT IT STUBS. The four WordPress functions that build a menu, and nothing
 * else. SFAF_Admin::add_menu_pages(), SFAF_Admin::hide_duplicate_submenus() and
 * SFAF_Post_Types::register_taxonomies() are the real methods from the real
 * files, so what is asserted is what those will do when WordPress calls them.
 *
 * WHAT IT CANNOT PROVE: that a screen renders, or that WordPress routes a page
 * whose menu entry was removed. remove_submenu_page() only touches the $submenu
 * array and leaves $_registered_pages alone, which is why the two unlisted
 * pages stay reachable, but that is WordPress's behaviour rather than ours and
 * this file does not re-implement it.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$GLOBALS['submenu']    = array();
$GLOBALS['taxonomies'] = array();

function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $callback = '', $position = null ) {
    $GLOBALS['submenu'][ $parent ][] = array( 'slug' => $slug, 'title' => $menu_title, 'cap' => $cap );
    return 'sfaf_' . $slug;
}

function remove_submenu_page( $parent, $slug ) {
    if ( ! isset( $GLOBALS['submenu'][ $parent ] ) ) {
        return false;
    }
    foreach ( $GLOBALS['submenu'][ $parent ] as $i => $item ) {
        if ( $item['slug'] === $slug ) {
            unset( $GLOBALS['submenu'][ $parent ][ $i ] );
            $GLOBALS['submenu'][ $parent ] = array_values( $GLOBALS['submenu'][ $parent ] );
            return $item;
        }
    }
    return false;
}

function register_taxonomy( $tax, $object_type, $args = array() ) {
    $GLOBALS['taxonomies'][ $tax ] = $args;
}
function register_post_type( $type, $args = array() ) {}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {}
function add_meta_box( $id, $title, $cb, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {}

/* SFAF_Series registers the series taxonomy from inside register_taxonomies(). */
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function register_taxonomy() {
        register_taxonomy( self::TAXONOMY, 'uc_event', array( 'show_ui' => false, 'show_in_menu' => false ) );
    }
}

require $root . '/admin/class-sfaf-admin.php';
require $root . '/includes/class-sfaf-post-types.php';

$PARENT = 'edit.php?post_type=uc_event';

/*
 * WORDPRESS PUTS THESE THERE ITSELF, before any plugin code runs: the post
 * type's own two entries, and one per taxonomy that asks to be in the menu.
 * Modelled here because they are what "Add New Event" and "Categories" ARE, and
 * a test of our menu that did not contain them could not show them leaving.
 */
$GLOBALS['submenu'][ $PARENT ][] = array( 'slug' => 'edit.php?post_type=uc_event', 'title' => 'All Events', 'cap' => 'edit_posts' );
$GLOBALS['submenu'][ $PARENT ][] = array( 'slug' => 'post-new.php?post_type=uc_event', 'title' => 'Add New Event', 'cap' => 'edit_posts' );

$admin = new SFAF_Admin();
$admin->add_menu_pages();
$admin->hide_duplicate_submenus();

$types = new SFAF_Post_Types();
$types->register_taxonomies();

// A taxonomy appears in the menu unless it says otherwise. Same default
// WordPress applies: show_in_menu falls back to show_ui, which falls back to
// public.
foreach ( $GLOBALS['taxonomies'] as $tax => $args ) {
    $public  = array_key_exists( 'public', $args ) ? (bool) $args['public'] : true;
    $show_ui = array_key_exists( 'show_ui', $args ) ? (bool) $args['show_ui'] : $public;
    $in_menu = array_key_exists( 'show_in_menu', $args ) ? (bool) $args['show_in_menu'] : $show_ui;
    if ( $in_menu ) {
        $GLOBALS['submenu'][ $PARENT ][] = array( 'slug' => 'edit-tags.php?taxonomy=' . $tax, 'title' => $tax, 'cap' => 'manage_categories' );
    }
}

$menu = array();
foreach ( $GLOBALS['submenu'][ $PARENT ] as $item ) {
    $menu[] = $item['slug'];
}

/* WHAT THE MENU IS SUPPOSED TO BE. Order is not asserted; membership is. */
$expected = array(
    'edit.php?post_type=uc_event', // All Events. The list stays.
    'uc-embed',                    // Embed Code
    'uc-automation',               // Automation
    'uc-users',                    // Calendar Users, the access fallback
    'uc-settings',                 // Settings
);

/* WHAT MUST NOT BE IN IT, each named so a failure says which one came back. */
$forbidden = array(
    'post-new.php?post_type=uc_event'    => 'Add New Event',
    'uc-rsvps'                           => 'RSVPs',
    'uc-series'                          => 'Series',
    'uc-migrate'                         => 'Series migration',
    'uc-shortcode-generator'             => 'Shortcode Generator',
    'edit-tags.php?taxonomy=uc_event_category' => 'Categories',
    'edit-tags.php?taxonomy=uc_organizer'      => 'Organizers',
    'edit-tags.php?taxonomy=uc_venue'          => 'Venues',
    'edit-tags.php?taxonomy=uc_series'         => 'Series taxonomy',
);

$fails = array();

foreach ( $expected as $slug ) {
    if ( ! in_array( $slug, $menu, true ) ) {
        $fails[] = "$slug should be in the Events menu and is not";
    }
}
foreach ( $forbidden as $slug => $label ) {
    if ( in_array( $slug, $menu, true ) ) {
        $fails[] = "$label ($slug) is back in the Events menu";
    }
}
foreach ( $menu as $slug ) {
    if ( ! in_array( $slug, $expected, true ) && ! isset( $forbidden[ $slug ] ) ) {
        $fails[] = "$slug is in the Events menu and this test has never heard of it";
    }
}

/*
 * THE TAXONOMIES LOST THE MENU ENTRY AND NOTHING ELSE.
 *
 * This is the assertion that catches show_in_menu being "simplified" to
 * show_ui later: the entry would still be gone, the menu test above would still
 * pass, and the metabox on the event editor would have vanished with no error
 * anywhere. uc_venue is the deliberate exception and is checked as one.
 */
foreach ( array( 'uc_event_category', 'uc_organizer' ) as $tax ) {
    $args = isset( $GLOBALS['taxonomies'][ $tax ] ) ? $GLOBALS['taxonomies'][ $tax ] : null;
    if ( null === $args ) {
        $fails[] = "$tax is no longer registered at all";
        continue;
    }
    if ( ! array_key_exists( 'show_in_menu', $args ) || false !== $args['show_in_menu'] ) {
        $fails[] = "$tax does not set show_in_menu => false, so it is still in the menu";
    }
    if ( array_key_exists( 'show_ui', $args ) && false === $args['show_ui'] ) {
        $fails[] = "$tax sets show_ui => false, which also removes its metabox from the event editor";
    }
    if ( ! array_key_exists( 'public', $args ) || true !== $args['public'] ) {
        $fails[] = "$tax is no longer public, which changes its archive and its REST output";
    }
}

if ( ! isset( $GLOBALS['taxonomies']['uc_venue']['show_ui'] ) || false !== $GLOBALS['taxonomies']['uc_venue']['show_ui'] ) {
    $fails[] = 'uc_venue no longer sets show_ui => false, and it is meant to be the stronger case';
}

echo "WordPress Events menu\n";
echo 'kept:      ' . implode( ', ', $expected ) . "\n";
echo 'gone:      ' . implode( ', ', array_values( $forbidden ) ) . "\n";
echo "checked:   nothing unexpected is in the menu, nothing removed has come back, and the two\n";
echo "           taxonomies lost show_in_menu without losing show_ui, public, or their metaboxes\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the menu is administrator concerns only, and nothing lost a capability on the way out.\n";
exit( 0 );
