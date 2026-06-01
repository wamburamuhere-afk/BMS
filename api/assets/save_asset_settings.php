<?php
/**
 * api/assets/save_asset_settings.php
 *
 * Update the single-row asset_settings config (Asset Register & PPE Schedule,
 * Phase 0). Admin / asset-edit permission only.
 *
 * POST fields:
 *   financial_year_start    — DATE (YYYY-MM-DD), required
 *   financial_year_end      — DATE (YYYY-MM-DD), required, must be after start
 *   global_take_on_date     — DATE (YYYY-MM-DD), optional (blank = NULL)
 *   depreciation_frequency  — 'annual' | 'monthly'
 *   depreciation_timing     — 'full_year' | 'pro_rata'
 *
 * There is always exactly one row (id = 1), seeded by the migration, so this
 * endpoint only ever UPDATEs.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canEdit('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you do not have permission to edit asset settings']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + input validation
csrf_check();

$fy_start  = trim($_POST['financial_year_start'] ?? '');
$fy_end    = trim($_POST['financial_year_end'] ?? '');
$take_on   = trim($_POST['global_take_on_date'] ?? '');
$frequency = $_POST['depreciation_frequency'] ?? 'annual';
$timing    = $_POST['depreciation_timing'] ?? 'full_year';

/** Validate a YYYY-MM-DD string into a real calendar date. */
function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

$errors = [];
if (!valid_date($fy_start)) $errors[] = 'Financial year start must be a valid date';
if (!valid_date($fy_end))   $errors[] = 'Financial year end must be a valid date';
if (!$errors && $fy_end <= $fy_start) {
    $errors[] = 'Financial year end must be after the start date';
}
if ($take_on !== '' && !valid_date($take_on)) {
    $errors[] = 'Global take-on date must be a valid date or left blank';
}
if (!in_array($frequency, ['annual', 'monthly'], true)) {
    $errors[] = "Depreciation frequency must be 'annual' or 'monthly'";
}
if (!in_array($timing, ['full_year', 'pro_rata'], true)) {
    $errors[] = "Depreciation timing must be 'full_year' or 'pro_rata'";
}

if ($errors) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('; ', $errors)]);
    exit;
}

// 5. Business logic — upsert the single config row (id = 1)
try {
    global $pdo;

    $take_on_val = $take_on === '' ? null : $take_on;

    $stmt = $pdo->prepare("
        INSERT INTO asset_settings
            (id, financial_year_start, financial_year_end,
             global_take_on_date, depreciation_frequency, depreciation_timing)
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            financial_year_start   = VALUES(financial_year_start),
            financial_year_end     = VALUES(financial_year_end),
            global_take_on_date    = VALUES(global_take_on_date),
            depreciation_frequency = VALUES(depreciation_frequency),
            depreciation_timing    = VALUES(depreciation_timing)
    ");
    $stmt->execute([$fy_start, $fy_end, $take_on_val, $frequency, $timing]);

    // 6. Activity log
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Updated Asset Settings',
        "FY {$fy_start}→{$fy_end}, take-on=" . ($take_on_val ?? 'none') .
        ", {$frequency}/{$timing}");

    echo json_encode(['success' => true, 'message' => 'Asset settings saved.']);

} catch (PDOException $e) {
    error_log('save_asset_settings error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
