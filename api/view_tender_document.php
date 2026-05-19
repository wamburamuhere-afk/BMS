<?php
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!canView('tenders')) {
    http_response_code(403);
    exit('Permission denied');
}

$id  = intval($_GET['id'] ?? 0);
$col = $_GET['col'] ?? '';
$force_download = !empty($_GET['download']);

$allowed_cols = [
    'tender_document',
    'participation_fee_document',
    'opening_document',
    'evaluation_document',
    'post_qualification_document',
    'award_letter_document',
    'submission_document',
    'submission_document_tzs',
    'submission_document_usd',
];

if (!$id || !in_array($col, $allowed_cols, true)) {
    http_response_code(400);
    exit('Invalid request');
}

$stmt = $pdo->prepare("SELECT `$col` FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$rel_path = $stmt->fetchColumn();

if (!$rel_path) {
    http_response_code(404);
    exit('Document not found');
}

// Prevent path traversal
$rel_path = ltrim(str_replace(['..', '\\'], ['', '/'], $rel_path), '/');
$full_path = ROOT_DIR . '/' . $rel_path;

if (!file_exists($full_path) || !is_file($full_path)) {
    http_response_code(404);
    exit('File not found on server');
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));

$mime_map = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$mime = $mime_map[$ext] ?? 'application/octet-stream';
$viewable = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true);
$disposition = (!$force_download && $viewable) ? 'inline' : 'attachment';

$safe_name = basename($full_path);

logActivity($pdo, $_SESSION['user_id'], 'VIEW', "[Tender Document] Downloaded/viewed '$col' for tender_id=$id");

// Discard any output buffered by roots.php so the binary file stream is clean
while (ob_get_level() > 0) ob_end_clean();

header("Content-Type: $mime");
header("Content-Disposition: $disposition; filename=\"$safe_name\"");
header("Content-Length: " . filesize($full_path));
header("Cache-Control: private, max-age=3600");
header("X-Content-Type-Options: nosniff");
readfile($full_path);
exit;
