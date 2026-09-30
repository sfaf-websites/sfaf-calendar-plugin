<?php
/**
 * THE WORDS OF EVERY MESSAGE A REGISTRANT RECEIVES, IN ENGLISH AND SPANISH (3.106.0).
 *
 * One catalogue: the messages, their variants, the shipped text in both
 * languages, the fixed labels around that text, and the overrides somebody
 * saves on the Email Templates screen. The builders in SFAF_Notifications,
 * SFAF_Follow and SFAF_Reminders take their words from here and nowhere else,
 * and the Templates screen and EMAILS.md render through those same builders,
 * so what the screen shows is what goes out.
 *
 * WHAT IS EDITABLE AND WHAT IS NOT. Each message has three pieces of text: the
 * subject (a page's title, for the two pages), the words above the event
 * details, and a closing line under them. The details table, the joining
 * block, the calendar buttons and the donate line sit between them and are
 * drawn by the builder, because they are facts and must not be edited away.
 * Their labels are here, in both languages, under labels().
 *
 * TOKENS ARE {name}. A paragraph that is nothing but a link token draws as a
 * button; a link token inside a sentence draws as a link with its label; a
 * paragraph holding a link token with no address (a test send has no cancel
 * link) is left out rather than printed half empty.
 *
 * STAFF MESSAGES ARE NOT HERE. The alert, the cancel alert, the summary, the
 * day-before count, the digest and the submission notices stay English and
 * keep their own builders.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Messages {

    /** One option per message per language: sfaf_email_{key}_{lang}. */
    const OPTION_PREFIX = 'sfaf_email_';

    /** @return array<string,string> */
    public static function languages() {
        return array( 'en' => 'English', 'es' => 'Spanish' );
    }

    /** A language code this catalogue has, else English. */
    public static function lang( $lang ) {
        return isset( self::languages()[ (string) $lang ] ) ? (string) $lang : 'en';
    }

    /**
     * Every token, with the name the Templates screen's chip shows.
     *
     * @return array<string,string>
     */
    public static function tokens() {
        return array(
            'first_name'   => 'First name',
            'last_name'    => 'Last name',
            'title'        => 'Event title',
            'date'         => 'Date',
            'time'         => 'Time',
            'location'     => 'Location',
            'organizer'    => 'Organizer',
            'old_date'     => 'Old date',
            'meeting_link' => 'Meeting link',
            'cancel_link'  => 'Cancel link',
            'confirm_link' => 'Confirm link',
            'event_link'   => 'Event page link',
            'position'     => 'Waitlist position',
            'expiry'       => 'Offer expiry',
            'series'       => 'Series name',
            'days'         => 'Days the link works',
            'stop_link'    => 'Stop link',
            'donate_link'  => 'Donate link',
            'count'        => 'Number of dates',
        );
    }

    /** The tokens that are links, and so draw as a link or a button. */
    public static function link_tokens() {
        return array( 'meeting_link', 'cancel_link', 'confirm_link', 'event_link', 'stop_link', 'donate_link' );
    }

    /**
     * Every registrant message, in the order the Templates screen lists them.
     *
     *   label     what the list calls it
     *   kind      email or page
     *   variants  variant key => its name on the list
     *   tokens    what its text may use
     *   links     the label a link token draws with, where it is not the usual one
     *
     * @return array
     */
    public static function catalog() {
        $event  = array( 'first_name', 'title', 'date', 'time', 'location', 'event_link' );
        $three  = array( 'in_person' => 'In person', 'online_link' => 'Online, with link', 'online_pending' => 'Online, link to come' );
        return array(
            'confirmation'   => array( 'label' => 'Confirmation', 'kind' => 'email', 'variants' => $three,
                'tokens' => array_merge( $event, array( 'last_name', 'organizer', 'meeting_link', 'cancel_link' ) ) ),
            'waitlist'       => array( 'label' => 'Waitlist confirmation', 'kind' => 'email', 'variants' => array( 'default' => '' ),
                'tokens' => array_merge( $event, array( 'position', 'cancel_link' ) ), 'links' => array( 'cancel_link' => 'leave_waitlist' ) ),
            'offer'          => array( 'label' => 'Waitlist offer', 'kind' => 'email', 'variants' => array( 'default' => '' ),
                'tokens' => array_merge( $event, array( 'expiry', 'confirm_link' ) ) ),
            'offer_passed'   => array( 'label' => 'Offer passed', 'kind' => 'email', 'variants' => array( 'default' => '' ),
                'tokens' => $event ),
            'reminder'       => array( 'label' => 'Morning-of reminder', 'kind' => 'email', 'variants' => $three,
                'tokens' => array_merge( $event, array( 'last_name', 'organizer', 'meeting_link', 'cancel_link' ) ) ),
            'cancelled'      => array( 'label' => 'Event cancelled', 'kind' => 'email', 'variants' => array( 'default' => 'One date', 'several' => 'Several dates' ),
                'tokens' => array_merge( $event, array( 'count' ) ) ),
            'changed'        => array( 'label' => 'Event changed', 'kind' => 'email', 'variants' => array( 'default' => 'One date', 'several' => 'Several dates' ),
                'tokens' => array_merge( $event, array( 'cancel_link', 'count' ) ) ),
            'reinstated'     => array( 'label' => 'Event back on', 'kind' => 'email', 'variants' => array( 'same_date' => 'Same date', 'moved' => 'New date', 'several' => 'Several dates' ),
                'tokens' => array_merge( $event, array( 'old_date', 'cancel_link', 'count' ) ) ),
            'follow_confirm' => array( 'label' => 'Follow a series: confirm', 'kind' => 'email', 'variants' => array( 'default' => '' ),
                'tokens' => array( 'series', 'days', 'confirm_link', 'stop_link' ), 'links' => array( 'confirm_link' => 'follow_yes' ) ),
            'donate_line'    => array( 'label' => 'Donate line', 'kind' => 'line', 'variants' => array( 'default' => '' ),
                'tokens' => array( 'donate_link' ) ),
            'cancel_page'    => array( 'label' => 'Cancel your place page', 'kind' => 'page',
                'variants' => array( 'ask' => 'Asking', 'done' => 'Released', 'leave' => 'Leaving the waitlist', 'left' => 'Left the waitlist', 'nothing' => 'Nothing to cancel' ),
                'tokens' => array( 'title', 'date', 'time' ) ),
            'offer_page'     => array( 'label' => 'Confirm your place page', 'kind' => 'page',
                'variants' => array( 'ask' => 'Asking', 'done' => 'Confirmed', 'gone' => 'Offer passed' ),
                'tokens' => array( 'title', 'date', 'time', 'expiry' ) ),
        );
    }

    /**
     * The shipped text: [key][variant][lang] => subject, intro, closing.
     *
     * The English is what the builders said before 3.106.0, where they said it.
     * The Spanish is neutral, uses usted, and keeps every name as entered.
     *
     * @return array
     */
    public static function defaults() {
        $cancel_en = 'Cannot make it? {cancel_link} so somebody else can take your place. We will ask you to confirm.';
        $cancel_es = '¿No puede asistir? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.';
        $d = array();

        $d['confirmation'] = array(
            'in_person' => array(
                'en' => array( 'subject' => 'You are registered for {title}', 'intro' => "You are registered, {first_name}.\n\nWe have your place. Here are the details.", 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Su inscripción en {title} está confirmada', 'intro' => "Su inscripción está confirmada, {first_name}.\n\nTenemos su lugar reservado. Estos son los detalles.", 'closing' => $cancel_es ),
            ),
            'online_link' => array(
                'en' => array( 'subject' => 'You are registered for {title}', 'intro' => "You are registered, {first_name}.\n\nWe have your place. Here are the details, and the link to join is below.", 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Su inscripción en {title} está confirmada', 'intro' => "Su inscripción está confirmada, {first_name}.\n\nTenemos su lugar reservado. Estos son los detalles, y el enlace para participar está más abajo.", 'closing' => $cancel_es ),
            ),
            'online_pending' => array(
                'en' => array( 'subject' => 'You are registered for {title}', 'intro' => "You are registered, {first_name}.\n\nWe have your place. Here are the details. The link to join will be sent before the event.", 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Su inscripción en {title} está confirmada', 'intro' => "Su inscripción está confirmada, {first_name}.\n\nTenemos su lugar reservado. Estos son los detalles. Le enviaremos el enlace para participar antes del evento.", 'closing' => $cancel_es ),
            ),
        );

        $d['waitlist'] = array( 'default' => array(
            'en' => array( 'subject' => 'You are on the waitlist for {title}',
                'intro' => "You are on the waitlist, {first_name}.\n\n{title} is full. You are number {position} on the waitlist. If a place opens, we will email you an offer, and you will have a set time to confirm it.",
                'closing' => 'No longer interested? {cancel_link}.' ),
            'es' => array( 'subject' => 'Está en la lista de espera de {title}',
                'intro' => "Está en la lista de espera, {first_name}.\n\n{title} está completo. Usted es el número {position} de la lista de espera. Si se libera un lugar, le enviaremos una oferta por correo electrónico y tendrá un plazo para confirmarla.",
                'closing' => '¿Ya no le interesa? {cancel_link}.' ),
        ) );

        $d['offer'] = array( 'default' => array(
            'en' => array( 'subject' => 'A place is open for {title}',
                'intro' => "A place is open, {first_name}.\n\nA place has opened for {title}, and it is yours if you confirm it by {expiry}. After that it goes to the next person on the waitlist.\n\n{confirm_link}",
                'closing' => 'No longer interested? Do nothing, and the place will go to the next person.' ),
            'es' => array( 'subject' => 'Hay un lugar disponible en {title}',
                'intro' => "Hay un lugar disponible, {first_name}.\n\nSe liberó un lugar en {title} y es suyo si lo confirma a más tardar el {expiry}. Después, pasará a la siguiente persona de la lista de espera.\n\n{confirm_link}",
                'closing' => '¿Ya no le interesa? No haga nada y el lugar pasará a la siguiente persona.' ),
        ) );

        $d['offer_passed'] = array( 'default' => array(
            'en' => array( 'subject' => 'The offer for {title} has passed',
                'intro' => "The offer has passed, {first_name}.\n\nThe place we offered you for {title} was not confirmed in time, so it has gone to the next person on the waitlist. You are no longer on the waitlist for this event.",
                'closing' => '' ),
            'es' => array( 'subject' => 'La oferta para {title} ha vencido',
                'intro' => "La oferta ha vencido, {first_name}.\n\nEl lugar que le ofrecimos en {title} no se confirmó a tiempo, así que pasó a la siguiente persona de la lista de espera. Ya no está en la lista de espera de este evento.",
                'closing' => '' ),
        ) );

        $d['reminder'] = array(
            'in_person' => array(
                'en' => array( 'subject' => 'Today: {title}', 'intro' => 'Your event is today.', 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Hoy: {title}', 'intro' => 'Su evento es hoy.', 'closing' => $cancel_es ),
            ),
            'online_link' => array(
                'en' => array( 'subject' => 'Today: {title}', 'intro' => "Your event is today.\n\nThe link to join is below.", 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Hoy: {title}', 'intro' => "Su evento es hoy.\n\nEl enlace para participar está más abajo.", 'closing' => $cancel_es ),
            ),
            'online_pending' => array(
                'en' => array( 'subject' => 'Today: {title}', 'intro' => "Your event is today.\n\nThe link to join will be sent before the event starts.", 'closing' => $cancel_en ),
                'es' => array( 'subject' => 'Hoy: {title}', 'intro' => "Su evento es hoy.\n\nLe enviaremos el enlace para participar antes de que comience el evento.", 'closing' => $cancel_es ),
            ),
        );

        $d['cancelled'] = array( 'default' => array(
            'en' => array( 'subject' => 'Cancelled: {title}',
                'intro' => "{title} is cancelled.\n\n{first_name}, this event is not going ahead, and you do not need to do anything.",
                'closing' => 'Your registration has been kept as a record that you signed up. Nothing else will be sent about this event.' ),
            'es' => array( 'subject' => 'Cancelado: {title}',
                'intro' => "{title} se canceló.\n\n{first_name}, este evento no se llevará a cabo y usted no necesita hacer nada.",
                'closing' => 'Conservamos su inscripción como constancia de que se registró. No le enviaremos nada más sobre este evento.' ),
        ) );

        $d['changed'] = array( 'default' => array(
            'en' => array( 'subject' => 'Changed: {title}',
                'intro' => "{title} has changed.\n\n{first_name}, some details have moved. Here is what is different.",
                'closing' => 'Cannot make the new time? {cancel_link} so somebody else can take your place. We will ask you to confirm.' ),
            'es' => array( 'subject' => 'Cambios: {title}',
                'intro' => "{title} tiene cambios.\n\n{first_name}, algunos detalles cambiaron. Esto es lo que es diferente.",
                'closing' => '¿No puede asistir en el nuevo horario? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.' ),
        ) );

        $held_en = 'Your registration was kept and still holds, so there is nothing to do if the new details suit you.';
        $held_es = 'Su inscripción se conservó y sigue vigente, así que no necesita hacer nada si los nuevos detalles le convienen.';
        $d['reinstated'] = array(
            'same_date' => array(
                'en' => array( 'subject' => 'Back on: {title}',
                    'intro' => "{title} is back on.\n\n{first_name}, this event was cancelled and is happening after all.\n\n" . $held_en,
                    'closing' => 'No longer able to come? {cancel_link} so somebody else can take your place. We will ask you to confirm.' ),
                'es' => array( 'subject' => 'Se reanuda: {title}',
                    'intro' => "{title} se llevará a cabo.\n\n{first_name}, este evento se había cancelado y finalmente sí se llevará a cabo.\n\n" . $held_es,
                    'closing' => '¿Ya no puede asistir? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.' ),
            ),
            'moved' => array(
                'en' => array( 'subject' => 'Back on: {title}',
                    'intro' => "{title} is back on.\n\n{first_name}, this event was cancelled and is happening after all.\n\nIt has also moved: it was {old_date} and it is now {date}.\n\n" . $held_en,
                    'closing' => 'Cannot make the new date? {cancel_link} so somebody else can take your place. We will ask you to confirm.' ),
                'es' => array( 'subject' => 'Se reanuda: {title}',
                    'intro' => "{title} se llevará a cabo.\n\n{first_name}, este evento se había cancelado y finalmente sí se llevará a cabo.\n\nTambién cambió de fecha: era el {old_date} y ahora es el {date}.\n\n" . $held_es,
                    'closing' => '¿No puede asistir en la nueva fecha? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.' ),
            ),
        );

        /*
         * SEVERAL DATES IN ONE EMAIL. SFAF_Announce sends one message per
         * person whatever they registered for, so a pattern change that moves
         * six of somebody's dates is one email listing six.
         */
        $d['cancelled']['several'] = array(
            'en' => array( 'subject' => 'Cancelled: {count} dates for {title}',
                'intro' => "{count} dates are cancelled.\n\n{first_name}, you were registered for these, and they are not going ahead. You do not need to do anything.",
                'closing' => 'Your registrations have been kept as a record that you signed up. Nothing else will be sent about these dates.' ),
            'es' => array( 'subject' => 'Cancelados: {count} fechas de {title}',
                'intro' => "Se cancelaron {count} fechas.\n\n{first_name}, usted se inscribió en estas fechas y no se llevarán a cabo. No necesita hacer nada.",
                'closing' => 'Conservamos sus inscripciones como constancia de que se registró. No le enviaremos nada más sobre estas fechas.' ),
        );
        $d['changed']['several'] = array(
            'en' => array( 'subject' => 'Changed: {count} dates for {title}',
                'intro' => "{count} dates have changed.\n\n{first_name}, you were registered for these, and they have moved.",
                'closing' => 'Cannot make the new times? {cancel_link} so somebody else can take your place. We will ask you to confirm.' ),
            'es' => array( 'subject' => 'Cambios: {count} fechas de {title}',
                'intro' => "Cambiaron {count} fechas.\n\n{first_name}, usted se inscribió en estas fechas y cambiaron.",
                'closing' => '¿No puede asistir en los nuevos horarios? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.' ),
        );
        $d['reinstated']['several'] = array(
            'en' => array( 'subject' => 'Back on: {count} dates for {title}',
                'intro' => "{count} dates are back on.\n\n{first_name}, these were cancelled and are happening after all. Your registrations were kept and still hold.",
                'closing' => 'No longer able to come to one of them? {cancel_link} so somebody else can take your place. We will ask you to confirm.' ),
            'es' => array( 'subject' => 'Se reanudan: {count} fechas de {title}',
                'intro' => "Se reanudan {count} fechas.\n\n{first_name}, estas fechas se habían cancelado y finalmente sí se llevarán a cabo. Sus inscripciones se conservaron y siguen vigentes.",
                'closing' => '¿Ya no puede asistir a alguna de ellas? {cancel_link} para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.' ),
        );

        $d['follow_confirm'] = array( 'default' => array(
            'en' => array( 'subject' => "Confirm you're following {series}",
                'intro' => "Confirm you're following {series}\n\nAlmost there! Confirm below and we'll email you whenever a new date is added.\n\n{confirm_link}\n\nThis link works for the next {days} days.",
                'closing' => "Didn't ask for this? Ignore it and nothing happens. You can {stop_link} any time." ),
            'es' => array( 'subject' => 'Confirme que desea seguir {series}',
                'intro' => "Confirme que desea seguir {series}\n\n¡Ya casi! Confirme a continuación y le enviaremos un correo electrónico cada vez que se agregue una nueva fecha.\n\n{confirm_link}\n\nEste enlace funciona durante los próximos {days} días.",
                'closing' => '¿No lo solicitó? Ignore este mensaje y no pasará nada. Puede {stop_link} en cualquier momento.' ),
        ) );

        $d['donate_line'] = array( 'default' => array(
            'en' => array( 'subject' => '', 'intro' => 'Support this work: {donate_link}.', 'closing' => '' ),
            'es' => array( 'subject' => '', 'intro' => 'Apoye este trabajo: {donate_link}.', 'closing' => '' ),
        ) );

        $d['cancel_page'] = array(
            'ask' => array(
                'en' => array( 'subject' => "Can't make it?", 'intro' => 'Cancel your registration for {title}?', 'closing' => 'Places are limited, so canceling puts yours back for someone else.' ),
                'es' => array( 'subject' => '¿No puede asistir?', 'intro' => '¿Desea cancelar su inscripción en {title}?', 'closing' => 'Los lugares son limitados, así que al cancelar su lugar queda disponible para otra persona.' ),
            ),
            'done' => array(
                'en' => array( 'subject' => 'Registration canceled', 'intro' => 'Your registration for {title} has been canceled.', 'closing' => 'Your place has gone back to the count for someone else.' ),
                'es' => array( 'subject' => 'Inscripción cancelada', 'intro' => 'Se canceló su inscripción en {title}.', 'closing' => 'Su lugar quedó disponible para otra persona.' ),
            ),
            'leave' => array(
                'en' => array( 'subject' => 'Leave the waitlist?', 'intro' => 'Leave the waitlist for {title}?', 'closing' => '' ),
                'es' => array( 'subject' => '¿Desea salir de la lista de espera?', 'intro' => '¿Desea salir de la lista de espera de {title}?', 'closing' => '' ),
            ),
            'left' => array(
                'en' => array( 'subject' => 'You have left the waitlist', 'intro' => 'You are no longer on the waitlist for {title}.', 'closing' => '' ),
                'es' => array( 'subject' => 'Salió de la lista de espera', 'intro' => 'Ya no está en la lista de espera de {title}.', 'closing' => '' ),
            ),
            'nothing' => array(
                'en' => array( 'subject' => 'Nothing to cancel', 'intro' => 'We could not find an active registration for {title} against this address. It may already have been canceled.', 'closing' => '' ),
                'es' => array( 'subject' => 'No hay nada que cancelar', 'intro' => 'No encontramos una inscripción activa en {title} para esta dirección. Es posible que ya se haya cancelado.', 'closing' => '' ),
            ),
        );

        $d['offer_page'] = array(
            'ask' => array(
                'en' => array( 'subject' => 'Your place is waiting', 'intro' => "Confirm your place at {title}?\n\nThis offer lasts until {expiry}.", 'closing' => '' ),
                'es' => array( 'subject' => 'Su lugar lo espera', 'intro' => "¿Desea confirmar su lugar en {title}?\n\nEsta oferta es válida hasta el {expiry}.", 'closing' => '' ),
            ),
            'done' => array(
                'en' => array( 'subject' => 'You are registered', 'intro' => 'Your place at {title} is confirmed. Your confirmation is on its way by email.', 'closing' => '' ),
                'es' => array( 'subject' => 'Su inscripción está confirmada', 'intro' => 'Su lugar en {title} está confirmado. Le enviamos la confirmación por correo electrónico.', 'closing' => '' ),
            ),
            'gone' => array(
                'en' => array( 'subject' => 'This offer has passed', 'intro' => 'The place offered for {title} has gone to the next person on the waitlist.', 'closing' => '' ),
                'es' => array( 'subject' => 'Esta oferta ha vencido', 'intro' => 'El lugar que se ofreció en {title} pasó a la siguiente persona de la lista de espera.', 'closing' => '' ),
            ),
        );

        return $d;
    }

    /**
     * The fixed words around the editable text, in both languages.
     *
     * @param string $id
     * @param string $lang
     * @return string
     */
    public static function label( $id, $lang = 'en' ) {
        static $t = null;
        if ( null === $t ) {
            $t = array(
                'event'            => array( 'Event', 'Evento' ),
                'date'             => array( 'Date', 'Fecha' ),
                'time'             => array( 'Time', 'Hora' ),
                'location'         => array( 'Location', 'Lugar' ),
                'add_to_calendar'  => array( 'Add to calendar', 'Agregar al calendario' ),
                'update_calendar'  => array( 'Update your calendar', 'Actualice su calendario' ),
                'back_in_calendar' => array( 'Put it back in your calendar', 'Vuelva a agregarlo a su calendario' ),
                'google'           => array( 'Google', 'Google' ),
                'apple'            => array( 'Apple or Outlook', 'Apple u Outlook' ),
                'see_event'        => array( 'See the event page', 'Ver la página del evento' ),
                'joining'          => array( 'Joining online', 'Cómo participar en línea' ),
                'join_event'       => array( 'Join the event', 'Participar en el evento' ),
                'paste'            => array( 'Or paste this into your browser:', 'O copie y pegue esto en su navegador:' ),
                'no_link_yet'      => array( 'A link to join will be sent before the event.', 'Le enviaremos un enlace para participar antes del evento.' ),
                'was_going_to_be'  => array( 'It was going to be:', 'Esto era lo previsto:' ),
                'now_is'           => array( 'The event is now:', 'El evento ahora es:' ),
                'what_changed'     => array( 'What changed', 'Qué cambió' ),
                'to'               => array( 'to', 'a' ),
                'online_event'     => array( 'Online Event', 'Evento en línea' ),
                'tbc'              => array( 'time to be confirmed', 'hora por confirmar' ),
                'cancel_link'      => array( 'Cancel your registration', 'Cancele su inscripción' ),
                'leave_waitlist'   => array( 'Leave the waitlist', 'Salga de la lista de espera' ),
                'confirm_link'     => array( 'Confirm my place', 'Confirmar mi lugar' ),
                'follow_yes'       => array( 'Yes, follow this series', 'Sí, seguir esta serie' ),
                'stop_link'        => array( 'stop these emails', 'dejar de recibir estos correos' ),
                'event_link'       => array( 'See the event page', 'Ver la página del evento' ),
                'donate_link'      => array( 'donate to SFAF', 'done a SFAF' ),
                'cancel_button'    => array( 'Yes, cancel my registration', 'Sí, cancelar mi inscripción' ),
                'leave_button'     => array( 'Yes, leave the waitlist', 'Sí, salir de la lista de espera' ),
                'confirm_button'   => array( 'Yes, confirm my place', 'Sí, confirmar mi lugar' ),
                'pre_today'        => array( 'Today, %s', 'Hoy, %s' ),
                'pre_cancelled'    => array( 'Cancelled, %s', 'Cancelado, %s' ),
                'pre_now'          => array( 'Now %s', 'Ahora %s' ),
                'pre_back'         => array( 'Back on, %s', 'Se reanuda, %s' ),
                'pre_waitlist'     => array( 'Waitlist, %s', 'Lista de espera, %s' ),
                'pre_offer'        => array( 'Confirm by %s', 'Confirme a más tardar el %s' ),
                'ics_join'         => array( 'Join', 'Participar' ),
                'ics_join_event'   => array( 'Join the event', 'Participar en el evento' ),
                'in_person_online' => array( 'In person and online', 'En persona y en línea' ),
            );
        }
        if ( ! isset( $t[ $id ] ) ) {
            return $id;
        }
        return ( 'es' === $lang ) ? $t[ $id ][1] : $t[ $id ][0];
    }

    /* ---------------------------------------------------------------------
     * Reading and writing the text
     * ------------------------------------------------------------------- */

    /** The option holding one message's overrides in one language. */
    public static function option_name( $key, $lang ) {
        return self::OPTION_PREFIX . sanitize_key( $key ) . '_' . self::lang( $lang );
    }

    /**
     * The shipped text of one variant.
     *
     * @return array{subject:string,intro:string,closing:string}
     */
    public static function shipped( $key, $variant, $lang ) {
        $d    = self::defaults();
        $lang = self::lang( $lang );
        if ( ! isset( $d[ $key ] ) ) {
            return array( 'subject' => '', 'intro' => '', 'closing' => '' );
        }
        if ( ! isset( $d[ $key ][ $variant ] ) ) {
            $variant = key( $d[ $key ] );
        }
        return $d[ $key ][ $variant ][ $lang ];
    }

    /** The saved override of one variant, or null. */
    public static function override( $key, $variant, $lang ) {
        $all = get_option( self::option_name( $key, $lang ), array() );
        return ( is_array( $all ) && isset( $all[ $variant ] ) && is_array( $all[ $variant ] ) ) ? $all[ $variant ] : null;
    }

    /**
     * The text that goes out: the override where there is one, else shipped.
     *
     * @return array{subject:string,intro:string,closing:string}
     */
    public static function get( $key, $variant, $lang ) {
        $base = self::shipped( $key, $variant, $lang );
        $own  = self::override( $key, $variant, $lang );
        if ( ! $own ) {
            return $base;
        }
        foreach ( array( 'subject', 'intro', 'closing' ) as $f ) {
            if ( isset( $own[ $f ] ) ) {
                $base[ $f ] = (string) $own[ $f ];
            }
        }
        return $base;
    }

    /**
     * Save one variant's text, after checking every token is one this message
     * may use. A typed brace that is not a token is refused by name rather
     * than sent to somebody as "{nme}".
     *
     * @return true|WP_Error
     */
    public static function save( $key, $variant, $lang, $fields ) {
        $cat = self::catalog();
        if ( ! isset( $cat[ $key ]['variants'][ $variant ] ) ) {
            return new WP_Error( 'sfaf_msg_unknown', 'That message does not exist.' );
        }
        $allowed = $cat[ $key ]['tokens'];
        $clean   = array();
        foreach ( array( 'subject', 'intro', 'closing' ) as $f ) {
            $text = isset( $fields[ $f ] ) ? str_replace( "\r\n", "\n", trim( wp_strip_all_tags( (string) $fields[ $f ] ) ) ) : '';
            preg_match_all( '/\{([a-z_]+)\}/', $text, $m );
            $bad = array_diff( array_unique( $m[1] ), $allowed );
            if ( $bad ) {
                return new WP_Error( 'sfaf_msg_token', 'Remove {' . implode( '}, {', $bad ) . '}: this message cannot fill it in.' );
            }
            $clean[ $f ] = $text;
        }
        if ( '' === $clean['intro'] ) {
            return new WP_Error( 'sfaf_msg_empty', 'Write the text above the details.' );
        }
        $name = self::option_name( $key, $lang );
        $all  = get_option( $name, array() );
        $all  = is_array( $all ) ? $all : array();
        $all[ $variant ] = $clean;
        update_option( $name, $all, false );
        return true;
    }

    /** Take one variant back to the shipped text. */
    public static function reset( $key, $variant, $lang ) {
        $name = self::option_name( $key, $lang );
        $all  = get_option( $name, array() );
        if ( is_array( $all ) && isset( $all[ $variant ] ) ) {
            unset( $all[ $variant ] );
            if ( $all ) {
                update_option( $name, $all, false );
            } else {
                delete_option( $name );
            }
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Filling tokens
     * ------------------------------------------------------------------- */

    /**
     * The label a link token draws with in one message.
     */
    private static function link_label( $key, $token, $lang ) {
        $cat = self::catalog();
        $id  = isset( $cat[ $key ]['links'][ $token ] ) ? $cat[ $key ]['links'][ $token ] : $token;
        return self::label( $id, $lang );
    }

    /**
     * Split text into paragraphs, drop the ones that need a link nobody has,
     * and tidy the grammar a missing first name leaves behind.
     *
     * @return string[]
     */
    private static function paragraphs( $text, $values ) {
        $out = array();
        foreach ( preg_split( "/\n\s*\n/", trim( (string) $text ) ) as $p ) {
            $p = trim( $p );
            if ( '' === $p ) {
                continue;
            }
            $skip = false;
            foreach ( self::link_tokens() as $lt ) {
                if ( false !== strpos( $p, '{' . $lt . '}' ) && '' === (string) ( isset( $values[ $lt ] ) ? $values[ $lt ] : '' ) ) {
                    $skip = true;
                }
            }
            if ( ! $skip ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Grammar a missing name leaves: ", ." and a paragraph opening on ", ". */
    private static function tidy( $s ) {
        $s = preg_replace( '/\s*,\s*([.!?])/u', '$1', $s );
        $s = preg_replace( '/^,\s*/u', '', $s );
        $s = preg_replace( '/ {2,}/', ' ', $s );
        return function_exists( 'mb_strtoupper' )
            ? mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 )
            : ucfirst( $s );
    }

    /**
     * One paragraph as plain text.
     */
    public static function fill_text( $key, $p, $values, $lang ) {
        $out = preg_replace_callback( '/\{([a-z_]+)\}/', function ( $m ) use ( $key, $values, $lang ) {
            $v = isset( $values[ $m[1] ] ) ? (string) $values[ $m[1] ] : '';
            if ( in_array( $m[1], self::link_tokens(), true ) ) {
                if ( 'meeting_link' === $m[1] ) {
                    return $v;
                }
                return self::link_label( $key, $m[1], $lang ) . ' (' . $v . ')';
            }
            return $v;
        }, $p );
        return self::tidy( $out );
    }

    /**
     * One paragraph as HTML. The text is escaped first and the links are put
     * in after, so an override can never carry markup of its own.
     */
    public static function fill_html( $key, $p, $values, $lang ) {
        // Plain tokens first, so a missing name is tidied like any other text;
        // link tokens become marks that survive the escaping.
        $marks = array();
        $plain = preg_replace_callback( '/\{([a-z_]+)\}/', function ( $m ) use ( &$marks, $values ) {
            if ( in_array( $m[1], self::link_tokens(), true ) ) {
                $marks[] = $m[1];
                return "\x01" . ( count( $marks ) - 1 ) . "\x02";
            }
            return isset( $values[ $m[1] ] ) ? (string) $values[ $m[1] ] : '';
        }, $p );
        $safe = esc_html( self::tidy( $plain ) );
        return preg_replace_callback( "/\x01(\d+)\x02/", function ( $m ) use ( $marks, $key, $values, $lang ) {
            $token = $marks[ (int) $m[1] ];
            $v     = isset( $values[ $token ] ) ? (string) $values[ $token ] : '';
            $label = ( 'meeting_link' === $token ) ? $v : self::link_label( $key, $token, $lang );
            return '<a href="' . esc_url( $v ) . '" style="color:' . SFAF_Email::C_TEAL . ';">' . esc_html( $label ) . '</a>';
        }, $safe );
    }

    /** A paragraph that is one link token and nothing else, or ''. */
    private static function sole_link( $p ) {
        return ( preg_match( '/^\{([a-z_]+)\}$/', trim( $p ), $m ) && in_array( $m[1], self::link_tokens(), true ) ) ? $m[1] : '';
    }

    /* ---------------------------------------------------------------------
     * Composing
     * ------------------------------------------------------------------- */

    /**
     * The joining block, in a language, from a link that is already known to be
     * one this person may be sent ('' when it is not in yet). SFAF_Online's two
     * gates decide WHETHER; this only decides the words.
     */
    public static function joining_html( $url, $lang ) {
        $html = SFAF_Email::label( self::label( 'joining', $lang ) );
        if ( '' === (string) $url ) {
            return $html . SFAF_Email::para( self::label( 'no_link_yet', $lang ) );
        }
        return $html
            . SFAF_Email::button( $url, self::label( 'join_event', $lang ), 'primary' )
            . SFAF_Email::small_para( esc_html( self::label( 'paste', $lang ) ) . ' ' . esc_html( $url ) );
    }

    /** The same block as plain text, newline-terminated. */
    public static function joining_text( $url, $lang ) {
        if ( '' === (string) $url ) {
            return self::label( 'joining', $lang ) . "\n" . self::label( 'no_link_yet', $lang ) . "\n\n";
        }
        return self::label( 'joining', $lang ) . "\n" . $url . "\n\n";
    }

    /**
     * An email to a registrant, from its text and the blocks between.
     *
     * $values are the tokens. $parts, all optional, in the order drawn:
     *   extra       string[]  paragraphs after the intro (a reason, a note)
     *   changes     array     label => array(from, to)
     *   lead        string    a label id for the line over the details
     *   details     array     label => value (labels already in the language)
     *   joining     string|null  html of the joining block
     *   joining_text string|null
     *   calendar    array{label:string,gcal:string,ics:string}
     *   event_url   string
     *   donate      string    a donation link, for the donate line
     *   intro_override string replaces the intro under its first paragraph
     *   preheader   string
     *
     * @return array{subject:string,html:string,text:string}
     */
    public static function compose( $key, $variant, $lang, $values, $parts ) {
        $lang  = self::lang( $lang );
        $t     = self::get( $key, $variant, $lang );
        $intro = self::paragraphs( $t['intro'], $values );
        $head  = $intro ? array_shift( $intro ) : '';
        if ( ! empty( $parts['heading'] ) ) {
            $head = (string) $parts['heading'];
        }

        if ( ! empty( $parts['intro_override'] ) ) {
            $intro = array( (string) $parts['intro_override'] );
            $raw_override = true;
        } else {
            $raw_override = false;
        }

        $html = '' !== $head ? SFAF_Email::heading( self::fill_text( $key, $head, $values, $lang ) ) : '';
        $text = '' !== $head ? self::fill_text( $key, $head, $values, $lang ) . "\n\n" : '';

        foreach ( $intro as $p ) {
            if ( $raw_override ) {
                foreach ( preg_split( "/\n\s*\n/", trim( $p ) ) as $q ) {
                    $html .= '<p style="margin:0 0 14px 0; font-family:' . SFAF_Email::FONT . '; font-size:16px; line-height:1.5; color:' . SFAF_Email::C_INK . ';">' . nl2br( esc_html( trim( $q ) ) ) . '</p>';
                }
                $text .= trim( $p ) . "\n\n";
                continue;
            }
            $sole = self::sole_link( $p );
            if ( '' !== $sole ) {
                $url   = isset( $values[ $sole ] ) ? (string) $values[ $sole ] : '';
                $label = self::link_label( $key, $sole, $lang );
                $html .= SFAF_Email::button_row( array( SFAF_Email::button( $url, $label, 'primary' ) ) );
                $text .= $label . ': ' . $url . "\n\n";
                continue;
            }
            $html .= self::para_html( self::fill_html( $key, $p, $values, $lang ) );
            $text .= self::fill_text( $key, $p, $values, $lang ) . "\n\n";
        }

        foreach ( isset( $parts['extra'] ) ? (array) $parts['extra'] : array() as $p ) {
            if ( '' === trim( (string) $p ) ) {
                continue;
            }
            $html .= SFAF_Email::para( $p );
            $text .= $p . "\n\n";
        }

        if ( ! empty( $parts['changes'] ) ) {
            $rows = array();
            foreach ( $parts['changes'] as $label => $pair ) {
                $rows[ $label ] = $pair[0] . '  ' . self::label( 'to', $lang ) . '  ' . $pair[1];
                $text .= $label . ': ' . $pair[0] . ' ' . self::label( 'to', $lang ) . ' ' . $pair[1] . "\n";
            }
            $html .= SFAF_Email::details( $rows );
            $text .= "\n";
        }

        if ( ! empty( $parts['lead'] ) ) {
            $html .= SFAF_Email::para( self::label( $parts['lead'], $lang ) );
            $text .= self::label( $parts['lead'], $lang ) . "\n\n";
        }

        if ( ! empty( $parts['details'] ) ) {
            $html .= SFAF_Email::details( $parts['details'] );
            $lines = array();
            foreach ( $parts['details'] as $label => $value ) {
                if ( '' !== trim( (string) $value ) ) {
                    $lines[] = $label . ': ' . $value;
                }
            }
            $text .= implode( "\n", $lines ) . "\n\n";
        }

        if ( ! empty( $parts['joining'] ) ) {
            $html .= $parts['joining'];
            $text .= isset( $parts['joining_text'] ) ? (string) $parts['joining_text'] : '';
        }

        if ( ! empty( $parts['calendar'] ) ) {
            $c       = $parts['calendar'];
            $buttons = array();
            if ( ! empty( $c['gcal'] ) ) { $buttons[] = SFAF_Email::button( $c['gcal'], self::label( 'google', $lang ), 'primary', true, true ); }
            if ( ! empty( $c['ics'] ) )  { $buttons[] = SFAF_Email::button( $c['ics'], self::label( 'apple', $lang ), 'outline', true, true ); }
            if ( $buttons ) {
                $html .= SFAF_Email::label( self::label( $c['label'], $lang ) );
                $html .= SFAF_Email::button_row( $buttons );
                $text .= self::label( $c['label'], $lang ) . "\n";
                if ( ! empty( $c['gcal'] ) ) { $text .= self::label( 'google', $lang ) . ': ' . $c['gcal'] . "\n"; }
                if ( ! empty( $c['ics'] ) )  { $text .= self::label( 'apple', $lang ) . ': ' . $c['ics'] . "\n"; }
            }
        }

        if ( ! empty( $parts['event_url'] ) ) {
            if ( ! empty( $parts['event_button'] ) ) {
                $html .= SFAF_Email::button( $parts['event_url'], self::label( 'see_event', $lang ), 'primary' );
            } else {
                $html .= SFAF_Email::link_para( $parts['event_url'], self::label( 'see_event', $lang ) );
            }
            $text .= self::label( 'see_event', $lang ) . ': ' . $parts['event_url'] . "\n";
        }

        /*
         * THE DONATE LINE (3.106.0): after the details and before the cancel
         * line, only in the messages that ask for it, and only with a link.
         */
        if ( ! empty( $parts['donate'] ) ) {
            $line  = self::get( 'donate_line', 'default', $lang );
            $dv    = array( 'donate_link' => (string) $parts['donate'] );
            $html .= self::para_html( self::fill_html( 'donate_line', $line['intro'], $dv, $lang ) );
            $text .= "\n" . self::fill_text( 'donate_line', $line['intro'], $dv, $lang ) . "\n";
        }

        $closing = self::paragraphs( $t['closing'], $values );
        if ( $closing ) {
            $html .= SFAF_Email::rule();
            $text .= "\n";
            foreach ( $closing as $p ) {
                $html .= SFAF_Email::small_para( self::fill_html( $key, $p, $values, $lang ) );
                $text .= self::fill_text( $key, $p, $values, $lang ) . "\n";
            }
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        $subject = self::fill_text( $key, $t['subject'], $values, $lang );
        return array(
            'subject' => $subject,
            'html'    => SFAF_Email::shell( isset( $parts['preheader'] ) ? (string) $parts['preheader'] : $subject, $html ),
            'text'    => $text,
        );
    }

    /** A body paragraph whose inside is already HTML. */
    private static function para_html( $inner ) {
        return '<p style="margin:0 0 14px 0; font-family:' . SFAF_Email::FONT . '; font-size:16px; line-height:1.5; color:' . SFAF_Email::C_INK . ';">' . $inner . '</p>';
    }

    /**
     * A page a registrant lands on: the title and the body HTML. The page's
     * document is sfaf_notice_page()'s, so this is the part that changes.
     *
     * @param array  $values
     * @param array  $parts  when (a date line), show_closing (bool), button (label id), token (hidden field name => value)
     * @return array{title:string,html:string}
     */
    public static function page( $key, $variant, $lang, $values, $parts = array() ) {
        $lang  = self::lang( $lang );
        $t     = self::get( $key, $variant, $lang );
        $html  = '';
        $first = true;
        foreach ( self::paragraphs( $t['intro'], $values ) as $p ) {
            $html .= '<p>' . self::fill_html( $key, $p, $values, $lang ) . '</p>';
            if ( $first && ! empty( $parts['when'] ) ) {
                $html .= '<p class="uc-notice-when">' . esc_html( $parts['when'] ) . '</p>';
            }
            $first = false;
        }
        if ( ! empty( $parts['show_closing'] ) ) {
            foreach ( self::paragraphs( $t['closing'], $values ) as $p ) {
                $html .= '<p>' . self::fill_html( $key, $p, $values, $lang ) . '</p>';
            }
        }
        if ( ! empty( $parts['button'] ) && ! empty( $parts['field'] ) ) {
            $html .= '<form method="post" class="uc-notice-form">'
                . '<input type="hidden" name="' . esc_attr( $parts['field'][0] ) . '" value="' . esc_attr( $parts['field'][1] ) . '" />'
                . '<button type="submit" class="uc-notice-btn">' . esc_html( self::label( $parts['button'], $lang ) ) . '</button>'
                . '</form>';
        }
        return array( 'title' => self::fill_text( $key, $t['subject'], $values, $lang ), 'html' => $html );
    }

    /* ---------------------------------------------------------------------
     * The sample event, for the Templates screen, EMAILS.md and the tests
     * ------------------------------------------------------------------- */

    /**
     * The fixed event every preview is filled with. Its dates are fixed, not
     * relative to today, so EMAILS.md does not change from one day to the next.
     *
     * @return array
     */
    public static function sample( $lang ) {
        $lang = self::lang( $lang );
        return array(
            'lang'         => $lang,
            'first_name'   => 'Alex',
            'last_name'    => 'Rivera',
            'title'        => 'Coffee and Conversation',
            'date'         => sfaf_ap_date( '2026-11-12', 'full', $lang ),
            'old_date'     => sfaf_ap_date( '2026-11-05', 'full', $lang ),
            'time'         => sfaf_ap_time_range( '18:00', '19:30', 'zone', $lang ),
            'location'     => 'SFAF Main Office, 1035 Market St, San Francisco, CA 94103',
            'organizer'    => 'Community Programs',
            'meeting_link' => 'https://zoom.us/j/123456789',
            'cancel_link'  => 'https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE',
            'confirm_link' => 'https://resources.sfaf.org/?uc_rsvp_offer=SAMPLE',
            'event_link'   => 'https://resources.sfaf.org/collections/events/coffee-and-conversation/',
            'position'     => '3',
            'expiry'       => sfaf_ap_datetime( '2026-11-11 18:00:00', 'full', $lang, true ),
            'series'       => 'Coffee and Conversation',
            'days'         => '7',
            'stop_link'    => 'https://resources.sfaf.org/?uc_follow_stop=SAMPLE',
            'donate_link'  => SFAF_DONATE_DEFAULT,
            'gcal'         => 'https://calendar.google.com/calendar/render?action=TEMPLATE',
            'ics'          => 'https://resources.sfaf.org/?uc_ics=SAMPLE',
        );
    }

    /**
     * Render one message or page with the sample event, through the builder
     * that sends it.
     *
     * @return array{subject:string,html:string,text:string}
     */
    public static function render_sample( $key, $variant, $lang ) {
        $lang = self::lang( $lang );
        $cat  = self::catalog();
        $kind = isset( $cat[ $key ]['kind'] ) ? $cat[ $key ]['kind'] : 'email';
        $s    = self::sample( $lang );

        if ( 'page' === $kind ) {
            $p = SFAF_Reminders::page_parts( $key, $variant, $lang, $s );
            return array( 'subject' => $p['title'], 'html' => sfaf_notice_page_html( $p['title'], $p['html'] ), 'text' => wp_strip_all_tags( $p['html'] ) );
        }
        if ( 'line' === $kind ) {
            $line = self::get( 'donate_line', 'default', $lang );
            $html = self::para_html( self::fill_html( 'donate_line', $line['intro'], $s, $lang ) );
            return array( 'subject' => '', 'html' => SFAF_Email::shell( '', $html ), 'text' => self::fill_text( 'donate_line', $line['intro'], $s, $lang ) );
        }
        if ( 'several' === $variant ) {
            $person = (object) array( 'first_name' => $s['first_name'], 'name' => $s['first_name'], 'token' => 'SAMPLE' );
            return SFAF_Announce::several_sample( $key, $person, $s );
        }
        if ( 'follow_confirm' === $key ) {
            return SFAF_Follow::build_confirmation( $s['series'], $s['confirm_link'], $s['stop_link'], (int) $s['days'], $lang );
        }
        $person = (object) array( 'first_name' => $s['first_name'], 'last_name' => $s['last_name'], 'name' => $s['first_name'] . ' ' . $s['last_name'],
            'email' => 'alex@example.org', 'token' => 'SAMPLE', 'format' => '' );
        return SFAF_Notifications::build( $key, 0, $person, array( 'sample' => $s, 'variant' => $variant ) );
    }

    /* ---------------------------------------------------------------------
     * The settings this replaces
     * ------------------------------------------------------------------- */

    /**
     * Move the Settings screen's confirmation and reminder text into the
     * English templates, once (3.106.0).
     *
     * Those four boxes and the Templates screen would otherwise be two places
     * to edit the same message. Anything typed there becomes the English
     * override of every variant of that message, with its old tokens renamed,
     * and is then taken out of uc_settings. Nothing is lost: an empty box
     * moves nothing.
     */
    public static function migrate_settings() {
        if ( get_option( 'sfaf_email_settings_moved' ) ) {
            return;
        }
        $s   = get_option( 'uc_settings', array() );
        $s   = is_array( $s ) ? $s : array();
        $map = array(
            '{event_name}' => '{title}', '{attendee_name}' => '{first_name}', '{event_date}' => '{date}',
            '{event_time_range}' => '{time}', '{event_time}' => '{time}', '{event_end_time}' => '{time}',
            '{event_location}' => '{location}', '{event_url}' => '{event_link}', '{organizer_name}' => '{organizer}',
            '{cancel_url}' => '{cancel_link}',
        );
        $moves = array(
            'confirmation' => array( 'email_rsvp_subject', 'email_rsvp_body', 'You are registered, {first_name}.' ),
            'reminder'     => array( 'email_dayof_subject', 'email_dayof_body', 'Your event is today.' ),
        );
        $changed = false;
        foreach ( $moves as $key => $m ) {
            $subject = isset( $s[ $m[0] ] ) ? trim( (string) $s[ $m[0] ] ) : '';
            $body    = isset( $s[ $m[1] ] ) ? trim( (string) $s[ $m[1] ] ) : '';
            if ( '' === $subject && '' === $body ) {
                continue;
            }
            foreach ( array_keys( self::catalog()[ $key ]['variants'] ) as $variant ) {
                $base = self::shipped( $key, $variant, 'en' );
                self::save( $key, $variant, 'en', array(
                    'subject' => '' !== $subject ? strtr( $subject, $map ) : $base['subject'],
                    'intro'   => '' !== $body ? $m[2] . "\n\n" . strtr( $body, $map ) : $base['intro'],
                    'closing' => $base['closing'],
                ) );
            }
            unset( $s[ $m[0] ], $s[ $m[1] ] );
            $changed = true;
        }
        if ( $changed ) {
            update_option( 'uc_settings', $s );
        }
        update_option( 'sfaf_email_settings_moved', 1 );
    }
}
