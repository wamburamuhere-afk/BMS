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
 *   code_prefix?              — asset code prefix (e.g. COMP)
 *   is_depreciable?           — '1' | '0' (0 for Land-type)
 *   tax_rate?                 — statutory ITA reducing-balance rate, 0..100
 *   gl_asset_account?         — GL account codes for posting
 *   gl_accum_account?
 *   gl_expense_account?
 *   description?
 *   status?                   — 'active' | 'archived'
 *
 * Validation (document §2.2): a depreciable straight-line category requires a
 * useful life; a depreciable reducing-balance category requires an RB rate;
 * every depreciable category requires a tax_rate. A non-depreciable (Land-type)
 * category saves with no rates.
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
$code_prefix   = strtoupper(trim($_POST['code_prefix'] ?? ''));
$is_depreciable = isset($_POST['is_depreciable']) ? (int)(bool)((int)$_POST['is_depreciable']) : 1;
$tax_rate      = isset($_POST['tax_rate']) && $_POST['tax_rate'] !== ''
    ? (float)$_POST['tax_rate'] : null;
$gl_asset      = trim($_POST['gl_asset_account'] ?? '');
$gl_accum      = trim($_POST['gl_accum_account'] ?? '');
$gl_expense    = trim($_POST['gl_expense_account'] ?? '');
$description   = trim($_POST['description'] ?? '');
$status        = $_POST['status'] ?? 'active';

// Non-depreciable (Land-type) categories carry no rates — null them out.
if (!$is_depreciable) {
    $life_years = null;
    $rb_rate    = null;
    $tax_rate   = null;
}

// ── Validation ──────────────────────────────────────────────────────────
$errors = [];
if ($category_name === '') $errors[] = 'category_name is required';
if (!in_array($method, ['straight_line', 'reducing_balance'], true)) {
    $errors[] = "default_method must be 'straight_line' or 'reducing_balance'";
}
if ($life_years !== null && $life_years < 1) $errors[] = 'default_useful_life_years must be >= 1';
if ($rb_rate !== null && ($rb_rate < 0 || $rb_rate > 100)) $errors[] = 'default_annual_rate_percent must be 0..100';
if ($salvage_pct < 0 || $salvage_pct > 100) $errors[] = 'default_salvage_percent must be 0..100';
if ($tax_rate !== null && ($tax_rate < 0 || $tax_rate > 100)) $errors[] = 'tax_rate must be 0..100';
if (strlen($code_prefix) > 10) $errors[] = 'code_prefix must be 10 characters or fewer';
if (!in_array($status, ['active','archived'], true)) $errors[] = "status must be 'active' or 'archived'";

// §2.2 — depreciable-category rules.
if ($is_depreciable) {
    if ($method === 'straight_line' && ($life_years === null || $life_years < 1)) {
        $errors[] = 'A depreciable straight-line category requires a useful life (years)';
    }
    if ($method === 'reducing_balance' && ($rb_rate === null || $rb_rate <= 0)) {
        $errors[] = 'A depreciable reducing-balance category requires an RB rate (%)';
    }
    if ($tax_rate === null) {
        $errors[] = 'A depreciable category requires a tax rate (%) for the tax depreciation area';
    }
}

if ($errors) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('; ', $errors)]);
    exit;
}

try {
    global $pdo;

    // Blank GL strings persist as NULL for consistency.
    $gl_asset_v   = $gl_asset   !== '' ? $gl_asset   : null;
    $gl_accum_v   = $gl_accum   !== '' ? $gl_accum   : null;
    $gl_expense_v = $gl_expense !== '' ? $gl_expense : null;
    $prefix_v     = $code_prefix !== '' ? $code_prefix : null;

    if ($is_update) {
        $stmt = $pdo->prepare("
            UPDATE asset_categories
               SET category_name=?, tra_class=?, default_method=?,
                   default_useful_life_years=?, default_annual_rate_percent=?,
                   default_salvage_percent=?, code_prefix=?, is_depreciable=?,
                   tax_rate=?, gl_asset_account=?, gl_accum_account=?,
                   gl_expense_account=?, description=?, status=?
             WHERE category_id=?
        ");
        $stmt->execute([
            $category_name, $tra_class, $method,
            $life_years, $rb_rate, $salvage_pct,
            $prefix_v, $is_depreciable, $tax_rate,
            $gl_asset_v, $gl_accum_v, $gl_expense_v,
            $description, $status, $category_id,
        ]);
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Updated Asset Category', "id={$category_id}, name={$category_name}");
        echo json_encode(['success' => true, 'message' => 'Asset category updated.', 'category_id' => $category_id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO asset_categories
                (category_name, tra_class, default_method,
                 default_useful_life_years, default_annual_rate_percent,
                 default_salvage_percent, code_prefix, is_depreciable,
                 tax_rate, gl_asset_account, gl_accum_account,
                 gl_expense_account, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $category_name, $tra_class, $method,
            $life_years, $rb_rate, $salvage_pct,
            $prefix_v, $is_depreciable, $tax_rate,
            $gl_asset_v, $gl_accum_v, $gl_expense_v,
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
