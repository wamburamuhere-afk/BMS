<?php
/**
 * BMS — Asset AuditService (Asset Register & PPE Schedule, Phase 3)
 *
 * Writes immutable rows to asset_audit_log. This is the asset-specific change
 * trail required by the document (create / update / dispose / depreciate). It
 * is separate from the system-wide logActivity() — both are written on asset
 * writes: logActivity() for the global activity feed, logAssetAudit() for the
 * per-asset history shown on the asset detail page.
 *
 * Never throws to the caller — an audit-write failure must not abort the
 * business transaction; it is logged to the PHP error log instead.
 */

if (!function_exists('logAssetAudit')) {
    /**
     * @param PDO         $pdo
     * @param int         $assetId
     * @param string      $action       create | update | dispose | depreciate | status
     * @param string|null $fieldChanged optional specific field
     * @param mixed       $oldValue     optional previous value
     * @param mixed       $newValue     optional new value
     * @param int|null    $userId       defaults to the session user
     */
    function logAssetAudit($pdo, int $assetId, string $action,
                           ?string $fieldChanged = null, $oldValue = null,
                           $newValue = null, ?int $userId = null): void
    {
        try {
            if ($userId === null) {
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            }
            $stmt = $pdo->prepare("
                INSERT INTO asset_audit_log
                    (asset_id, action, field_changed, old_value, new_value, changed_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $assetId,
                substr($action, 0, 30),
                $fieldChanged !== null ? substr($fieldChanged, 0, 60) : null,
                $oldValue !== null ? (is_scalar($oldValue) ? (string)$oldValue : json_encode($oldValue)) : null,
                $newValue !== null ? (is_scalar($newValue) ? (string)$newValue : json_encode($newValue)) : null,
                $userId,
            ]);
        } catch (Throwable $e) {
            error_log('logAssetAudit failed (asset ' . $assetId . ', ' . $action . '): ' . $e->getMessage());
        }
    }
}

if (!function_exists('suggestAssetCondition')) {
    /**
     * Map a book NBV percentage to a suggested condition (document §4.4).
     * Always overridable by a human after inspection.
     *
     *   > 75%  → excellent
     *   50–75% → good
     *   25–50% → fair
     *   1–25%  → poor
     *   0      → eol (end of life)
     */
    function suggestAssetCondition(float $cost, float $nbv): string
    {
        if ($cost <= 0) return 'good';
        $pct = ($nbv / $cost) * 100;
        if ($pct <= 0)  return 'eol';
        if ($pct <= 25) return 'poor';
        if ($pct <= 50) return 'fair';
        if ($pct <= 75) return 'good';
        return 'excellent';
    }
}
