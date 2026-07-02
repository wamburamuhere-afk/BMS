<?php
// API: Download a Lifecycle Event attachment (gatekeeper — §19)
// HR letters/certificates are sensitive: auth + permission + project scope
// are checked before a single byte is streamed. Files are never linked directly.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!canView('employee_lifecycle')) {
    http_response_code(403);
    exit('Access denied');
}

$event_id = intval($_GET['event_id'] ?? $_GET['id'] ?? 0);
if (!$event_id) {
    http_response_code(400);
    exit('Invalid event ID');
}

// Project-scope gate (JSON body on failure is fine — the link opens in a new tab)
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_lifecycle_events', 'event_id', $event_id);
}

$stmt = $pdo->prepare("SELECT attachment_path, attachment_name FROM employee_lifecycle_events
                       WHERE event_id = ? AND status != 'deleted'");
$stmt->execute([$event_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['attachment_path'])) {
    http_response_code(404);
    exit('Attachment not found');
}

// Containment check — the stored path must resolve inside uploads/lifecycle/
$base = realpath(__DIR__ . '/../uploads/lifecycle');
$file = realpath(__DIR__ . '/../' . $row['attachment_path']);
if ($base === false || $file === false || strpos($file, $base) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Attachment file missing');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file) ?: 'application/octet-stream';
$name  = $row['attachment_name'] ?: basename($file);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
