<?php
require_once __DIR__ . '/lib.php';

$uid = $_GET['uid'] ?? $_POST['uid'] ?? '';
$sig = $_GET['sig'] ?? $_POST['sig'] ?? '';

function cancel_page($title, $body, $code = 200) {
    if ($code !== 200) header("HTTP/1.1 $code");
    ?>
<!DOCTYPE html>
<html lang="en" class="bg-gray-50 dark:bg-gray-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — meetme</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <script>let t=localStorage.getItem('theme');(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))&&document.documentElement.classList.add('dark')</script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 max-w-sm w-full text-center">
        <?= $body ?>
    </div>
</body>
</html>
<?php
    exit;
}

if (empty($uid) || empty($sig)) {
    cancel_page('Invalid Link', '<div class="text-4xl mb-4">:(</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Invalid Link</h1>
        <p class="text-gray-500 dark:text-gray-400">This cancellation link is invalid.</p>', 400);
}

$expected = generate_cancel_token($uid);
if (!hash_equals($expected, $sig)) {
    cancel_page('Invalid Link', '<div class="text-4xl mb-4">:(</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Invalid Link</h1>
        <p class="text-gray-500 dark:text-gray-400">This cancellation link is invalid.</p>', 403);
}

$booking_path = __DIR__ . '/bookings/' . sha1($uid) . '.json';

if (!file_exists($booking_path)) {
    cancel_page('Already Cancelled', '<div class="text-4xl mb-4">👍</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Already Cancelled</h1>
        <p class="text-gray-500 dark:text-gray-400">This booking has already been cancelled or doesn\'t exist.</p>');
}

$booking = json_decode(file_get_contents($booking_path), true);

if (($booking['status'] ?? '') !== 'booked') {
    cancel_page('Not Found', '<div class="text-4xl mb-4">:(</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Not Found</h1>
        <p class="text-gray-500 dark:text-gray-400">No active booking found for this link.</p>', 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    send_cancellation_email($booking);
    @unlink($booking_path);

    cancel_page('Cancelled', '<div class="text-4xl mb-4">✓</div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Booking Cancelled</h1>
        <p class="text-gray-500 dark:text-gray-400">Your booking has been cancelled and ' . htmlspecialchars(OWNER_NAME) . ' has been notified.</p>');
}

$when = !empty($booking['slot_start']) ? format_date(new DateTime($booking['slot_start'])) : '';
$summary = $booking['slot_summary'] ?? 'Meeting';

cancel_page('Cancel Booking', '
    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Cancel Booking?</h1>
    <div class="text-left bg-gray-50 dark:bg-gray-700 rounded-xl p-4 my-4 space-y-1">
        <p class="text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">What:</span> ' . htmlspecialchars($summary) . '</p>
        ' . ($when ? '<p class="text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">When:</span> ' . htmlspecialchars($when) . '</p>' : '') . '
        <p class="text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">Guest:</span> ' . htmlspecialchars($booking['name']) . '</p>
    </div>
    <form method="POST">
        <input type="hidden" name="uid" value="' . htmlspecialchars($uid) . '">
        <input type="hidden" name="sig" value="' . htmlspecialchars($sig) . '">
        <button type="submit" class="w-full py-3 px-4 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors">
            Yes, Cancel This Booking
        </button>
    </form>
    <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">' . htmlspecialchars(OWNER_NAME) . ' will be notified.</p>');
