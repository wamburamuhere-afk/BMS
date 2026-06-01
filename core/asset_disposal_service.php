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
require_once __DIR__ . '/asset_depreciation_run.php';   // fyBoundsForYear / firstFyYear
require_once __DIR__ . '/asset_gl_service.php';

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

        $stmt = $pdo->prepare("
            SELECT a.cost, a.status, a.disposal_date, a.asset_code,
                   c.gl_asset_account, c.gl_accum_account
              FROM assets a
              LEFT JOIN asset_categories c ON c.category_id = a.category_id
             WHERE a.asset_id = ? AND a.status != 'deleted'
        ");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            return ['success' => false, 'message' => 'Asset not found'];
        }
        if (in_array($asset['status'], ['disposed', 'written_off'], true) || $asset['disposal_date']) {
            return ['success' => false, 'message' => 'Asset is already disposed'];
        }

        $cost     = (float)$asset['cost'];
        $settings = getAssetSettings($pdo);
        $timing   = $settings['depreciation_timing'];

        // Accumulated depreciation per area as at the disposal date.
        $areaStmt = $pdo->prepare("SELECT * FROM asset_depreciation_areas WHERE asset_id = ?");
        $areaStmt->execute([$assetId]);
        $areaList = $areaStmt->fetchAll(PDO::FETCH_ASSOC);
        $accumBook = 0.0; $accumTax = 0.0;
        $accumByArea = [];
        foreach ($areaList as $area) {
            $calc = calcAreaDepreciation($area, $cost, $disposalDate, $timing);
            $accumByArea[$area['area']] = ['accum' => $calc['accumulated'], 'bf' => (float)$area['opening_accum_bf']];
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

            // Sync posted depreciation_entries to the disposal snapshot so the PPE
            // schedule reconciles regardless of run/dispose order: drop periods
            // ending after the disposal FY, and set the disposal-year entry's
            // accumulated to the snapshot (charge = snapshot − prior accumulated).
            $dispFy = firstFyYear($settings, $disposalDate);
            [$pStart, $pEnd] = fyBoundsForYear($settings, $dispFy);
            $delFuture = $pdo->prepare("DELETE FROM depreciation_entries WHERE asset_id = ? AND area = ? AND period_end > ?");
            $priorQ    = $pdo->prepare("SELECT accumulated FROM depreciation_entries WHERE asset_id = ? AND area = ? AND period_end < ? ORDER BY period_end DESC LIMIT 1");
            $upsertE   = $pdo->prepare("
                INSERT INTO depreciation_entries
                    (asset_id, area, period_start, period_end, opening_value, charge, accumulated, closing_nbv, posted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    period_start=VALUES(period_start), opening_value=VALUES(opening_value),
                    charge=VALUES(charge), accumulated=VALUES(accumulated),
                    closing_nbv=VALUES(closing_nbv), posted=1
            ");
            foreach ($accumByArea as $areaName => $info) {
                $delFuture->execute([$assetId, $areaName, $pEnd]);
                $priorQ->execute([$assetId, $areaName, $pStart]);
                $prior = $priorQ->fetchColumn();
                $priorAccum = $prior !== false ? (float)$prior : $info['bf'];
                $accum  = $info['accum'];
                $charge = round(max(0.0, $accum - $priorAccum), 2);
                $upsertE->execute([
                    $assetId, $areaName, $pStart, $pEnd,
                    round($cost - $priorAccum, 2), $charge, $accum, round($cost - $accum, 2),
                ]);
            }

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

        // §9.1 — GL posting (best-effort; needs category + settings accounts).
        $glEntryId = postAssetDisposalGl($pdo, $assetId, (string)$asset['asset_code'],
            ['gl_asset_account' => $asset['gl_asset_account'], 'gl_accum_account' => $asset['gl_accum_account']],
            ['gl_clearing_account' => $settings['gl_clearing_account'] ?? null,
             'gl_gain_loss_account' => $settings['gl_gain_loss_account'] ?? null],
            $snapshot, $disposalDate, $userId);
        if ($glEntryId) $snapshot['gl_entry_id'] = $glEntryId;

        return ['success' => true, 'message' => 'Asset disposed.', 'snapshot' => $snapshot];
    }
}
