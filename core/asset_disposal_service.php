<?php
/**
 * BMS — DisposalService (Asset Register & PPE Schedule, Phase 6)
 *
 * Disposes an asset: snapshots the figures the PPE schedule's disposal lines
 * need (original cost, accumulated depreciation per area at the disposal date),
 * computes net book value and the gain/loss, flips the asset status, and stops
 * future depreciation (the engine already halts at assets.disposal_date).
 *
 *   nbv_at_disposal = original_cost - accum_dep_book_at_disposal
 *   gain_loss       = proceeds - nbv_at_disposal   (P&L — NOT part of the
 *                                                   PPE schedule movement)
 *
 * One disposal per asset (unique key on asset_disposals.asset_id).
 */

require_once __DIR__ . '/asset_depreciation_service.php';
require_once __DIR__ . '/asset_settings.php';
require_once __DIR__ . '/asset_audit_service.php';

if (!function_exists('disposeAsset')) {
    /**
     * @param PDO   $pdo
     * @param int   $assetId
     * @param array $d  ['disposal_date','method','proceeds','notes']
     * @param int   $userId
     * @return array{success:bool, message:string, snapshot?:array}
     */
    function disposeAsset($pdo, int $assetId, array $d, int $userId): array
    {
        $disposalDate = $d['disposal_date'] ?? date('Y-m-d');
        $method       = $d['method'] ?? 'sold';
        $proceeds     = isset($d['proceeds']) && $d['proceeds'] !== '' ? (float)$d['proceeds'] : 0.0;
        $notes        = trim($d['notes'] ?? '');

        if (!in_array($method, ['sold', 'scrapped', 'donated', 'written_off'], true)) {
            return ['success' => false, 'message' => 'Invalid disposal method'];
        }
        if (!DateTime::createFromFormat('Y-m-d', $disposalDate)) {
            return ['success' => false, 'message' => 'Invalid disposal date'];
        }

        $stmt = $pdo->prepare("SELECT cost, status, disposal_date FROM assets WHERE asset_id = ? AND status != 'deleted'");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            return ['success' => false, 'message' => 'Asset not found'];
        }
        if (in_array($asset['status'], ['disposed', 'written_off'], true) || $asset['disposal_date']) {
            return ['success' => false, 'message' => 'Asset is already disposed'];
        }

        $cost   = (float)$asset['cost'];
        $timing = getAssetSettings($pdo)['depreciation_timing'];

        // Accumulated depreciation per area as at the disposal date.
        $areaStmt = $pdo->prepare("SELECT * FROM asset_depreciation_areas WHERE asset_id = ?");
        $areaStmt->execute([$assetId]);
        $accumBook = 0.0; $accumTax = 0.0;
        foreach ($areaStmt->fetchAll(PDO::FETCH_ASSOC) as $area) {
            $calc = calcAreaDepreciation($area, $cost, $disposalDate, $timing);
            if ($area['area'] === 'book') $accumBook = $calc['accumulated'];
            if ($area['area'] === 'tax')  $accumTax  = $calc['accumulated'];
        }

        $nbv      = round($cost - $accumBook, 2);
        $gainLoss = round($proceeds - $nbv, 2);
        $newStatus = $method === 'written_off' ? 'written_off' : 'disposed';

        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO asset_disposals
                    (asset_id, disposal_date, method, original_cost,
                     accum_dep_book_at_disposal, accum_dep_tax_at_disposal,
                     proceeds, nbv_at_disposal, gain_loss, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $assetId, $disposalDate, $method, $cost,
                $accumBook, $accumTax, $proceeds, $nbv, $gainLoss,
                ($notes !== '' ? $notes : null), $userId,
            ]);

            // Flip status + stop future depreciation (engine halts at disposal_date).
            $pdo->prepare("
                UPDATE assets
                   SET status = ?, disposal_date = ?, disposal_proceeds = ?,
                       disposal_gain_loss = ?, updated_by = ?, updated_at = NOW()
                 WHERE asset_id = ?
            ")->execute([$newStatus, $disposalDate, $proceeds, $gainLoss, $userId, $assetId]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => 'Asset is already disposed'];
            }
            error_log('disposeAsset error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }

        $snapshot = [
            'original_cost'   => $cost,
            'accum_dep_book'  => $accumBook,
            'accum_dep_tax'   => $accumTax,
            'nbv_at_disposal' => $nbv,
            'proceeds'        => $proceeds,
            'gain_loss'       => $gainLoss,
            'method'          => $method,
            'disposal_date'   => $disposalDate,
        ];

        logActivity($pdo, $userId, 'Disposed Asset',
            "Asset ID: $assetId, method: $method, NBV: $nbv, gain/loss: $gainLoss");
        logAssetAudit($pdo, $assetId, 'dispose', 'status', $asset['status'], $newStatus, $userId);

        return ['success' => true, 'message' => 'Asset disposed.', 'snapshot' => $snapshot];
    }
}
