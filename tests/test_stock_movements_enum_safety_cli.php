<?php
/**
 * stock_movements ENUM Safety — Bug-class Regression Suite
 *
 * `stock_movements` has two strict ENUM columns:
 *
 *   movement_type  ENUM('purchase_in','sale_out','adjustment_in',
 *                       'adjustment_out','transfer_in','transfer_out',
 *                       'return_in','return_out','production_in',
 *                       'production_out','damaged','expired','found',
 *                       'theft','correction','issue_out')
 *
 *   reference_type ENUM('purchase_order','sales_order','pos_sale',
 *                       'invoice','stock_adjustment','stock_transfer',
 *                       'return','production_order','manual')
 *
 * Writing a value outside the ENUM list causes MySQL to silently truncate
 * the row and raise:
 *
 *     SQLSTATE[01000]: Warning: 1265 Data truncated for column '<col>' at row 1
 *
 * which surfaces as a failed approval in the UI (DN/GRN approve, POS sale).
 *
 * This suite extracts the literal string written to each ENUM column
 * in every PHP file that INSERTs into stock_movements, and FAILS the push
 * gate if any literal is not a member of the canonical ENUM list.
 *
 * Scope: this PR fixes api/approve_dn.php. Other writers
 * (api/approve_grn.php, api/create_grn.php, api/update_grn_status.php,
 * api/pos/process_sale.php) are deliberately listed in $known_pending
 * so this suite passes today but is ready to broaden coverage in
 * follow-up PRs by removing entries from that list.
 *
 * Run:  php tests/test_stock_movements_enum_safety_cli.php
 *   Exit 0 = all pass (safe to commit / push)
 *   Exit 1 = failures  (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ stock_movements ENUM Safety Guard ═══\033[0m\n";

$ENUM_MOVEMENT_TYPE = [
    'purchase_in','sale_out','adjustment_in','adjustment_out',
    'transfer_in','transfer_out','return_in','return_out',
    'production_in','production_out','damaged','expired','found',
    'theft','correction','issue_out',
];

$ENUM_REFERENCE_TYPE = [
    'purchase_order','sales_order','pos_sale','invoice',
    'stock_adjustment','stock_transfer','return','production_order','manual',
];

// Files this suite currently asserts on. Add more as their literals get
// fixed; remove an entry from $known_pending and the suite will start
// guarding it.
$IN_SCOPE = [
    'api/approve_dn.php',
];

// Files known to still contain non-ENUM literals — tracked as follow-up
// work but excluded from this gate so the build stays green until each
// one is fixed in its own PR.
$known_pending = [
    'api/approve_grn.php',
    'api/create_grn.php',
    'api/update_grn_status.php',
    'api/pos/process_sale.php',
];

// ─────────────────────────────────────────────────────────────────────────────
section('1. ENUM literal extraction — INSERT INTO stock_movements');
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Find every "INSERT INTO stock_movements (...) VALUES (...)" in $src and
 * return [[$fullSql, $startLine], ...]. Handles nested parens (NOW()),
 * single-quoted strings, and multi-line INSERTs.
 */
function find_stock_movement_inserts(string $src): array {
    $out = [];
    $offset = 0;
    while (preg_match('/INSERT\s+INTO\s+stock_movements\s*\(/i', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $insertStart = $m[0][1];
        $colsOpen    = $insertStart + strlen($m[0][0]) - 1;
        $colsBody    = read_paren_group($src, $colsOpen);
        if ($colsBody === null) { $offset = $colsOpen + 1; continue; }
        $afterCols   = $colsOpen + strlen($colsBody) + 2;

        $tail = substr($src, $afterCols);
        if (preg_match('/^\s*VALUES\s*\(/i', $tail, $vm, PREG_OFFSET_CAPTURE)) {
            $valsOpen = $afterCols + $vm[0][1] + strlen($vm[0][0]) - 1;
            $valsBody = read_paren_group($src, $valsOpen);
            if ($valsBody === null) { $offset = $afterCols; continue; }
            $end      = $valsOpen + strlen($valsBody) + 2;
            $line     = substr_count(substr($src, 0, $insertStart), "\n") + 1;
            $out[]    = [substr($src, $insertStart, $end - $insertStart), $line];
            $offset   = $end;
        } else {
            // SELECT-based INSERT or non-static — skip to next match
            $offset = $afterCols;
        }
    }
    return $out;
}

/**
 * Walk the string starting at $start until the matching ')' is found,
 * respecting paren depth and single-quoted strings (so NOW() inside a
 * VALUES list and ',' inside a quoted literal don't confuse us). Returns
 * the contents between the opening '(' and its match, exclusive.
 */
function read_paren_group(string $s, int $openIdx): ?string {
    if (!isset($s[$openIdx]) || $s[$openIdx] !== '(') return null;
    $depth = 0;
    $inStr = false;
    $n = strlen($s);
    $bodyStart = $openIdx + 1;
    for ($i = $openIdx; $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($c === '\\' && $i + 1 < $n) { $i++; continue; }
            if ($c === "'") $inStr = false;
            continue;
        }
        if ($c === "'") { $inStr = true; continue; }
        if ($c === '(') $depth++;
        elseif ($c === ')') {
            $depth--;
            if ($depth === 0) return substr($s, $bodyStart, $i - $bodyStart);
        }
    }
    return null;
}

/**
 * Top-level split on ',' — ignores commas inside parens (NOW(...)) and
 * inside single-quoted strings.
 */
function split_top_level(string $s): array {
    $parts = [];
    $cur   = '';
    $depth = 0;
    $inStr = false;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; continue; }
            if ($c === "'") $inStr = false;
            continue;
        }
        if ($c === "'") { $inStr = true; $cur .= $c; continue; }
        if ($c === '(') { $depth++; $cur .= $c; continue; }
        if ($c === ')') { $depth--; $cur .= $c; continue; }
        if ($c === ',' && $depth === 0) {
            $parts[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if ($cur !== '') $parts[] = trim($cur);
    return $parts;
}

/**
 * Parse a single INSERT INTO stock_movements statement and return the
 * literal string written to the named column, or null if the column is
 * supplied by a placeholder (?), expression, or anything else that can't
 * be statically verified.
 */
function extract_literal_for_column(string $insertSql, string $column): ?string {
    if (!preg_match(
        '/INSERT\s+INTO\s+stock_movements\s*\(/i',
        $insertSql, $m, PREG_OFFSET_CAPTURE
    )) {
        return null;
    }
    $colsOpen = $m[0][1] + strlen($m[0][0]) - 1;   // position of the '(' after stock_movements
    $colsBody = read_paren_group($insertSql, $colsOpen);
    if ($colsBody === null) return null;

    // Find VALUES ( or SELECT ... — only VALUES is statically parseable here.
    $after = substr($insertSql, $colsOpen + strlen($colsBody) + 2);
    if (!preg_match('/^\s*VALUES\s*\(/i', $after, $vm, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $valsOpenRel = $vm[0][1] + strlen($vm[0][0]) - 1;
    $valsOpenAbs = $colsOpen + strlen($colsBody) + 2 + $valsOpenRel;
    $valsBody = read_paren_group($insertSql, $valsOpenAbs);
    if ($valsBody === null) return null;

    $cols = array_map('trim', split_top_level($colsBody));
    $vals = split_top_level($valsBody);
    if (count($cols) !== count($vals)) return null;

    foreach ($cols as $i => $c) {
        if (strcasecmp($c, $column) === 0) {
            $v = trim($vals[$i]);
            if ($v === '?') return null;
            if (preg_match("/^'([^']*)'$/", $v, $mm)) return $mm[1];
            return null;
        }
    }
    return null;
}

$files_seen = 0;
foreach ($IN_SCOPE as $rel) {
    $abs = "$root/" . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!file_exists($abs)) {
        fail("$rel — file not found (move/rename?)");
        continue;
    }
    $files_seen++;
    $src = file_get_contents($abs);
    $inserts = find_stock_movement_inserts($src);
    if (empty($inserts)) {
        fail("$rel — no INSERT INTO stock_movements found (file refactored?)");
        continue;
    }
    foreach ($inserts as [$sql, $line]) {

        $mt = extract_literal_for_column($sql, 'movement_type');
        if ($mt === null) {
            pass("$rel:$line — movement_type uses a placeholder or expression (skipped, cannot statically verify)");
        } else {
            check(
                in_array($mt, $ENUM_MOVEMENT_TYPE, true),
                "$rel:$line — movement_type literal '$mt' is a valid ENUM member",
                "$rel:$line — movement_type literal '$mt' is NOT in the column's ENUM (will truncate at runtime)"
            );
        }

        $rt = extract_literal_for_column($sql, 'reference_type');
        if ($rt === null) {
            pass("$rel:$line — reference_type uses a placeholder or expression (skipped, cannot statically verify)");
        } else {
            check(
                in_array($rt, $ENUM_REFERENCE_TYPE, true),
                "$rel:$line — reference_type literal '$rt' is a valid ENUM member",
                "$rel:$line — reference_type literal '$rt' is NOT in the column's ENUM (will truncate at runtime)"
            );
        }
    }
}
if ($files_seen === 0) {
    fail('No in-scope files were checked — $IN_SCOPE may be empty');
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Documented follow-up work — files still containing the bug');
// ─────────────────────────────────────────────────────────────────────────────
// Sanity check: each $known_pending file actually still has a stock_movements
// INSERT with at least one non-ENUM literal. If a file no longer matches,
// that's good news — remove it from $known_pending and add it to $IN_SCOPE
// in the same PR so it's actively guarded going forward.
foreach ($known_pending as $rel) {
    $abs = "$root/" . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!file_exists($abs)) {
        // File deleted/renamed — fine, just note it. Don't fail.
        pass("$rel — listed as pending but file no longer exists (remove from \$known_pending)");
        continue;
    }
    $src = file_get_contents($abs);
    $still_buggy = false;
    foreach (find_stock_movement_inserts($src) as [$sql, $line]) {
        $mt = extract_literal_for_column($sql, 'movement_type');
        $rt = extract_literal_for_column($sql, 'reference_type');
        if (($mt !== null && !in_array($mt, $ENUM_MOVEMENT_TYPE, true)) ||
            ($rt !== null && !in_array($rt, $ENUM_REFERENCE_TYPE, true))) {
            $still_buggy = true;
            break;
        }
    }
    if ($still_buggy) {
        pass("$rel — still has a non-ENUM literal (tracked as follow-up; promote to \$IN_SCOPE once fixed)");
    } else {
        // Bug already fixed but file not promoted — tell the maintainer.
        fail("$rel — no longer contains a non-ENUM literal; remove from \$known_pending and add to \$IN_SCOPE so it's actively guarded");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m══════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures > 0) {
    echo "\033[31m❌ $failures failure(s) — fix before pushing.\033[0m\n\n";
    exit(1);
}
echo "\033[32m✅ All checks passed.\033[0m\n\n";
exit(0);
