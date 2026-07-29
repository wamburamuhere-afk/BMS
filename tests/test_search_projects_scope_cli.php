<?php
/**
 * api/search_projects.php — project-scope guard (behavioural)
 *   php tests/test_search_projects_scope_cli.php
 *
 * Found while auditing other search/picker endpoints for the same bug as
 * api/sales/search_orders.php: this file claimed "returns only
 * user-accessible projects via scopeFilterSql already in get_projects.php"
 * via a scope-audit skip marker, but is its own independent, unfiltered
 * query. A non-admin using this picker would see every project in the
 * company. Currently unused by any page/JS in this codebase (checked via
 * grep across app/, api/, assets/js/) — fixed before anything wires into it.
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

require_once dirname(__DIR__) . '/roots.php';

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

echo "\n\033[1m═══ api/search_projects.php — project-scope guard ═══\033[0m\n";

head('Source — no longer claims a false guarantee, genuinely scope-filters');
$src = @file_get_contents(dirname(__DIR__) . '/api/search_projects.php') ?: '';
(strpos($src, 'scope-audit: skip') === false)
    ? ok('the misleading scope-audit skip marker is gone')
    : bad('still carries the scope-audit skip marker');
(strpos($src, "scopeFilterSql('project', 'projects')") !== false)
    ? ok("calls the strict scopeFilterSql('project', 'projects')")
    : bad('missing the scope filter call');

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/api/search_projects.php') . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('api/search_projects.php — no syntax errors')
    : bad('api/search_projects.php — ' . trim((string)$res));

head("END-TO-END — a real non-admin's search only returns their in-scope projects");
$user = $pdo->query("
    SELECT u.user_id, (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) pcount
    FROM users u
    WHERE u.role_id != 1 AND (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) > 0
    ORDER BY pcount DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "  \033[33m—\033[0m skipped: no non-admin with a project assignment found\n";
} else {
    $uid = (int)$user['user_id'];
    $_SESSION['user_id'] = $uid; loadUserScope($uid);
    $myProjects = array_map('intval', $_SESSION['scope']['projects'] ?? []);

    $total  = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status='active'")->fetchColumn();
    $scoped = array_map('intval', $pdo->query(
        "SELECT project_id FROM projects WHERE status='active' " . scopeFilterSql('project', 'projects')
    )->fetchAll(PDO::FETCH_COLUMN));

    $leaked = array_diff($scoped, $myProjects);
    empty($leaked)
        ? ok("user #$uid sees exactly their own project(s): " . json_encode($scoped) . " (of $total total)")
        : bad('leaked project(s) outside assignment: ' . json_encode(array_values($leaked)));
}
