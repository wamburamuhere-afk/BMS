<?php
/**
 * BMS — Asset CodeService (Asset Register & PPE Schedule, Phase 3)
 *
 * Generates asset codes from a category's code_prefix, e.g. COMP → COMP-0001.
 * The numeric part is zero-padded to 4 digits and increments per prefix
 * (so each category has its own running sequence).
 *
 *   peekNextAssetCode()     — what the NEXT code would be (for live form display)
 *   generateAssetCode()     — same value, used at save time
 *
 * The unique key on assets.asset_code is the real guard against duplicates; the
 * max+1 scan here just produces the next sensible candidate.
 */

if (!function_exists('assetCodePrefixForCategory')) {
    /**
     * Resolve the code_prefix for a category id. Falls back to 'AST' when the
     * category has no prefix configured or the id is unknown.
     */
    function assetCodePrefixForCategory($pdo, ?int $categoryId): string
    {
        $prefix = '';
        if ($categoryId) {
            $stmt = $pdo->prepare("SELECT code_prefix FROM asset_categories WHERE category_id = ?");
            $stmt->execute([$categoryId]);
            $prefix = (string)($stmt->fetchColumn() ?: '');
        }
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
        return $prefix !== '' ? $prefix : 'AST';
    }
}

if (!function_exists('peekNextAssetCode')) {
    /**
     * Compute the next available asset code for a category WITHOUT consuming it.
     *
     * @return array{prefix:string, sequence:int, code:string}
     */
    function peekNextAssetCode($pdo, ?int $categoryId): array
    {
        $prefix = assetCodePrefixForCategory($pdo, $categoryId);

        // Highest existing numeric suffix for this prefix (codes like PREFIX-0007).
        $stmt = $pdo->prepare("
            SELECT asset_code FROM assets
             WHERE asset_code LIKE ?
        ");
        $stmt->execute([$prefix . '-%']);

        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $m)) {
                $n = (int)$m[1];
                if ($n > $max) $max = $n;
            }
        }

        $next = $max + 1;
        return [
            'prefix'   => $prefix,
            'sequence' => $next,
            'code'     => $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT),
        ];
    }
}

if (!function_exists('generateAssetCode')) {
    /**
     * Return the next asset code string for a category (e.g. COMP-0001).
     */
    function generateAssetCode($pdo, ?int $categoryId): string
    {
        return peekNextAssetCode($pdo, $categoryId)['code'];
    }
}
