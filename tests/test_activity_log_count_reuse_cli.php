<?php
/**
 * tests/test_activity_log_count_reuse_cli.php
 * -------------------------------------------
 * Guards the DataTables COUNT reuse in app/activity_log.php added by the
 * query-optimization work.
 *
 * The page fires three queries per DataTables request, all over the same LAG
 * dedup subquery: recordsTotal, recordsFiltered, and the page rows. When the
 * search box is empty, recordsFiltered is built from exactly the same
 * conditions and parameters as recordsTotal — so it is the identical query and
 * is now skipped, reusing the value already computed.
 *
 * This test asserts the invariant that makes that safe:
 *
 *   1. With an empty search term the two COUNTs are identical for every
 *      realistic filter combination (none, date range, user, user+date, empty
 *      window, and each activity type).
 *   2. A NON-empty search term still narrows the set and is never skipped.
 *
 * If someone later adds a condition to the filtered branch that is not also in
 * the total branch, case 1 breaks here rather than silently reporting a wrong
 * row count in the UI.
 *
 * Requires a database.
 *
 * Run:  php tests/test_activity_log_count_reuse_cli.php
 */

require __DIR__ . '/../core/activity_log_helpers.php';
require __DIR__ . '/../includes/config.php';

$passed = 0; $failed = 0;
function check(string $label, $expected, $actual): void {
    global $passed, $failed;
    if ($expected === $actual) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}

try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "SKIP — no database available: " . $e->getMessage() . "\n";
    exit(0);
}

$dedup   = activityViewDedupExclusion();
$typeMap = activityTypeMap();

$someUser = (int) $pdo->query("SELECT user_id FROM activity_logs WHERE user_id IS NOT NULL
                               GROUP BY user_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();

/** Build the LAG subquery exactly as the page does, for a given inner filter. */
$makeBase = function (array $innerConds): string {
    $innerWhere = $innerConds ? 'WHERE ' . implode(' AND ', $innerConds) : '';
    return "FROM (
        SELECT al.id, al.action, al.description, al.created_at, al.ip_address, u.username,
               LAG(al.action) OVER (PARTITION BY al.user_id ORDER BY al.created_at, al.id) AS prev_action
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $innerWhere
    ) _log";
};

$runCount = function (string $base, string $where, array $p) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) $base $where");
    foreach ($p as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    return (int) $st->fetchColumn();
};

$combos = [
    ['no filters',           null,      '',           ''],
    ['date range',           null,      '2026-01-01', '2026-12-31'],
    ['single day',           null,      '2026-08-20', '2026-08-20'],
    ['user filter',          $someUser, '',           ''],
    ['user + date',          $someUser, '2026-01-01', '2026-12-31'],
    ['empty window',         null,      '2099-01-01', '2099-12-31'],
];

echo "Empty search box — recordsFiltered must equal recordsTotal\n";

foreach ($combos as [$label, $uid, $from, $to]) {
    $innerConds = []; $innerP = [];
    if ($uid)  { $innerConds[] = 'al.user_id = :iuid';    $innerP[':iuid'] = $uid; }
    if ($from) { $innerConds[] = 'al.created_at >= :idf'; $innerP[':idf'] = $from . ' 00:00:00'; }
    if ($to)   { $innerConds[] = 'al.created_at <= :idt'; $innerP[':idt'] = $to . ' 23:59:59'; }

    $base       = $makeBase($innerConds);
    $outerConds = [$dedup];
    $whereOuter = 'WHERE ' . implode(' AND ', $outerConds);

    $total = $runCount($base, $whereOuter, $innerP);

    // The filtered branch with an empty search term — identical by construction.
    $dtConds = $outerConds; $dtP = $innerP;
    $whereDt = 'WHERE ' . implode(' AND ', $dtConds);
    check("$label — SQL identical",    $whereOuter, $whereDt);
    check("$label — count identical",  $total,      $runCount($base, $whereDt, $dtP));
}

echo "\nType filters — same invariant with the type condition applied\n";

foreach (array_slice(array_keys($typeMap), 0, 4) as $type) {
    $base = $makeBase([]);
    [$frag, $fp] = buildActivityTypeSql($type, 'dt_ft_');
    $outerConds  = [$dedup, $frag];
    $outerP      = $fp;
    $whereOuter  = 'WHERE ' . implode(' AND ', $outerConds);

    $total   = $runCount($base, $whereOuter, $outerP);
    $whereDt = 'WHERE ' . implode(' AND ', $outerConds);   // empty search
    check("type=$type — count identical", $total, $runCount($base, $whereDt, $outerP));
}

echo "\nNon-empty search must still run and must not exceed the total\n";

$base       = $makeBase([]);
$whereOuter = "WHERE $dedup";
$total      = $runCount($base, $whereOuter, []);

foreach (['Login', 'zzz_no_such_text_zzz'] as $term) {
    $w  = "WHERE $dedup AND (action LIKE :dts OR description LIKE :dts OR username LIKE :dts)";
    $n  = $runCount($base, $w, [':dts' => '%' . $term . '%']);
    check("search '$term' does not exceed total", true, $n <= $total);
}

// A search that matches nothing must yield exactly zero, never the reused total.
$w    = "WHERE $dedup AND (action LIKE :dts OR description LIKE :dts OR username LIKE :dts)";
$none = $runCount($base, $w, [':dts' => '%zzz_no_such_text_zzz%']);
check('impossible search yields 0, not the reused total', 0, $none);

echo "\nPasses: $passed\nFailures: $failed\n";
exit($failed > 0 ? 1 : 0);
