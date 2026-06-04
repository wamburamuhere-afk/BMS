<?php
// File: api/sales/search_credit_customers.php
// scope-audit: skip — customers are company-wide master data (not project-scoped);
// document-level scope is enforced on the credit note itself, not the picker.
// Select2 AJAX source for the Credit Note customer picker (§UI-3).
// Customers are company-wide master data (not project-scoped), so all active
// customers are searchable; document-level scope is enforced on the note itself.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('credit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $sql = "SELECT customer_id, customer_name, company_name
              FROM customers
             WHERE status = 'active'";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (customer_name LIKE ? OR company_name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $sql .= " ORDER BY customer_name ASC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['customer_name'];
        if (!empty($r['company_name'])) $text .= ' — ' . $r['company_name'];
        $results[] = ['id' => (int)$r['customer_id'], 'text' => $text];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_credit_customers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
