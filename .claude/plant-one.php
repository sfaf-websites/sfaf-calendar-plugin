<?php
/* Apply one planted fault by name. Exits 1 and says so if the text it expects
 * is not there, so a plant can never silently fail to apply. */
$which = isset( $argv[1] ) ? $argv[1] : '';
$P = 'includes/class-sfaf-portal.php';
$C = 'public/css/portal.css';

$edits = array(
    /* The 3.36.0-3.40.0 shape: the cancel card rendered inside the event form. */
    'nested-form' => array( $P,
        '<?php $this->render_access_card( $user, $event_id ); ?>',
        '<?php $this->render_cancel_card( $user, $event_id ); ?>' . "\n" .
        '                <?php $this->render_access_card( $user, $event_id ); ?>' ),

    /* The fallback that unpublished a published event on a save naming no mode. */
    'draft-fallback' => array( $P,
        "sanitize_key( \$_POST['save_mode'] ) : 'keep'",
        "sanitize_key( \$_POST['save_mode'] ) : 'draft'" ),

    /* The save stops carrying the answered scope, so the editor asks again. */
    'drop-scope' => array( $P,
        "'edit_scope' => \$result['scope'],",
        '' ),

    /* A size and weight pair that is not a step on the ladder. */
    'off-ladder' => array( $C,
        '',   /* appended, see below */
        '' ),

    /* The inline organizer creation field returns to the editor. */
    'organizer-field' => array( $P,
        '<?php
                break;',
        '<input type="text" name="organizer_new" value="" />
                <?php
                break;' ),

    /* The save half that invents an organizer term. */
    'organizer-save' => array( $P,
        "wp_set_object_terms( \$event_id, \$orgs, 'uc_organizer' );",
        "if ( ! empty( \$_POST['organizer_new'] ) ) { \$orgs[] = (int) SFAF_Organizers::save( 0, \$_POST['organizer_new'] ); }\n            wp_set_object_terms( \$event_id, \$orgs, 'uc_organizer' );" ),
);

if ( 'off-ladder' === $which ) {
    file_put_contents( $C, "\n.uc-planted-fault { font-size: 13.5px; font-weight: 550; }\n", FILE_APPEND );
    echo "planted: off-ladder\n";
    exit( 0 );
}

if ( ! isset( $edits[ $which ] ) ) {
    fwrite( STDERR, "unknown plant: $which\n" );
    exit( 2 );
}

list( $file, $find, $replace ) = $edits[ $which ];
$src = file_get_contents( $file );
if ( false === strpos( $src, $find ) ) {
    fwrite( STDERR, "PLANT DID NOT APPLY: text not found for '$which' in $file\n" );
    exit( 1 );
}
/* Replace the FIRST occurrence only, which is what a real edit would be. */
$pos = strpos( $src, $find );
$src = substr( $src, 0, $pos ) . $replace . substr( $src, $pos + strlen( $find ) );
file_put_contents( $file, $src );
echo "planted: $which\n";
