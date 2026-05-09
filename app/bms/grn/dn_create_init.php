<?php
// File: app/bms/grn/dn_create.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('dn');
includeHeader();

global $pdo;

$project_id  = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$edit_id     = isset($_GET['edit'])       ? intval($_GET['edit'])       : 0;
$po_id       = isset($_GET['po_id'])      ? intval($_GET['po_id'])      : 0;
$is_edit     = $edit_id > 0;
$is_from_po  = $po_id > 0;

// ── 1. LOAD PRIMARY DATA (IF EDIT) ───────────────────────────
$dn = null;
$dn_items = [];
if ($is_edit) {
    // Load DN first to get its project context
    $stmt = $pdo->prepare("SELECT d.*, s.supplier_name, w.warehouse_name FROM deliveries d LEFT JOIN suppliers s ON d.supplier_id = s.supplier_id LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id WHERE d.delivery_id = ?");
    $stmt->execute([$edit_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dn) {
        // OVERRIDE project_id from the record
        $project_id = intval($dn['project_id'] ?? 0);
        
        // Load existing items
        $stmt2 = $pdo->prepare("SELECT di.*, p.product_name, p.sku, p.unit FROM delivery_items di LEFT JOIN products p ON di.product_id = p.product_id WHERE di.delivery_id = ? ORDER BY di.delivery_item_id");
        $stmt2->execute([$edit_id]);
        $dn_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── 2. LOAD PO DATA (IF FROM PO) ─────────────────────────────
$po_data = null;
$po_items = [];
if ($is_from_po) {
    $stmt = $pdo->prepare("SELECT po.*, s.supplier_name, w.warehouse_name 
                           FROM purchase_orders po 
                           LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
                           LEFT JOIN warehouses w ON po.warehouse_id = w.warehouse_id 
                           WHERE po.purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($po_data) {
        if ($po_data['project_id'] > 0) $project_id = $po_data['project_id'];
        
        // Load PO items with remaining quantity calculation
        $stmt2 = $pdo->prepare("
            SELECT 
                poi.*, 
                p.product_name, 
                p.sku, 
                p.unit,
                (poi.quantity - COALESCE((
                    SELECT SUM(di.quantity_delivered) 
                    FROM delivery_items di 
                    JOIN deliveries d ON di.delivery_id = d.delivery_id 
                    WHERE d.purchase_order_id = poi.purchase_order_id 
                    AND di.product_id = poi.product_id
                    AND d.status != 'cancelled'
                ), 0)) as quantity_remaining
            FROM purchase_order_items poi 
            LEFT JOIN products p ON poi.product_id = p.product_id 
            WHERE poi.purchase_order_id = ?
        ");
        $stmt2->execute([$po_id]);
        $po_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

$has_project = $project_id > 0;

// ── 3. LOAD SYSTEM LISTS ─────────────────────────────────────
// Get project info
$project = null;
if ($has_project) {
    $stmt = $pdo->prepare("SELECT project_id, project_name, contract_number as contract_no FROM projects WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all projects
$all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Get ALL active warehouses
$all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id, 0) as project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Filter warehouses for the initial dropdown view
$warehouses = [];
foreach ($all_warehouses as $wh) {
    if ($has_project) {
        if ($wh['project_id'] == $project_id) $warehouses[] = $wh;
    } else {
        if ($wh['project_id'] == 0) $warehouses[] = $wh;
    }
}

// Get eligible POs & Suppliers
$po_list = $pdo->query("
    SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id, 0) as warehouse_id, IFNULL(po.project_id, 0) as project_id, s.supplier_name 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.supplier_id
    WHERE po.status IN ('approved', 'ordered', 'partially_received', 'received', 'completed')
    ORDER BY po.order_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$po_suppliers = $pdo->query("
    SELECT DISTINCT s.supplier_id, s.supplier_name, s.company_name 
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    WHERE po.status IN ('approved', 'ordered', 'partially_received', 'received', 'completed')
    AND s.status = 'active'
    ORDER BY s.supplier_name
")->fetchAll(PDO::FETCH_ASSOC);

$project_suppliers = $po_suppliers;

$return_url = $has_project
    ? getUrl('project_view') . '?id=' . $project_id . '&tab=procurement'
    : getUrl('delivery_notes');
?>
