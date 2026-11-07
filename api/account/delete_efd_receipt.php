<?php
/**
 * Remove an EFD / VFD receipt from an invoice (soft delete — the file stays for the audit
 * trail, the customer stops seeing it). POST: _csrf, receipt_id. Permission: canDelete('invoices').
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/customer_documents.php';

header('Content-Type: application/json');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => t('Unauthorized')], 401);
if (!canDelete('invoices')) $out(['success' => false, 'message' => t('Permission denied')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => t('Method not allowed')], 405);
csrf_check();

global $pdo;
$id = (int)($_POST['receipt_id'] ?? 0);
if (!cdEfdReady($pdo)) $out(['success' => false, 'message' => t('Receipt not found.')], 404);
$st = $pdo->prepare("SELECT r.receipt_id, r.invoice_id, r.receipt_number, i.invoice_number FROM invoice_efd_receipts r
                       JOIN invoices i ON i.invoice_id = r.invoice_id WHERE r.receipt_id = ? AND r.status = 'active'");
$st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) $out(['success' => false, 'message' => t('Receipt not found.')], 404);
assertScopeForRecord('invoices', 'invoice_id', (int)$r['invoice_id']);

$pdo->prepare("UPDATE invoice_efd_receipts SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE receipt_id = ?")
    ->execute([(int)$_SESSION['user_id'], $id]);
logActivity($pdo, (int)$_SESSION['user_id'], 'EFD receipt removed', "EFD receipt " . ($r['receipt_number'] ?: '#' . $id) . " removed from invoice {$r['invoice_number']}");
logAudit($pdo, (int)$_SESSION['user_id'], 'efd_receipt_delete', ['entity_type' => 'invoice', 'entity_id' => (int)$r['invoice_id'],
    'old_values' => ['receipt_id' => $id, 'status' => 'active'], 'new_values' => ['status' => 'deleted']]);
$out(['success' => true, 'message' => t('Receipt removed.')]);
