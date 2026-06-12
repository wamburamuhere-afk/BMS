<?php
/**
 * api/account/get_expenses_by_category.php
 * ----------------------------------------
 * Data source for the "Expenses by Category" tree/roll-up view.
 *
 * Arranges spend as Expense Type → Category → Sub-category and rolls each
 * parent up from its descendants (the Chart-of-Accounts pattern). Attribution
 * is resolved HERE from the live `expense_category_map` (the system's real
 * source of truth — add/update write the map, never `expenses.category_id`),
 * so nothing in the data is mutated and the figures never drift.
 *
 *   - Each expense attributes to exactly ONE leaf category (deepest valid leaf;
 *     ties broken by lowest id) → no double-counting, Type totals reconcile to
 *     the true total.
 *   - An expense with no valid-leaf mapping falls into the "Uncategorised" group
 *     (shown as a first-class row, never hidden) so the view always reconciles.
 *
 * Modes:
 *   mode=tree  (default) → the rolled-up tree + Uncategorised + grand total
 *   mode=drill&node_type=category|type|uncategorised[&node_id=N]
 *                        → every expense under that node's subtree (S/NO list)
 *
 * Read-only. Permission-gated (canView('expenses')) and project-scoped (§23).
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────
$year       = date('Y');
$date_from  = $_GET['date_from'] ?? "$year-01-01";
$date_to    = $_GET['date_to']   ?? "$year-12-31";
$status     = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
$mode       = $_GET['mode'] ?? 'tree';
$node_type  = $_GET['node_type'] ?? '';
$node_id    = isset($_GET['node_id']) ? (int)$_GET['node_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = "$year-01-01";
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = "$year-12-31";
$valid_statuses = ['pending', 'reviewed', 'approved', 'rejected', 'paid'];
if ($status !== null && !in_array($status, $valid_statuses, true)) $status = null;

try {
    // ── Category tree (all categories; resolution needs the full shape) ─────
    $cats = $pdo->query("SELECT id, type_id, parent_id, name, status FROM expense_categories")
                ->fetchAll(PDO::FETCH_ASSOC);
    $catById = [];           // id => row
    $childrenOf = [];        // parent_id => [child_id,...]
    foreach ($cats as $c) {
        $catById[(int)$c['id']] = $c;
    }
    foreach ($cats as $c) {
        $pid = $c['parent_id'] !== null ? (int)$c['parent_id'] : 0;
        if ($pid) $childrenOf[$pid][] = (int)$c['id'];
    }
    $isLeaf = fn(int $id): bool => empty($childrenOf[$id]);
    $depthOf = function (int $id) use ($catById): int {
        $d = 1; $p = isset($catById[$id]) ? ($catById[$id]['parent_id'] !== null ? (int)$catById[$id]['parent_id'] : 0) : 0;
        $guard = 0;
        while ($p && $guard++ < 20) { $d++; $p = isset($catById[$p]) && $catById[$p]['parent_id'] !== null ? (int)$catById[$p]['parent_id'] : 0; }
        return $d;
    };

    // Resolve the single canonical leaf for an expense from its map rows.
    $resolveLeaf = function (array $mapIds) use ($catById, $isLeaf, $depthOf): ?int {
        $cand = [];
        foreach ($mapIds as $m) {
            $m = (int)$m;
            if (isset($catById[$m]) && $isLeaf($m)) $cand[] = $m;
        }
        if (!$cand) return null;
        $cand = array_values(array_unique($cand));
        usort($cand, fn($a, $b) => ($depthOf($b) <=> $depthOf($a)) ?: ($a <=> $b)); // deepest, then lowest id
        return $cand[0];
    };

    // ── Expenses in scope/period ────────────────────────────────────────────
    $where  = ["DATE(e.expense_date) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    if ($status !== null) { $where[] = "e.status = ?"; $params[] = $status; }
    $scope  = scopeFilterSqlNullable('project', 'e');   // §23 — non-admins limited to their projects (+ untagged)
    $where_sql = implode(' AND ', $where) . $scope;

    // Map of expense_id => [category_id,...] for the in-scope expenses.
    $expStmt = $pdo->prepare("SELECT e.expense_id, e.amount, e.status, e.expense_date FROM expenses e WHERE $where_sql");
    $expStmt->execute($params);
    $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $expIds = array_map(fn($r) => (int)$r['expense_id'], $expenses);
    $mapByExp = [];
    if ($expIds) {
        $ph = implode(',', array_fill(0, count($expIds), '?'));
        $mStmt = $pdo->prepare("SELECT expense_id, category_id FROM expense_category_map WHERE expense_id IN ($ph)");
        $mStmt->execute($expIds);
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mapByExp[(int)$m['expense_id']][] = (int)$m['category_id'];
        }
    }

    // ── Attribute each expense to one leaf; tally own spend + count ─────────
    $ownSpend = [];   // category_id => float
    $ownCount = [];   // category_id => int
    $leafOfExp = [];  // expense_id => leaf_id|null  (reused by drill mode)
    $uncat_total = 0.0; $uncat_count = 0;
    $grand_total = 0.0;

    foreach ($expenses as $e) {
        $eid = (int)$e['expense_id'];
        $amt = (float)$e['amount'];
        $grand_total += $amt;
        $leaf = $resolveLeaf($mapByExp[$eid] ?? []);
        $leafOfExp[$eid] = $leaf;
        if ($leaf === null) { $uncat_total += $amt; $uncat_count++; continue; }
        $ownSpend[$leaf] = ($ownSpend[$leaf] ?? 0) + $amt;
        $ownCount[$leaf] = ($ownCount[$leaf] ?? 0) + 1;
    }
    $grand_total = round($grand_total, 2);

    // ── Roll-up: node total = own + Σ descendants (memoised recursion) ──────
    $rollCache = [];
    $rollup = function (int $id) use (&$rollup, &$rollCache, $childrenOf, $ownSpend): float {
        if (isset($rollCache[$id])) return $rollCache[$id];
        $sum = (float)($ownSpend[$id] ?? 0);
        foreach ($childrenOf[$id] ?? [] as $cid) $sum += $rollup($cid);
        return $rollCache[$id] = round($sum, 2);
    };
    $rollCount = function (int $id) use (&$rollCount, $childrenOf, $ownCount): int {
        $n = (int)($ownCount[$id] ?? 0);
        foreach ($childrenOf[$id] ?? [] as $cid) $n += $rollCount($cid);
        return $n;
    };

    // ── DRILL MODE: list expenses under a node's subtree ───────────────────
    if ($mode === 'drill') {
        // Determine the leaf-set the drill covers.
        $leafSet = null;  // null → uncategorised
        if ($node_type === 'category' && $node_id && isset($catById[$node_id])) {
            $sub = [];
            $collect = function (int $id) use (&$collect, $childrenOf, &$sub) {
                $sub[$id] = true;
                foreach ($childrenOf[$id] ?? [] as $c) $collect($c);
            };
            $collect($node_id);
            $leafSet = $sub;
        } elseif ($node_type === 'type' && $node_id) {
            $sub = [];
            foreach ($catById as $cid => $c) {
                if ((int)$c['type_id'] === $node_id) $sub[$cid] = true;
            }
            $leafSet = $sub;
        } elseif ($node_type === 'uncategorised') {
            $leafSet = null;
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid drill node']);
            exit;
        }

        // Pick the expense_ids whose resolved leaf falls in the set (or uncategorised).
        $pickIds = [];
        foreach ($leafOfExp as $eid => $leaf) {
            if ($node_type === 'uncategorised') { if ($leaf === null) $pickIds[] = $eid; }
            else { if ($leaf !== null && isset($leafSet[$leaf])) $pickIds[] = $eid; }
        }

        $rows = []; $subtotal = 0.0;
        if ($pickIds) {
            $ph = implode(',', array_fill(0, count($pickIds), '?'));
            $q = $pdo->prepare("
                SELECT e.expense_id, e.expense_date, e.description, e.reference_number,
                       e.amount, e.status, e.paid_to_type,
                       CASE
                           WHEN e.paid_to_type = 'supplier'       THEN (SELECT supplier_name FROM suppliers       WHERE supplier_id = e.paid_to_id)
                           WHEN e.paid_to_type = 'staff'          THEN (SELECT CONCAT(first_name,' ',last_name) FROM employees WHERE employee_id = e.paid_to_id)
                           WHEN e.paid_to_type = 'sub_contractor' THEN (SELECT supplier_name FROM sub_contractors  WHERE supplier_id = e.paid_to_id)
                           ELSE e.vendor
                       END AS paid_to_name
                  FROM expenses e
                 WHERE e.expense_id IN ($ph)
                 ORDER BY e.expense_date DESC, e.expense_id DESC
            ");
            $q->execute($pickIds);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['leaf_id']   = $leafOfExp[(int)$r['expense_id']] ?? null;
                $r['leaf_name'] = $r['leaf_id'] && isset($catById[$r['leaf_id']]) ? $catById[$r['leaf_id']]['name'] : null;
                $rows[] = $r;
                $subtotal += (float)$r['amount'];
            }
        }

        if ($node_type === 'uncategorised') {
            $nodeName = 'Uncategorised';
        } elseif ($node_type === 'category') {
            $nodeName = $catById[$node_id]['name'] ?? 'Category';
        } else { // type — resolve from expense_types, never the category map
            $nodeName = $pdo->query("SELECT name FROM expense_types WHERE id = " . (int)$node_id)->fetchColumn() ?: 'Type';
        }

        echo json_encode([
            'success'   => true,
            'mode'      => 'drill',
            'node'      => ['type' => $node_type, 'id' => $node_id, 'name' => $nodeName],
            'rows'      => $rows,
            'subtotal'  => round($subtotal, 2),
            'count'     => count($rows),
            'period'    => ['from' => $date_from, 'to' => $date_to],
        ]);
        exit;
    }

    // ── TREE MODE: Type → Category → Sub-category with roll-up ──────────────
    $buildNode = function (int $cid) use (&$buildNode, $catById, $childrenOf, $ownSpend, $ownCount, $rollup, $rollCount, $depthOf) {
        $kids = [];
        foreach ($childrenOf[$cid] ?? [] as $c) $kids[] = $buildNode($c);
        return [
            'id'           => $cid,
            'name'         => $catById[$cid]['name'],
            'level'        => $depthOf($cid),
            'own_spend'    => round((float)($ownSpend[$cid] ?? 0), 2),
            'rollup_spend' => $rollup($cid),
            'own_count'    => (int)($ownCount[$cid] ?? 0),
            'rollup_count' => $rollCount($cid),
            'has_children' => !empty($childrenOf[$cid]),
            'children'     => $kids,
        ];
    };

    $types = $pdo->query("SELECT id, name FROM expense_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $typeTotal = [];   // type_id => float (sum of own spend for that type's categories)
    foreach ($ownSpend as $cid => $amt) {
        $tid = (int)($catById[$cid]['type_id'] ?? 0);
        if ($tid) $typeTotal[$tid] = ($typeTotal[$tid] ?? 0) + $amt;
    }

    $tree = [];
    $typesSum = 0.0;
    foreach ($types as $t) {
        $tid = (int)$t['id'];
        // top-level categories of this type
        $roots = [];
        foreach ($catById as $cid => $c) {
            if ((int)$c['type_id'] === $tid && ($c['parent_id'] === null || (int)$c['parent_id'] === 0)) {
                $roots[] = $buildNode($cid);
            }
        }
        $tt = round((float)($typeTotal[$tid] ?? 0), 2);
        $typesSum += $tt;
        $tree[] = [
            'type_id'      => $tid,
            'name'         => $t['name'],
            'total_spend'  => $tt,
            'share_pct'    => $grand_total > 0 ? round($tt / $grand_total * 100, 1) : 0,
            'categories'   => $roots,
        ];
    }
    // Sort Types by spend (biggest first) for the dashboard feel.
    usort($tree, fn($a, $b) => $b['total_spend'] <=> $a['total_spend']);

    $uncat_total = round($uncat_total, 2);
    $reconciles  = abs(round($typesSum + $uncat_total, 2) - $grand_total) < 0.01;

    echo json_encode([
        'success'        => true,
        'mode'           => 'tree',
        'period'         => ['from' => $date_from, 'to' => $date_to],
        'status'         => $status,
        'types'          => $tree,
        'uncategorised'  => ['count' => $uncat_count, 'total' => $uncat_total,
                             'share_pct' => $grand_total > 0 ? round($uncat_total / $grand_total * 100, 1) : 0],
        'grand_total'    => $grand_total,
        'expense_count'  => count($expenses),
        'reconciles'     => $reconciles,
    ]);

} catch (Throwable $e) {
    error_log('get_expenses_by_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
