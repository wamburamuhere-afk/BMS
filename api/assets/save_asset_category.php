<?php
/**
 * api/assets/save_asset_category.php
 *
 * Create or update an asset category. Admin / asset-create permission only.
 *
 * POST fields:
 *   category_id?              — when set, update; otherwise insert
 *   category_name             — required, unique
 *   tra_class?                — optional TRA reference (e.g. "Class 4")
 *   default_method            — 'straight_line' | 'reducing_balance'
 *   default_useful_life_years — integer ≥ 1
 *   default_annual_rate_percent — 0..100
 *   default_salvage_percent     — 0..100
 *   description?
 *   status?                   — 'active' | 'archived'
 *
 * The unique key on category_name prevents duplicates regardless of caller
 * race conditions.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
$is_update   = $category_id > 0;

if ($is_update ? !canEdit('assets') : !canCreate('assets')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: you do not have permission to ' . ($is_update ? 'edit' : 'create') . ' asset categories',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$category_name = trim($_POST['category_name'] ?? '');
$tra_class     = trim($_POST['tra_class'] ?? '');
$method        = $_POST['default_method'] ?? 'straight_line';
$life_years    = isset($_POST['default_useful_life_years']) && $_POST['default_useful_life_years'] !== ''
    ? (int)$_POST['default_useful_life_years'] : null;
$rb_rate       = isset($_POST['default_annual_rate_percent']) && $_POST['default_annual_rate_percent'] !== ''
    ? (float)$_POST['default_annual_rate_percent'] : null;
$salvage_pct   = isset($_POST['default_salvage_percent']) && $_POST['default_salvage_percent'] !== ''
    ? (float)$_POST['default_salvage_percent'] : 0.0;
$description   = trim($_POST['description'] ?? '');
$status        = $_POST['status'] ?? 'active';

// ── Validation ──────────────────────────────────────────────────────────
$errors = [];
if ($category_name === '') $errors[] = 'category_name is required';
if (!in_array($method, ['straight_line', 'reducing_balance'], true)) {
    $errors[] = "default_method must be 'straight_line' or 'reducing_balance'";
}
if ($life_years !== null && $life_years < 1) $errors[] = 'default_useful_life_years must be >= 1';
if ($rb_rate !== null && ($rb_rate < 0 || $rb_rate > 100)) $errors[] = 'default_annual_rate_percent must be 0..100';
if ($salvage_pct < 0 || $salvage_pct > 100) $errors[] = 'default_salvage_percent must be 0..100';
if (!in_array($status, ['active','archived'], true)) $errors[] = "status must be 'active' or 'archived'";

if ($errors) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('; ', $errors)]);
    exit;
}

try {
    global $pdo;

    if ($is_update) {
        $stmt = $pdo->prepare("
            UPDATE asset_categories
               SET category_name=?, tra_class=?, default_method=?,
                   default_useful_life_years=?, default_annual_rate_percent=?,
                   default_salvage_percent=?, description=?, status=?
             WHERE category_id=?
        ");
        $stmt->execute([
            $category_name, $tra_class, $method,
            $life_years, $rb_rate, $salvage_pct,
            $description, $status, $category_id,
        ]);
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Updated Asset Category', "id={$category_id}, name={$category_name}");
        echo json_encode(['success' => true, 'message' => 'Asset category updated.', 'category_id' => $category_id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO asset_categories
                (category_name, tra_class, default_method,
                 default_useful_life_years, default_annual_rate_percent,
                 default_salvage_percent, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $category_name, $tra_class, $method,
            $life_years, $rb_rate, $salvage_pct,
            $description, $status,
        ]);
        $new_id = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Created Asset Category', "id={$new_id}, name={$category_name}");
        echo json_encode(['success' => true, 'message' => 'Asset category created.', 'category_id' => $new_id]);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {  // unique violation
        echo json_encode(['success' => false, 'message' => 'A category with that name already exists.']);
        exit;
    }
    error_log('save_asset_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
