<?php
require_once __DIR__ . '/lib.php';

session_start();

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: /admin');
    exit;
}

// Login process with brute-force protection
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    $lockfile = __DIR__ . '/cache/login_attempts.json';
    $ip = sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $attempts = file_exists($lockfile) ? json_decode(file_get_contents($lockfile), true) : [];
    $now = time();

    // Prune entries older than 15 minutes
    foreach ($attempts as $k => $v) {
        if ($now - ($v['last'] ?? 0) > 900) unset($attempts[$k]);
    }

    $my = $attempts[$ip] ?? ['count' => 0, 'last' => 0];

    if ($my['count'] >= 5 && $now - $my['last'] < 900) {
        $login_error = 'Too many attempts. Try again in 15 minutes.';
    } elseif (defined('ADMIN_PASS') && hash_equals(ADMIN_PASS, $_POST['login_password'])) {
        unset($attempts[$ip]);
        file_put_contents($lockfile, json_encode($attempts));
        $_SESSION['admin_logged_in'] = true;
        header('Location: /admin');
        exit;
    } else {
        $my['count']++;
        $my['last'] = $now;
        $attempts[$ip] = $my;
        file_put_contents($lockfile, json_encode($attempts));
        $login_error = 'Invalid password.';
    }
}

if (empty($_SESSION['admin_logged_in'])) {
    // Show Tailwind login form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Login - meetme</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="meetme">
    <meta name="theme-color" content="#111827">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon.svg">
    <link href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>" rel="stylesheet">
    <script>let t=localStorage.getItem('theme');(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))&&document.documentElement.classList.add('dark')</script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center p-4 font-sans antialiased selection:bg-indigo-100 selection:text-indigo-900">
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js');</script>
    <div class="bg-white dark:bg-gray-800 p-8 md:p-10 rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-gray-100 dark:border-gray-700 max-w-sm w-full">
        <div class="flex justify-center mb-6">
            <div class="h-14 w-14 bg-indigo-50 dark:bg-indigo-900/30 rounded-2xl flex items-center justify-center border border-indigo-100 dark:border-indigo-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
        </div>
        <h1 class="text-2xl font-bold mb-2 text-center text-gray-900 dark:text-gray-100">Welcome Back</h1>
        <p class="text-center text-sm text-gray-500 dark:text-gray-400 dark:text-gray-400 mb-8">Enter your password to access the dashboard</p>

        <?php if ($login_error): ?>
            <div class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 p-3 rounded-xl mb-6 text-sm font-medium text-center border border-red-100 dark:border-red-800"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="?">
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password</label>
                <div class="relative">
                    <input type="password" name="login_password" class="w-full pl-4 pr-10 py-3 bg-gray-50/50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 focus:bg-white dark:focus:bg-gray-700 transition-all duration-200 outline-none dark:text-gray-100" placeholder="••••••••" required autofocus>
                </div>
            </div>
            <button type="submit" class="w-full bg-gray-900 dark:bg-indigo-600 text-white font-medium py-3 px-4 rounded-xl hover:bg-black dark:hover:bg-indigo-700 transition-all duration-200 shadow-sm hover:shadow-md transform active:scale-[0.98]">
                Log In to Admin
            </button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function check_csrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }
}

$message = '';
$invite_url = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_invite' || $action === 'update_invite') {
        $token = ($action === 'update_invite') ? $_POST['token'] : rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $path = __DIR__ . '/invites/' . $token . '.json';
        
        $old_data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        
        $data = [
            'label' => trim($_POST['label']),
            'mode' => $_POST['mode'],
            'horizon_days' => (int)($_POST['horizon_days'] ?: DEFAULT_HORIZON_DAYS),
            'weekends' => isset($_POST['weekends']),
            'tag' => trim($_POST['tag'] ?: ''),
            'greeting' => trim($_POST['greeting'] ?: ''),
            'cooldown_days' => (int)($_POST['cooldown_days'] ?: DEFAULT_COOLDOWN_DAYS),
            'created_at' => $old_data['created_at'] ?? date('c'),
            'revoked' => $old_data['revoked'] ?? false,
            'consumed_at' => $old_data['consumed_at'] ?? null
        ];
        atomic_write($path, $data);
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $script_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $base_url = $protocol . "://" . $host . $script_dir . '/';
        $invite_url = $base_url . '?k=' . $token;
        
        $message = "Invite " . ($action === 'update_invite' ? "updated" : "created") . " successfully.";
    }
    
    if ($action === 'revoke_invite') {
        $token = $_POST['token'];
        $path = __DIR__ . '/invites/' . $token . '.json';
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $data['revoked'] = true;
            atomic_write($path, $data);
        }
    }

    if ($action === 'delete_invite') {
        $token = $_POST['token'];
        @unlink(__DIR__ . '/invites/' . $token . '.json');
    }
    
    if ($action === 'cancel_booking') {
        $uid = $_POST['uid'];
        $booking_path = __DIR__ . '/bookings/' . sha1($uid) . '.json';
        if (file_exists($booking_path)) {
            $booking = json_decode(file_get_contents($booking_path), true);
            if (($booking['status'] ?? '') === 'booked') {
                send_cancellation_email($booking);
            }
            @unlink($booking_path);
        }
    }

    if ($action === 'block_slot') {
        $uid = $_POST['uid'];
        $data = ['status' => 'blocked', 'uid' => $uid, 'at' => date('c')];
        atomic_write(__DIR__ . '/bookings/' . sha1($uid) . '.json', $data);
    }
    
    if ($action === 'unblock_slot') {
        $uid = $_POST['uid'];
        @unlink(__DIR__ . '/bookings/' . sha1($uid) . '.json');
    }
}

// Data loading
$invites = [];
$invite_files = glob(__DIR__ . '/invites/*.json');
foreach ($invite_files as $f) {
    $invites[basename($f, '.json')] = json_decode(file_get_contents($f), true);
}

$show_all_invites = isset($_GET['show_all']);
$filtered_invites = [];
$now = time();
$hidden_count = 0;

foreach ($invites as $token => $inv) {
    $is_consumed = !empty($inv['consumed_at']);
    $is_revoked = !empty($inv['revoked']);
    $hidden = false;
    
    if (!$show_all_invites && ($is_consumed || $is_revoked)) {
        // If it's consumed/revoked more than 7 days ago, hide it
        $ref_time = $is_consumed ? strtotime($inv['consumed_at']) : 0;
        // If revoked but never consumed, maybe hide after 7 days of creation or just hide?
        // Let's use consumed_at if available, else created_at.
        $ref_time = $ref_time ?: strtotime($inv['created_at'] ?? 'now');
        if ($now - $ref_time > 7 * 86400) {
            $hidden = true;
            $hidden_count++;
        }
    }
    
    if (!$hidden) {
        $filtered_invites[$token] = $inv;
    }
}

// Sort invites: active first, then consumed/revoked.
uasort($filtered_invites, function($a, $b) {
    $a_inactive = (!empty($a['revoked']) || !empty($a['consumed_at'])) ? 1 : 0;
    $b_inactive = (!empty($b['revoked']) || !empty($b['consumed_at'])) ? 1 : 0;
    
    if ($a_inactive !== $b_inactive) {
        return $a_inactive <=> $b_inactive;
    }
    
    $a_time = strtotime($a['created_at'] ?? '1970-01-01');
    $b_time = strtotime($b['created_at'] ?? '1970-01-01');
    return $b_time <=> $a_time;
});

$invites = $filtered_invites;

$bookings = [];
$booking_files = glob(__DIR__ . '/bookings/*.json');
foreach ($booking_files as $f) {
    $bookings[] = json_decode(file_get_contents($f), true);
}

$admin_slots = get_all_slots_admin();
$active_uids = array_column($admin_slots, 'uid');

// Pre-fill for edit
$edit_token = $_GET['edit'] ?? '';
$edit_data = [];
if ($edit_token && isset($invites[$edit_token])) {
    $edit_data = $invites[$edit_token];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin — meetme</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="meetme">
    <meta name="theme-color" content="#111827">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon.svg">
    <link href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>" rel="stylesheet">
    <script>let t=localStorage.getItem('theme');(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))&&document.documentElement.classList.add('dark')</script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js');</script>
    <div class="max-w-6xl mx-auto p-4 md:p-6 space-y-8">
        <header class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-4">
            <h1 class="text-2xl font-bold">meetme Admin</h1>
            <div class="flex items-center gap-4">
                <?php $feed_token = hash('sha256', ADMIN_PASS . ':meetme-feed'); ?>
                <button type="button" onclick="copyFeedUrl(this)" data-url="<?= h(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/feed?t=' . $feed_token) ?>" class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-gray-200 transition-colors" title="Copy calendar feed URL (ICS)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Cal Feed
                </button>
                <button type="button" id="theme-toggle" onclick="toggleTheme()" class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-gray-200 transition-colors" title="Toggle dark mode">
                    <svg id="theme-sun" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg id="theme-moon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
                <a href="?logout=1" class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-gray-200 transition-colors">Log Out</a>
            </div>
        </header>
        <script>
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcons();
        }
        function updateThemeIcons() {
            const isDark = document.documentElement.classList.contains('dark');
            document.getElementById('theme-sun').classList.toggle('hidden', !isDark);
            document.getElementById('theme-moon').classList.toggle('hidden', isDark);
        }
        updateThemeIcons();
        </script>
        <script>
        function copyFeedUrl(btn) {
            navigator.clipboard.writeText(btn.dataset.url).then(() => {
                const orig = btn.innerHTML;
                btn.textContent = 'Copied!';
                btn.classList.add('text-green-600');
                setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('text-green-600'); }, 2000);
            });
        }
        </script>

        <?php
        $upcoming_bookings = array_filter($bookings, function($b) use ($active_uids) {
            return ($b['status'] ?? '') === 'booked' && in_array($b['uid'], $active_uids);
        });
        $active_invite_count = count(array_filter($invites, function($inv) {
            return empty($inv['revoked']) && empty($inv['consumed_at']);
        }));
        $free_slot_count = count(array_filter($admin_slots, function($s) { return !$s['is_blocked']; }));
        $next_meeting = null;
        foreach ($upcoming_bookings as $b) {
            if (!empty($b['slot_start'])) {
                $s = new DateTime($b['slot_start']);
                if (!$next_meeting || $s < $next_meeting['start']) {
                    $next_meeting = ['start' => $s, 'name' => $b['name'], 'summary' => $b['slot_summary'] ?? ''];
                }
            }
        }
        ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= count($upcoming_bookings) ?></div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Upcoming bookings</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $active_invite_count ?></div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Active invites</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $free_slot_count ?></div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Free slots</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <?php if ($next_meeting): ?>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate"><?= h($next_meeting['summary']) ?> — <?= h($next_meeting['name']) ?></div>
                    <div class="text-sm text-gray-500 dark:text-gray-400"><?= h(format_date($next_meeting['start'])) ?></div>
                <?php else: ?>
                    <div class="text-sm text-gray-400 dark:text-gray-500">No upcoming meetings</div>
                <?php endif; ?>
                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Next meeting</div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-300 rounded-xl p-4 shadow-sm mb-6">
                <p class="font-medium"><?= h($message) ?></p>
                <?php if ($invite_url): ?>
                <div class="mt-3 flex items-center gap-2 max-w-xl">
                    <input type="text" readonly value="<?= h($invite_url) ?>" class="flex-1 bg-white dark:bg-gray-700 border border-green-300 dark:border-green-700 rounded-lg px-3 py-2 text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <button type="button" onclick="copyLink(this, '<?= h($invite_url) ?>')" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm whitespace-nowrap">
                        Copy Link
                    </button>
                    <a href="https://wa.me/?text=<?= rawurlencode($invite_url) ?>" target="_blank" class="px-3 py-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium rounded-lg transition-colors shadow-sm whitespace-nowrap" title="Share via WhatsApp">
                        <svg class="h-5 w-5 inline -mt-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    </a>
                </div>
                <script>
                    function copyLink(btn, url) {
                        navigator.clipboard.writeText(url).then(() => {
                            const originalText = btn.innerText;
                            btn.innerText = 'Copied!';
                            btn.classList.replace('bg-green-600', 'bg-gray-900');
                            btn.classList.replace('hover:bg-green-700', 'hover:bg-gray-800');
                            setTimeout(() => {
                                btn.innerText = originalText;
                                btn.classList.replace('bg-gray-900', 'bg-green-600');
                                btn.classList.replace('hover:bg-gray-800', 'hover:bg-green-700');
                            }, 2000);
                        });
                    }
                </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Form Section -->
            <section class="lg:col-span-1">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 md:p-6 sticky top-6">
                    <h2 class="text-lg font-semibold mb-4 text-gray-800 dark:text-gray-200"><?= $edit_token ? 'Edit' : 'Create' ?> Invite</h2>
                    <form method="POST" action="?" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="<?= $edit_token ? 'update_invite' : 'create_invite' ?>">
                        <?php if ($edit_token): ?>
                            <input type="hidden" name="token" value="<?= h($edit_token) ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Guest Name / Label</label>
                            <input type="text" name="label" value="<?= h($edit_data['label'] ?? '') ?>" required class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mode</label>
                            <select name="mode" class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100 bg-white">
                                <option value="single" <?= ($edit_data['mode'] ?? '') === 'single' ? 'selected' : '' ?>>Single Use</option>
                                <option value="reusable" <?= ($edit_data['mode'] ?? '') === 'reusable' ? 'selected' : '' ?>>Reusable</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tag (optional, e.g. "anna")</label>
                            <input type="text" name="tag" value="<?= h($edit_data['tag'] ?? '') ?>" class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Custom Greeting (optional)</label>
                            <input type="text" name="greeting" value="<?= h($edit_data['greeting'] ?? '') ?>" class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Horizon (days)</label>
                                <input type="number" name="horizon_days" value="<?= h($edit_data['horizon_days'] ?? '') ?>" placeholder="<?= DEFAULT_HORIZON_DAYS ?>" class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cooldown (days)</label>
                                <input type="number" name="cooldown_days" value="<?= h($edit_data['cooldown_days'] ?? '') ?>" placeholder="<?= DEFAULT_COOLDOWN_DAYS ?>" class="w-full border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white dark:bg-gray-700 dark:text-gray-100">
                            </div>
                        </div>

                        <div class="flex items-center pt-2">
                            <input type="checkbox" id="weekends" name="weekends" <?= ($edit_data['weekends'] ?? false) ? 'checked' : '' ?> class="h-4 w-4 text-gray-900 focus:ring-gray-900 border-gray-300 rounded">
                            <label for="weekends" class="ml-2 block text-sm text-gray-700">Allow Weekends</label>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-gray-900 dark:bg-indigo-600 hover:bg-gray-800 dark:hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900 transition-colors">
                                <?= $edit_token ? 'Update' : 'Generate' ?> Invite
                            </button>
                            <?php if ($edit_token): ?>
                                <a href="?" class="mt-3 block text-center text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 transition-colors">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Data Tables Section -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Invites -->
                <section class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Invites</h2>
                        <?php if ($hidden_count > 0 && !$show_all_invites): ?>
                            <a href="?show_all=1" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Show <?= $hidden_count ?> hidden</a>
                        <?php elseif ($show_all_invites): ?>
                            <a href="?" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Hide older</a>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Guest</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Mode</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($invites as $token => $inv): ?>
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= h($inv['label']) ?></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5"><?= h($token) ?></div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if ($inv['revoked']): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Revoked</span>
                                            <?php elseif ($inv['consumed_at']): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Consumed</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 capitalize">
                                            <?= h($inv['mode']) ?>
                                        </td>
                                        <?php
                                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                                            $host = $_SERVER['HTTP_HOST'];
                                            $script_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                            $full_invite_url = $protocol . "://" . $host . $script_dir . '/?k=' . $token;
                                        ?>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                            <button type="button" onclick="copyLinkSmall(this, '<?= h($full_invite_url) ?>')" class="text-gray-600 hover:text-gray-900 transition-colors">Copy</button>
                                            <a href="?edit=<?= h($token) ?>" class="text-indigo-600 hover:text-indigo-900 transition-colors">Edit</a>
                                            <form method="POST" class="inline" onsubmit="return confirm('Revoke this invite?')">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="token" value="<?= h($token) ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <button type="submit" class="text-orange-600 hover:text-orange-900 transition-colors">Revoke</button>
                                            </form>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this invite completely?')">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="token" value="<?= h($token) ?>">
                                                <input type="hidden" name="action" value="delete_invite">
                                                <button type="submit" class="text-red-600 hover:text-red-900 transition-colors">Del</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <script>
                            function copyLinkSmall(btn, url) {
                                navigator.clipboard.writeText(url).then(() => {
                                    const originalText = btn.innerText;
                                    btn.innerText = 'Copied!';
                                    btn.classList.add('text-green-600');
                                    setTimeout(() => {
                                        btn.innerText = originalText;
                                        btn.classList.remove('text-green-600');
                                    }, 1500);
                                });
                            }
                        </script>
                    </div>
                </section>

                <!-- Bookings -->
                <section class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Bookings</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Slot / Tag</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Guest</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Note</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invite</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($bookings as $b): ?>
                                    <?php if (($b['status'] ?? '') === 'booked'): ?>
                                        <?php $is_ghost = !in_array($b['uid'], $active_uids); ?>
                                        <tr class="hover:bg-gray-50/50 transition-colors dark:hover:bg-gray-700/50 <?= $is_ghost ? 'opacity-50 bg-gray-50 dark:bg-gray-800' : '' ?>">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if (!empty($b['slot_start'])): ?>
                                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= h(format_date(new DateTime($b['slot_start']))) ?></div>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?= h($b['slot_summary'] ?? '') ?></div>
                                                <?php else: ?>
                                                    <div class="text-sm font-mono text-gray-600 dark:text-gray-400 truncate max-w-[150px]" title="<?= h($b['uid']) ?>"><?= h(substr($b['uid'], 0, 16)) ?>...</div>
                                                <?php endif; ?>
                                                <?php if ($is_ghost): ?>
                                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300">Past</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= h($b['name']) ?></div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= h($b['email']) ?></div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate" title="<?= h($b['note']) ?>"><?= h($b['note'] ?: '-') ?></div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                                <?= h($b['invite_token']) ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium">
                                                <form method="POST" class="inline" onsubmit="return confirm('Cancel this booking?')">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="uid" value="<?= h($b['uid']) ?>">
                                                    <input type="hidden" name="action" value="cancel_booking">
                                                    <button type="submit" class="text-red-600 hover:text-red-900 transition-colors">Cancel</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (empty($bookings)): ?>
                                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No bookings yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Free Slots -->
                <section class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Free Slots (Next 60 Days)</h2>
                    </div>
                    <div class="overflow-x-auto max-h-[600px]">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Summary</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tag</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($admin_slots as $slot): ?>
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition-colors <?= $slot['is_blocked'] ? 'opacity-60 bg-gray-50 dark:bg-gray-800' : '' ?>">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            <?= h(format_date($slot['start'])) ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            <?= h($slot['summary']) ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if ($slot['tag']): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">#<?= h($slot['tag']) ?></span>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium">
                                            <?php if ($slot['is_blocked']): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="uid" value="<?= h($slot['uid']) ?>">
                                                    <input type="hidden" name="action" value="unblock_slot">
                                                    <button type="submit" class="text-indigo-600 hover:text-indigo-900 transition-colors">Unblock</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="uid" value="<?= h($slot['uid']) ?>">
                                                    <input type="hidden" name="action" value="block_slot">
                                                    <button type="submit" class="text-gray-500 hover:text-red-600 transition-colors">Block</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($admin_slots)): ?>
                                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No slots found in iCloud feed.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>
