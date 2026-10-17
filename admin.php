<?php
require_once __DIR__ . '/lib.php';

session_start();

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
    
    if ($action === 'block_slot') {
        $uid = $_POST['uid'];
        $data = ['status' => 'blocked', 'uid' => $uid, 'at' => date('c')];
        atomic_write(__DIR__ . '/bookings/' . sha1($uid) . '.json', $data);
    }
    
    if ($action === 'unblock_slot' || $action === 'cancel_booking') {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — meetme</title>
    <link href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>" rel="stylesheet">
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased min-h-screen">
    <div class="max-w-6xl mx-auto p-4 md:p-6 space-y-8">
        <header class="flex justify-between items-center border-b border-gray-200 pb-4">
            <h1 class="text-2xl font-bold">meetme Admin</h1>
        </header>

        <?php if ($message): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 shadow-sm mb-6">
                <p class="font-medium"><?= h($message) ?></p>
                <?php if ($invite_url): ?>
                <div class="mt-3 flex items-center gap-2 max-w-xl">
                    <input type="text" readonly value="<?= h($invite_url) ?>" class="flex-1 bg-white border border-green-300 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <button type="button" onclick="copyLink(this, '<?= h($invite_url) ?>')" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm whitespace-nowrap">
                        Copy Link
                    </button>
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
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 md:p-6 sticky top-6">
                    <h2 class="text-lg font-semibold mb-4 text-gray-800"><?= $edit_token ? 'Edit' : 'Create' ?> Invite</h2>
                    <form method="POST" action="admin.php" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="<?= $edit_token ? 'update_invite' : 'create_invite' ?>">
                        <?php if ($edit_token): ?>
                            <input type="hidden" name="token" value="<?= h($edit_token) ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Guest Name / Label</label>
                            <input type="text" name="label" value="<?= h($edit_data['label'] ?? '') ?>" required class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
                            <select name="mode" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border bg-white">
                                <option value="single" <?= ($edit_data['mode'] ?? '') === 'single' ? 'selected' : '' ?>>Single Use</option>
                                <option value="reusable" <?= ($edit_data['mode'] ?? '') === 'reusable' ? 'selected' : '' ?>>Reusable</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tag (optional, e.g. "anna")</label>
                            <input type="text" name="tag" value="<?= h($edit_data['tag'] ?? '') ?>" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Custom Greeting (optional)</label>
                            <input type="text" name="greeting" value="<?= h($edit_data['greeting'] ?? '') ?>" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Horizon (days)</label>
                                <input type="number" name="horizon_days" value="<?= h($edit_data['horizon_days'] ?? '') ?>" placeholder="<?= DEFAULT_HORIZON_DAYS ?>" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cooldown (days)</label>
                                <input type="number" name="cooldown_days" value="<?= h($edit_data['cooldown_days'] ?? '') ?>" placeholder="<?= DEFAULT_COOLDOWN_DAYS ?>" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-gray-900 focus:border-gray-900 text-sm py-2 px-3 border">
                            </div>
                        </div>

                        <div class="flex items-center pt-2">
                            <input type="checkbox" id="weekends" name="weekends" <?= ($edit_data['weekends'] ?? false) ? 'checked' : '' ?> class="h-4 w-4 text-gray-900 focus:ring-gray-900 border-gray-300 rounded">
                            <label for="weekends" class="ml-2 block text-sm text-gray-700">Allow Weekends</label>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-gray-900 hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900 transition-colors">
                                <?= $edit_token ? 'Update' : 'Generate' ?> Invite
                            </button>
                            <?php if ($edit_token): ?>
                                <a href="admin.php" class="mt-3 block text-center text-sm text-gray-500 hover:text-gray-700 transition-colors">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Data Tables Section -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Invites -->
                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 bg-gray-50/50 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Invites</h2>
                        <?php if ($hidden_count > 0 && !$show_all_invites): ?>
                            <a href="?show_all=1" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Show <?= $hidden_count ?> hidden</a>
                        <?php elseif ($show_all_invites): ?>
                            <a href="admin.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Hide older</a>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mode</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($invites as $token => $inv): ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?= h($inv['label']) ?></div>
                                            <div class="text-xs text-gray-500 font-mono mt-0.5"><?= h($token) ?></div>
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
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 capitalize">
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
                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 bg-gray-50/50">
                        <h2 class="text-lg font-semibold text-gray-800">Bookings</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slot / Tag</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Note</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invite</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($bookings as $b): ?>
                                    <?php if (($b['status'] ?? '') === 'booked'): ?>
                                        <?php $is_ghost = !in_array($b['uid'], $active_uids); ?>
                                        <tr class="hover:bg-gray-50/50 transition-colors <?= $is_ghost ? 'opacity-50 bg-gray-50' : '' ?>">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-mono text-gray-600 truncate max-w-[150px]" title="<?= h($b['uid']) ?>"><?= h(substr($b['uid'], 0, 16)) ?>...</div>
                                                <?php if ($is_ghost): ?>
                                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-800">Ghost / Deleted</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900"><?= h($b['name']) ?></div>
                                                <div class="text-xs text-gray-500"><?= h($b['email']) ?></div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="text-sm text-gray-500 max-w-xs truncate" title="<?= h($b['note']) ?>"><?= h($b['note'] ?: '-') ?></div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 font-mono">
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
                                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No bookings yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Free Slots -->
                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 bg-gray-50/50">
                        <h2 class="text-lg font-semibold text-gray-800">Free Slots (Next 60 Days)</h2>
                    </div>
                    <div class="overflow-x-auto max-h-[600px]">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Summary</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tag</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($admin_slots as $slot): ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors <?= $slot['is_blocked'] ? 'opacity-60 bg-gray-50' : '' ?>">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                            <?= h(format_date($slot['start'])) ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
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
                                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No slots found in iCloud feed.</td></tr>
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
