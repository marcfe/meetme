<?php
require_once __DIR__ . '/lib.php';

$expected = hash('sha256', ADMIN_PASS . ':meetme-feed');
$given = $_GET['t'] ?? '';

if (!hash_equals($expected, $given)) {
    header('HTTP/1.1 403 Forbidden');
    die('Invalid token');
}

$ics = get_calendar_data();
$events_by_uid = [];

if ($ics) {
    $vcal = Sabre\VObject\Reader::read($ics);
    foreach ($vcal->VEVENT as $event) {
        $uid = (string)$event->UID;
        $events_by_uid[$uid] = $event;
    }
}

$booking_files = glob(__DIR__ . '/bookings/*.json');
$confirmed = [];

foreach ($booking_files as $f) {
    $b = json_decode(file_get_contents($f), true);
    if (($b['status'] ?? '') !== 'booked') continue;
    if (isset($events_by_uid[$b['uid']])) {
        $confirmed[] = ['booking' => $b, 'event' => $events_by_uid[$b['uid']], 'from_ics' => true];
    } elseif (!empty($b['slot_start'])) {
        $confirmed[] = ['booking' => $b, 'event' => null, 'from_ics' => false];
    }
}

$cal = new Sabre\VObject\Component\VCalendar();
$cal->PRODID = '-//meetme//Confirmed Bookings//EN';
$cal->{'X-WR-CALNAME'} = 'meetme Bookings';
$cal->{'X-WR-TIMEZONE'} = TIMEZONE;

foreach ($confirmed as $item) {
    $b = $item['booking'];
    $ev = $item['event'];

    if ($ev) {
        $start = $ev->DTSTART->getDateTime();
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime() : (clone $start)->modify('+1 hour');
        $summary = (string)$ev->SUMMARY;
        $location = (string)$ev->LOCATION;
    } else {
        $start = new DateTime($b['slot_start']);
        $end = new DateTime($b['slot_end']);
        $summary = $b['slot_summary'] ?? 'Meeting';
        $location = $b['slot_location'] ?? '';
    }

    $guest_line = $b['name'] . (!empty($b['email']) ? ' (' . $b['email'] . ')' : '');

    $vevent = $cal->add('VEVENT', [
        'SUMMARY' => $summary . ' — ' . $b['name'],
        'DTSTART' => $start,
        'DTEND' => $end,
        'LOCATION' => $location,
        'DESCRIPTION' => "Guest: $guest_line\nNote: " . ($b['note'] ?: '-'),
        'UID' => 'meetme-' . sha1($b['uid']),
        'DTSTAMP' => new DateTime($b['at']),
    ]);
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="meetme-bookings.ics"');
echo $cal->serialize();
