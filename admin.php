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
        
        $message = "Invite " . ($action === 'update_invite' ? "updated" : "created") . ": " . $base_url . '?k=' . $token;
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
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin — meetme</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { max-width: 1000px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 800px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>meetme Admin</h1>
    
    <?php if ($message): ?>
        <div class="message message-success" style="word-break: break-all;"><?= h($message) ?></div>
    <?php endif; ?>

    <div class="grid">
        <section>
            <h2><?= $edit_token ? 'Edit' : 'Create' ?> Invite</h2>
            <form method="POST" action="admin.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="<?= $edit_token ? 'update_invite' : 'create_invite' ?>">
                <?php if ($edit_token): ?>
                    <input type="hidden" name="token" value="<?= h($edit_token) ?>">
                <?php endif; ?>
                
                <div class="field">
                    <label>Guest Name / Label</label>
                    <input type="text" name="label" value="<?= h($edit_data['label'] ?? '') ?>" required>
                </div>
                
                <div class="field">
                    <label>Mode</label>
                    <select name="mode">
                        <option value="single" <?= ($edit_data['mode'] ?? '') === 'single' ? 'selected' : '' ?>>Single Use</option>
                        <option value="reusable" <?= ($edit_data['mode'] ?? '') === 'reusable' ? 'selected' : '' ?>>Reusable</option>
                    </select>
                </div>
                
                <div class="field">
                    <label>Tag (optional, e.g. "anna")</label>
                    <input type="text" name="tag" value="<?= h($edit_data['tag'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>Custom Greeting (optional)</label>
                    <input type="text" name="greeting" value="<?= h($edit_data['greeting'] ?? '') ?>">
                </div>
                
                <div class="field">
                    <label>Horizon (days)</label>
                    <input type="number" name="horizon_days" value="<?= h($edit_data['horizon_days'] ?? '') ?>" placeholder="<?= DEFAULT_HORIZON_DAYS ?>">
                </div>

                <div class="field">
                    <label><input type="checkbox" name="weekends" <?= ($edit_data['weekends'] ?? false) ? 'checked' : '' ?>> Allow Weekends</label>
                </div>

                <div class="field">
                    <label>Cooldown (days, for reusable)</label>
                    <input type="number" name="cooldown_days" value="<?= h($edit_data['cooldown_days'] ?? '') ?>" placeholder="<?= DEFAULT_COOLDOWN_DAYS ?>">
                </div>

                <button type="submit"><?= $edit_token ? 'Update' : 'Generate' ?> Invite</button>
                <?php if ($edit_token): ?>
                    <a href="admin.php" style="display:block; text-align:center; margin-top:10px; font-size:14px; color:#666;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </section>

        <section>
            <h2>Invites</h2>
            <table>
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Status</th>
                        <th>Mode</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invites as $token => $inv): ?>
                        <tr>
                            <td><?= h($inv['label']) ?><br><small><?= h($token) ?></small></td>
                            <td>
                                <?php if ($inv['revoked']): ?>
                                    <span class="badge badge-revoked">Revoked</span>
                                <?php elseif ($inv['consumed_at']): ?>
                                    <span class="badge badge-consumed">Consumed</span>
                                <?php else: ?>
                                    <span class="badge badge-active">Active</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($inv['mode']) ?></td>
                            <?php
                                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                                $host = $_SERVER['HTTP_HOST'];
                                $script_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                $full_invite_url = $protocol . "://" . $host . $script_dir . '/?k=' . $token;
                            ?>
                            <td>
                                <a href="?edit=<?= h($token) ?>" class="btn-small" style="text-decoration:none; background:#eee; color:#000; border:1px solid #ccc; padding:3px 8px; border-radius:3px;">Edit</a>
                                <button type="button" onclick="navigator.clipboard.writeText('<?= h($full_invite_url) ?>')" class="btn-small">Copy Link</button>
                                <form method="POST" style="display:inline; border:none; padding:0;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="token" value="<?= h($token) ?>">
                                    <input type="hidden" name="action" value="revoke_invite">
                                    <button type="submit" class="btn-small btn-red">Revoke</button>
                                </form>
                                <form method="POST" style="display:inline; border:none; padding:0;" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="token" value="<?= h($token) ?>">
                                    <input type="hidden" name="action" value="delete_invite">
                                    <button type="submit" class="btn-small btn-red">Del</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section>
        <h2>Bookings</h2>
        <table>
            <thead>
                <tr>
                    <th>Slot</th>
                    <th>Guest</th>
                    <th>Note</th>
                    <th>Invite</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php if (($b['status'] ?? '') === 'booked'): ?>
                        <?php $is_ghost = !in_array($b['uid'], $active_uids); ?>
                        <tr style="<?= $is_ghost ? 'opacity: 0.6; background: #fdfdfd;' : '' ?>">
                            <td>
                                <?= h($b['uid']) ?>
                                <?php if ($is_ghost): ?>
                                    <br><span class="badge badge-revoked" style="font-size:10px;">Ghost / Deleted</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($b['name']) ?><br><small><?= h($b['email']) ?></small></td>
                            <td><?= h($b['note']) ?></td>
                            <td><?= h($b['invite_token']) ?></td>
                            <td>
                                <form method="POST" style="display:inline; border:none; padding:0;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="uid" value="<?= h($b['uid']) ?>">
                                    <input type="hidden" name="action" value="cancel_booking">
                                    <button type="submit" class="btn-small btn-red">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h2>Free Slots (Next 60 Days)</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Summary</th>
                    <th>Tag</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admin_slots as $slot): ?>
                    <tr>
                        <td><?= h(format_date($slot['start'])) ?></td>
                        <td><?= h($slot['summary']) ?></td>
                        <td><?= h($slot['tag'] ?: '-') ?></td>
                        <td>
                            <?php if ($slot['is_blocked']): ?>
                                <form method="POST" style="display:inline; border:none; padding:0;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="uid" value="<?= h($slot['uid']) ?>">
                                    <input type="hidden" name="action" value="unblock_slot">
                                    <button type="submit" class="btn-small">Unblock</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline; border:none; padding:0;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="uid" value="<?= h($slot['uid']) ?>">
                                    <input type="hidden" name="action" value="block_slot">
                                    <button type="submit" class="btn-small btn-red">Block</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
