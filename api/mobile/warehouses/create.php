<?php
// scope-audit: skip — warehouse creation; admin or canCreate('warehouses') only
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('warehouses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$warehouse_name = trim($body['warehouse_name'] ?? '');
if ($warehouse_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Shop name is required']); exit; }

$warehouse_code = strtoupper(trim($body['warehouse_code'] ?? ''));
$address        = trim($body['address']  ?? '');
$city           = trim($body['city']     ?? '');
$phone          = trim($body['phone']    ?? '');
$email          = trim($body['email']    ?? '');
$contact_person = trim($body['contact_person'] ?? '');
$notes          = trim($body['notes']    ?? '');
$pos_mode       = in_array($body['pos_mode'] ?? 'retail', ['retail','restaurant','hybrid'], true) ? $body['pos_mode'] : 'retail';
$status         = in_array($body['status'] ?? 'active', ['active','inactive'], true) ? $body['status'] : 'active';

try {
    // Auto-generate code if not provided
    if ($warehouse_code === '') {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($warehouse_name, 0, 4)));
        $warehouse_code = $prefix . '-' . rand(100, 999);
    }

    // Unique code guard
    $dup = $pdo->prepare("SELECT 1 FROM warehouses WHERE warehouse_code = ? AND status != 'deleted'");
    $dup->execute([$warehouse_code]);
    if ($dup->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>"Shop code '$warehouse_code' is already in use"]);
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO warehouses
            (warehouse_name, warehouse_code, address, city, phone, email,
             contact_person, pos_mode, status, notes, capacity, is_primary, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW())
    ");
    $stmt->execute([
        $warehouse_name, $warehouse_code,
        $address        !== '' ? $address        : null,
        $city           !== '' ? $city           : null,
        $phone          !== '' ? $phone          : null,
        $email          !== '' ? $email          : null,
        $contact_person !== '' ? $contact_person : null,
        $pos_mode, $status,
        $notes          !== '' ? $notes          : null,
        $user_id,
    ]);
    $warehouse_id = (int)$pdo->lastInsertId();

    // Every warehouse needs at least one location (required by stock ledger)
    $pdo->prepare("
        INSERT INTO locations (warehouse_id, location_name, location_code, location_type, capacity, status, created_by)
        VALUES (?, 'Main Storage Area', 'MAIN', 'storage', 0, 'active', ?)
    ")->execute([$warehouse_id, $user_id]);

    // Grant creator access to their new warehouse (matches web behaviour)
    if (!isAdmin()) {
        if (!isset($_SESSION['scope'])) loadUserScope($user_id);
        $scope = $_SESSION['scope'] ?? [];
        if (empty($scope['is_admin'])) {
            $granted = $scope['warehouses'] ?? [];
            if (!in_array('*', $granted, true) && !in_array($warehouse_id, $granted, true)) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by, created_at) VALUES (?, 'warehouse', ?, ?, NOW())")
                        ->execute([$user_id, $warehouse_id, $user_id]);
                    $_SESSION['scope']['warehouses'][] = $warehouse_id;
                } catch (Throwable $e) {
                    // Non-fatal — creator can still see it after next login
                }
            }
        }
    }

    $pdo->commit();

    logActivity($pdo, $user_id, "Mobile: created shop '$warehouse_name' ($warehouse_code)");

    echo json_encode([
        'success'        => true,
        'warehouse_id'   => $warehouse_id,
        'warehouse_code' => $warehouse_code,
        'warehouse_name' => $warehouse_name,
        'message'        => 'Shop created successfully',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mobile/warehouses/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
