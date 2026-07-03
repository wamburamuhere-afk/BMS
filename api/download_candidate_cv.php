<?php
// API: Download a candidate CV (gatekeeper — §19; candidate PII).
// auth + canView('recruitment') + path containment.
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) { http_response_code(401); exit('Unauthorized'); }
if (!canView('recruitment')) { http_response_code(403); exit('Access denied'); }

$cand = intval($_GET['candidate_id'] ?? 0);
if (!$cand) { http_response_code(400); exit('Invalid candidate ID'); }

$stmt = $pdo->prepare("SELECT cv_path, cv_name FROM candidates WHERE candidate_id = ? AND status = 'active'");
$stmt->execute([$cand]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['cv_path'])) { http_response_code(404); exit('CV not found'); }

$base = realpath(__DIR__ . '/../uploads/candidate_cvs');
$file = realpath(__DIR__ . '/../' . $row['cv_path']);
if ($base === false || $file === false || strpos($file, $base) !== 0 || !is_file($file)) { http_response_code(404); exit('File missing'); }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file) ?: 'application/octet-stream';
$name = $row['cv_name'] ?: basename($file);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
