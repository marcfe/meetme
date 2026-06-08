<?php
require_once __DIR__ . '/lib.php';

$token = $_GET['k'] ?? '';
$invite_file = __DIR__ . '/invites/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';

if (empty($token) || !file_exists($invite_file)) {
    header("HTTP/1.1 404 Not Found");
    die("<h1>404 Not Found</h1>");
}

$invite = json_decode(file_get_contents($invite_file), true);

if (($invite['revoked'] ?? false) === true) {
    header("HTTP/1.1 404 Not Found");
    die("<h1>404 Not Found</h1>");
}

if (($invite['mode'] ?? 'single') === 'single' && !empty($invite['consumed_at'])) {
    die("<h1>" . h($t['back']) . "</h1><p>This link has already been used.</p>");
}

$error = false;
$success = false;
$cooldown_hit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_rate_limit();
    
    // Honeypot
    if (!empty($_POST['website'])) {
        $success = true; // Silent
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $slot_uid = $_POST['slot_uid'] ?? '';
        
        if (empty($name) || empty($slot_uid)) {
            $error = $t['required_fields'];
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = $t['invalid_email'];
        } else {
            // Reusable cooldown check
            if ($invite['mode'] === 'reusable' && !empty($email)) {
                $cooldown_days = $invite['cooldown_days'] ?? DEFAULT_COOLDOWN_DAYS;
                $bookings_dir = __DIR__ . '/bookings/';
                $files = scandir($bookings_dir);
                foreach ($files as $f) {
                    if (strpos($f, '.json') !== false) {
                        $b = json_decode(file_get_contents($bookings_dir . $f), true);
                        if (($b['email'] ?? '') === $email && ($b['invite_token'] ?? '') === $token) {
                            $booked_at = new DateTime($b['at']);
                            $diff = $booked_at->diff(new DateTime());
                            if ($diff->days < $cooldown_days) {
                                $cooldown_hit = sprintf($t['cooldown_message'], format_date($booked_at));
                                break;
                            }
                        }
                    }
                }
            }
            
            if (!$cooldown_hit) {
                // Find slot
                $slots = get_slots($invite);
                $chosen_slot = null;
                foreach ($slots as $s) {
                    if ($s['uid'] === $slot_uid) {
                        $chosen_slot = $s;
                        break;
                    }
                }
                
                if ($chosen_slot) {
                    $booking_file = __DIR__ . '/bookings/' . sha1($slot_uid) . '.json';
                    $booking_data = [
                        'status' => 'booked',
                        'uid' => $slot_uid,
                        'name' => $name,
                        'email' => $email,
                        'note' => $note,
                        'invite_token' => $token,
                        'at' => date('c')
                    ];
                    
                    // Atomic booking
                    $fp = @fopen($booking_file, 'xb');
                    if ($fp) {
                        fwrite($fp, json_encode($booking_data, JSON_PRETTY_PRINT));
                        fclose($fp);
                        
                        // Mark invite as consumed if single-use
                        if ($invite['mode'] === 'single') {
                            $invite['consumed_at'] = date('c');
                            atomic_write($invite_file, $invite);
                        }
                        
                        // Send Mail
                        send_booking_email($email, $chosen_slot, $booking_data);
                        $success = true;
                    } else {
                        $error = $t['slot_taken'];
                    }
                } else {
                    $error = "Slot no longer available.";
                }
            }
        }
    }
}

$slots = get_slots($invite);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($t['title']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1><?= h($invite['greeting'] ?: $t['invite_greeting']) ?></h1>
        <div class="lang-switcher">
            <a href="?k=<?= h($token) ?>&lang=bd" class="<?= $lang === 'bd' ? 'active' : '' ?>">🇨🇭</a>
            <a href="?k=<?= h($token) ?>&lang=de" class="<?= $lang === 'de' ? 'active' : '' ?>">🇩🇪</a>
            <a href="?k=<?= h($token) ?>&lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">🇬🇧</a>
        </div>
    </header>

    <?php if ($success): ?>
        <div class="message message-success">
            <strong><?= h($t['booking_success']) ?></strong><br>
            <?= h($t['booking_confirmation']) ?>
        </div>
    <?php elseif ($cooldown_hit): ?>
        <div class="message message-error">
            <?= h($cooldown_hit) ?>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="message message-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (empty($slots)): ?>
            <p><?= h($t['no_slots']) ?></p>
        <?php else: ?>
            <form method="POST">
                <!-- Honeypot -->
                <input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px">

                <div class="field">
                    <label>Slot</label>
                    <?php foreach ($slots as $slot): ?>
                        <div class="slot-radio">
                            <input type="radio" name="slot_uid" value="<?= h($slot['uid']) ?>" id="slot_<?= h(sha1($slot['uid'])) ?>" required>
                            <label for="slot_<?= h(sha1($slot['uid'])) ?>" class="slot-item" style="display: block; cursor: pointer;">
                                <span class="slot-time"><?= h(format_date($slot['start'])) ?></span>
                                <span class="slot-summary"><?= h($slot['summary']) ?></span>
                                <?php if ($slot['display_location']): ?>
                                    <div class="slot-location"><?= h($slot['display_location']) ?></div>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="field">
                    <label><?= h($t['name']) ?></label>
                    <input type="text" name="name" value="<?= h($invite['label'] ?? '') ?>" required>
                </div>

                <div class="field">
                    <label><?= h($t['email']) ?> (optional)</label>
                    <input type="email" name="email">
                </div>

                <div class="field">
                    <label><?= h($t['note']) ?></label>
                    <textarea name="note"></textarea>
                </div>

                <button type="submit"><?= h($t['submit_booking']) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
