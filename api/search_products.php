<?php
// File: api/search_products.php
// scope-audit: skip — products are company-wide catalogue master data; the picker
// applies the standard project-scope nullable filter for non-admins below.
// Shared Select2 AJAX source for picking a REAL product on a line item
// (Credit/Debit notes "Add Line"). Returns {id, text, price, tax_rate} so the
// caller can fill a real product row — never a fabricated/free-text item.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $sql = "SELECT product_id, product_name, sku, COALESCE(selling_price, 0) AS selling_price,
                   COALESCE(tax_rate, 0) AS tax_rate
              FROM products p
             WHERE status = 'active'";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (product_name LIKE ? OR sku LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    // Standard project-scope (global products with project_id IS NULL always pass).
    $sql .= scopeFilterSqlNullable('project', 'p');
    $sql .= " ORDER BY product_name ASC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = $r['product_name'];
        if (!empty($r['sku'])) $label .= ' (' . $r['sku'] . ')';
        $results[] = [
            'id'         => (int)$r['product_id'],
            'text'       => $label,
            'name'       => $r['product_name'],
            'price'      => (float)$r['selling_price'],
            'tax_rate'   => ((float)$r['tax_rate'] == 18) ? 18 : 0,
        ];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_products error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
