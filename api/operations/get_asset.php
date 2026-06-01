<?php
// api/operations/get_asset.php
//
// Returns a single asset master record plus its parallel book + tax
// depreciation areas (Asset Register & PPE Schedule, Phase 3a) so the edit
// form can repopulate both areas.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}
if (!canView('assets')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Permission denied"]);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(["success" => false, "message" => "Asset ID is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_id = ?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        echo json_encode(["success" => false, "message" => "Asset not found"]);
        exit;
    }

    // Attach the depreciation areas keyed by area (book / tax) for the form.
    $astmt = $pdo->prepare("SELECT area, method, useful_life, rate, salvage_value,
                                   start_date, opening_accum_bf
                              FROM asset_depreciation_areas WHERE asset_id = ?");
    $astmt->execute([$id]);
    $areas = ['book' => null, 'tax' => null];
    foreach ($astmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $areas[$row['area']] = $row;
    }
    $asset['areas'] = $areas;

    echo json_encode(["success" => true, "data" => $asset]);
} catch (Exception $e) {
    error_log("get_asset error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
