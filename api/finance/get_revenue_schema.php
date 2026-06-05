<?php
/**
 * api/finance/get_revenue_schema.php
 * Returns the active revenue category tree (parent → children) for the create
 * form picker and the dedicated Revenue Categories management page.
 *   { success, data: [ {id, name, children:[ {id,name,children:[]} ]} ] }
 */
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('revenue') && !canView('revenue_categories')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}

function buildRevenueTree(array $all, ?int $parentId): array {
    $out = [];
    foreach ($all as $c) {
        $p = ($c['parent_id'] === null || $c['parent_id'] === '') ? null : (int)$c['parent_id'];
        if ($p === $parentId) {
            $c['children'] = buildRevenueTree($all, (int)$c['id']);
            $out[] = $c;
        }
    }
    return $out;
}

try {
    $all = $pdo->query("SELECT id, parent_id, name FROM revenue_categories WHERE status = 'active' ORDER BY name ASC")
               ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => buildRevenueTree($all, null)]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
