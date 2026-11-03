<?php
/**
 * app/constant/communication/notification_open.php — open ONE notification:
 * mark it read and go straight to the record it is about (its action_url).
 *   GET notification_open?id=N
 * Only the signed-in user's own notification; only an in-app relative path
 * (rnSafeActionPath) — anything else goes to the Notifications page, so a
 * stored link can never send someone to another site.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_notify.php';

if (!isAuthenticated()) { header('Location: ' . getUrl('login')); exit; }

$id  = (int)($_GET['id'] ?? 0);
$uid = (int)($_SESSION['user_id'] ?? 0);
$go  = getUrl('notification_center');

if ($id > 0 && $uid > 0) {
    try {
        $st = $pdo->prepare("SELECT action_url FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1");
        $st->execute([$id, $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE notification_id = ? AND user_id = ?")->execute([$id, $uid]);
            $path = rnSafeActionPath($row['action_url'] ?? '');
            if ($path !== '') $go = rtrim(getBasePath(), '/') . '/' . $path;
        }
    } catch (Throwable $e) {
        error_log('notification_open: ' . $e->getMessage());
    }
}
header('Location: ' . $go, true, 302);
exit;
