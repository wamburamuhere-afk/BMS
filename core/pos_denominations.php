<?php
/**
 * core/pos_denominations.php
 *
 * Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting at shift
 * open/close. Extracted for independent testability, same reasoning as
 * every other core/pos_*.php helper added in this tranche.
 */

/**
 * Parse the admin-configured denomination list (system_settings.tzs_denominations,
 * a comma-separated list of values, highest-first by convention but not
 * enforced) into a clean float array. Never hardcoded — falls back to a
 * sane default only when the setting is missing or empty.
 */
function posDenominationList(): array
{
    $raw = function_exists('get_setting') ? get_setting('tzs_denominations', '10000,5000,2000,1000,500,200,100,50') : '10000,5000,2000,1000,500,200,100,50';
    $values = array_filter(array_map('floatval', explode(',', (string)$raw)), fn($v) => $v > 0);
    return array_values($values) ?: [10000, 5000, 2000, 1000, 500, 200, 100, 50];
}

/**
 * Validate a denomination breakdown against the expected total.
 *
 * @param array $breakdown [{value: float, count: int}, ...] — the client's
 *              submitted counts, e.g. from a JSON-decoded POST field. Never
 *              trusted blindly: only denomination VALUES on the admin's own
 *              configured list are accepted, and the computed sum must
 *              reconcile to $expectedTotal.
 * @return array{valid: bool, error: ?string, sum: float}
 */
function validateDenominationBreakdown(array $breakdown, float $expectedTotal): array
{
    if (empty($breakdown)) {
        return ['valid' => true, 'error' => null, 'sum' => 0.0]; // breakdown is optional
    }

    $allowed = posDenominationList();
    $sum = 0.0;
    foreach ($breakdown as $row) {
        $value = isset($row['value']) ? (float)$row['value'] : 0.0;
        $count = isset($row['count']) ? (int)$row['count'] : 0;
        if ($count < 0) {
            return ['valid' => false, 'error' => t('Denomination count cannot be negative.'), 'sum' => 0.0];
        }
        if ($count === 0) continue;
        $matches = false;
        foreach ($allowed as $a) { if (abs($a - $value) < 0.01) { $matches = true; break; } }
        if (!$matches) {
            return ['valid' => false, 'error' => sprintf(t('%s is not a configured denomination.'), number_format($value, 2)), 'sum' => 0.0];
        }
        $sum += $value * $count;
    }

    $sum = round($sum, 2);
    if (abs($sum - round($expectedTotal, 2)) > 0.01) {
        return ['valid' => false, 'error' => sprintf(t('Denomination breakdown (%s) does not match the entered total (%s).'), number_format($sum, 2), number_format($expectedTotal, 2)), 'sum' => $sum];
    }

    return ['valid' => true, 'error' => null, 'sum' => $sum];
}

/**
 * Persist a validated breakdown. Idempotent per (shift, context) — a retry
 * replaces rather than duplicates.
 */
function saveDenominationBreakdown(PDO $pdo, int $shiftId, string $context, array $breakdown): void
{
    if (empty($breakdown) || !in_array($context, ['open', 'close'], true)) return;

    $pdo->prepare("DELETE FROM cash_denomination_counts WHERE shift_id = ? AND context = ?")->execute([$shiftId, $context]);

    $stmt = $pdo->prepare("INSERT INTO cash_denomination_counts (shift_id, context, denomination_value, count) VALUES (?, ?, ?, ?)");
    foreach ($breakdown as $row) {
        $count = isset($row['count']) ? (int)$row['count'] : 0;
        if ($count <= 0) continue;
        $stmt->execute([$shiftId, $context, (float)$row['value'], $count]);
    }
}

/**
 * Read back a shift's breakdown for display (Z-Report).
 * @return array [{denomination_value, count, subtotal}, ...] highest-value first
 */
function getDenominationBreakdown(PDO $pdo, int $shiftId, string $context): array
{
    $stmt = $pdo->prepare("SELECT denomination_value, count FROM cash_denomination_counts WHERE shift_id = ? AND context = ? ORDER BY denomination_value DESC");
    $stmt->execute([$shiftId, $context]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['denomination_value'] = (float)$r['denomination_value'];
        $r['count'] = (int)$r['count'];
        $r['subtotal'] = round($r['denomination_value'] * $r['count'], 2);
    }
    return $rows;
}
