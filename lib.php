<?php
/**
 * meetme Library Functions
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

use Sabre\VObject;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Force Timezone
date_default_timezone_set(TIMEZONE);

/**
 * i18n Helper
 */
function get_lang() {
    global $L;
    if (isset($_GET['lang']) && isset($L[$_GET['lang']])) {
        setcookie('lang', $_GET['lang'], time() + (86400 * 30), "/");
        return $_GET['lang'];
    }
    if (isset($_COOKIE['lang']) && isset($L[$_COOKIE['lang']])) {
        return $_COOKIE['lang'];
    }
    
    return 'bd';
}

$lang = get_lang();
$t = $L[$lang];

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Rate Limiting
 */
function check_rate_limit() {
    $limit_file = __DIR__ . '/cache/ratelimit.json';
    $ip_hash = sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = time();
    $limit_period = 3600; // 1 hour

    $fp = fopen($limit_file, 'c+');
    if (!$fp) return; // Fail open if we can't write? Or fail closed? Let's fail open but log.
    
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $data = json_decode($content, true) ?: [];
    
    // Prune old
    foreach ($data as $ip => $ts_array) {
        $data[$ip] = array_filter($ts_array, function($ts) use ($now, $limit_period) {
            return $ts > ($now - $limit_period);
        });
        if (empty($data[$ip])) unset($data[$ip]);
    }
    
    // Check current IP
    $current_hits = $data[$ip_hash] ?? [];
    if (count($current_hits) >= RATE_LIMIT_PER_HOUR) {
        flock($fp, LOCK_UN);
        fclose($fp);
        header('HTTP/1.1 429 Too Many Requests');
        die($GLOBALS['t']['rate_limit_exceeded']);
    }
    
    // Add hit
    $data[$ip_hash][] = $now;
    
    // Save
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * ICS Fetching & Caching
 */
function get_calendar_data() {
    $cache_file = __DIR__ . '/cache/offer.ics';
    $etag_file = __DIR__ . '/cache/offer.etag';
    $now = time();
    
    $cached_ics = file_exists($cache_file) ? file_get_contents($cache_file) : '';
    $cached_etag = file_exists($etag_file) ? trim(file_get_contents($etag_file)) : '';
    $cache_mtime = file_exists($cache_file) ? filemtime($cache_file) : 0;
    
    if ($now - $cache_mtime < CACHE_TTL && !empty($cached_ics)) {
        return $cached_ics;
    }
    
    // Try fetch
    $ch = curl_init(ICS_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if (!empty($cached_etag)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["If-None-Match: $cached_etag"]);
    }
    
    $full_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($full_response, 0, $header_size);
    $body = substr($full_response, $header_size);
    
    if ($http_code === 304) {
        touch($cache_file); // Update mtime
        curl_close($ch);
        return $cached_ics;
    }
    
    if ($http_code === 200 && !empty($body)) {
        preg_match('/ETag: (.*)/i', $headers, $matches);
        $etag = isset($matches[1]) ? trim($matches[1]) : '';
        
        file_put_contents($cache_file, $body);
        if (!empty($etag)) file_put_contents($etag_file, $etag);
        curl_close($ch);
        return $body;
    }
    
    curl_close($ch);
    // stale-while-error
    return $cached_ics;
}

/**
 * Slot Filtering Logic
 */
function get_slots($invite = null) {
    $ics = get_calendar_data();
    if (!$ics) return [];
    
    $vcal = VObject\Reader::read($ics);
    $slots = [];
    $now = new DateTime();
    
    $horizon_days = $invite['horizon_days'] ?? DEFAULT_HORIZON_DAYS;
    $horizon = (new DateTime())->modify("+$horizon_days days");
    
    $allow_weekends = $invite['weekends'] ?? false;
    $tag = $invite['tag'] ?? null;
    
    foreach ($vcal->VEVENT as $event) {
        $start = $event->DTSTART->getDateTime();
        $end = isset($event->DTEND) ? $event->DTEND->getDateTime() : (clone $start)->modify('+1 hour');
        $uid = (string)$event->UID;
        $summary = (string)$event->SUMMARY;
        $location = (string)$event->LOCATION;
        
        // Basic filters
        if ($start < $now) continue;
        if ($start > $horizon) continue;
        
        // Check if booked or blocked
        if (file_exists(__DIR__ . '/bookings/' . sha1($uid) . '.json')) continue;
        
        // Weekend rule
        $day_of_week = (int)$start->format('N'); // 1 (Mon) to 7 (Sun)
        $hour = (int)$start->format('G');
        
        if (!$allow_weekends) {
            if ($day_of_week > 5) continue; // Skip Sat/Sun
        } else {
            // Mon-Thu: all day
            // Fri: >= 18:00
            // Sat/Sun: all day
            if ($day_of_week === 5 && $hour < 18) continue;
        }
        
        // Tag filter
        $found_tag = null;
        if (preg_match('/#(\w+)/i', $summary, $matches)) {
            $found_tag = strtolower($matches[1]);
        }
        
        if ($tag) {
            if ($found_tag !== strtolower($tag)) continue;
            $summary = trim(preg_replace('/#' . preg_quote($tag, '/') . '/i', '', $summary));
        } else {
            if ($found_tag !== null) continue; // Public pool: untagged only
        }
        
        $slots[] = [
            'uid' => $uid,
            'start' => $start,
            'end' => $end,
            'summary' => translate_summary($summary),
            'location' => $location,
            'display_location' => format_location($location)
        ];
    }
    
    usort($slots, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });
    
    return $slots;
}

function format_location($location) {
    global $LOCATION_MAP, $t;
    foreach ($LOCATION_MAP as $key => $display) {
        if (stripos($location, $key) !== false) {
            return sprintf($t['preferred_around'], $display);
        }
    }
    return $location;
}

function translate_summary($summary) {
    global $t;
    $summary = preg_replace_callback('/\b(Lunch|Dinner)\b/i', function($matches) use ($t) {
        $key = strtolower($matches[1]);
        return $t[$key] ?? $matches[0];
    }, $summary);
    return $summary;
}

function format_date($dt) {
    $days_bd = ['Sun' => 'Su', 'Mon' => 'Mä', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa'];
    $days_de = ['Sun' => 'So', 'Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa'];
    
    $lang = $GLOBALS['lang'];
    $day = $dt->format('D');
    
    if ($lang === 'bd') $prefix = $days_bd[$day] ?? $day;
    elseif ($lang === 'de') $prefix = $days_de[$day] ?? $day;
    else $prefix = $day;
    
    return $prefix . ', ' . $dt->format('d.m.Y, H:i');
}

/**
 * Atomic Writing
 */
function atomic_write($path, $data) {
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) return false;
    if (!rename($tmp, $path)) {
        unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Email Sending
 */
function send_booking_email($guest_email, $slot, $booking) {
    global $t;

    // 1. Send to OWNER
    $owner_summary = sprintf($t['meeting_with'], $slot['summary'], $booking['name']);
    $owner_body = "New booking confirmed!\n\n";
    $owner_body .= "What: " . $slot['summary'] . "\n";
    $owner_body .= "When: " . format_date($slot['start']) . "\n";
    $owner_body .= "Where: " . $slot['location'] . "\n\n";
    $owner_body .= "Guest: " . $booking['name'] . " (" . ($booking['email'] ?: 'no email') . ")\n";
    $owner_body .= "Note: " . ($booking['note'] ?: '-') . "\n";
    
    send_single_ics_mail(OWNER_EMAIL, OWNER_NAME, $owner_summary, $owner_body, $slot, $booking, true);

    // 2. Send to GUEST
    if (!empty($guest_email)) {
        $guest_summary = sprintf($t['meeting_with'], $slot['summary'], OWNER_NAME);
        $guest_body = "Booking confirmed!\n\n";
        $guest_body .= "What: " . $slot['summary'] . "\n";
        $guest_body .= "When: " . format_date($slot['start']) . "\n";
        $guest_body .= "Where: " . $slot['location'] . "\n\n";
        $guest_body .= "Host: " . OWNER_NAME . "\n";
        
        send_single_ics_mail($guest_email, $booking['name'], $guest_summary, $guest_body, $slot, $booking, false);
    }
    
    return true;
}

function send_single_ics_mail($to_email, $to_name, $summary, $body, $slot, $booking, $is_to_owner) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = !empty(SMTP_USER);
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        
        $mail->setFrom(FROM_EMAIL, OWNER_NAME);
        if (defined('REPLY_TO') && !empty(REPLY_TO)) {
            $mail->addReplyTo(REPLY_TO, OWNER_NAME);
        }
        $mail->addAddress($to_email, $to_name);
        
        $mail->isHTML(false);
        $mail->Subject = $summary . ' (' . format_date($slot['start']) . ')';
        $mail->Body = $body;
        
        // Add ICS
        $vcal = new VObject\Component\VCalendar([
            'METHOD' => 'REQUEST',
        ]);
        $vevent = $vcal->add('VEVENT', [
            'SUMMARY' => $summary,
            'DTSTART' => $slot['start'],
            'DTEND'   => $slot['end'],
            'LOCATION' => $slot['location'],
            'DESCRIPTION' => $body,
            'UID' => $slot['uid'],
            'SEQUENCE' => 99,
            'ORGANIZER' => 'mailto:' . OWNER_EMAIL,
        ]);
        
        // Add Attendee (the person receiving the mail)
        $vevent->add('ATTENDEE', 'mailto:' . $to_email, [
            'ROLE' => 'REQ-PARTICIPANT',
            'PARTSTAT' => 'NEEDS-ACTION',
            'RSVP' => 'TRUE',
            'CN' => $to_name,
        ]);
        
        $mail->addStringAttachment($vcal->serialize(), 'invite.ics', 'base64', 'text/calendar; method=REQUEST');
        $mail->send();
    } catch (Exception $e) {
        error_log("Mail Error: " . $mail->ErrorInfo);
    }
}

function send_request_email($name, $email, $message, $slot_summary, $slot_time) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = !empty(SMTP_USER);
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        $mail->setFrom(FROM_EMAIL, 'meetme');
        $mail->addAddress(OWNER_EMAIL, OWNER_NAME);
        $mail->addReplyTo($email, $name);
        
        $mail->Subject = "New Meeting Request from $name";
        $mail->Body = "Name: $name\nEmail: $email\nSlot: $slot_summary ($slot_time)\n\nMessage:\n$message";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function get_all_slots_admin() {
    $ics = get_calendar_data();
    if (!$ics) return [];
    $vcal = VObject\Reader::read($ics);
    $slots = [];
    $now = new DateTime();
    $horizon = (new DateTime())->modify("+60 days");
    
    foreach ($vcal->VEVENT as $event) {
        $start = $event->DTSTART->getDateTime();
        if ($start < $now || $start > $horizon) continue;
        
        $uid = (string)$event->UID;
        $summary = (string)$event->SUMMARY;
        $tag = null;
        if (preg_match('/#(\w+)/i', $summary, $matches)) {
            $tag = $matches[1];
        }
        
        $slots[] = [
            'uid' => $uid,
            'start' => $start,
            'summary' => translate_summary($summary),
            'tag' => $tag,
            'is_blocked' => file_exists(__DIR__ . '/bookings/' . sha1($uid) . '.json')
        ];
    }
    usort($slots, function($a, $b) { return $a['start'] <=> $b['start']; });
    return $slots;
}
