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
<html lang="<?= $lang ?>" class="bg-gray-50 text-gray-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($t['title']) ?> — meetme</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="min-h-screen flex flex-col items-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-8">
            <header class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight"><?= h($t['title']) ?></h1>
                <div class="flex space-x-1 bg-gray-100 p-1 rounded-lg">
                    <a href="?lang=bd" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'bd' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇨🇭</a>
                    <a href="?lang=de" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'de' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇩🇪</a>
                    <a href="?lang=en" class="px-2 py-1 rounded-md text-sm transition-colors <?= $lang === 'en' ? 'bg-white shadow-sm' : 'hover:bg-gray-200' ?>">🇬🇧</a>
                </div>
            </header>

            <p class="text-gray-500 mb-8"><?= h($t['subtitle']) ?></p>

            <?php if ($success): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg shadow-sm">
                    <strong class="font-medium">Merci!</strong> I mäudde mi bi dir.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg shadow-sm">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <section class="mb-10">
                <h2 class="text-lg font-bold text-gray-900 mb-4"><?= h($t['next_slots']) ?></h2>
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
                    <ul class="space-y-4">
                        <?php foreach ($slots as $slot): ?>
                            <li data-summary="<?= h($slot['summary']) ?>" class="slot-item bg-white border-2 border-gray-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start">
                                    <div class="font-semibold text-gray-900"><?= h(format_date($slot['start'])) ?></div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-indigo-800">
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
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if (PUBLIC_REQUESTS_ENABLED && !empty($slots)): ?>
                <form method="POST" class="bg-gray-50 -mx-6 -mb-8 px-6 py-8 border-t border-gray-200">
                    <h2 class="text-lg font-bold text-gray-900 mb-6"><?= h($t['request_slot']) ?></h2>
                    
                    <!-- Honeypot -->
                    <input name="website" tabindex="-1" autocomplete="off" class="sr-only">
                    
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['name']) ?></label>
                            <input type="text" name="name" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm px-3 py-2 border bg-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['email']) ?></label>
                            <input type="email" name="email" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm px-3 py-2 border bg-white">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Slot</label>
                            <select name="slot_uid" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm px-3 py-2 border bg-white">
                                <?php foreach ($slots as $slot): ?>
                                    <option value="<?= h($slot['uid']) ?>"><?= h(format_date($slot['start'])) ?> — <?= h($slot['summary']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($t['message']) ?> <span class="text-gray-400 font-normal">(optional)</span></label>
                            <textarea name="message" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm px-3 py-2 border bg-white"></textarea>
                        </div>
                        
                        <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900 transition-colors">
                            <?= h($t['submit_request']) ?>
                        </button>
                    </div>
                </form>
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
