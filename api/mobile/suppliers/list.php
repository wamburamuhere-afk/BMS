<?php
// scope-audit: skip — supplier list for Simple POS; no project scope on suppliers
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search = trim($_GET['search'] ?? '');
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $status = $_GET['status'] ?? '';

    // Full WHERE includes contact_person in search; safe WHERE omits it for older schemas.
    $whereFull = ["s.status != 'deleted'"];
    $whereSafe = ["s.status != 'deleted'"];
    $paramsFull = []; $paramsSafe = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereFull[] = "(s.supplier_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.contact_person LIKE ?)";
        $paramsFull  = array_merge($paramsFull, [$like, $like, $like, $like]);
        $whereSafe[] = "(s.supplier_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
        $paramsSafe  = array_merge($paramsSafe, [$like, $like, $like]);
    }
    if (in_array($status, ['active','inactive'], true)) {
        $whereFull[] = "s.status = ?"; $paramsFull[] = $status;
        $whereSafe[] = "s.status = ?"; $paramsSafe[] = $status;
    }

    $whereSqlFull = 'WHERE ' . implode(' AND ', $whereFull);
    $whereSqlSafe = 'WHERE ' . implode(' AND ', $whereSafe);
    $lim = ' ORDER BY s.supplier_name ASC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    // Try full query (all columns); fall back to core columns if schema is older.
    try {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers s $whereSqlFull");
        $cntStmt->execute($paramsFull);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.supplier_id, s.supplier_code, s.supplier_name, s.contact_person,
                   s.phone, s.email, s.address, s.city, s.supplier_type,
                   s.status, s.notes, s.created_at, s.updated_at
              FROM suppliers s $whereSqlFull $lim");
        $stmt->execute($paramsFull);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $fullE) {
        error_log('mobile/suppliers/list.php full query failed (schema mismatch — run tenant migration 2026_09_30): ' . $fullE->getMessage());
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers s $whereSqlSafe");
        $cntStmt->execute($paramsSafe);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.supplier_id, s.supplier_code, s.supplier_name,
                   '' AS contact_person, s.phone, s.email, s.address,
                   '' AS city, '' AS supplier_type,
                   s.status, '' AS notes, s.created_at, NULL AS updated_at
              FROM suppliers s $whereSqlSafe $lim");
        $stmt->execute($paramsSafe);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'data'    => $rows,
    ]);
} catch (Throwable $e) {
    error_log('mobile/suppliers/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
