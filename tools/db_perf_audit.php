<?php
// scope-audit: skip — read-only DBA diagnostic; reads information_schema metadata
// and row counts only, never business data, and applies no project scope by design.
/**
 * tools/db_perf_audit.php — READ-ONLY database performance audit.
 *
 * Purpose:
 *   Report what the query-optimization work needs in order to prioritise
 *   correctly against REAL production data. A local dev database is not
 *   representative (e.g. `invoices` holds 12 rows locally), so index and
 *   query fixes cannot be ranked by impact without these numbers.
 *
 * Safety:
 *   - CLI only. Refuses to run over HTTP.
 *   - Issues SELECTs exclusively. No INSERT/UPDATE/DELETE/ALTER, no temp
 *     tables, no locks taken beyond a normal read.
 *   - Reads information_schema metadata plus row counts. It never reads the
 *     contents of a business row, so no customer/financial data is printed.
 *   - Exact COUNT(*) is only issued for tables the optimiser estimates are
 *     under --count-limit rows (default 200,000). Anything larger is reported
 *     with information_schema's approximation, clearly labelled "~". This
 *     avoids a slow full index scan on a large production table.
 *
 * Usage (on the live server):
 *   php tools/db_perf_audit.php
 *   php tools/db_perf_audit.php --top=40 --min-rows=1000
 *   php tools/db_perf_audit.php --count-limit=0     # never issue exact COUNT(*)
 *
 * Output is plain text, safe to copy and paste back verbatim.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';

// ── options ──────────────────────────────────────────────────────────────────
$opt = getopt('', ['top::', 'min-rows::', 'count-limit::']);
$TOP         = max(1, (int)($opt['top']         ?? 30));
$MIN_ROWS    = max(0, (int)($opt['min-rows']    ?? 500));
$COUNT_LIMIT = isset($opt['count-limit']) ? max(0, (int)$opt['count-limit']) : 200000;

try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "Could not connect: " . $e->getMessage() . "\n";
    exit(1);
}

$db = DB_NAME;
$ver = $pdo->query("SELECT VERSION()")->fetchColumn();

echo str_repeat('=', 78) . "\n";
echo "BMS DB PERFORMANCE AUDIT  (read-only)\n";
echo "database : $db\n";
echo "server   : MySQL $ver\n";
echo "date     : " . date('Y-m-d H:i:s') . "\n";
echo "options  : top=$TOP min-rows=$MIN_ROWS count-limit=" . ($COUNT_LIMIT ?: 'off') . "\n";
echo str_repeat('=', 78) . "\n";

// ── gather metadata ──────────────────────────────────────────────────────────
$tables = $pdo->query("
    SELECT TABLE_NAME, TABLE_ROWS, ENGINE,
           ROUND(DATA_LENGTH/1024/1024, 1)  AS data_mb,
           ROUND(INDEX_LENGTH/1024/1024, 1) AS index_mb
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_ROWS DESC
")->fetchAll(PDO::FETCH_ASSOC);

$idx = [];
foreach ($pdo->query("
    SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
") as $r) {
    $idx[$r['TABLE_NAME']][$r['INDEX_NAME']][(int)$r['SEQ_IN_INDEX']] = $r['COLUMN_NAME'];
}

$cols = [];
foreach ($pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
") as $r) {
    $cols[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
}

/** Exact count when cheap, else the optimiser's estimate prefixed with "~". */
$rowsOf = function (array $t) use ($pdo, $COUNT_LIMIT): array {
    $approx = (int)$t['TABLE_ROWS'];
    if ($COUNT_LIMIT > 0 && $approx <= $COUNT_LIMIT) {
        try {
            return [(int)$pdo->query("SELECT COUNT(*) FROM `{$t['TABLE_NAME']}`")->fetchColumn(), true];
        } catch (Throwable $e) { /* fall through */ }
    }
    return [$approx, false];
};

$fmt = fn(array $r) => ($r[1] ? '' : '~') . number_format($r[0]);

// ── 1. biggest tables ────────────────────────────────────────────────────────
echo "\n1. LARGEST TABLES (top $TOP by row count)\n";
printf("   %-34s %12s %9s %9s  %s\n", 'table', 'rows', 'data MB', 'idx MB', 'engine');
echo '   ' . str_repeat('-', 72) . "\n";
$sizes = [];
foreach (array_slice($tables, 0, $TOP) as $t) {
    $r = $rowsOf($t);
    $sizes[$t['TABLE_NAME']] = $r[0];
    printf("   %-34s %12s %9s %9s  %s\n",
        $t['TABLE_NAME'], $fmt($r), $t['data_mb'], $t['index_mb'], $t['ENGINE']);
}

// ── 2. no index beyond PRIMARY ───────────────────────────────────────────────
echo "\n2. TABLES WITH NO INDEX BEYOND PRIMARY  (>= $MIN_ROWS rows)\n";
echo "   These full-scan on every filtered read. This is the pattern that made\n";
echo "   the dashboard scan 16,372 rows before the phase-1 fix.\n";
$hit = 0;
foreach ($tables as $t) {
    if ((int)$t['TABLE_ROWS'] < $MIN_ROWS) continue;
    $keys  = array_keys($idx[$t['TABLE_NAME']] ?? []);
    $nonPk = array_diff($keys, ['PRIMARY']);
    if ($nonPk) continue;
    $r = $rowsOf($t);
    printf("   %-34s %12s rows\n", $t['TABLE_NAME'], $fmt($r));
    $hit++;
}
if (!$hit) echo "   (none)\n";

// ── 3. un-indexed likely filter/join columns ─────────────────────────────────
echo "\n3. LIKELY FILTER/JOIN COLUMNS WITH NO INDEX  (>= $MIN_ROWS rows)\n";
echo "   A column here leads no index, so a WHERE/JOIN on it cannot use one.\n";
echo "   Note: low-cardinality flags (is_active) rarely benefit - judge per case.\n";
$patterns = ['/_id$/', '/_date$/', '/^status$/', '/^created_at$/', '/^updated_at$/'];
$hit = 0;
foreach ($tables as $t) {
    $tn = $t['TABLE_NAME'];
    if ((int)$t['TABLE_ROWS'] < $MIN_ROWS) continue;

    $leading = [];
    foreach (($idx[$tn] ?? []) as $c) { ksort($c); $leading[reset($c)] = true; }

    $missing = [];
    foreach (($cols[$tn] ?? []) as $c) {
        foreach ($patterns as $p) {
            if (preg_match($p, $c) && !isset($leading[$c])) { $missing[] = $c; break; }
        }
    }
    if (!$missing) continue;
    $r = $rowsOf($t);
    printf("   %-30s %10s rows  ->  %s\n", $tn, $fmt($r), implode(', ', array_slice($missing, 0, 6)));
    $hit++;
}
if (!$hit) echo "   (none)\n";

// ── 4. redundant indexes ─────────────────────────────────────────────────────
echo "\n4. REDUNDANT INDEXES  (write cost, no read benefit)\n";
echo "   'A == B' are exact duplicates. 'A is a prefix of B' means B already\n";
echo "   serves every lookup A can serve. Dropping is a separate, riskier task.\n";
$hit = 0;
foreach ($idx as $tn => $keys) {
    $sig = [];
    foreach ($keys as $k => $c) { ksort($c); $sig[$k] = implode(',', $c); }
    foreach ($sig as $k1 => $s1) {
        if ($k1 === 'PRIMARY') continue;
        foreach ($sig as $k2 => $s2) {
            if ($k1 === $k2) continue;
            if ($s1 === $s2 && strcmp($k1, $k2) < 0)      printf("   %-26s %s == %s  (%s)\n", $tn, $k1, $k2, $s1);
            elseif ($s1 !== $s2 && strpos($s2, $s1 . ',') === 0) printf("   %-26s %s (%s) is a prefix of %s (%s)\n", $tn, $k1, $s1, $k2, $s2);
            else continue;
            $hit++;
        }
    }
}
if (!$hit) echo "   (none)\n";

// ── 5. non-InnoDB ────────────────────────────────────────────────────────────
echo "\n5. NON-InnoDB TABLES  (no row-level locking / no transaction safety)\n";
$hit = 0;
foreach ($tables as $t) {
    if (strcasecmp((string)$t['ENGINE'], 'InnoDB') === 0) continue;
    $r = $rowsOf($t);
    printf("   %-34s %12s rows  engine=%s\n", $t['TABLE_NAME'], $fmt($r), $t['ENGINE']);
    $hit++;
}
if (!$hit) echo "   (none - all InnoDB)\n";

// ── 6. sizes of the tables named in non-sargable date predicates ─────────────
echo "\n6. SIZE OF TABLES USED WITH NON-SARGABLE DATE FILTERS\n";
echo "   Code in ~33 files filters with DATE(col)=... / MONTH(col)=..., which\n";
echo "   prevents index use. Rewriting only matters where the table is large.\n";
$watch = ['invoices', 'pos_sales', 'audit_logs', 'activity_logs', 'leads', 'expenses',
          'payments', 'sales_orders', 'purchase_orders', 'journal_entries',
          'journal_entry_items', 'stock_movements', 'attendance', 'products',
          'customers', 'employees', 'supplier_invoices', 'deliveries', 'quotations'];
$byName = [];
foreach ($tables as $t) $byName[$t['TABLE_NAME']] = $t;
foreach ($watch as $w) {
    if (!isset($byName[$w])) { printf("   %-24s (table not present)\n", $w); continue; }
    $r = $rowsOf($byName[$w]);
    $keys = [];
    foreach (($idx[$w] ?? []) as $k => $c) { if ($k !== 'PRIMARY') { ksort($c); $keys[] = $k . '(' . implode(',', $c) . ')'; } }
    printf("   %-24s %12s rows   %s\n", $w, $fmt($r), $keys ? implode(' ', array_slice($keys, 0, 3)) : 'NO NON-PK INDEX');
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Done. Nothing was modified. Copy this output back to prioritise the work.\n";
echo str_repeat('=', 78) . "\n";
