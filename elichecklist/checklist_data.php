<?php
/**
 * Shared data definition for the Event Checklist.
 * Included by both index.php (rendering) and api.php (validation),
 * so the checklist is defined in exactly one place.
 */

$SECTIONS = [
    'A' => ['title' => 'Planning & coordination', 'items' => [
        'Confirm the event objective, theme and expected outcomes',
        'Confirm event date, venue, access times and closing time',
        'Prepare the event budget and payment schedule',
        'Confirm the guest list and VIPs',
        'Confirm speakers, facilitators, MC and special guests',
        'Prepare the programme flow with timings',
        'Assign team leaders and responsibilities',
        'Create a contact sheet for all suppliers and key personnel',
        'Confirm supplier contracts, deposits and balances',
    ]],
    'B' => ['title' => 'Venue & layout', 'items' => [
        'Inspect venue capacity, ventilation, lighting and cleanliness',
        'Confirm seating layout for all guests plus speakers and staff',
        'Plan registration area, stage, aisles and emergency exits',
        'Confirm stage, podium, backdrop and signage',
        'Arrange tables for registration, catering and materials',
        'Confirm toilets, accessibility and parking arrangements',
        'Test air conditioning or ventilation',
        'Arrange reserved seating for VIPs and speakers',
        'Confirm venue setup and breakdown times',
    ]],
    'C' => ['title' => 'Materials', 'items' => [
        'Install stage backdrop, banners and directional signage',
        'Prepare name badges and lanyards',
        'Print attendance register or prepare digital check-in',
        'Prepare programmes, handouts and stationery',
        'Prepare certificates, gifts or merchandise if needed',
        'Prepare speaker name cards and reserved-seat signs',
        'Check all artwork, spelling and logos',
    ]],
    'D' => ['title' => 'Audio-visual & technology', 'items' => [
        'Confirm PA system and speakers cover the room',
        'Test microphones, including spare batteries',
        'Test projector, screen and presentation laptop',
        'Collect all presentations and check slide order',
        'Confirm clicker, adapters, extension cables and power strips',
        'Test videos, sound clips, QR codes and internet access',
        'Prepare backup laptop or offline copies of presentations',
        'Assign an AV technician throughout the event',
    ]],
    'E' => ['title' => 'Catering & hospitality', 'items' => [
        'Confirm menu, dietary requirements and allergies',
        'Agree quantities, serving times and service style',
        'Confirm tea / coffee break and lunch arrangements',
        'Arrange drinking water for guests, speakers and staff',
        'Confirm caterer arrival, setup and clearing times',
        'Provide labelled waste bins and keep serving areas clean',
        'Arrange separate refreshments for speakers and VIPs if required',
        'Confirm final catering numbers and payment terms',
    ]],
    'F' => ['title' => 'People & programme', 'items' => [
        'Brief the MC on names, titles, theme and pronunciation',
        'Confirm speaker arrival times and presentation durations',
        'Prepare speaker introductions and session transitions',
        'Assign registration staff and ushers',
        'Assign a timekeeper and stage manager',
        'Brief photographers, videographers and social media team',
        'Prepare audience engagement activities and Q&A process',
        'Prepare opening and closing announcements',
        'Share the final programme and emergency contacts with the team',
    ]],
    'G' => ['title' => 'Safety & contingency', 'items' => [
        'Confirm emergency exits and evacuation procedures',
        'Identify first-aid provision and emergency contacts',
        'Check fire extinguishers and accessible exit routes',
        'Confirm backup power arrangements where needed',
        'Prepare contingency plans for speaker or supplier delays',
        'Keep a basic event emergency and incident log',
        'Confirm security arrangements and lost-property procedure',
        'Check that walkways and cables are safely managed',
    ]],
    'H' => ['title' => 'Close-out & follow-up', 'items' => [
        'Thank speakers, sponsors, suppliers and guests',
        'Confirm attendance count and collect feedback',
        'Check all rented equipment and borrowed items',
        'Inspect venue for lost property and damage',
        'Settle approved supplier balances and collect receipts',
        'Back up photographs, videos and presentation files',
        'Send thank-you messages and certificates where applicable',
        'Prepare an event report with budget, attendance and lessons learned',
    ]],
];

$SCHEDULE = [
    ['6:00',  'Venue & supplier setup',       'Venue team, decorators, AV and caterers arrive. Check furniture, stage, power and cleanliness.'],
    ['7:00',  'Rehearsal & team briefing',    'Test microphones, presentations, videos and internet. Brief ushers, registration, security, MC and stage manager.'],
    ['8:00',  'Registration & reception',     'Open check-in, issue badges and programmes, seat guests, play background music, welcome VIPs.'],
    ['9:00',  'Programme begins',             'Opening, welcome remarks, keynote sessions and audience engagement.'],
    ['10:30', 'Tea break',                    'Coordinate service, monitor queues, announce the time to resume.'],
    ['11:00', 'Main sessions resume',         'Manage speakers, presentations, questions, panels and timekeeping.'],
    ['13:00', 'Lunch break',                  'Coordinate meal service, hospitality and guest movement.'],
    ['14:00', 'Afternoon sessions & closing', 'Resume sessions, final engagement, key takeaways, acknowledgements, close.'],
    ['16:00', 'Departure & breakdown',        'Manage departures, pack equipment, clear the venue, check hired items, final inspection.'],
];

$STAFFING = [
    ['Event manager',               '1',            'Overall coordination and decisions'],
    ['Assistant coordinator',       '1',            'Supplier and logistics coordination'],
    ['Registration team',           '4',            'Check-in and badges'],
    ['Ushers',                      '4',            'Seating, directions and guest assistance'],
    ['MC',                          '1',            'Programme flow and announcements'],
    ['Stage manager / timekeeper',  '1',            'Speaker cues and time control'],
    ['AV technicians',              '2',            'Sound, screens and presentations'],
    ['Hospitality / catering',      'with caterer', 'Food, beverages and clearing'],
    ['Security / first-aid',        'with venue',   'Safety and incident response'],
    ['Photographer / videographer', '1–2',          'Event documentation'],
];

$QUANTITIES = [
    ['Guest chairs',          'Headcount, plus contingency'],
    ['Name badges',           'Headcount + 10%'],
    ['Printed programmes',    'Headcount + 10% if distributing to all'],
    ['Pens',                  'Headcount + 10% if required'],
    ['Drinking water',        'Based on duration and venue conditions'],
    ['Tea-break portions',    'Headcount, plus contingency'],
    ['Lunch portions',        'Headcount, plus contingency'],
    ['Microphones',           '2–4, depending on sessions'],
    ['Registration stations', '2–4, depending on check-in method'],
];

$SIGNOFF = [
    'Venue access and setup times confirmed',
    'All suppliers have received their arrival instructions',
    'Final guest and VIP lists shared with registration',
    'Programme and speaker attendance reconfirmed',
    'Catering numbers, menu and service times approved',
    'AV rehearsal completed or scheduled',
    'Team group chat and contact sheet ready',
    'Emergency arrangements and exit routes checked',
    'Outstanding payments and supplier balances reviewed',
    'Final briefing time communicated to the entire team',
];

/** Meta fields the event header exposes (shared across all users). */
$META_FIELDS = ['name', 'date', 'venue', 'pax'];

function item_id(string $section, int $i): string { return 'c_' . $section . '_' . $i; }

/** Every valid checklist item id (main sections + sign-off), as id => true. */
function checklist_valid_ids(): array {
    global $SECTIONS, $SIGNOFF;
    $ids = [];
    foreach ($SECTIONS as $letter => $sec) {
        foreach ($sec['items'] as $i => $_) { $ids[item_id($letter, $i)] = true; }
    }
    foreach ($SIGNOFF as $i => $_) { $ids['so_' . $i] = true; }
    return $ids;
}

/** Count of main checklist items (excludes the sign-off list) used for the progress bar. */
function checklist_main_count(): int {
    global $SECTIONS;
    $n = 0;
    foreach ($SECTIONS as $sec) { $n += count($sec['items']); }
    return $n;
}
