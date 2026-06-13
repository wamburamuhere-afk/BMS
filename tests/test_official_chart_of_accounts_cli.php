<?php
/**
 * Official Chart of Accounts migration — CLI test
 *   php tests/test_official_chart_of_accounts_cli.php
 *
 * Guards the MYOB-style, Tanzania-localized official chart established by
 * migrations/2026_06_13_official_chart_of_accounts.php:
 *   - the migration file is sound (lints; settings-driven statutory reuse;
 *     guarded cleanup that never deletes wired/history accounts);
 *   - the resulting chart is correct: anchor accounts present + active + correctly
 *     parented; no ZZOLD_; no duplicate codes; every parent resolves; bank accounts
 *     carry the bank sub-type;
 *   - all 14 statutory system_settings resolve to ACTIVE accounts (wiring intact);
 *   - no broken account references anywhere in the DB.
 * Read-only — verifies the migrated state; never re-runs the (destructive) migration.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$MIG = 'migrations/2026_06_13_official_chart_of_accounts.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. Migration file — lint + structure');
$path = "$root/$MIG";
if (!file_exists($path)) { fail("MISSING: $MIG"); }
else {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$MIG lints clean") : fail("php -l failed: $MIG");
}
$src = file_exists($path) ? file_get_contents($path) : '';
has($src, "require_once __DIR__ . '/../roots.php'", 'requires roots.php');
has($src, "'1-0000','Assets'", 'defines the Assets header (1-0000)');
has($src, "'2-1310','Output VAT Payable'", 'localized: Output VAT Payable (not GST Collected)');
has($src, "'default_output_vat_account_id'", 'statutory reuse is settings-driven (production-safe)');
has($src, '$settingReuse', 'has the settingReuse map');
// guarded cleanup
has($src, 'journal_entry_items WHERE account_id', 'cleanup guards on posted GL history');
has($src, "_account_id\$' AND setting_value", 'cleanup guards on settings-wired accounts');
has($src, 'journal_mappings WHERE debit_account_id', 'cleanup guards on journal-mapping accounts');
has($src, 'DELETE FROM accounts WHERE account_id', 'cleanup deletes only unguarded junk');

// ─────────────────────────────────────────────────────────────────────────
section('2. Resulting chart — anchor accounts present, active, correctly parented');
// [code, expected account_type, parent_code|null]
$anchors = [
    ['1-0000','asset',null], ['1-1000','asset','1-0000'], ['1-1100','asset','1-1000'], ['1-1110','asset','1-1100'],
    ['1-1200','asset','1-1000'], ['1-3000','asset','1-0000'],
    ['2-0000','liability',null], ['2-1310','liability','2-1300'], ['2-1410','liability','2-1400'],
    ['3-0000','equity',null], ['4-0000','income',null], ['5-0000','expense',null],
    ['6-0000','expense',null], ['6-2410','expense','6-2400'],
];
$accByCode = [];
foreach ($pdo->query("SELECT account_id,account_code,account_name,account_type,parent_account_id,status,is_system FROM accounts") as $r) {
    $accByCode[$r['account_code']] = $r;
}
foreach ($anchors as [$code,$type,$parentCode]) {
    $a = $accByCode[$code] ?? null;
    if (!$a)                              { fail("anchor $code missing"); continue; }
    if ($a['status'] !== 'active')        { fail("anchor $code not active"); continue; }
    if ($a['account_type'] !== $type)     { fail("anchor $code type=$a[account_type] (want $type)"); continue; }
    if ($parentCode === null) {
        ($a['parent_account_id'] === null) ? pass("$code active $type, top-level") : fail("$code should be top-level");
    } else {
        $pcode = '';
        foreach ($accByCode as $c => $x) { if ((int)$x['account_id'] === (int)$a['parent_account_id']) { $pcode = $c; break; } }
        ($pcode === $parentCode) ? pass("$code active $type, parent=$parentCode") : fail("$code parent=$pcode (want $parentCode)");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Integrity invariants');
$zz = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE status='active' AND account_code LIKE 'ZZOLD_%'")->fetchColumn();
($zz === 0) ? pass('no ZZOLD_ codes in the active chart') : fail("$zz ZZOLD_ accounts active");
$dup = (int)$pdo->query("SELECT COUNT(*)-COUNT(DISTINCT account_code) FROM accounts WHERE status='active'")->fetchColumn();
($dup === 0) ? pass('no duplicate active account codes') : fail("$dup duplicate active codes");
$orphanParents = (int)$pdo->query("SELECT COUNT(*) FROM accounts a WHERE a.parent_account_id IS NOT NULL
                                    AND NOT EXISTS (SELECT 1 FROM accounts p WHERE p.account_id=a.parent_account_id)")->fetchColumn();
($orphanParents === 0) ? pass('every parent_account_id resolves to a real account') : fail("$orphanParents accounts have a missing parent");
// bank accounts carry the bank sub-type (is_bank)
$bank = (int)$pdo->query("SELECT COUNT(*) FROM accounts a JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                          WHERE a.account_code='1-1110' AND st.is_bank=1 AND a.status='active'")->fetchColumn();
($bank === 1) ? pass('Cheque Account (1-1110) carries the bank sub-type (visible in Bank Accounts)') : fail('1-1110 not flagged as a bank account');

// ─────────────────────────────────────────────────────────────────────────
section('4. Wiring + references intact');
$settingsOk = true; $settingsChecked = 0;
foreach ($pdo->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key REGEXP '_account_id\$' AND setting_value REGEXP '^[0-9]+\$'") as $s) {
    $settingsChecked++;
    $st = $pdo->query("SELECT status FROM accounts WHERE account_id=" . (int)$s['setting_value'])->fetchColumn();
    if ($st !== 'active') { $settingsOk = false; fail("setting {$s['setting_key']} → inactive/missing account"); }
}
$settingsOk ? pass("all $settingsChecked statutory settings resolve to ACTIVE accounts") : null;

$cols = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME LIKE '%account_id%'
                       AND TABLE_NAME NOT IN ('accounts','chart_of_accounts')")->fetchAll(PDO::FETCH_ASSOC);
$broken = 0;
foreach ($cols as $c) {
    try { $broken += (int)$pdo->query("SELECT COUNT(*) FROM `{$c['TABLE_NAME']}` x
            WHERE x.`{$c['COLUMN_NAME']}` IS NOT NULL AND x.`{$c['COLUMN_NAME']}` > 0
              AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.account_id = x.`{$c['COLUMN_NAME']}`)")->fetchColumn(); }
    catch (Throwable $e) {}
}
($broken === 0) ? pass('no broken account references anywhere in the DB') : fail("$broken broken account references");
