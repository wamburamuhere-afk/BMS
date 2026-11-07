<?php
/**
 * Attach an EFD / VFD receipt (PDF / JPG / PNG) to an approved invoice on BJP's install.
 * The company linked to the invoice's customer sees it on its Billing page (Receipts tab)
 * and gets an email.
 *
 * POST (multipart): _csrf, invoice_id, file, receipt_number (optional), receipt_date (optional)
 * Permission: canEdit('invoices')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/customer_documents.php';

header('Content-Type: application/json');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => t('Unauthorized')], 401);
if (!canEdit('invoices')) $out(['success' => false, 'message' => t('Permission denied')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => t('Method not allowed')], 405);
csrf_check();
if (!isPlatformVendorContext()) $out(['success' => false, 'message' => t('EFD receipts are shared only from BJP to its customers.')], 404);

global $pdo;
$invoiceId = (int)($_POST['invoice_id'] ?? 0);
$number = mb_substr(trim((string)($_POST['receipt_number'] ?? '')), 0, 100);
$date = trim((string)($_POST['receipt_date'] ?? ''));
if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) $out(['success' => false, 'message' => t('Choose a valid receipt date.')], 422);

assertScopeForRecord('invoices', 'invoice_id', $invoiceId);
$st = $pdo->prepare("SELECT invoice_id, invoice_number, customer_id, status, approved_at FROM invoices WHERE invoice_id = ?");
$st->execute([$invoiceId]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) $out(['success' => false, 'message' => t('Invoice not found.')], 404);
if (!cdFindDocument($pdo, 'invoice', $invoiceId, (int)$inv['customer_id'])) {
    $out(['success' => false, 'message' => t('Approve the invoice first — the customer can only see approved invoices.')], 422);
}
if (!vlTenantsOfCustomer((int)$inv['customer_id'])) {
    $out(['success' => false, 'message' => t('This customer is not linked to a company on the system, so there is nowhere to show the receipt.')], 422);
}

$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $out(['success' => false, 'message' => t('Choose the receipt file.')], 422);
$ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
$allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
if (!isset($allowed[$ext]) || $allowed[$ext] !== $mime) $out(['success' => false, 'message' => t('Attach a PDF, JPG or PNG.')], 422);
if ((int)$f['size'] > 10 * 1024 * 1024) $out(['success' => false, 'message' => t('The file is larger than 10 MB.')], 422);

try {
    cdEfdEnsureSchema($pdo);
    require_once __DIR__ . '/../../core/tenant_bootstrap.php';
    $dir = bmsUploadsDir('finance/efd');
    $name = 'EFD_' . $invoiceId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    assertUploadWithinQuota($pdo, (int)$f['size']);
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . $name)) $out(['success' => false, 'message' => t('The file could not be saved. Please try again.')], 500);
    $display = mb_substr(trim(pathinfo((string)$f['name'], PATHINFO_FILENAME)), 0, 150) ?: 'EFD receipt';
    $pdo->prepare("INSERT INTO invoice_efd_receipts (invoice_id, receipt_number, receipt_date, file_name, file_path, file_type, file_size, uploaded_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$invoiceId, $number !== '' ? $number : null, $date !== '' ? $date : null, $display . '.' . $ext,
                   bmsUploadsRel('finance/efd') . $name, $mime, (int)$f['size'], (int)$_SESSION['user_id']]);
    $rid = (int)$pdo->lastInsertId();
    logActivity($pdo, (int)$_SESSION['user_id'], 'EFD receipt added', "EFD receipt " . ($number ?: '#' . $rid) . " attached to invoice {$inv['invoice_number']}");
    logAudit($pdo, (int)$_SESSION['user_id'], 'efd_receipt_upload', ['entity_type' => 'invoice', 'entity_id' => $invoiceId,
        'new_values' => ['receipt_id' => $rid, 'receipt_number' => $number, 'receipt_date' => $date]]);
    cdNotifyIfLinked($pdo, 'receipt', $invoiceId);
    $out(['success' => true, 'message' => t('Receipt added. The customer can see it on their Billing page and has been emailed.')]);
} catch (Throwable $e) {
    error_log('upload_efd_receipt: ' . $e->getMessage());
    $out(['success' => false, 'message' => t('The receipt could not be saved. Please try again.')], 500);
}
