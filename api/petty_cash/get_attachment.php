<?php
ob_start();
require_once __DIR__ . '/../../roots.php';

ini_set('display_errors', 0);
error_reporting(0);

// Block unauthenticated access
if (!isAuthenticated()) {
    http_response_code(403);
    die("Access Denied: Please log in to view this file.");
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    http_response_code(400);
    die("Invalid transaction ID.");
}

// Fetch file path from DB
$stmt = $pdo->prepare("SELECT receipt_file FROM petty_cash_transactions WHERE id = ?");
$stmt->execute([$id]);
$receipt_file = $stmt->fetchColumn();

if (!$receipt_file) {
    http_response_code(404);
    die("No attachment found for this transaction.");
}

// PRE-EXISTING BUG, fixed here: this read `uploads/petty_cash/` while
// save_transaction.php and delete_transaction.php both use
// `uploads/finance/petty_cash/`. Receipts were written to one directory and
// looked for in another, so every attachment 404'd. The three now agree, via
// the same accessor — which is also what keeps them agreeing per tenant.
require_once __DIR__ . '/../../core/tenant_bootstrap.php';
$file_path = bmsUploadsDir('finance/petty_cash') . basename($receipt_file);

if (!file_exists($file_path)) {
    http_response_code(404);
    die("File not found on server.");
}

// Map extension to content type
$ext = strtolower(pathinfo($receipt_file, PATHINFO_EXTENSION));
$content_types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$content_type = $content_types[$ext] ?? 'application/octet-stream';

// ?download=1 forces browser download; default is inline (view in browser)
$disposition = isset($_GET['download']) && $_GET['download'] == '1' ? 'attachment' : 'inline';

ob_end_clean();
header('Content-Type: ' . $content_type);
header('Content-Disposition: ' . $disposition . '; filename="receipt_' . $id . '.' . $ext . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit();
