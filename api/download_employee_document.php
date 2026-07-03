<?php
// API: Download an employee document (gatekeeper — §19).
// IDs, contracts and permits are sensitive: auth + permission + employee
// scope + path containment are all checked before streaming.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

if (!isAuthenticated()) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!canView('employee_documents')) {
    http_response_code(403);
    exit('Access denied');
}

$emp_doc_id = intval($_GET['emp_doc_id'] ?? $_GET['id'] ?? 0);
if (!$emp_doc_id) {
    http_response_code(400);
    exit('Invalid document ID');
}

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_documents', 'emp_doc_id', $emp_doc_id);
}

$stmt = $pdo->prepare("SELECT file_path, original_filename FROM employee_documents
                       WHERE emp_doc_id = ? AND status = 'active'");
$stmt->execute([$emp_doc_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['file_path'])) {
    http_response_code(404);
    exit('Document not found');
}

$base = realpath(__DIR__ . '/../uploads/employee_docs');
$file = realpath(__DIR__ . '/../' . $row['file_path']);
if ($base === false || $file === false || strpos($file, $base) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Document file missing');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file) ?: 'application/octet-stream';
$name  = $row['original_filename'] ?: basename($file);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
