<?php
// api/lookups/get_lookups.php
// Select2-AJAX source for the self-growing reference-data dropdowns
// (supplier_type, payment_terms, currency, ...). Returns {results, pagination}.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['results' => [], 'message' => 'Unauthorized']);
    exit;
}

// Whitelist of lookup keys this endpoint will serve.
$allowed = ['supplier_type', 'payment_terms', 'currency'];

$key = trim($_GET['key'] ?? '');
$q   = trim($_GET['q'] ?? ($_GET['term'] ?? ''));

if (!in_array($key, $allowed, true)) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    $sql = "SELECT value, label
              FROM form_lookups
             WHERE lookup_key = ? AND status = 'active'";
    $params = [$key];
    if ($q !== '') {
        $sql .= " AND (label LIKE ? OR value LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $sql .= " ORDER BY sort_order, label LIMIT 30";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = ['id' => $r['value'], 'text' => $r['label']];
    }

    echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
} catch (PDOException $e) {
    error_log('get_lookups error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['results' => [], 'message' => 'Server error']);
}
