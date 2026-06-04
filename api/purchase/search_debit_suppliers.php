<?php
// File: api/purchase/search_debit_suppliers.php
// scope-audit: skip — suppliers are company-wide master data (not project-scoped);
// document-level scope is enforced on the debit note itself, not the picker.
// Select2 AJAX source for the Debit Note supplier picker (§UI-3).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('debit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active'";
    $params = [];
    if ($q !== '') { $sql .= " AND supplier_name LIKE ?"; $params[] = "%$q%"; }
    $sql .= " ORDER BY supplier_name ASC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = ['id' => (int)$r['supplier_id'], 'text' => $r['supplier_name']];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_debit_suppliers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
