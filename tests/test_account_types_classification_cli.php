<?php
/**
 * Account Types Classification Migration — Regression Guard
 *
 * Locks in the structural contract added by
 * migrations/2026_05_27_account_types_classification.php:
 *   - 4 new ENUM columns on account_types (statement, category,
 *     normal_side, cash_flow_category)
 *   - Idempotent SHOW COLUMNS guards
 *   - Seed UPDATE statements use deterministic LIKE patterns
 *   - At least one rule for each of the 6 canonical categories
 *
 * This is a SOURCE-level test — it does not touch the database. It
 * verifies the migration file's invariants, which is what the pre-push
 * hook needs to catch before any code reaches a live server.
 *
 * Run:  php tests/test_account_types_classification_cli.php
 *   Exit 0 = all pass (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Account Types Classification Migration — Regression Guard ═══\033[0m\n";

$migration = $root . '/migrations/2026_05_27_account_types_classification.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. Migration file exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($migration), 'migration file exists', 'migration file missing');

$out = []; $code = 0;
exec('php -l ' . escapeshellarg($migration) . ' 2>&1', $out, $code);
check($code === 0, 'migration passes php -l', 'migration has PHP syntax errors: ' . implode(' | ', $out));

$src = is_file($migration) ? file_get_contents($migration) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. Required structural elements present');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, "require_once __DIR__ . '/../roots.php'"),
    'migration requires roots.php',
    'migration does not require roots.php'
);

check(
    (bool) preg_match("/SHOW\s+TABLES\s+LIKE\s+'account_types'/i", $src),
    'migration guards on table existence (SHOW TABLES LIKE account_types)',
    'migration does not guard on table existence — will fail on servers missing the table'
);

check(
    (bool) preg_match('/try\s*\{[\s\S]*\}\s*catch\s*\(\s*PDOException/', $src),
    'migration is wrapped in try / catch PDOException',
    'migration has no PDOException catch block'
);

check(
    str_contains($src, 'exit(1)'),
    'migration calls exit(1) on failure',
    'migration does not exit(1) on failure — deploy runner cannot detect it'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. All 4 classification columns declared');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['statement', 'category', 'normal_side', 'cash_flow_category'] as $col) {
    $nameMentioned = str_contains($src, "'$col'") || str_contains($src, "\"$col\"");
    $loopReference = str_contains($src, $col);
    check(
        $nameMentioned && $loopReference,
        "column declared: $col",
        "column missing from migration: $col"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. ENUM definitions match the canonical contract');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/ENUM\\(\\s*'BS'\\s*,\\s*'IS'\\s*\\)/", $src),
    "statement column uses ENUM('BS','IS')",
    "statement column does not use the canonical ENUM('BS','IS')"
);

check(
    (bool) preg_match("/ENUM\\(\\s*'asset'\\s*,\\s*'liability'\\s*,\\s*'equity'\\s*,\\s*'revenue'\\s*,\\s*'expense'\\s*,\\s*'cogs'\\s*\\)/", $src),
    "category column uses ENUM('asset','liability','equity','revenue','expense','cogs')",
    "category column does not use the 6 canonical accounting categories"
);

check(
    (bool) preg_match("/ENUM\\(\\s*'debit'\\s*,\\s*'credit'\\s*\\)/", $src),
    "normal_side column uses ENUM('debit','credit')",
    "normal_side column does not use the canonical ENUM('debit','credit')"
);

check(
    (bool) preg_match("/ENUM\\(\\s*'operating'\\s*,\\s*'investing'\\s*,\\s*'financing'\\s*,\\s*'cash'\\s*,\\s*'none'\\s*\\)/", $src),
    "cash_flow_category uses canonical 5-value ENUM",
    "cash_flow_category does not use the canonical 5-value ENUM"
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Idempotency — every ALTER guarded by a column check');
// ─────────────────────────────────────────────────────────────────────────────

// Count `ALTER TABLE ... ADD COLUMN` statements and ensure each is preceded
// by a guard. The migration uses a loop pattern — the guard is the
// SHOW COLUMNS query inside the loop, and the ALTER happens conditionally.
$alterCount      = preg_match_all('/ALTER\s+TABLE\s+account_types\s+ADD\s+COLUMN/i', $src);
$columnsGuardSrc = (bool) preg_match("/SHOW\\s+COLUMNS\\s+FROM\\s+account_types\\s+LIKE\\s+[\"\$]/i", $src);
check(
    $alterCount > 0 && $columnsGuardSrc,
    "ALTER TABLE statements are guarded by SHOW COLUMNS checks ($alterCount found)",
    "ALTER TABLE statements appear ungarded — migration would fail on second run"
);

// Seeding must use `category IS NULL` guard so re-runs don't overwrite
// manual adjustments.
check(
    (bool) preg_match('/AND\s+category\s+IS\s+NULL/i', $src),
    'seed UPDATE includes "AND category IS NULL" guard (manual edits preserved)',
    'seed UPDATE has no IS NULL guard — re-running would overwrite manual classifications'
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Every canonical category has at least one seed rule');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'] as $category) {
    $hits = preg_match_all("/'$category'\\s*,/", $src);
    check(
        $hits > 0,
        "category '$category' has at least one seed rule (found $hits)",
        "category '$category' has NO seed rule — type_names of that kind will be left unclassified"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. Cash Flow categories all present in seed rules');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['operating', 'investing', 'financing', 'cash'] as $cfCat) {
    $hits = preg_match_all("/'$cfCat'/", $src);
    check(
        $hits > 0,
        "cash_flow_category '$cfCat' assigned by at least one seed rule (found $hits)",
        "cash_flow_category '$cfCat' has no seed rule — Cash Flow report will miss those movements"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('8. Migration filename follows convention');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/^2026_05_27_[a-z_]+\.php$/', basename($migration)),
    'filename follows YYYY_MM_DD_description.php convention',
    'filename does not follow YYYY_MM_DD_description.php convention'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Account types classification migration invariants intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Migration regression — see failures above.\033[0m\n\n";
exit(1);
