<?php
// api/operations/save_asset.php
//
// Asset Register & PPE Schedule — Phase 3a (backend).
// Creates/updates an asset master record AND its parallel book + tax
// depreciation areas, generates the asset code from the category prefix,
// sets a suggested condition, and writes an asset_audit_log entry.
//
// Backward compatible: the legacy single-track form fields
// (useful_life_years / annual_rate_percent / depreciation_method /
// salvage_value / depreciation_start_date) are still accepted and used to
// build the book area when the new per-area fields are absent.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_code_service.php';
require_once __DIR__ . '/../../core/asset_audit_service.php';

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$asset_id = $_POST['asset_id'] ?? null;

if (!empty($asset_id) ? !canEdit('assets') : !canCreate('assets')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to " . (!empty($asset_id) ? 'edit' : 'create') . " assets"]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 1;
$action  = $_POST['action'] ?? '';

// ── Status-only update (from the list dropdown) ──────────────────────────────
if ($action === 'update_status' && $asset_id) {
    try {
        $status = $_POST['status'] ?? 'active';
        $prev = $pdo->prepare("SELECT status FROM assets WHERE asset_id = ?");
        $prev->execute([$asset_id]);
        $old_status = $prev->fetchColumn();

        $stmt = $pdo->prepare("UPDATE assets SET status = ?, updated_by = ?, updated_at = NOW() WHERE asset_id = ?");
        $stmt->execute([$status, $user_id, $asset_id]);

        logActivity($pdo, $user_id, "Updated Asset Status", "Asset ID: $asset_id, new status: $status");
        logAssetAudit($pdo, (int)$asset_id, 'status', 'status', $old_status, $status, (int)$user_id);

        echo json_encode(["success" => true, "message" => "Asset status updated to " . ucfirst($status)]);
        exit;
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }
}

// ── Core identification / acquisition / assignment fields ────────────────────
$asset_name   = trim($_POST['asset_name'] ?? '');
$asset_code   = trim($_POST['asset_code'] ?? '');
$category     = $_POST['category'] ?? '';
$category_id  = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
$location     = $_POST['location'] ?? '';
$cost         = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float)$_POST['cost'] : 0.0;
$status       = $_POST['status'] ?? 'active';
$description  = $_POST['description'] ?? '';
$purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;

$acquisition_type = $_POST['acquisition_type'] ?? 'new';
if (!in_array($acquisition_type, ['new', 'existing'], true)) $acquisition_type = 'new';

// Legacy single-track depreciation fields (old form / fallback source).
$legacy_method = $_POST['depreciation_method'] ?? null;
if ($legacy_method !== null && !in_array($legacy_method, ['straight_line', 'reducing_balance'], true)) {
    $legacy_method = null;
}
$legacy_life     = isset($_POST['useful_life_years']) && $_POST['useful_life_years'] !== '' ? (int)$_POST['useful_life_years'] : null;
$legacy_rate     = isset($_POST['annual_rate_percent']) && $_POST['annual_rate_percent'] !== '' ? (float)$_POST['annual_rate_percent'] : null;
$legacy_salvage  = isset($_POST['salvage_value']) && $_POST['salvage_value'] !== '' ? (float)$_POST['salvage_value'] : 0.0;
$legacy_start    = !empty($_POST['depreciation_start_date']) ? $_POST['depreciation_start_date'] : null;

$capitalization_date = !empty($_POST['capitalization_date']) ? $_POST['capitalization_date'] : ($legacy_start ?: $purchase_date);
$take_on_date        = !empty($_POST['take_on_date']) ? $_POST['take_on_date'] : null;

// Optional assignment / identification fields (new form).
$serial_number   = trim($_POST['serial_number'] ?? '');
$invoice_ref     = trim($_POST['invoice_ref'] ?? '');
$warranty_expiry = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
$custodian_id    = isset($_POST['custodian_id'])    && $_POST['custodian_id']    !== '' ? (int)$_POST['custodian_id']    : null;
$supplier_id     = isset($_POST['supplier_id'])     && $_POST['supplier_id']     !== '' ? (int)$_POST['supplier_id']     : null;
$location_id     = isset($_POST['location_id'])     && $_POST['location_id']     !== '' ? (int)$_POST['location_id']     : null;
$parent_asset_id = isset($_POST['parent_asset_id']) && $_POST['parent_asset_id'] !== '' ? (int)$_POST['parent_asset_id'] : null;
$condition_in    = $_POST['condition'] ?? '';
if ($condition_in !== '' && !in_array($condition_in, ['excellent','good','fair','poor','eol'], true)) {
    $condition_in = '';
}

if (!$asset_name || !$category) {
    echo json_encode(["success" => false, "message" => "Asset Name and Category are required"]);
    exit;
}

// ── Resolve the category (for prefix, is_depreciable, defaults, tax rate) ─────
$cat = null;
if ($category_id) {
    $cstmt = $pdo->prepare("SELECT * FROM asset_categories WHERE category_id = ?");
    $cstmt->execute([$category_id]);
    $cat = $cstmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$cat && $category !== '') {
    $cstmt = $pdo->prepare("SELECT * FROM asset_categories WHERE category_name = ?");
    $cstmt->execute([$category]);
    $cat = $cstmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($cat) $category_id = (int)$cat['category_id'];
}
$is_depreciable = $cat ? (int)$cat['is_depreciable'] === 1 : true;

// ── Resolve BOOK area inputs (new per-area fields → legacy → category default) ─
$book_method = $_POST['book_method'] ?? $legacy_method ?? ($cat['default_method'] ?? 'straight_line');
if (!in_array($book_method, ['straight_line', 'reducing_balance'], true)) $book_method = 'straight_line';
$book_life    = isset($_POST['book_useful_life']) && $_POST['book_useful_life'] !== '' ? (int)$_POST['book_useful_life']
                : ($legacy_life ?? ($cat['default_useful_life_years'] ?? null));
$book_rate    = isset($_POST['book_rate']) && $_POST['book_rate'] !== '' ? (float)$_POST['book_rate']
                : ($legacy_rate ?? ($cat['default_annual_rate_percent'] ?? null));
$book_salvage = isset($_POST['book_salvage']) && $_POST['book_salvage'] !== '' ? (float)$_POST['book_salvage'] : $legacy_salvage;
$book_bf      = ($acquisition_type === 'existing' && isset($_POST['book_opening_accum_bf']) && $_POST['book_opening_accum_bf'] !== '')
                ? (float)$_POST['book_opening_accum_bf'] : 0.0;

// ── Resolve TAX area inputs (always reducing balance) ────────────────────────
$tax_rate = isset($_POST['tax_rate']) && $_POST['tax_rate'] !== '' ? (float)$_POST['tax_rate']
            : (($cat && $cat['tax_rate'] !== null) ? (float)$cat['tax_rate'] : null);
$tax_bf   = ($acquisition_type === 'existing' && isset($_POST['tax_opening_accum_bf']) && $_POST['tax_opening_accum_bf'] !== '')
            ? (float)$_POST['tax_opening_accum_bf'] : 0.0;

// Depreciation area start date: existing → take-on; new → capitalization.
$area_start = ($acquisition_type === 'existing' && $take_on_date) ? $take_on_date : $capitalization_date;

// ── Suggested condition from book NBV % (unless the user set one) ─────────────
$condition = $condition_in;
if ($condition === '') {
    if (!$is_depreciable) {
        $condition = 'good';
    } else {
        $book_nbv  = max(0.0, $cost - $book_bf);
        $condition = suggestAssetCondition($cost, $book_nbv);
    }
}

/** Upsert one depreciation area row (unique on asset_id + area). */
function upsertDepreciationArea($pdo, int $assetId, string $area, string $method,
                                ?int $life, ?float $rate, float $salvage,
                                ?string $start, float $bf): void
{
    if (!$start) return; // area needs a start date to be meaningful
    $stmt = $pdo->prepare("
        INSERT INTO asset_depreciation_areas
            (asset_id, area, method, useful_life, rate, salvage_value, start_date, opening_accum_bf)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            method = VALUES(method), useful_life = VALUES(useful_life),
            rate = VALUES(rate), salvage_value = VALUES(salvage_value),
            start_date = VALUES(start_date), opening_accum_bf = VALUES(opening_accum_bf)
    ");
    $stmt->execute([$assetId, $area, $method, $life, $rate, $salvage, $start, $bf]);
}

try {
    $pdo->beginTransaction();
    $is_update = !empty($asset_id);

    // Auto-generate the code on create when the user left it blank.
    if (!$is_update && $asset_code === '') {
        $asset_code = generateAssetCode($pdo, $category_id);
    }

    if ($is_update) {
        $stmt = $pdo->prepare("UPDATE assets SET
            asset_name = ?, asset_code = ?, category = ?, category_id = ?,
            location = ?, location_id = ?, serial_number = ?, invoice_ref = ?,
            warranty_expiry = ?,
            supplier_id = ?, custodian_id = ?, parent_asset_id = ?,
            acquisition_type = ?, purchase_date = ?, capitalization_date = ?,
            take_on_date = ?, cost = ?, status = ?, `condition` = ?, description = ?,
            useful_life_years = ?, annual_rate_percent = ?, depreciation_method = ?,
            salvage_value = ?, depreciation_start_date = ?,
            updated_by = ?, updated_at = NOW()
            WHERE asset_id = ?");
        $stmt->execute([
            $asset_name, $asset_code, $category, $category_id,
            $location, $location_id, ($serial_number ?: null), ($invoice_ref ?: null),
            $warranty_expiry,
            $supplier_id, $custodian_id, $parent_asset_id,
            $acquisition_type, $purchase_date, $capitalization_date,
            $take_on_date, $cost, $status, $condition, $description,
            $book_life, $book_rate, $book_method, $book_salvage, $area_start,
            $user_id, $asset_id,
        ]);
        $message = "Asset updated successfully";
    } else {
        $stmt = $pdo->prepare("INSERT INTO assets (
            asset_name, asset_code, category, category_id,
            location, location_id, serial_number, invoice_ref, warranty_expiry,
            supplier_id, custodian_id, parent_asset_id,
            acquisition_type, purchase_date, capitalization_date,
            take_on_date, cost, status, `condition`, description,
            useful_life_years, annual_rate_percent, depreciation_method,
            salvage_value, depreciation_start_date,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $asset_name, $asset_code, $category, $category_id,
            $location, $location_id, ($serial_number ?: null), ($invoice_ref ?: null), $warranty_expiry,
            $supplier_id, $custodian_id, $parent_asset_id,
            $acquisition_type, $purchase_date, $capitalization_date,
            $take_on_date, $cost, $status, $condition, $description,
            $book_life, $book_rate, $book_method, $book_salvage, $area_start,
            $user_id,
        ]);
        $asset_id = (int)$pdo->lastInsertId();
        $message = "Asset added successfully";
    }

    // ── Parallel depreciation areas (only for depreciable categories) ────────
    if ($is_depreciable) {
        upsertDepreciationArea($pdo, (int)$asset_id, 'book', $book_method,
            $book_life, $book_rate, $book_salvage, $area_start, $book_bf);

        if ($tax_rate !== null) {
            upsertDepreciationArea($pdo, (int)$asset_id, 'tax', 'reducing_balance',
                null, $tax_rate, 0.0, $area_start, $tax_bf);
        }
    } else {
        // Non-depreciable (e.g. Land): remove any stray areas from a prior save.
        $pdo->prepare("DELETE FROM asset_depreciation_areas WHERE asset_id = ?")
            ->execute([$asset_id]);
    }

    $pdo->commit();

    logActivity($pdo, $user_id, $is_update ? "Updated Asset" : "Created Asset",
        "Asset ID: $asset_id, name: $asset_name, code: $asset_code, cost: $cost");
    logAssetAudit($pdo, (int)$asset_id, $is_update ? 'update' : 'create',
        null, null, $is_update ? null : $asset_code, (int)$user_id);

    echo json_encode(["success" => true, "message" => $message, "asset_id" => $asset_id, "asset_code" => $asset_code]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage();
    $friendly = $msg;
    if ($e->getCode() === '23000') {
        if (stripos($msg, "asset_code") !== false) {
            $friendly = "An asset with this code already exists. Use a different Asset Code.";
        } elseif (stripos($msg, 'category_id') !== false) {
            $friendly = "The selected category is no longer available. Please pick another from the dropdown.";
        } else {
            $friendly = "This save conflicts with existing data. Please double-check the form and try again.";
        }
    }
    error_log("save_asset SQLSTATE " . $e->getCode() . ": " . $msg);
    echo json_encode(["success" => false, "message" => $friendly]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("save_asset error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
