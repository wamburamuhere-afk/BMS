<?php
// scope-audit: skip — bulk import utility; product catalog is global (warehouse access checked below)
/**
 * API: Import Products (CSV) — core/product_import.php does the work.
 * POST (multipart): file (CSV), action = preview | commit, warehouse_id (when the
 * user can stock more than one shop), _csrf.
 *   preview — validates every row and says what would happen; writes nothing.
 *   commit  — re-validates the uploaded file (never trusts a previous preview) and
 *             saves every new product in one transaction.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../core/product_import.php';
global $pdo;

function piJson(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (!isAuthenticated()) piJson(['success' => false, 'message' => t('Unauthorized')], 401);
if (!canCreate('products')) piJson(['success' => false, 'message' => t('Access Denied: you do not have permission to import products')], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') piJson(['success' => false, 'message' => t('Method not allowed')], 405);
csrf_check();

$action = $_POST['action'] ?? 'preview';
if (!in_array($action, ['preview', 'commit'], true)) piJson(['success' => false, 'message' => t('Invalid request')], 400);

$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) piJson(['success' => false, 'message' => t('Choose a CSV file to upload.')], 422);
if ((int)$f['size'] > 5 * 1024 * 1024) piJson(['success' => false, 'message' => t('The file is larger than 5 MB.')], 422);
if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'csv') piJson(['success' => false, 'message' => t('Only CSV files (.csv) are accepted. In Excel use Save As → CSV.')], 422);

// The shop opening stock goes into: one the user may stock (same list Add Product offers).
$shops = warehousesForSelect($pdo);
if (!$shops) piJson(['success' => false, 'message' => t('Create a shop first — opening stock needs a shop to go into.')], 422);
$shopIds = array_map('intval', array_column($shops, 'warehouse_id'));
$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
if ($warehouseId === 0 && count($shopIds) === 1) $warehouseId = $shopIds[0];
$shopList = array_map(fn($s) => ['id' => (int)$s['warehouse_id'], 'name' => $s['warehouse_name']], $shops);

$parsed = piParseCsv((string)$f['tmp_name']);
if ($parsed['error']) piJson(['success' => false, 'message' => $parsed['error']], 422);

try {
    $plan = piPlan($pdo, $parsed['rows']);
    $summary = piSummary($plan);

    if ($action === 'preview') {
        piJson(['success' => true, 'plan' => $plan, 'summary' => $summary, 'shops' => $shopList, 'warehouse_id' => $warehouseId ?: null]);
    }

    if (!in_array($warehouseId, $shopIds, true)) piJson(['success' => false, 'message' => t('Choose the shop the opening stock goes into.'), 'shops' => $shopList], 422);
    if ($summary['error'] > 0) piJson(['success' => false, 'message' => t('Fix the rows marked with an error first — nothing was saved.'), 'plan' => $plan, 'summary' => $summary], 422);
    if ($summary['new'] === 0) piJson(['success' => false, 'message' => t('There is nothing new to import — every product in the file already exists.')], 422);

    $res = piImportCommit($pdo, $plan, $warehouseId, (int)$_SESSION['user_id']);
    logActivity($pdo, (int)$_SESSION['user_id'], 'Import products',
        "Imported {$res['created']} products from CSV ({$res['skipped']} skipped)"
        . ($res['categories_created'] ? '; new categories: ' . implode(', ', $res['categories_created']) : ''));
    piJson(['success' => true, 'created' => $res['created'], 'skipped' => $res['skipped'],
            'categories_created' => $res['categories_created'],
            'message' => sprintf(t('%d products imported, %d skipped.'), $res['created'], $res['skipped'])]);
} catch (InvalidArgumentException $e) {
    piJson(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('import_products: ' . $e->getMessage());
    piJson(['success' => false, 'message' => t('The import failed and nothing was saved. Try again; if it repeats, contact support.')], 500);
}
