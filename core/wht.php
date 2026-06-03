<?php
/**
 * core/wht.php
 * ------------
 * Withholding Tax (WHT) helpers — PURCHASE side. You are the withholding agent:
 * when you pay a supplier / sub-contractor you keep a slice (2% goods-to-Govt,
 * 5% resident services) and owe it to TRA. WHT is a balance-sheet-only liability
 * — it never touches P&L.
 *
 * Unlike VAT (recognised at invoice approval), WHT is recognised AT PAYMENT — the
 * cash point. The actual ledger entry
 *     Dr Accounts Payable (gross) / Cr Cash (gross−WHT) / Cr WHT Payable (WHT)
 * is posted as ONE atomic entry by postOutflow() in payment_source.php and undone
 * by reverseOutflow(). These helpers own the configuration, the rate maths, the
 * idempotency flag (wht_posted: NULL = "not posted"), and the drift-proof position
 * (Σ wht_posted over live documents) — mirroring core/vat.php's vatNetPosition().
 */

require_once __DIR__ . '/payment_source.php';

if (!function_exists('whtPayableAccountId')) {
    /** Configured WHT Payable account (liability), or null. */
    function whtPayableAccountId(PDO $pdo): ?int
    {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_wht_payable_account_id' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        return ($v !== false && (int)$v > 0) ? (int)$v : null;
    }
}

if (!function_exists('whtRatePercent')) {
    /** The % for a WHT tax_rates row (tax_kind='wht'); 0 for none / non-WHT / inactive. */
    function whtRatePercent(PDO $pdo, ?int $rateId): float
    {
        if (!$rateId) return 0.0;
        $s = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ? AND tax_kind = 'wht' AND status = 'active' LIMIT 1");
        $s->execute([$rateId]);
        $v = $s->fetchColumn();
        return $v !== false ? (float)$v : 0.0;
    }
}

if (!function_exists('computeWht')) {
    /** WHT amount on a VAT-EXCLUSIVE base at the given percent, rounded to 2dp. */
    function computeWht(float $base, float $ratePercent): float
    {
        if ($base <= 0 || $ratePercent <= 0) return 0.0;
        return round($base * $ratePercent / 100, 2);
    }
}

if (!function_exists('markWhtPosted')) {
    /** Stamp the exact WHT amount posted (idempotency). Whitelisted tables only. */
    function markWhtPosted(PDO $pdo, string $table, int $id, float $amount): void
    {
        $pk = ['supplier_invoices' => 'id', 'supplier_payments' => 'payment_id'][$table] ?? null;
        if ($pk === null || $id <= 0) return;
        $pdo->prepare("UPDATE `$table` SET wht_posted = ? WHERE $pk = ?")->execute([round($amount, 2), $id]);
    }
}

if (!function_exists('clearWhtPosted')) {
    /** Clear the posted flag (on payment reversal / delete). No-op if unknown table. */
    function clearWhtPosted(PDO $pdo, string $table, int $id): void
    {
        $pk = ['supplier_invoices' => 'id', 'supplier_payments' => 'payment_id'][$table] ?? null;
        if ($pk === null || $id <= 0) return;
        $pdo->prepare("UPDATE `$table` SET wht_posted = NULL WHERE $pk = ?")->execute([$id]);
    }
}

if (!function_exists('whtPosition')) {
    /**
     * Total WHT withheld but not yet cleared — the liability owed to TRA. Summed
     * from the wht_posted flag on live documents (drift-proof, like vatNetPosition).
     * @return array{payable:float}
     */
    function whtPosition(PDO $pdo): array
    {
        try {
            $inv = (float)$pdo->query("SELECT COALESCE(SUM(wht_posted), 0) FROM supplier_invoices WHERE status <> 'deleted'")->fetchColumn();
            $pay = (float)$pdo->query("SELECT COALESCE(SUM(wht_posted), 0) FROM supplier_payments WHERE status NOT IN ('cancelled','failed')")->fetchColumn();
            return ['payable' => round($inv + $pay, 2)];
        } catch (PDOException $e) {
            return ['payable' => 0.0];   // pre-migration server: no WHT columns yet
        }
    }
}
