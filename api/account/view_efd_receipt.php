<?php
/**
 * Staff view of one EFD / VFD receipt file (gatekeeper — files are never linked directly).
 * GET: id. Permission: canView('invoices') + the invoice's project scope.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/customer_documents.php';

if (!isAuthenticated() || !canView('invoices')) { http_response_code(403); exit('Access denied'); }
global $pdo;
$id = (int)($_GET['id'] ?? 0);
if (!cdEfdReady($pdo)) { http_response_code(404); exit('Not found'); }
$st = $pdo->prepare("SELECT invoice_id, file_path, file_name FROM invoice_efd_receipts WHERE receipt_id = ? AND status = 'active'");
$st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Not found'); }
assertScopeForRecordHtml('invoices', 'invoice_id', (int)$r['invoice_id']);

$uploads = realpath(ROOT_DIR . '/uploads');
$full = realpath(ROOT_DIR . '/' . ltrim((string)$r['file_path'], '/'));
if (!$full || !$uploads || !str_starts_with($full, $uploads . DIRECTORY_SEPARATOR) || !is_file($full)) { http_response_code(404); exit('Not found'); }
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($full);
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(404); exit('Not found'); }
$name = preg_replace('/[^A-Za-z0-9 _.-]/', '_', (string)$r['file_name']) ?: 'receipt';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($full);
