<?php
// API: Download a training certificate (gatekeeper — §19).
// auth + canView('trainings') + participant's-employee scope + path containment.
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) { http_response_code(401); exit('Unauthorized'); }
if (!canView('trainings')) { http_response_code(403); exit('Access denied'); }

$participant_id = intval($_GET['participant_id'] ?? 0);
if (!$participant_id) { http_response_code(400); exit('Invalid participant ID'); }

$stmt = $pdo->prepare("SELECT p.certificate_path, p.certificate_name, p.employee_id
                       FROM training_participants p WHERE p.participant_id = ?");
$stmt->execute([$participant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['certificate_path'])) { http_response_code(404); exit('Certificate not found'); }

if (function_exists('assertScopeForEmployee')) assertScopeForEmployee((int)$row['employee_id']);

$base = realpath(__DIR__ . '/../uploads/training_certs');
$file = realpath(__DIR__ . '/../' . $row['certificate_path']);
if ($base === false || $file === false || strpos($file, $base) !== 0 || !is_file($file)) {
    http_response_code(404); exit('Certificate file missing');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file) ?: 'application/octet-stream';
$name = $row['certificate_name'] ?: basename($file);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
