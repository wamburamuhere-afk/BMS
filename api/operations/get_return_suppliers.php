<?php
// scope-audit: skip — return supplier lookup helper; warehouse + project scope gated below
// File: api/operations/get_return_suppliers.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$warehouse_id = intval($_GET['warehouse_id'] ?? 0);
$project_id = intval($_GET['project_id'] ?? 0);

if (!$warehouse_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// §23 rule 2 — guard the chosen ids. The UI pre-scopes both, but nothing
// stopped a hand-crafted request reading another project's supplier list.
// Mirrors the check already in get_return_grns.php.
if (!userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your scope']);
    exit();
}
if (!userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope']);
    exit();
}

try {
    // Suppliers that actually delivered into this warehouse for this project.
    //
    // The project must come from the GRN (`purchase_receipts.project_id`) — the
    // receipt is what a return is raised against. Filtering on `suppliers.project_id`
    // instead returned nothing: that legacy column holds only a supplier's single
    // "primary" project, while the real supplier↔project links live in the
    // `supplier_projects` junction, so suppliers linked to this project (or left
    // untagged, or tagged to a different primary) were all silently excluded even
    // though they had approved GRNs in the warehouse.
    //
    // NULL project on a receipt = untagged/company-wide; included per §23 rule 3
    // (same leniency as scopeFilterSqlNullable), otherwise legacy GRNs recorded
    // before project tagging could never be returned from the project screen.
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.supplier_id, s.supplier_name
        FROM purchase_receipts pr
        JOIN suppliers s ON s.supplier_id = pr.supplier_id
        WHERE pr.warehouse_id = ?
        AND (pr.project_id = ? OR pr.project_id IS NULL)
        AND pr.status != 'cancelled'
        AND s.status != 'deleted'
        ORDER BY s.supplier_name ASC
    ");
    $stmt->execute([$warehouse_id, $project_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $suppliers]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
