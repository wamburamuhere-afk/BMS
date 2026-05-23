<?php
/**
 * Document Categories — Cleanup Regression Test Suite
 *
 * Guards the document-library category cleanup:
 *   - "+" button removed from the Upload Document modal
 *   - Add Category modal + openAddCategoryModal JS removed
 *   - api/document/save_category.php deleted (no ad-hoc category creation)
 *   - migrations/2026_05_22_consolidate_document_categories.php
 *     idempotently seeds the 5 canonical categories and re-points orphan
 *     documents (works on empty, partial, or fully-seeded databases)
 *
 * Run:  php tests/test_document_categories_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 *
 * Sections 1-4 need no database and run everywhere, including CI.
 * Section 5 is a live-DB smoke test — it runs only when includes/config.php
 * is present (i.e. on a real install) and is skipped cleanly otherwise.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;
$skips    = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function skip(string $m): void    { global $skips;    $skips++;    echo "  \033[33m⊘\033[0m  $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

$LIB_REL = 'app/constant/document/document_library.php';
$MIG_REL = 'migrations/2026_05_22_consolidate_document_categories.php';
$API_REL = 'api/document/save_category.php';

$CANONICAL = [
    'Legal & Contracts',
    'Financial Reports',
    'HR & Employment',
    'Compliance & Regulatory',
    'General Documents',
];

echo "\n\033[1m═══ Document Categories — Cleanup Regression Suite ═══\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. PHP syntax (php -l)');
// ─────────────────────────────────────────────────────────────────────────────
foreach ([$LIB_REL, $MIG_REL] as $rel) {
    $path = "$root/$rel";
    if (!file_exists($path)) { fail("Missing file: $rel"); continue; }
    if (!function_exists('shell_exec')) { skip("shell_exec disabled — cannot lint $rel"); continue; }
    $out = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    check(!preg_match('/(Parse|Fatal) error/i', $out),
        "Syntax OK: $rel",
        "Syntax error in $rel —\n     " . trim($out));
}

$lib = file_exists("$root/$LIB_REL") ? file_get_contents("$root/$LIB_REL") : '';
$mig = file_exists("$root/$MIG_REL") ? file_get_contents("$root/$MIG_REL") : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. document_library.php — "+" button and Add Category modal removed');
// ─────────────────────────────────────────────────────────────────────────────
check(!str_contains($lib, "onclick=\"openAddCategoryModal()\""),
    'document_library.php: "+" button onclick removed',
    'document_library.php: "+" button onclick still present');
check(!str_contains($lib, 'bi bi-plus-lg'),
    'document_library.php: bi-plus-lg icon removed from category area',
    'document_library.php: bi-plus-lg icon still present');
check(!str_contains($lib, 'id="addCategoryModal"'),
    'document_library.php: addCategoryModal block removed',
    'document_library.php: addCategoryModal block still present');
check(!str_contains($lib, 'id="addCategoryForm"'),
    'document_library.php: addCategoryForm removed',
    'document_library.php: addCategoryForm still present');
check(!str_contains($lib, 'function openAddCategoryModal'),
    'document_library.php: openAddCategoryModal JS function removed',
    'document_library.php: openAddCategoryModal still defined');
check(!str_contains($lib, '/api/document/save_category.php'),
    'document_library.php: no longer calls api/document/save_category.php',
    'document_library.php: still calls save_category.php');

// ─────────────────────────────────────────────────────────────────────────────
section('3. api/document/save_category.php — endpoint removed');
// ─────────────────────────────────────────────────────────────────────────────
check(!file_exists("$root/$API_REL"),
    'api/document/save_category.php is deleted (ad-hoc creation no longer possible)',
    'api/document/save_category.php still exists — anyone can still POST to it');

// ─────────────────────────────────────────────────────────────────────────────
section('4. Migration — 5 canonical categories, name-based, idempotent');
// ─────────────────────────────────────────────────────────────────────────────
foreach ($CANONICAL as $name) {
    check(str_contains($mig, "'$name'"),
        "migration: lists canonical category '$name'",
        "migration: canonical category '$name' missing");
}
check(str_contains($mig, "SELECT id FROM document_categories WHERE category_name = ?"),
    'migration: checks existence by name before inserting (idempotent — safe on any DB)',
    'migration: does not check existence by name — would create duplicates');
check(str_contains($mig, 'UPDATE documents SET category_id = ?'),
    'migration: re-points documents from removed rows (data preserved)',
    'migration: does not re-point documents — could orphan them');
check(str_contains($mig, 'DELETE FROM document_categories'),
    'migration: deletes non-canonical rows',
    'migration: does not clean up non-canonical rows');
check(str_contains($mig, 'beginTransaction'),
    'migration: wraps the cleanup in a transaction (atomic)',
    'migration: cleanup is not atomic');
check(str_contains($mig, 'exit(1)'),
    'migration: exit(1) on failure (halts deploy)',
    'migration: missing exit(1) on failure');

// ─────────────────────────────────────────────────────────────────────────────
// Section 5 bootstrap — load $pdo from roots.php only if config.php exists.
// ─────────────────────────────────────────────────────────────────────────────
$pdo          = null;
$dbSkipReason = '';
if (!file_exists("$root/includes/config.php")) {
    $dbSkipReason = 'includes/config.php not present (CI / fresh checkout)';
} else {
    set_error_handler(static fn() => true);
    try {
        ob_start();
        require_once "$root/roots.php";
        while (ob_get_level() > 0) { ob_end_clean(); }
    } catch (Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $dbSkipReason = 'DB bootstrap failed: ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    if ($dbSkipReason === '' && !($pdo instanceof PDO)) {
        $dbSkipReason = 'no PDO handle after bootstrap';
    }
}

section('5. Live-DB smoke test (after migration runs)');
if ($dbSkipReason !== '') {
    skip("$dbSkipReason — live-DB smoke test skipped");
} else {
    try {
        $names = $pdo->query("SELECT category_name FROM document_categories")
                     ->fetchAll(PDO::FETCH_COLUMN);
        sort($names);
        $expected = $CANONICAL;
        sort($expected);

        // Every canonical name must be present.
        foreach ($expected as $name) {
            check(in_array($name, $names, true),
                "live DB: canonical category '$name' is present",
                "live DB: canonical category '$name' MISSING — migration not run?");
        }
        // No non-canonical names should remain.
        $extras = array_diff($names, $expected);
        check(empty($extras),
            'live DB: only canonical categories remain (no junk / duplicates)',
            'live DB: extra categories still present — ' . implode(', ', $extras));

        // No orphan documents — every category_id either NULL or points at an existing row.
        $orphans = (int) $pdo->query(
            "SELECT COUNT(*) FROM documents d
             WHERE d.category_id IS NOT NULL
               AND d.category_id NOT IN (SELECT id FROM document_categories)"
        )->fetchColumn();
        check($orphans === 0,
            'live DB: no orphan documents (every category_id is valid or NULL)',
            "live DB: $orphans orphan document(s) still reference a deleted category");
    } catch (Throwable $e) {
        fail('Live-DB smoke test errored: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes test(s) passed";
    echo $skips ? " ($skips skipped) — safe to push.\033[0m\n" : " — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed  |  $skips skipped\033[0m\n";
echo "\033[31mFix the errors above — DO NOT push.\033[0m\n";
echo "\033[1m════════════════════════════════════════\033[0m\n\n";
exit(1);
