<?php
/**
 * BMS — Asset GL Service (Asset Register & PPE Schedule, Phase 9)
 *
 * Posts asset journal entries to the canonical ledger via postLedgerEntry().
 * Account determination uses the category's GL account CODES (gl_asset_account,
 * gl_accum_account, gl_expense_account) plus the asset_settings clearing /
 * gain-loss accounts; codes are resolved to accounts.account_id.
 *
 *   Depreciation:  Dr Depreciation Expense   Cr Accumulated Depreciation
 *   Disposal:      Cr Asset (cost)  Dr Accumulated Dep  Dr Clearing (proceeds)
 *                  Cr/Dr Gain/(Loss)
 *   Acquisition:   Dr Asset  Cr Clearing      (helper; not auto-wired — the
 *                  procurement/GRN flow usually books the purchase already)
 *
 * Every poster is best-effort and self-contained: if a required account isn't
 * configured/resolvable, it returns null and logs — it never breaks the asset
 * operation that called it. The depreciation poster is the one the acceptance
 * criterion ties to the book PPE schedule.
 */

require_once __DIR__ . '/ledger_post.php';
require_once __DIR__ . '/gl_accounts.php';   // fixedAssetAccountId, accumulatedDepreciationAccountId, depreciationExpenseAccountId, apAccountId, takeOnEquityAccountId

if (!function_exists('_asset_already_posted')) {
    function _asset_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('resolveAssetAccountId')) {
    /** Map a GL account code to an active accounts.account_id, or null. */
    function resolveAssetAccountId($pdo, ?string $code): ?int
    {
        static $cache = [];
        $code = $code !== null ? trim($code) : '';
        if ($code === '') return null;
        if (array_key_exists($code, $cache)) return $cache[$code];

        $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE account_code = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $cache[$code] = ($id !== false ? (int)$id : null);
    }
}

if (!function_exists('postAssetDepreciationGl')) {
    /**
     * Dr Depreciation Expense / Cr Accumulated Depreciation for one charge.
     * Returns entry_id, or null when accounts are missing / charge is zero.
     */
    function postAssetDepreciationGl($pdo, int $assetId, string $assetCode,
                                     ?string $expenseCode, ?string $accumCode,
                                     float $charge, string $date, int $userId): ?int
    {
        if ($charge <= 0.0) return null;
        // Prefer the category's configured GL codes; fall back to the canonical
        // resolvers so depreciation posts even when a category has no codes set
        // (the common case — the asset_categories gl_* columns ship empty).
        $expId   = resolveAssetAccountId($pdo, $expenseCode) ?: depreciationExpenseAccountId($pdo);
        $accumId = resolveAssetAccountId($pdo, $accumCode)   ?: accumulatedDepreciationAccountId($pdo);
        if (!$expId || !$accumId) return null;

        try {
            return postLedgerEntry($pdo,
                "Depreciation — $assetCode",
                [
                    ['account_id' => $expId,   'type' => 'debit',  'amount' => $charge, 'description' => 'Depreciation expense'],
                    ['account_id' => $accumId, 'type' => 'credit', 'amount' => $charge, 'description' => 'Accumulated depreciation'],
                ],
                null, $assetId, 'asset', $date, $userId
            );
        } catch (Throwable $e) {
            error_log("postAssetDepreciationGl asset $assetId: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('postAssetDisposalGl')) {
    /**
     * Disposal entry. $cat = category GL codes; $set = asset settings (clearing,
     * gain/loss). $snap has original_cost, accum_dep_book, nbv_at_disposal,
     * proceeds, gain_loss. Returns entry_id, or null if it cannot be balanced
     * with the configured accounts.
     */
    function postAssetDisposalGl($pdo, int $assetId, string $assetCode,
                                 array $cat, array $set, array $snap,
                                 string $date, int $userId): ?int
    {
        $assetAcc    = resolveAssetAccountId($pdo, $cat['gl_asset_account'] ?? null);
        $accumAcc    = resolveAssetAccountId($pdo, $cat['gl_accum_account'] ?? null);
        $clearingAcc = resolveAssetAccountId($pdo, $set['gl_clearing_account'] ?? null);
        $gainLossAcc = resolveAssetAccountId($pdo, $set['gl_gain_loss_account'] ?? null);

        $cost     = (float)$snap['original_cost'];
        $accum    = (float)$snap['accum_dep_book'];
        $proceeds = (float)$snap['proceeds'];
        $gain     = (float)$snap['gain_loss'];   // proceeds − nbv

        // Required: asset account (to remove cost). Others required only when
        // their leg is non-zero.
        if (!$assetAcc) return null;
        if ($accum    > 0.0 && !$accumAcc)    return null;
        if ($proceeds > 0.0 && !$clearingAcc) return null;
        if (abs($gain) > 0.01 && !$gainLossAcc) return null;

        $lines = [];
        $lines[] = ['account_id' => $assetAcc, 'type' => 'credit', 'amount' => $cost, 'description' => 'Asset cost removed'];
        if ($accum > 0.0)    $lines[] = ['account_id' => $accumAcc,    'type' => 'debit',  'amount' => $accum,    'description' => 'Accumulated depreciation removed'];
        if ($proceeds > 0.0) $lines[] = ['account_id' => $clearingAcc, 'type' => 'debit',  'amount' => $proceeds, 'description' => 'Disposal proceeds'];
        if (abs($gain) > 0.01) {
            $lines[] = $gain > 0
                ? ['account_id' => $gainLossAcc, 'type' => 'credit', 'amount' => $gain,        'description' => 'Gain on disposal']
                : ['account_id' => $gainLossAcc, 'type' => 'debit',  'amount' => abs($gain),   'description' => 'Loss on disposal'];
        }

        try {
            return postLedgerEntry($pdo, "Disposal — $assetCode", $lines, null, $assetId, 'asset', $date, $userId);
        } catch (Throwable $e) {
            error_log("postAssetDisposalGl asset $assetId: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('postAssetAcquisitionGl')) {
    /**
     * Capitalization entry: Dr Asset / Cr Clearing. Helper for callers that want
     * the asset register to book acquisitions (not auto-wired by default, since
     * the procurement flow usually books the purchase). Returns entry_id or null.
     */
    function postAssetAcquisitionGl($pdo, int $assetId, string $assetCode,
                                    ?string $assetAccCode, ?string $clearingCode,
                                    float $cost, string $date, int $userId): ?int
    {
        if ($cost <= 0.0) return null;
        $assetAcc    = resolveAssetAccountId($pdo, $assetAccCode);
        $clearingAcc = resolveAssetAccountId($pdo, $clearingCode);
        if (!$assetAcc || !$clearingAcc) return null;

        try {
            return postLedgerEntry($pdo,
                "Asset capitalised — $assetCode",
                [
                    ['account_id' => $assetAcc,    'type' => 'debit',  'amount' => $cost, 'description' => 'Asset cost'],
                    ['account_id' => $clearingAcc, 'type' => 'credit', 'amount' => $cost, 'description' => 'Acquisition clearing'],
                ],
                null, $assetId, 'asset', $date, $userId
            );
        } catch (Throwable $e) {
            error_log("postAssetAcquisitionGl asset $assetId: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('postAssetAcquisition')) {
    /**
     * money.md OUT-12 — capitalise an asset to the GL when it is added. Never throws,
     * idempotent on (entity_type='asset_acquisition', entity_id=asset_id).
     *
     *   acquisition_type='new'      (purchased now, on credit):
     *       Dr Fixed Asset (cost) / Cr Accounts Payable (cost)
     *   acquisition_type='existing' (take-on of an already-owned asset):
     *       Dr Fixed Asset (cost) / Cr Accumulated Depreciation (opening accum b/f)
     *                              / Cr Take-on Equity (NBV = cost − b/f)
     *
     * Fixed Asset account = category's gl_asset_account code if set, else the
     * canonical fixedAssetAccountId() (1-3000).
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postAssetAcquisition(
        PDO $pdo, int $assetId, string $assetCode, float $cost, string $acqType,
        float $openingAccumBf, ?string $catAssetCode, string $date,
        ?int $projectId, int $userId
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($assetId <= 0 || $cost <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _asset_already_posted($pdo, 'asset_acquisition', $assetId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $cost = round($cost, 2);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;

        $assetAcc = resolveAssetAccountId($pdo, $catAssetCode) ?: fixedAssetAccountId($pdo);
        if (!$assetAcc) { $out['reason'] = 'accounts_not_configured'; return $out; }

        if ($acqType === 'existing') {
            $bf  = round(max(0.0, $openingAccumBf), 2);
            if ($bf > $cost) $bf = $cost;
            $nbv = round($cost - $bf, 2);
            $accumAcc  = $bf  > 0 ? accumulatedDepreciationAccountId($pdo) : null;
            $equityAcc = $nbv > 0 ? takeOnEquityAccountId($pdo)            : null;
            if (($bf > 0 && !$accumAcc) || ($nbv > 0 && !$equityAcc)) { $out['reason'] = 'accounts_not_configured'; return $out; }

            $lines = [['account_id' => (int)$assetAcc, 'type' => 'debit', 'amount' => $cost, 'description' => 'Asset cost (take-on)']];
            if ($bf  > 0) $lines[] = ['account_id' => (int)$accumAcc,  'type' => 'credit', 'amount' => $bf,  'description' => 'Accumulated depreciation brought forward'];
            if ($nbv > 0) $lines[] = ['account_id' => (int)$equityAcc, 'type' => 'credit', 'amount' => $nbv, 'description' => 'Asset taken onto the books (opening)'];
        } else {
            $ap = apAccountId($pdo);
            if (!$ap) { $out['reason'] = 'accounts_not_configured'; return $out; }
            $lines = [
                ['account_id' => (int)$assetAcc, 'type' => 'debit',  'amount' => $cost, 'description' => 'Asset acquired'],
                ['account_id' => (int)$ap,       'type' => 'credit', 'amount' => $cost, 'description' => 'Owed to supplier (Accounts Payable)'],
            ];
        }

        try {
            $entry = postLedgerEntry($pdo, "Asset acquired — $assetCode", $lines, $pid, $assetId, 'asset_acquisition', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postAssetAcquisition asset $assetId: " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}
