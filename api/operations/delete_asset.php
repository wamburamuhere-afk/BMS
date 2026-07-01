<?php
// api/operations/delete_asset.php
//
// account_financial.md flow #13 (FIX) — professional asset-delete policy.
// This was a bare hard-delete of the assets row that ORPHANED its posted GL — the
// acquisition (Dr Fixed Asset / Cr AP), every depreciation charge (Dr Dep Expense /
// Cr Accum Dep) and any disposal — leaving Fixed Assets / AP / Accumulated
// Depreciation / Depreciation Expense overstated, with no source document.
//
// Accounting rule (immutability + reverse-don't-delete):
//   • Posted DEPRECIATION or a DISPOSAL is immutable history (it can span closed,
//     already-reported periods) → BLOCK; the asset must be DISPOSED, not deleted,
//     so the ledger trail is preserved.
//   • Capitalised IN ERROR (acquisition entry only, no depreciation, not disposed)
//     → reverse that single capitalisation entry (idempotent) then SOFT-delete (§12).
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/expense_posting.php';   // accrualEntryId / reverseAccrualEntry (shared reversal)

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

if (!canDelete('assets')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to delete assets"]);
    exit;
}

$asset_id = (int)($_POST['asset_id'] ?? 0);
if (!$asset_id) {
    echo json_encode(["success" => false, "message" => "Asset ID is required"]);
    exit;
}

try {
    $a = $pdo->prepare("SELECT asset_id, asset_code, status FROM assets WHERE asset_id = ?");
    $a->execute([$asset_id]);
    $asset = $a->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        echo json_encode(["success" => false, "message" => "Asset not found"]);
        exit;
    }
    if ($asset['status'] === 'deleted') {
        echo json_encode(["success" => false, "message" => "This asset is already deleted"]);
        exit;
    }

    // Posted ledger footprint for this asset.
    $depCount  = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='asset' AND entity_id=" . (int)$asset_id . " AND status='posted'")->fetchColumn();
    $dispCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='asset_disposal' AND entity_id=" . (int)$asset_id . " AND status='posted'")->fetchColumn();

    // Immutable history → block. Dispose the asset instead (keeps the trail).
    if ($depCount > 0 || $dispCount > 0 || in_array($asset['status'], ['disposed', 'written_off'], true)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" =>
            "This asset has posted depreciation or disposal entries and cannot be deleted. Dispose the asset instead — that keeps the ledger history intact."]);
        exit;
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);

    $pdo->beginTransaction();

    // Reverse the capitalisation entry if one was posted (idempotent on
    // 'asset_acquisition_void'; no-op if the asset was never capitalised):
    //   'new'      Dr Fixed Asset / Cr AP                → reversal Dr AP / Cr Fixed Asset
    //   'existing' Dr Asset / Cr Accum Dep / Cr Take-on Equity → flipped
    if (accrualEntryId($pdo, 'asset_acquisition', $asset_id)) {
        reverseAccrualEntry($pdo, 'asset_acquisition', $asset_id, $user_id);
    }

    // Soft-delete (§12) — keep the record + full audit trail.
    $pdo->prepare("UPDATE assets SET status='deleted', updated_by=?, updated_at=NOW() WHERE asset_id=?")
        ->execute([$user_id ?: null, $asset_id]);

    $pdo->commit();

    logActivity($pdo, $user_id, "Delete asset",
        "deleted asset {$asset['asset_code']} with id $asset_id — soft-deleted; capitalisation entry reversed if present");

    echo json_encode(["success" => true, "message" => "Asset deleted successfully"]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("delete_asset error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
