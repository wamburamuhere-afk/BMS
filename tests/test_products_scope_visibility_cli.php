<?php
/**
 * BMS — Products list project-scope VISIBILITY guard (behavioural)
 *
 * The sibling guard (test_scope_enforcement_cli.php) greps the source for a
 * scope marker. That is necessary but not sufficient: products.php once carried
 * a hand-rolled block that pushed a literal '0' condition for non-admins with no
 * projects, producing `WHERE 1=1 AND 0` and a totally blank page — including the
 * global (project_id IS NULL) catalogue. The grep guard passed the whole time.
 *
 * This test asserts the SQL the page actually builds, and the row counts it
 * actually returns, for each scope state. Read-only: no writes, no fixtures.
 *
 * Run:
 *   php tests/test_products_scope_visibility_cli.php
 *
 * Exit 0 = all checks pass.  Exit 1 = at least one check failed.
 */

// ── Child mode ────────────────────────────────────────────────────────────
// Renders the real products.php as a real non-admin with zero projects and
// reports what the page actually produced. Runs in its own process because the
// page bootstraps a session, emits a full HTML document, and may exit() on a
// permission redirect. Parent consumes the JSON on the last line.
if (($argv[1] ?? '') === '--render-child') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/bms/products';
    $_SERVER['SCRIPT_NAME']    = '/bms/index.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/roots.php';

    $uid = (int)($argv[2] ?? 0);
    $u = $pdo->prepare("SELECT username, role_id FROM users WHERE user_id = ?");
    $u->execute([$uid]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    $_SESSION['user_id']  = $uid;
    $_SESSION['username'] = $row['username'];
    $_SESSION['role_id']  = (int)$row['role_id'];
    loadUserScope($uid);

    ob_start();
    include dirname(__DIR__) . '/app/bms/product/products.php';
    ob_end_clean();

    $tagged = count(array_filter($products, fn($p) => $p['project_id'] !== null));
    file_put_contents('php://stdout', "\n__RESULT__" . json_encode([
        'is_admin'    => $_SESSION['scope']['is_admin'],
        'projects'    => $_SESSION['scope']['projects'],
        'total_count' => (int)$total_count,
        'rows'        => count($products),
        'tagged'      => $tagged,
    ]) . "\n");
    exit(0);
}

require_once dirname(__DIR__) . '/roots.php';

$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

/** Mirror the products.php base filters so counts are comparable. */
const PAGE_FILTERS = "p.is_service = 0 AND p.status = 'active'";

function setScope(bool $isAdmin, array $projects): void {
    $_SESSION['scope'] = [
        'is_admin' => $isAdmin, 'projects' => $projects,
        'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [],
        'computed_at' => time(),
    ];
}

function countWithScope(PDO $pdo, string $frag): int {
    $sql = "SELECT COUNT(DISTINCT p.product_id) FROM products p WHERE 1=1 AND " . PAGE_FILTERS . " $frag";
    return (int)$pdo->query($sql)->fetchColumn();
}

function countRaw(PDO $pdo, string $where): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM products p WHERE " . PAGE_FILTERS . " AND ($where)")->fetchColumn();
}

echo "\n\033[1m═══ Products list — project scope visibility ═══\033[0m\n";

// Ground truth, computed independently of the helper.
$globals = countRaw($pdo, 'p.project_id IS NULL');
$anyTagged = $pdo->query("SELECT p.project_id FROM products p WHERE " . PAGE_FILTERS . " AND p.project_id IS NOT NULL LIMIT 1")->fetchColumn();

head('Admin — unfiltered');
setScope(true, []);
$frag = scopeFilterSqlNullable('project', 'p');
$frag === '' ? ok("admin gets no scope fragment") : bad("admin got a fragment: '$frag'");

head('Non-admin with NO projects — must still see the global catalogue');
setScope(false, []);
$frag = scopeFilterSqlNullable('project', 'p');
$n = countWithScope($pdo, $frag);

// The regression: this used to be `AND 0` and returned nothing.
strpos($frag, ' AND 0') === false
    ? ok('fragment is not a blanket AND 0 deny')
    : bad("REGRESSION: zero-project user gets a blanket deny — '$frag'");

$n === $globals
    ? ok("sees exactly the $globals global (project_id IS NULL) product(s)")
    : bad("expected $globals global product(s), got $n");

if ($globals > 0) {
    $n > 0 ? ok('global catalogue is not blank') : bad('REGRESSION: products list is blank despite ' . $globals . ' global product(s)');
} else {
    echo "  \033[33m—\033[0m skipped blank-page check: no global products in this DB\n";
}

// A zero-project user must never see a project-tagged row.
$taggedVisible = (int)$pdo->query(
    "SELECT COUNT(*) FROM products p WHERE " . PAGE_FILTERS . " AND p.project_id IS NOT NULL $frag"
)->fetchColumn();
$taggedVisible === 0 ? ok('no project-tagged products leak to a zero-project user')
                     : bad("$taggedVisible project-tagged product(s) leaked");

head('Non-admin WITH a project — globals plus that project');
if ($anyTagged === false || $anyTagged === null) {
    echo "  \033[33m—\033[0m skipped: no project-tagged products in this DB\n";
} else {
    $pid = (int)$anyTagged;
    setScope(false, [$pid]);
    $frag = scopeFilterSqlNullable('project', 'p');
    $n = countWithScope($pdo, $frag);
    $expected = countRaw($pdo, "p.project_id IS NULL OR p.project_id = $pid");
    $n === $expected ? ok("project $pid: sees $expected (globals + own project)")
                     : bad("project $pid: expected $expected, got $n");
}

head('Grant-all override — unfiltered');
setScope(false, ['*']);
$frag = scopeFilterSqlNullable('project', 'p');
$frag === '' ? ok("grant-all ['*'] yields no fragment") : bad("grant-all got a fragment: '$frag'");

head('Fragment shape — safe to append after $conditions');
setScope(false, []);
$frag = scopeFilterSqlNullable('project', 'p');
preg_match('/^\s*AND\s/', $frag)
    ? ok('fragment carries its own leading AND (must NOT be pushed into $conditions)')
    : bad("fragment lacks a leading AND: '$frag'");

head('END-TO-END — render the real page as a zero-project non-admin');
// Pick a real non-admin who has products view permission and no project assignments.
$candidate = $pdo->query("
    SELECT u.user_id
    FROM users u
    JOIN role_permissions rp ON rp.role_id = u.role_id
    JOIN permissions pm      ON pm.permission_id = rp.permission_id
    WHERE u.role_id != 1
      AND pm.page_key = 'products' AND rp.can_view = 1
      AND (SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.user_id) = 0
    LIMIT 1
")->fetchColumn();

if ($candidate === false || $candidate === null) {
    echo "  \033[33m—\033[0m skipped: no zero-project non-admin with products view permission\n";
} else {
    $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --render-child ' . (int)$candidate . " 2>$devnull";
    $out = shell_exec($cmd) ?: '';
    if (!preg_match('/__RESULT__(\{.*\})/', $out, $m)) {
        bad('could not render products.php (no result from child process)');
    } else {
        $r = json_decode($m[1], true);
        $r['is_admin'] === false && $r['projects'] === []
            ? ok("rendered as a genuine zero-project non-admin (user $candidate)")
            : bad('child did not run with a zero-project non-admin scope');

        // THE regression: the page used to build `WHERE 1=1 AND 0` and return nothing.
        if ($globals > 0) {
            $r['total_count'] > 0
                ? ok("page returned {$r['total_count']} product(s), not a blank list")
                : bad("REGRESSION: page returned 0 products despite $globals global product(s)");
        }
        $r['total_count'] === $globals
            ? ok("page total_count ($globals) equals the global catalogue")
            : bad("page total_count = {$r['total_count']}, expected $globals");

        $r['tagged'] === 0
            ? ok('page leaked no project-tagged products')
            : bad("page leaked {$r['tagged']} project-tagged product(s)");
    }
}

head('Source — the hand-rolled deny block is gone');
$srcFile = dirname(__DIR__) . '/app/bms/product/products.php';
$src = @file_get_contents($srcFile) ?: '';
strpos($src, "\$conditions[] = '0';") === false
    ? ok("products.php no longer pushes a literal '0' condition")
    : bad("products.php still contains \$conditions[] = '0';");

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes checks passed.\033[0m\n";
    exit(0);
}
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
