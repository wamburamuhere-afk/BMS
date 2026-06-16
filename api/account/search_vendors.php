<?php
/**
 * api/account/search_vendors.php
 *
 * Select2 AJAX source for "Supplier / Sub-contractor" pickers (Vendor Statement
 * and similar). Suppliers and sub_contractors are separate tables that each
 * auto-increment their own supplier_id — so the same numeric id can refer to two
 * different real entities. Every result here is tagged with its source table
 * (`type`) so the caller never has to guess which table an id came from.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}
if (!canView('financial_reports')) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $like = '%' . $q . '%';

    $sql = "
        SELECT supplier_id AS id, supplier_name AS name, 'supplier' AS type
          FROM suppliers
         WHERE status = 'active'" . ($q !== '' ? " AND supplier_name LIKE ?" : "") . "
        UNION ALL
        SELECT supplier_id AS id, supplier_name AS name, 'sub_contractor' AS type
          FROM sub_contractors
         WHERE status = 'active'" . ($q !== '' ? " AND supplier_name LIKE ?" : "") . "
        ORDER BY name ASC
        LIMIT 20
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($q !== '' ? [$like, $like] : []);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'id'   => (int)$r['id'],
            'text' => $r['name'] . ($r['type'] === 'sub_contractor' ? ' (Sub-contractor)' : ''),
            'type' => $r['type'],
        ];
    }
    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log('search_vendors error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
