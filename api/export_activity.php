<?php
/**
 * api/export_activity.php
 *
 * Exports the CURRENT user's own recent activity (access_log) as a CSV
 * download — used by the My Profile → Activity tab "Export Activity" button.
 * Users only ever export their own log; no cross-user access.
 */
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $stmt = $pdo->prepare("
        SELECT action, resource, ip_address, timestamp
          FROM access_log
         WHERE user_id = ?
      ORDER BY timestamp DESC
         LIMIT 1000
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'my_activity_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date & Time', 'Action', 'Resource', 'IP Address']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['timestamp'] ?? '',
            $r['action'] ?? '',
            $r['resource'] ?? '',
            $r['ip_address'] ?? '',
        ]);
    }
    fclose($out);

    if (function_exists('logActivity')) {
        logActivity($pdo, $user_id, 'Exported own activity log (CSV)');
    }
    exit;

} catch (Throwable $e) {
    error_log('export_activity error: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not export activity.');
}
