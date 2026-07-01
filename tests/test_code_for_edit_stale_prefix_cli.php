<?php
/**
 * codeForEdit() — stale company-prefix regression guard
 * -------------------------------------------------------
 * Boss rule: changing Company Profile's "Document Code Prefix" must ripple
 * through to EXISTING records the next time each is edited — not freeze them
 * on whatever prefix was active when they were created.
 *
 * Before this fix, codeForEdit() only recognised a code as "auto-generated,
 * eligible for re-coding" if it was blank or matched the entity's pre-prefix
 * legacy pattern. A record already in PREFIX-TYPE-NNNN shape but under a
 * *previous* prefix fell through to "manual/custom code, leave it alone" —
 * so it never adopted a newly-changed prefix, even on edit.
 *
 * core/code_generator.php now also matches ANY-PREFIX-TYPE-NNNN (not just the
 * current prefix) as auto-generated for that TYPE, so it gets re-coded to the
 * current prefix on edit. Genuinely custom/manual codes (that don't fit the
 * PREFIX-TYPE-NNNN shape at all) must still be left untouched.
 *
 * Run: php tests/test_code_for_edit_stale_prefix_cli.php
 * Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/code_generator.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ codeForEdit() — Stale Company-Prefix Guard ═══\033[0m\n";

$TYPE = 'ZZTEST'; // sentinel type — cannot collide with a real entity's sequence
$currentPrefix = companyCodePrefix($pdo);
echo "  \033[90m· live company_code_prefix = $currentPrefix\033[0m\n";

function lastNo(PDO $pdo, string $type): int {
    $st = $pdo->prepare("SELECT last_no FROM code_sequences WHERE sequence_name = ?");
    $st->execute([$type]);
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

try {
    section('1. Already current prefix — left as-is, no number burned');
    $before = lastNo($pdo, $TYPE);
    $currentFormatCode = "$currentPrefix-$TYPE-0007";
    $result = codeForEdit($pdo, $TYPE, $currentFormatCode);
    ($result === $currentFormatCode) ? pass("unchanged: $result") : fail("expected unchanged, got $result");
    (lastNo($pdo, $TYPE) === $before) ? pass('sequence NOT incremented') : fail('sequence incremented — should not burn a number for an already-current code');

    section('2. Stale prefix, same TYPE shape — re-coded to the current prefix');
    $before = lastNo($pdo, $TYPE);
    $staleCode = "OLDPF-$TYPE-0042"; // 5-char prefix — the real max (company_code_prefix caps at 5)
    $result = codeForEdit($pdo, $TYPE, $staleCode);
    ($result !== $staleCode) ? pass("re-coded away from the stale prefix: $staleCode -> $result") : fail('stale-prefix code was left unchanged — the bug is still present');
    (strpos($result, "$currentPrefix-$TYPE-") === 0) ? pass('new code carries the CURRENT prefix') : fail("new code does not carry current prefix — got $result");
    (lastNo($pdo, $TYPE) === $before + 1) ? pass('sequence incremented by exactly 1') : fail('sequence did not advance as expected');

    section('3. A different stale prefix, still same shape — also re-coded');
    $result2 = codeForEdit($pdo, $TYPE, "OLD-$TYPE-0001");
    (strpos($result2, "$currentPrefix-$TYPE-") === 0 && $result2 !== $result)
        ? pass("re-coded to a fresh, distinct number: $result2")
        : fail("expected a fresh distinct code, got $result2");

    section('4. Blank current — still generates fresh code (unchanged legacy behaviour)');
    $resultBlank = codeForEdit($pdo, $TYPE, '');
    (strpos($resultBlank, "$currentPrefix-$TYPE-") === 0) ? pass("blank -> $resultBlank") : fail("blank did not generate a current-prefix code — got $resultBlank");

    section('5. Legacy pre-prefix pattern — still re-coded (unchanged legacy behaviour)');
    $resultLegacy = codeForEdit($pdo, $TYPE, "$TYPE-99", "$TYPE-\\d+");
    (strpos($resultLegacy, "$currentPrefix-$TYPE-") === 0) ? pass("legacy '$TYPE-99' -> $resultLegacy") : fail("legacy pattern did not re-code — got $resultLegacy");

    section('6. Genuinely manual/custom code — still respected, untouched');
    $before = lastNo($pdo, $TYPE);
    $manual = 'MyOwnReference-2026';
    $resultManual = codeForEdit($pdo, $TYPE, $manual);
    ($resultManual === $manual) ? pass("manual code left untouched: $manual") : fail("manual code was overwritten — got $resultManual");
    (lastNo($pdo, $TYPE) === $before) ? pass('sequence NOT incremented for a manual code') : fail('sequence incremented for a manual code — should never happen');

} finally {
    $pdo->prepare("DELETE FROM code_sequences WHERE sequence_name = ?")->execute([$TYPE]);
    $pdo->prepare("DELETE FROM code_change_log WHERE sequence_name = ?")->execute([$TYPE]);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM code_sequences WHERE sequence_name = '$TYPE'")->fetchColumn();
    ($left === 0) ? pass('test sequence row cleaned up — no data left behind') : fail('test sequence row NOT cleaned up');
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: \033[31m$failures\033[0m\n";
exit($failures > 0 ? 1 : 0);
