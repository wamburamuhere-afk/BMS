<?php
/**
 * Balance Sheet — Current/Non-Current liquidity classification CLI test.
 *   php tests/test_bs_liquidity_classification_cli.php
 *
 * Proves the data-driven liquidity classification (migration 2026_06_03):
 *   1. Source invariants — migration + helpers present, Balance Sheet uses the
 *      resolver and dropped the inline strpos() heuristic; touched files lint.
 *   2. fc_resolve_liquidity() — stored value wins; name-heuristic fallback.
 *   3. Migration applied + seed correctness (live DB).
 *   4. The data-driven Balance Sheet SELECT runs under the DB sql_mode
 *      (ONLY_FULL_GROUP_BY) and the resolver buckets every real account row.
 *
 * Requires the migration to have run: php migrations/2026_06_03_account_types_liquidity.php
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_classification.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function lints($f) { exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc); return $rc === 0; }

echo "\n\033[1m── 1. Source invariants ──\033[0m\n";
$mig  = "$root/migrations/2026_06_03_account_types_liquidity.php";
$fcF  = "$root/core/financial_classification.php";
$bsF  = "$root/app/constant/reports/balance_sheet.php";
ok(file_exists($mig), "migration 2026_06_03_account_types_liquidity.php exists");
ok(lints($mig), "migration passes php -l");
ok(lints($fcF), "financial_classification.php passes php -l");
ok(lints($bsF), "balance_sheet.php passes php -l");

$mc = file_get_contents($mig);
ok(strpos($mc, "ADD COLUMN `liquidity`") !== false, "migration ADDs the liquidity column");
ok(strpos($mc, "ENUM('current','non_current')") !== false, "liquidity is ENUM(current,non_current)");
ok(strpos($mc, "SHOW COLUMNS FROM account_types LIKE 'liquidity'") !== false, "migration is idempotent (column guard)");
ok(strpos($mc, "liquidity IS NULL") !== false, "seed UPDATEs gated on liquidity IS NULL (re-runnable)");
ok(strpos($mc, "exit(1)") !== false, "migration exit(1)s on failure");

$fc = file_get_contents($fcF);
ok(strpos($fc, 'function fc_resolve_liquidity') !== false, "fc_resolve_liquidity() defined");
ok(strpos($fc, 'function fc_has_liquidity')     !== false, "fc_has_liquidity() defined");
ok(strpos($fc, 'function fc_liquidities')       !== false, "fc_liquidities() defined");

$bs = file_get_contents($bsF);
ok(strpos($bs, 'fc_resolve_liquidity') !== false, "Balance Sheet uses fc_resolve_liquidity()");
ok(strpos($bs, 'fc_has_liquidity')     !== false, "Balance Sheet guards on fc_has_liquidity()");
ok(strpos($bs, "strpos(\$hay, 'fixed')") === false, "old inline strpos('fixed') heuristic removed from page");

echo "\n\033[1m── 2. fc_resolve_liquidity() pure logic ──\033[0m\n";
ok(fc_resolve_liquidity('non_current', 'Cash', 'x') === 'non_current',                "stored 'non_current' wins");
ok(fc_resolve_liquidity('current', 'Fixed Assets', 'PPE') === 'current',              "stored 'current' overrides a 'fixed' name");
ok(fc_resolve_liquidity(null, 'Fixed Assets', '') === 'non_current',                  "null + 'Fixed Assets' → non_current (heuristic)");
ok(fc_resolve_liquidity(null, 'Current Assets', 'Cash at Bank') === 'current',        "null + current-ish name → current");
ok(fc_resolve_liquidity('', 'Property, Plant & Equipment', '') === 'non_current',     "empty + 'Property, Plant' → non_current");
ok(fc_resolve_liquidity(null, '', '') === 'current',                                  "null + nothing → current (conservative default)");
ok(fc_resolve_liquidity(null, 'Long-Term Loan', '') === 'non_current',                "null + 'Long-Term' → non_current");

echo "\n\033[1m── 3. Migration applied + seed correctness (live DB) ──\033[0m\n";
$hasCol = fc_has_liquidity($pdo);
ok($hasCol, "account_types.liquidity column exists (run the migration if this fails)");
if ($hasCol) {
    $nullCnt = (int) $pdo->query("SELECT COUNT(*) FROM account_types WHERE category IN ('asset','liability') AND liquidity IS NULL")->fetchColumn();
    ok($nullCnt === 0, "no asset/liability type left unseeded (found $nullCnt NULL)");

    $badInv = (int) $pdo->query("SELECT COUNT(*) FROM account_types WHERE category='asset' AND cash_flow_category='investing' AND liquidity <> 'non_current'")->fetchColumn();
    ok($badInv === 0, "asset types flagged 'investing' are non_current ($badInv mismatched)");

    $badFin = (int) $pdo->query("SELECT COUNT(*) FROM account_types WHERE category='liability' AND cash_flow_category='financing' AND liquidity <> 'non_current'")->fetchColumn();
    ok($badFin === 0, "liability types flagged 'financing' are non_current ($badFin mismatched)");

    $badEnum = (int) $pdo->query("SELECT COUNT(*) FROM account_types WHERE liquidity IS NOT NULL AND liquidity NOT IN ('current','non_current')")->fetchColumn();
    ok($badEnum === 0, "liquidity only ever holds current / non_current");

    // Equity/IS types are intentionally left NULL (n/a) — assert we didn't seed them.
    $isSeeded = (int) $pdo->query("SELECT COUNT(*) FROM account_types WHERE category IN ('equity','revenue','expense','cogs') AND liquidity IS NOT NULL")->fetchColumn();
    ok($isSeeded === 0, "equity/revenue/expense/cogs types left NULL (liquidity n/a)");
}

echo "\n\033[1m── 4. Data-driven Balance Sheet SELECT runs (GROUP BY / sql_mode) ──\033[0m\n";
try {
    $liqSel = $hasCol ? "at.liquidity AS liquidity," : "NULL AS liquidity,";
    $liqGrp = $hasCol ? ", at.liquidity" : "";
    $sql = "SELECT a.account_id, a.account_name, a.account_code, at.type_name AS type_name, at.category AS category, $liqSel
                   COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END),0) AS total_debit
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
         LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id AND je.entry_date <= ? AND je.status='posted'
             WHERE a.status='active' AND at.category IN ('asset','liability','equity')
          GROUP BY a.account_id, a.account_name, a.account_code, at.type_name, at.category$liqGrp
          ORDER BY a.account_code ASC";
    $st = $pdo->prepare($sql); $st->execute([date('Y-m-d')]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ok(true, "Balance Sheet liquidity SELECT executes cleanly (" . count($rows) . " account rows)");

    $bad = 0;
    foreach ($rows as $r) {
        $l = fc_resolve_liquidity($r['liquidity'] ?? null, $r['type_name'] ?? '', $r['account_name'] ?? '');
        if (!in_array($l, ['current', 'non_current'], true)) $bad++;
    }
    ok($bad === 0, "resolver returns a valid bucket for every BS account row ($bad invalid)");
} catch (Throwable $e) {
    ok(false, "BS SELECT failed: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
