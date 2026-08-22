<?php
/**
 * api/purchase/get_debit_notes.php
 * Debit Notes list feed.
 *
 * The list used to be embedded straight into debit_notes.php as a JSON blob, so
 * there was no way for another page to show the same list. This is that query
 * lifted out unchanged (same columns, same origin label, same project scoping
 * via the linked purchase_order) and served to every host:
 *
 *   app/bms/purchase/debit_notes/debit_notes.php → full list
 *   app/bms/Suppliers/supplier_details.php       → Debit Notes tab (supplier_id)
 *
 * Returns { success, data[], stats{}, suppliers[] }.
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('debit_notes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    global $pdo;

    $supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

    $params = [];
    $query = "
        SELECT
            dn.debit_note_id,
            dn.debit_note_number,
            dn.debit_date,
            dn.grand_total,
            dn.status,
            dn.purchase_return_id,
            dn.purchase_order_id,
            dn.supplier_id,
            s.supplier_name,
            pr.return_number
        FROM debit_notes dn
        LEFT JOIN suppliers s          ON dn.supplier_id        = s.supplier_id
        LEFT JOIN purchase_returns pr  ON dn.purchase_return_id = pr.purchase_return_id
        LEFT JOIN purchase_orders po   ON pr.purchase_order_id  = po.purchase_order_id
        WHERE dn.status != 'deleted'
    ";

    if ($supplier_id > 0) {
        $query .= " AND dn.supplier_id = ?";
        $params[] = $supplier_id;
    }

    // debit_notes has no project_id of its own — scope through the linked PO,
    // exactly as the page did before.
    $query .= scopeFilterSqlNullable('project', 'po');
    $query .= " ORDER BY dn.debit_date DESC, dn.debit_note_id DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($r) {
        if (!empty($r['purchase_return_id'])) {
            $origin = 'Return ' . ($r['return_number'] ?: ('#' . $r['purchase_return_id']));
        } elseif (!empty($r['purchase_order_id'])) {
            $origin = 'PO #' . $r['purchase_order_id'];
        } else {
            $origin = 'Standalone';
        }
        return [
            'id'          => (int) $r['debit_note_id'],
            'number'      => $r['debit_note_number'],
            'date'        => $r['debit_date'] ? date('d M Y', strtotime($r['debit_date'])) : '—',
            'supplier_id' => (int) $r['supplier_id'],
            'supplier'    => $r['supplier_name'] ?: 'Supplier',
            'origin'      => $origin,
            'amount'      => (float) $r['grand_total'],
            'status'      => $r['status'],
        ];
    }, $rows);

    $countBy = function ($status) use ($rows) {
        return count(array_filter($rows, function ($r) use ($status) { return $r['status'] === $status; }));
    };

    // Supplier list for the page's filter dropdown — only suppliers that
    // actually have a debit note in scope.
    $suppliers = [];
    foreach ($data as $d) {
        if ($d['supplier_id'] > 0) $suppliers[$d['supplier_id']] = $d['supplier'];
    }
    asort($suppliers);

    echo json_encode([
        'success' => true,
        'data'    => array_values($data),
        'stats'   => [
            'total'    => count($rows),
            'pending'  => $countBy('pending'),
            'approved' => $countBy('approved'),
            'paid'     => $countBy('paid'),
            'value'    => array_sum(array_map(function ($r) { return (float) $r['grand_total']; }, $rows)),
        ],
        'suppliers' => array_map(
            function ($id, $name) { return ['id' => $id, 'name' => $name]; },
            array_keys($suppliers), array_values($suppliers)
        ),
    ]);

} catch (Exception $e) {
    error_log('get_debit_notes error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
