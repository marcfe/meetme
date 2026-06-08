<?php
require_once __DIR__ . '/lib.php';

// Route to invite handler if k is present
if (isset($_GET['k'])) {
    require_once __DIR__ . '/i.php';
    exit;
}

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && PUBLIC_REQUESTS_ENABLED) {
    check_rate_limit();
    
    // Honeypot
    if (!empty($_POST['website'])) {
        $success = true; // Silent success
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $slot_uid = $_POST['slot_uid'] ?? '';
        
        if (empty($name) || empty($email) || empty($slot_uid)) {
            $error = $t['required_fields'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = $t['invalid_email'];
        } else {
            // Find slot info for email
            $slots = get_slots();
            $chosen_slot = null;
            foreach ($slots as $s) {
                if ($s['uid'] === $slot_uid) {
                    $chosen_slot = $s;
                    break;
                }
            }
            
            if ($chosen_slot) {
                $slot_time = format_date($chosen_slot['start']);
                if (send_request_email($name, $email, $message, $chosen_slot['summary'], $slot_time)) {
                    $success = true;
                } else {
                    $error = "Mail error.";
                }
            } else {
                $error = "Slot no longer available.";
            }
        }
    }
}

$slots = array_slice(get_slots(), 0, 3);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($t['title']) ?> — meetme</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1><?= h($t['title']) ?></h1>
        <div class="lang-switcher">
            <a href="?lang=bd" class="<?= $lang === 'bd' ? 'active' : '' ?>">🇨🇭</a>
            <a href="?lang=de" class="<?= $lang === 'de' ? 'active' : '' ?>">🇩🇪</a>
            <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">🇬🇧</a>
        </div>
    </header>

    <p class="subtitle"><?= h($t['subtitle']) ?></p>

    <?php if ($success): ?>
        <div class="message message-success">
            Merci! I mäudde mi bi dir.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message message-error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <section>
        <h2><?= h($t['next_slots']) ?></h2>
        <?php if (empty($slots)): ?>
            <p><?= h($t['no_slots']) ?></p>
        <?php else: ?>
            <ul class="slots">
                <?php foreach ($slots as $slot): ?>
                    <li class="slot-item">
                        <span class="slot-time"><?= h(format_date($slot['start'])) ?></span>
                        <span class="slot-summary"><?= h($slot['summary']) ?></span>
                        <?php if ($slot['display_location']): ?>
                            <div class="slot-location"><?= h($slot['display_location']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if (PUBLIC_REQUESTS_ENABLED && !empty($slots)): ?>
        <form method="POST">
            <h2><?= h($t['request_slot']) ?></h2>
            
            <!-- Honeypot -->
            <input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px">
            
            <div class="field">
                <label><?= h($t['name']) ?></label>
                <input type="text" name="name" required>
            </div>
            
            <div class="field">
                <label><?= h($t['email']) ?></label>
                <input type="email" name="email" required>
            </div>

            <div class="field">
                <label>Slot</label>
                <select name="slot_uid" required>
                    <?php foreach ($slots as $slot): ?>
                        <option value="<?= h($slot['uid']) ?>"><?= h(format_date($slot['start'])) ?> — <?= h($slot['summary']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="field">
                <label><?= h($t['message']) ?> (optional)</label>
                <textarea name="message"></textarea>
            </div>
            
            <button type="submit"><?= h($t['submit_request']) ?></button>
        </form>
    <?php endif; ?>

</body>
</html>
