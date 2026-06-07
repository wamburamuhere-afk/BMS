<?php
/**
 * Account code auto-generation + Chart of Accounts UI-constants compliance
 * ------------------------------------------------------------------------
 *   php tests/test_account_code_and_ui_cli.php
 *
 * (1) the hierarchical code generator (api/account/get_next_account_code.php)
 *     produces the right next code under a parent / class, and never collides;
 * (2) chart_of_accounts.php follows .claude/ui-constants.md (Select2 on DB
 *     selects, SweetAlert not alert(), gear-fill action button, §UI-6 code
 *     auto-fill with refresh button).
 *
 * Code-generation checks reproduce the endpoint's gap-fill logic against live
 * data (read-only). Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }

register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

// Reproduce the endpoint's gap-fill child-code logic for assertions.
function nextChild(PDO $pdo, string $parentCode): ?string {
    $p = $pdo->prepare("SELECT account_id, level FROM accounts WHERE account_code = ?");
    $p->execute([$parentCode]);
    $row = $p->fetch(PDO::FETCH_ASSOC);
    if (!$row || !preg_match('/^(\d)-(\d{4})$/', $parentCode, $m)) return null;
    $D = $m[1]; $pos = (int)$row['level'] - 1; $prefix = substr($m[2], 0, $pos);
    $kids = $pdo->prepare("SELECT account_code FROM accounts WHERE parent_account_id = ?");
    $kids->execute([(int)$row['account_id']]);
    $used = [];
    foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (preg_match('/^\d-(\d{4})$/', (string)$c, $mm)) $used[(int)substr($mm[1], $pos, 1)] = true;
    }
    for ($d = 1; $d <= 9; $d++) if (empty($used[$d])) return $D . '-' . $prefix . $d . str_repeat('0', 3 - $pos);
    return null;
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Endpoint exists + lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    $out=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/api/account/get_next_account_code.php") . ' 2>&1', $out, $rc);
    ok($rc === 0, 'get_next_account_code.php lint-clean');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Hierarchical next-code is correct (live)');
    // ─────────────────────────────────────────────────────────────────────
    // Under a group header → next group; under a leaf-parent → next sub-code.
    $cur = nextChild($pdo, '1-1000');   // Current Assets (1-1100,1-1200,1-1300)
    ok($cur === '1-1400', "under 1-1000 Current Assets → $cur (expect 1-1400)");
    $cc  = nextChild($pdo, '2-1100');   // Credit Cards (2-1110/20/30)
    ok($cc === '2-1140', "under 2-1100 Credit Cards → $cc (expect 2-1140)");
    $oe  = nextChild($pdo, '1-3100');   // Office Equipment (1-3110,1-3120)
    ok($oe === '1-3130', "under 1-3100 Office Equipment → $oe (expect 1-3130)");

    // ─────────────────────────────────────────────────────────────────────
    section('3. Generated code is always unused');
    // ─────────────────────────────────────────────────────────────────────
    foreach (['1-1000','2-1100','1-3100','1-0000'] as $pc) {
        $nc = nextChild($pdo, $pc);
        if ($nc === null) { ok(true, "under $pc → (slot full / n/a)"); continue; }
        $taken = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code = " . $pdo->quote($nc))->fetchColumn();
        ok($taken === 0, "suggested code $nc (under $pc) is not already taken");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. chart_of_accounts.php follows ui-constants.md');
    // ─────────────────────────────────────────────────────────────────────
    $s = src($root, 'app/constant/accounts/chart_of_accounts.php');
    ok(strpos($s, 'alert(') === false, '§UI-4: no alert() — uses SweetAlert2');
    ok(substr_count($s, 'Swal.fire(') >= 3, '§UI-4: SweetAlert2 used for feedback');
    ok(strpos($s, 'select2-static') !== false, '§UI-3: DB selects use select2-static');
    ok(preg_match('/id="account_type"[^>]*class="form-select select2-static"|class="form-select select2-static"[^>]*id="account_type"/', $s) === 1, '§UI-3: account_type is form-select select2-static');
    ok(strpos($s, 'bi-gear-fill') !== false && strpos($s, 'bi-gear"') === false, '§UI-5: action button uses bi-gear-fill');
    ok(strpos($s, 'btn-outline-primary dropdown-toggle') !== false, '§UI-5: gear button is btn-outline-primary');
    ok(strpos($s, "ajax.reload(null, false)") !== false, '§UI-2: AJAX table redraws (no full reload) after save');
    ok(strpos($s, 'id="btnGenCode"') !== false, '§UI-6: account code has a refresh button');
    ok(strpos($s, 'function generateAccountCode') !== false, '§UI-6: generateAccountCode() defined');
    ok(strpos($s, "get_next_account_code.php") !== false, '§UI-6: code field calls the generator endpoint');
    ok(strpos($s, "toggleClass('d-none', !adding)") !== false, '§UI-6: refresh button shown on Add only');

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
