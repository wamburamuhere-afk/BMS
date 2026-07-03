<?php
// API: Download a business-trip attachment (gatekeeper — §19).
// auth + canView('employee_trips') + the trip's-employee scope + path containment.
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) { http_response_code(401); exit('Unauthorized'); }
if (!canView('employee_trips')) { http_response_code(403); exit('Access denied'); }

$trip_id = intval($_GET['trip_id'] ?? 0);
if (!$trip_id) { http_response_code(400); exit('Invalid trip ID'); }

if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_trips', 'trip_id', $trip_id);

$stmt = $pdo->prepare("SELECT attachment_path, attachment_name FROM employee_trips WHERE trip_id = ? AND status != 'deleted'");
$stmt->execute([$trip_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['attachment_path'])) { http_response_code(404); exit('Attachment not found'); }

$base = realpath(__DIR__ . '/../uploads/trips');
$file = realpath(__DIR__ . '/../' . $row['attachment_path']);
if ($base === false || $file === false || strpos($file, $base) !== 0 || !is_file($file)) { http_response_code(404); exit('File missing'); }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file) ?: 'application/octet-stream';
$name = $row['attachment_name'] ?: basename($file);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
