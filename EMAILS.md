# EMAILS.md, SFAF Calendar

Every message the calendar sends to somebody who registers, and the two pages they land on, in English and Spanish side by side. For the Spanish review.

The event in every example is the same sample: "Coffee and Conversation", Thursday, November 12, 2026, 6 to 7:30 pm, at the SFAF Main Office. Links are samples too.

This file is written by `php .claude/emails-md.php --write` from the text the plugin ships, and a build refuses to go out if the two differ. To change a message, change it in `includes/class-sfaf-messages.php` or on the Email Templates screen, not here.

Words in a message that come from the event, such as its title, location and organizer, are printed as they were entered and are not translated.

## Confirmation

### In person

| | English | Spanish |
|---|---|---|
| Subject | You are registered for Coffee and Conversation | Su inscripción en Coffee and Conversation está confirmada |
| Text | You are registered, Alex.<br><br>We have your place. Here are the details.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Add to calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su inscripción está confirmada, Alex.<br><br>Tenemos su lugar reservado. Estos son los detalles.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Agregar al calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Online, with link

| | English | Spanish |
|---|---|---|
| Subject | You are registered for Coffee and Conversation | Su inscripción en Coffee and Conversation está confirmada |
| Text | You are registered, Alex.<br><br>We have your place. Here are the details, and the link to join is below.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Joining online<br>https://zoom.us/j/123456789<br><br>Add to calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su inscripción está confirmada, Alex.<br><br>Tenemos su lugar reservado. Estos son los detalles, y el enlace para participar está más abajo.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Cómo participar en línea<br>https://zoom.us/j/123456789<br><br>Agregar al calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Online, link to come

| | English | Spanish |
|---|---|---|
| Subject | You are registered for Coffee and Conversation | Su inscripción en Coffee and Conversation está confirmada |
| Text | You are registered, Alex.<br><br>We have your place. Here are the details, and how to join is below.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Joining online<br>A link to join will be sent before the event.<br><br>Add to calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su inscripción está confirmada, Alex.<br><br>Tenemos su lugar reservado. Estos son los detalles, y cómo participar está más abajo.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Cómo participar en línea<br>Le enviaremos un enlace para participar antes del evento.<br><br>Agregar al calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Waitlist confirmation

| | English | Spanish |
|---|---|---|
| Subject | You are on the waitlist for Coffee and Conversation | Está en la lista de espera de Coffee and Conversation |
| Text | You are on the waitlist, Alex.<br><br>Coffee and Conversation is full. You are number 3 on the waitlist. If a place opens, we will email you an offer, and you will have a set time to confirm it.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>No longer interested? Leave the waitlist (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE).<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Está en la lista de espera, Alex.<br><br>Coffee and Conversation ya no tiene lugares disponibles. Usted es el número 3 de la lista de espera. Si se libera un lugar, le enviaremos una oferta por correo electrónico y tendrá un plazo para confirmarla.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>¿Ya no le interesa? Salga de la lista de espera (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE).<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Waitlist offer

| | English | Spanish |
|---|---|---|
| Subject | A place is open for Coffee and Conversation | Hay un lugar disponible en Coffee and Conversation |
| Text | A place is open, Alex.<br><br>A place has opened for Coffee and Conversation, and it is yours if you confirm it by Wednesday, November 11, 2026 at 6 pm PT. After that it goes to the next person on the waitlist.<br><br>Confirm my place: https://resources.sfaf.org/?uc_rsvp_offer=SAMPLE<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>No longer interested? Do nothing, and the place will go to the next person.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Hay un lugar disponible, Alex.<br><br>Se liberó un lugar en Coffee and Conversation y es suyo si lo confirma a más tardar el miércoles, 11 de noviembre de 2026 a las 6 p. m., hora del Pacífico. Después, pasará a la siguiente persona de la lista de espera.<br><br>Confirmar mi lugar: https://resources.sfaf.org/?uc_rsvp_offer=SAMPLE<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>¿Ya no le interesa? No haga nada y el lugar pasará a la siguiente persona.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Offer passed

| | English | Spanish |
|---|---|---|
| Subject | The offer for Coffee and Conversation has passed | La oferta para Coffee and Conversation ha vencido |
| Text | The offer has passed, Alex.<br><br>The place we offered you for Coffee and Conversation was not confirmed in time, so it has gone to the next person on the waitlist. You are no longer on the waitlist for this event.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | La oferta ha vencido, Alex.<br><br>El lugar que le ofrecimos en Coffee and Conversation no se confirmó a tiempo, así que pasó a la siguiente persona de la lista de espera. Ya no está en la lista de espera de este evento.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Morning-of reminder

### In person

| | English | Spanish |
|---|---|---|
| Subject | Today: Coffee and Conversation | Hoy: Coffee and Conversation |
| Text | Your event is today.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su evento es hoy.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Online, with link

| | English | Spanish |
|---|---|---|
| Subject | Today: Coffee and Conversation | Hoy: Coffee and Conversation |
| Text | Your event is today.<br><br>The link to join is below.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Joining online<br>https://zoom.us/j/123456789<br><br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su evento es hoy.<br><br>El enlace para participar está más abajo.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Cómo participar en línea<br>https://zoom.us/j/123456789<br><br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Online, link to come

| | English | Spanish |
|---|---|---|
| Subject | Today: Coffee and Conversation | Hoy: Coffee and Conversation |
| Text | Your event is today.<br><br>How to join is below.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Joining online<br>A link to join will be sent before the event.<br><br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>Cannot make it? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Su evento es hoy.<br><br>Cómo participar está más abajo.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Cómo participar en línea<br>Le enviaremos un enlace para participar antes del evento.<br><br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate).<br><br>¿No puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Event cancelled

### One date

| | English | Spanish |
|---|---|---|
| Subject | Cancelled: Coffee and Conversation | Cancelado: Coffee and Conversation |
| Text | Coffee and Conversation is cancelled.<br><br>Alex, this event is not going ahead, and you do not need to do anything.<br><br>It was going to be:<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br><br>Your registration has been kept as a record that you signed up. Nothing else will be sent about this event.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Coffee and Conversation se canceló.<br><br>Alex, este evento no se llevará a cabo y usted no necesita hacer nada.<br><br>Esto era lo previsto:<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br><br>Conservamos su inscripción como constancia de que se registró. No le enviaremos nada más sobre este evento.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Several dates

| | English | Spanish |
|---|---|---|
| Subject | Cancelled: 2 dates for Coffee and Conversation | Cancelados: 2 fechas de Coffee and Conversation |
| Text | 2 dates are cancelled.<br><br>Alex, you were registered for these, and they are not going ahead. You do not need to do anything.<br><br>Thursday, November 12, 2026: 6–7:30 pm PT<br>Thursday, November 19, 2026: 6–7:30 pm PT<br><br><br>Your registrations have been kept as a record that you signed up. Nothing else will be sent about these dates.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Se cancelaron 2 fechas.<br><br>Alex, usted se inscribió en estas fechas y no se llevarán a cabo. No necesita hacer nada.<br><br>jueves, 12 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br>jueves, 19 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br><br><br>Conservamos sus inscripciones como constancia de que se registró. No le enviaremos nada más sobre estas fechas.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Event changed

### One date

| | English | Spanish |
|---|---|---|
| Subject | Changed: Coffee and Conversation | Cambios: Coffee and Conversation |
| Text | Coffee and Conversation has changed.<br><br>Alex, some details have moved. Here is what is different.<br><br>Date: Thursday, November 5, 2026 to Thursday, November 12, 2026<br><br>The event is now:<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Update your calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Cannot make the new time? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Coffee and Conversation tiene cambios.<br><br>Alex, algunos detalles cambiaron. Esto es lo que es diferente.<br><br>Fecha: jueves, 5 de noviembre de 2026 a jueves, 12 de noviembre de 2026<br><br>El evento ahora es:<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Actualice su calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>¿No puede asistir en el nuevo horario? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Several dates

| | English | Spanish |
|---|---|---|
| Subject | Changed: 2 dates for Coffee and Conversation | Cambios: 2 fechas de Coffee and Conversation |
| Text | 2 dates have changed.<br><br>Alex, you were registered for these, and they have moved.<br><br>Thursday, November 12, 2026: 6–7:30 pm PT<br>Thursday, November 19, 2026: 6–7:30 pm PT<br><br><br>Cannot make the new times? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Cambiaron 2 fechas.<br><br>Alex, usted se inscribió en estas fechas y cambiaron.<br><br>jueves, 12 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br>jueves, 19 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br><br><br>¿No puede asistir en los nuevos horarios? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Event back on

### Same date

| | English | Spanish |
|---|---|---|
| Subject | Back on: Coffee and Conversation | Se reanuda: Coffee and Conversation |
| Text | Coffee and Conversation is back on.<br><br>Alex, this event was cancelled and is happening after all.<br><br>Your registration was kept and still holds, so there is nothing to do if the new details suit you.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Put it back in your calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>No longer able to come? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Coffee and Conversation se llevará a cabo.<br><br>Alex, este evento se había cancelado y finalmente sí se llevará a cabo.<br><br>Su inscripción se conservó y sigue vigente, así que no necesita hacer nada si los nuevos detalles le convienen.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Vuelva a agregarlo a su calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>¿Ya no puede asistir? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### New date

| | English | Spanish |
|---|---|---|
| Subject | Back on: Coffee and Conversation | Se reanuda: Coffee and Conversation |
| Text | Coffee and Conversation is back on.<br><br>Alex, this event was cancelled and is happening after all.<br><br>It has also moved: it was Thursday, November 5, 2026 and it is now Thursday, November 12, 2026.<br><br>Your registration was kept and still holds, so there is nothing to do if the new details suit you.<br><br>Event: Coffee and Conversation<br>Date: Thursday, November 12, 2026<br>Time: 6–7:30 pm PT<br>Location: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Put it back in your calendar<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple or Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>See the event page: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>Cannot make the new date? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Coffee and Conversation se llevará a cabo.<br><br>Alex, este evento se había cancelado y finalmente sí se llevará a cabo.<br><br>También cambió de fecha: era el jueves, 5 de noviembre de 2026 y ahora es el jueves, 12 de noviembre de 2026.<br><br>Su inscripción se conservó y sigue vigente, así que no necesita hacer nada si los nuevos detalles le convienen.<br><br>Evento: Coffee and Conversation<br>Fecha: jueves, 12 de noviembre de 2026<br>Hora: 6–7:30 p. m., hora del Pacífico<br>Lugar: SFAF Main Office, 1035 Market St, San Francisco, CA 94103<br><br>Vuelva a agregarlo a su calendario<br>Google: https://calendar.google.com/calendar/render?action=TEMPLATE<br>Apple u Outlook: https://resources.sfaf.org/?uc_ics=SAMPLE<br>Ver la página del evento: https://resources.sfaf.org/collections/events/coffee-and-conversation/<br><br>¿No puede asistir en la nueva fecha? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

### Several dates

| | English | Spanish |
|---|---|---|
| Subject | Back on: 2 dates for Coffee and Conversation | Se reanudan: 2 fechas de Coffee and Conversation |
| Text | 2 dates are back on.<br><br>Alex, these were cancelled and are happening after all. Your registrations were kept and still hold.<br><br>Thursday, November 12, 2026: 6–7:30 pm PT<br>Thursday, November 19, 2026: 6–7:30 pm PT<br><br><br>No longer able to come to one of them? Cancel your registration (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) so somebody else can take your place. We will ask you to confirm.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Se reanudan 2 fechas.<br><br>Alex, estas fechas se habían cancelado y finalmente sí se llevarán a cabo. Sus inscripciones se conservaron y siguen vigentes.<br><br>jueves, 12 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br>jueves, 19 de noviembre de 2026: 6–7:30 p. m., hora del Pacífico<br><br><br>¿Ya no puede asistir a alguna de ellas? Cancele su inscripción (https://resources.sfaf.org/?uc_rsvp_cancel=SAMPLE) para que otra persona pueda ocupar su lugar. Le pediremos que lo confirme.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Follow a series: confirm

| | English | Spanish |
|---|---|---|
| Subject | Confirm you're following Coffee and Conversation | Confirme que desea seguir Coffee and Conversation |
| Text | Confirm you're following Coffee and Conversation<br><br>Almost there! Confirm below and we'll email you whenever a new date is added.<br><br>Yes, follow this series: https://resources.sfaf.org/?uc_follow_confirm=SAMPLE<br><br>This link works for the next 7 days.<br><br><br>Didn't ask for this? Ignore it and nothing happens. You can stop these emails (https://resources.sfaf.org/?uc_follow_stop=SAMPLE) any time.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 | Confirme que desea seguir Coffee and Conversation<br><br>¡Ya casi! Confirme a continuación y le enviaremos un correo electrónico cada vez que se agregue una nueva fecha.<br><br>Sí, seguir esta serie: https://resources.sfaf.org/?uc_follow_confirm=SAMPLE<br><br>Este enlace funciona durante los próximos 7 días.<br><br><br>¿No lo solicitó? Ignore este mensaje y no pasará nada. Puede dejar de recibir estos correos (https://resources.sfaf.org/?uc_follow_stop=SAMPLE) en cualquier momento.<br><br>San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103 |

## Donate line

| | English | Spanish |
|---|---|---|
| Text | Support this work: donate to SFAF (https://donate.sfaf.org/campaign/773029/donate). | Apoye este trabajo: done a SFAF (https://donate.sfaf.org/campaign/773029/donate). |

## Cancel your place page

### Asking

| | English | Spanish |
|---|---|---|
| Title | Can't make it? | ¿No puede asistir? |
| Page | Cancel your registration for Coffee and Conversation?<br><br>Thursday, November 12, 2026 6–7:30 pm PT<br><br>Places are limited, so canceling puts yours back for someone else.<br><br>Yes, cancel my registration | ¿Desea cancelar su inscripción en Coffee and Conversation?<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico<br><br>Los lugares son limitados, así que al cancelar su lugar queda disponible para otra persona.<br><br>Sí, cancelar mi inscripción |

### Released

| | English | Spanish |
|---|---|---|
| Title | Registration canceled | Inscripción cancelada |
| Page | Your registration for Coffee and Conversation has been canceled.<br><br>Thursday, November 12, 2026 6–7:30 pm PT<br><br>Your place has gone back to the count for someone else. | Se canceló su inscripción en Coffee and Conversation.<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico<br><br>Su lugar quedó disponible para otra persona. |

### Leaving the waitlist

| | English | Spanish |
|---|---|---|
| Title | Leave the waitlist? | ¿Desea salir de la lista de espera? |
| Page | Leave the waitlist for Coffee and Conversation?<br><br>Thursday, November 12, 2026 6–7:30 pm PT<br><br>Yes, leave the waitlist | ¿Desea salir de la lista de espera de Coffee and Conversation?<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico<br><br>Sí, salir de la lista de espera |

### Left the waitlist

| | English | Spanish |
|---|---|---|
| Title | You have left the waitlist | Salió de la lista de espera |
| Page | You are no longer on the waitlist for Coffee and Conversation.<br><br>Thursday, November 12, 2026 6–7:30 pm PT | Ya no está en la lista de espera de Coffee and Conversation.<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico |

### Nothing to cancel

| | English | Spanish |
|---|---|---|
| Title | Nothing to cancel | No hay nada que cancelar |
| Page | We could not find an active registration for Coffee and Conversation against this address. It may already have been canceled. | No encontramos una inscripción activa en Coffee and Conversation para esta dirección. Es posible que ya se haya cancelado. |

## Confirm your place page

### Asking

| | English | Spanish |
|---|---|---|
| Title | Your place is waiting | Su lugar lo espera |
| Page | Confirm your place at Coffee and Conversation?<br><br>Thursday, November 12, 2026 6–7:30 pm PT<br><br>This offer lasts until Wednesday, November 11, 2026 at 6 pm PT.<br><br>Yes, confirm my place | ¿Desea confirmar su lugar en Coffee and Conversation?<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico<br><br>Esta oferta es válida hasta el miércoles, 11 de noviembre de 2026 a las 6 p. m., hora del Pacífico.<br><br>Sí, confirmar mi lugar |

### Confirmed

| | English | Spanish |
|---|---|---|
| Title | You are registered | Su inscripción está confirmada |
| Page | Your place at Coffee and Conversation is confirmed. Your confirmation is on its way by email.<br><br>Thursday, November 12, 2026 6–7:30 pm PT | Su lugar en Coffee and Conversation está confirmado. Le enviamos la confirmación por correo electrónico.<br><br>jueves, 12 de noviembre de 2026 6–7:30 p. m., hora del Pacífico |

### Offer passed

| | English | Spanish |
|---|---|---|
| Title | This offer has passed | Esta oferta ha vencido |
| Page | The place offered for Coffee and Conversation has gone to the next person on the waitlist. | El lugar que se ofreció en Coffee and Conversation pasó a la siguiente persona de la lista de espera. |

## The fixed words around the text

Labels, buttons and the calendar file's words. These are not edited on the Templates screen.

| English | Spanish |
|---|---|
| Event | Evento |
| Date | Fecha |
| Time | Hora |
| Location | Lugar |
| Add to calendar | Agregar al calendario |
| Update your calendar | Actualice su calendario |
| Put it back in your calendar | Vuelva a agregarlo a su calendario |
| Google | Google |
| Apple or Outlook | Apple u Outlook |
| See the event page | Ver la página del evento |
| Joining online | Cómo participar en línea |
| Join the event | Participar en el evento |
| Or paste this into your browser: | O copie y pegue esto en su navegador: |
| A link to join will be sent before the event. | Le enviaremos un enlace para participar antes del evento. |
| It was going to be: | Esto era lo previsto: |
| The event is now: | El evento ahora es: |
| What changed | Qué cambió |
| to | a |
| Online Event | Evento en línea |
| time to be confirmed | hora por confirmar |
| Cancel your registration | Cancele su inscripción |
| Leave the waitlist | Salga de la lista de espera |
| Confirm my place | Confirmar mi lugar |
| Yes, follow this series | Sí, seguir esta serie |
| stop these emails | dejar de recibir estos correos |
| See the event page | Ver la página del evento |
| donate to SFAF | done a SFAF |
| Yes, cancel my registration | Sí, cancelar mi inscripción |
| Yes, leave the waitlist | Sí, salir de la lista de espera |
| Yes, confirm my place | Sí, confirmar mi lugar |
| Today, %s | Hoy, %s |
| Cancelled, %s | Cancelado, %s |
| Now %s | Ahora %s |
| Back on, %s | Se reanuda, %s |
| Waitlist, %s | Lista de espera, %s |
| Confirm by %s | Confirme a más tardar el %s |
| Join | Participar |
| Join the event | Participar en el evento |
| In person and online | En persona y en línea |
