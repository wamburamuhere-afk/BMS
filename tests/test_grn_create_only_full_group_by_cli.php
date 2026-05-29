<?php
/**
 * GRN create — ONLY_FULL_GROUP_BY regression test
 * -----------------------------------------------
 *   php tests/test_grn_create_only_full_group_by_cli.php
 *
 * Guards against the fatal that hit demo on 2026-05-29:
 *   "SQLSTATE[42000]: 1055 Expression #8 of SELECT list is not in GROUP BY
 *    clause and contains nonaggregated column 'poi.unit_price' ...
 *    incompatible with sql_mode=only_full_group_by"
 *
 * Root cause: grn_create.php pre-fills items from a DN with a query that
 * GROUPs BY di.delivery_item_id but selected poi.unit_price / poi.tax_rate
 * un-aggregated. Local WAMP runs with only_full_group_by OFF so it passed
 * locally; production runs with it ON (MySQL 5.7+/8.0 default) so it threw.
 *
 * This test forces the strict mode ON, then runs the EXACT query — the 1055
 * check fires on prepare/execute regardless of whether any rows match, so no
 * seed data is needed. Dummy params (0,0,0) are enough to validate structure.
 *
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

// ─────────────────────────────────────────────────────────────────────────────
section('1. Source guard — poi columns stay aggregated');
// ─────────────────────────────────────────────────────────────────────────────
$src = @file_get_contents("$root/app/bms/grn/grn_create.php") ?: '';

if ($src === '') {
    fail('cannot read app/bms/grn/grn_create.php');
} else {
    // The fix must keep poi.unit_price / poi.tax_rate inside an aggregate
    // (MAX) so the GROUP BY di.delivery_item_id query is only_full_group_by safe.
    preg_match('/MAX\(\s*poi\.unit_price\s*\)/i', $src)
        ? pass('poi.unit_price is wrapped in MAX()')
        : fail('poi.unit_price is NOT aggregated — only_full_group_by will reject it');

    preg_match('/MAX\(\s*poi\.tax_rate\s*\)/i', $src)
        ? pass('poi.tax_rate is wrapped in MAX()')
        : fail('poi.tax_rate is NOT aggregated — only_full_group_by will reject it');
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. DB execution under production-strict sql_mode');
// ─────────────────────────────────────────────────────────────────────────────
if (!file_exists("$root/includes/config.php") && !file_exists("$root/roots.php")) {
    echo "  (skipped — no DB config available in this environment)\n";
} else {
    try {
        require_once "$root/roots.php";
        global $pdo;

        // Force the SAME strict mode production uses, so a non-compliant query
        // throws here exactly as it would online.
        $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        $mode = $pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
        str_contains($mode, 'ONLY_FULL_GROUP_BY')
            ? pass('session forced to ONLY_FULL_GROUP_BY')
            : fail('could not enable ONLY_FULL_GROUP_BY for the session');

        // EXACT query copied from app/bms/grn/grn_create.php (DN pre-fill).
        $sql = "
            SELECT di.product_id,
                   COALESCE(p.product_name, di.product_name) AS product_name,
                   COALESCE(p.sku, di.sku)                   AS sku,
                   COALESCE(p.unit, di.unit, 'pcs')          AS unit,
                   di.quantity_delivered                      AS dn_qty,
                   COALESCE(SUM(ri.quantity_received), 0)    AS received_qty,
                   GREATEST(di.quantity_delivered - COALESCE(SUM(ri.quantity_received),0), 0) AS pending_qty,
                   COALESCE(MAX(poi.unit_price), MAX(p.cost_price), 0) AS unit_price,
                   COALESCE(MAX(poi.tax_rate), 0)                      AS tax_rate
            FROM delivery_items di
            LEFT JOIN products p           ON di.product_id = p.product_id
            LEFT JOIN purchase_order_items poi
                ON poi.purchase_order_id = ? AND poi.product_id = di.product_id
            LEFT JOIN receipt_items ri      ON ri.product_id = di.product_id
            LEFT JOIN purchase_receipts pr  ON ri.receipt_id = pr.receipt_id
                AND pr.delivery_id = ? AND pr.status = 'approved'
            WHERE di.delivery_id = ?
            GROUP BY di.delivery_item_id
            HAVING pending_qty > 0
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([0, 0, 0]); // dummy params — 1055 fires on structure, not data
            $stmt->fetchAll(PDO::FETCH_ASSOC);
            pass('DN pre-fill query executes cleanly under ONLY_FULL_GROUP_BY');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1055')) {
                fail('1055 only_full_group_by error STILL thrown: ' . $e->getMessage());
            } else {
                fail('query failed under strict mode: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        fail('DB bootstrap failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m── Summary ──\033[0m\n";
echo "  Passed: $passes   Failed: $failures\n";
exit($failures > 0 ? 1 : 0);
