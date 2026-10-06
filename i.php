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
<html lang="<?= $lang ?>" class="bg-gray-50 text-gray-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($t['title']) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <style>
        .slot-radio input:checked + label {
            border-color: #4f46e5;
            background-color: #f9fafb;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-8">
            <header class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight leading-tight">
                    <?= nl2br(h($invite['greeting'] ?: $t['invite_greeting'])) ?>
                </h1>
                <div class="flex space-x-1 bg-gray-100 p-1 rounded-lg ml-4 flex-shrink-0">
                    <a href="?k=<?= h($token) ?>&lang=bd" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'bd' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇨🇭</a>
                    <a href="?k=<?= h($token) ?>&lang=de" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'de' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇩🇪</a>
                    <a href="?k=<?= h($token) ?>&lang=en" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'en' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇬🇧</a>
                </div>
            </header>

            <h2 class="text-lg font-semibold text-gray-700 mb-1"><?= h($t['title']) ?></h2>
            <p class="text-gray-500 mb-8"><?= h($t['subtitle']) ?></p>

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-4 rounded-lg shadow-sm">
                    <strong class="font-medium text-lg block mb-1"><?= h($t['booking_success']) ?></strong>
                    <?= h($t['booking_confirmation']) ?>
                </div>
            <?php elseif ($cooldown_hit): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-4 rounded-lg shadow-sm">
                    <?= h($cooldown_hit) ?>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg shadow-sm">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($slots)): ?>
                    <div class="bg-gray-50 rounded-lg p-6 text-center border border-gray-100">
                        <p class="text-gray-500"><?= h($t['no_slots']) ?></p>
                    </div>
                <?php else: ?>
                    <?php $unique_summaries = array_unique(array_column($slots, 'summary')); ?>
                    <?php if (count($unique_summaries) > 1): ?>
                        <div class="flex flex-wrap gap-2 mb-6" id="slot-filters">
                            <button type="button" class="filter-btn px-4 py-1.5 rounded-full text-sm font-medium bg-gray-900 text-white transition-colors" data-filter="all"><?= h($t['all'] ?? 'All') ?></button>
                            <?php foreach ($unique_summaries as $summary): ?>
                                <button type="button" class="filter-btn px-4 py-1.5 rounded-full text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" data-filter="<?= h($summary) ?>"><?= h($summary) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="space-y-6">
                        <!-- Honeypot -->
                        <input name="website" tabindex="-1" autocomplete="off" class="sr-only">

                        <div>
                            
                            <div class="space-y-4">
                                <?php foreach ($slots as $slot): ?>
                                    <div class="slot-radio relative slot-item" data-summary="<?= h($slot['summary']) ?>">
                                        <input type="radio" name="slot_uid" value="<?= h($slot['uid']) ?>" id="slot_<?= h(sha1($slot['uid'])) ?>" required class="peer sr-only">
                                        <label for="slot_<?= h(sha1($slot['uid'])) ?>" class="block bg-white border-2 border-gray-200 rounded-xl p-4 cursor-pointer hover:bg-gray-50 transition-colors shadow-sm">
                                            <div class="flex justify-between items-start">
                                                <div class="font-semibold text-gray-900 peer-checked:text-indigo-800"><?= h(format_date($slot['start'])) ?></div>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    <?= h($slot['summary']) ?>
                                                </span>
                                            </div>
                                            <?php if ($slot['display_location']): ?>
                                                <div class="mt-2 flex items-center text-sm text-gray-500">
                                                    <svg class="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                                    </svg>
                                                    <?= h($slot['display_location']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['name']) ?></label>
                            <input type="text" name="name" value="<?= h($invite['label'] ?? '') ?>" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-3 py-2 border bg-white">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['email']) ?> <span class="text-gray-400 font-normal">(optional)</span></label>
                            <input type="email" name="email" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-3 py-2 border bg-white">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['note']) ?></label>
                            <textarea name="note" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm px-3 py-2 border bg-white"></textarea>
                        </div>

                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-black focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                            <?= h($t['submit_booking']) ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const filters = document.querySelectorAll('.filter-btn');
        const slots = document.querySelectorAll('.slot-item');
        if (!filters.length) return;
        filters.forEach(btn => {
            btn.addEventListener('click', () => {
                filters.forEach(f => {
                    f.classList.remove('bg-gray-900', 'text-white');
                    f.classList.add('bg-gray-100', 'text-gray-700');
                });
                btn.classList.remove('bg-gray-100', 'text-gray-700');
                btn.classList.add('bg-gray-900', 'text-white');
                const filter = btn.dataset.filter;
                slots.forEach(slot => {
                    if (filter === 'all' || slot.dataset.summary === filter) {
                        slot.style.display = '';
                    } else {
                        slot.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>
</body>
</html>
