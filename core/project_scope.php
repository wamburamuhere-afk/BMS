<?php
/**
 * BMS — Project-Scope Access Control (Phase A foundation)
 *
 * Second axis of access control, orthogonal to the role/permission
 * system. The role system answers "what verbs?"; this one answers
 * "which rows?".
 *
 * Phase A ships the helpers but does NOT wire them into any query
 * yet. Phases B-E apply the filter to actual SELECT statements; Phase
 * F locks the CI ceiling at 0.
 *
 * Auto-loaded from core/permissions.php so it's available everywhere
 * the permission helpers are.
 */

if (!function_exists('loadUserScope')) {
    /**
     * Compute and cache the user's accessible-resource sets into
     * $_SESSION['scope']. Runs once per session and on demand after
     * an admin changes assignments.
     *
     *   $_SESSION['scope'] = [
     *       'is_admin'    => bool,
     *       'projects'    => int[],
     *       'warehouses'  => int[],   // derived from projects + overrides
     *       'suppliers'   => int[],
     *       'customers'   => int[],
     *       'employees'   => int[],
     *       'computed_at' => int (unix ts),
     *   ];
     *
     * Admins get the `is_admin` sentinel and an empty list for each
     * resource type — the helpers below short-circuit for admins.
     */
    function loadUserScope(int $userId): void
    {
        global $pdo;

        // Always set the admin sentinel first.
        $isAdmin = function_exists('isAdmin') && isAdmin();

        if ($isAdmin) {
            $_SESSION['scope'] = [
                'is_admin'    => true,
                'projects'    => [],
                'warehouses'  => [],
                'suppliers'   => [],
                'customers'   => [],
                'employees'   => [],
                'computed_at' => time(),
            ];
            return;
        }

        if (!$pdo instanceof PDO) {
            // Defensive — keep previous scope if available; otherwise empty.
            if (!isset($_SESSION['scope'])) {
                $_SESSION['scope'] = _empty_scope();
            }
            return;
        }

        try {
            // ── 1. Primary: projects the user is assigned to. ────
            $stmt = $pdo->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
            $stmt->execute([$userId]);
            $projects = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            // ── 2. Derived sets — only computed when there's at least
            //      one project to derive from. Single SQL pass per type.
            $warehouses = [];
            $suppliers  = [];
            $customers  = [];
            $employees  = [];

            if (!empty($projects)) {
                $placeholders = implode(',', array_fill(0, count($projects), '?'));

                // Warehouses appearing on the user's projects via the
                // tables that link a project to a warehouse.
                $warehouses = _scope_distinct_ids($pdo, "
                    SELECT DISTINCT warehouse_id FROM purchase_orders
                        WHERE warehouse_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT warehouse_id FROM purchase_receipts
                        WHERE warehouse_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT warehouse_id FROM deliveries
                        WHERE warehouse_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT warehouse_id FROM stock_movements
                        WHERE warehouse_id IS NOT NULL AND project_id IN ($placeholders)
                ", array_merge($projects, $projects, $projects, $projects));

                // Suppliers on the user's projects via POs, GRNs, payments.
                $suppliers = _scope_distinct_ids($pdo, "
                    SELECT DISTINCT supplier_id FROM purchase_orders
                        WHERE supplier_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT supplier_id FROM purchase_receipts
                        WHERE supplier_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT supplier_id FROM supplier_payments
                        WHERE supplier_id IS NOT NULL AND purchase_order_id IN (
                            SELECT purchase_order_id FROM purchase_orders WHERE project_id IN ($placeholders)
                        )
                ", array_merge($projects, $projects, $projects));

                // Customers on the user's projects via invoices / sales orders.
                $customers = _scope_distinct_ids($pdo, "
                    SELECT DISTINCT customer_id FROM invoices
                        WHERE customer_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT customer_id FROM sales_orders
                        WHERE customer_id IS NOT NULL AND project_id IN ($placeholders)
                    UNION
                    SELECT DISTINCT customer_id FROM projects
                        WHERE customer_id IS NOT NULL AND project_id IN ($placeholders)
                ", array_merge($projects, $projects, $projects));

                // Employees assigned to the user's projects.
                $employees = _scope_distinct_ids($pdo, "
                    SELECT DISTINCT employee_id FROM employees
                        WHERE project_id IN ($placeholders)
                ", $projects);
            }

            // ── 3. Apply overrides ────────────────────────────────
            // Each row either grants a specific resource_id or
            // resource_id = NULL (== grant ALL of that type).
            $ovStmt = $pdo->prepare("
                SELECT resource_type, resource_id
                FROM user_scope_overrides
                WHERE user_id = ?
            ");
            $ovStmt->execute([$userId]);
            $overrides = $ovStmt->fetchAll(PDO::FETCH_ASSOC);

            $grantAll = ['warehouse' => false, 'supplier' => false, 'customer' => false, 'employee' => false];
            $extras   = ['warehouse' => [], 'supplier' => [], 'customer' => [], 'employee' => []];

            foreach ($overrides as $o) {
                $t = $o['resource_type'];
                if (!isset($grantAll[$t])) continue;
                if ($o['resource_id'] === null) {
                    $grantAll[$t] = true;
                } else {
                    $extras[$t][] = (int)$o['resource_id'];
                }
            }

            // If an override grants ALL of a resource type, set the
            // session list to the sentinel ['*'] — the helpers below
            // treat that as unrestricted for that resource type.
            $warehouses = $grantAll['warehouse'] ? ['*'] : array_values(array_unique(array_merge($warehouses, $extras['warehouse'])));
            $suppliers  = $grantAll['supplier']  ? ['*'] : array_values(array_unique(array_merge($suppliers,  $extras['supplier'])));
            $customers  = $grantAll['customer']  ? ['*'] : array_values(array_unique(array_merge($customers,  $extras['customer'])));
            $employees  = $grantAll['employee']  ? ['*'] : array_values(array_unique(array_merge($employees,  $extras['employee'])));

            $_SESSION['scope'] = [
                'is_admin'    => false,
                'projects'    => $projects,
                'warehouses'  => $warehouses,
                'suppliers'   => $suppliers,
                'customers'   => $customers,
                'employees'   => $employees,
                'computed_at' => time(),
            ];
        } catch (Throwable $e) {
            error_log('loadUserScope failed: ' . $e->getMessage());
            // On failure: default-deny. Empty scope = nothing visible.
            $_SESSION['scope'] = _empty_scope();
        }
    }
}

if (!function_exists('refreshScopeCache')) {
    /**
     * Recompute the scope cache for a specific user. Call this from
     * the assignment UI immediately after INSERT/DELETE on
     * user_projects or user_scope_overrides for the affected user.
     *
     * If $userId is the currently-logged-in user, the session is
     * updated in place. For other users the cache update happens on
     * their next login (we don't reach into other people's sessions).
     */
    function refreshScopeCache(int $userId): void
    {
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
            loadUserScope($userId);
        }
        // Else: no-op. Their next login will pick up the new scope.
    }
}

if (!function_exists('userCan')) {
    /**
     * Single-record gate. Returns true if the current user is allowed
     * to touch a specific row of the given resource type.
     *
     *   userCan('project',   $row['project_id']);
     *   userCan('warehouse', $row['warehouse_id']);
     *
     * Admin always returns true. Sentinel ['*'] returns true.
     */
    function userCan(string $resourceType, $resourceId): bool
    {
        if (!isset($_SESSION['scope'])) {
            // Helpers might be called before bootstrap; lazy-load.
            if (isset($_SESSION['user_id'])) {
                loadUserScope((int)$_SESSION['user_id']);
            } else {
                return false;
            }
        }
        if (!empty($_SESSION['scope']['is_admin'])) return true;

        $key = _scope_list_key($resourceType);
        if ($key === null) return false;          // unknown resource type → deny
        $list = $_SESSION['scope'][$key] ?? [];
        if (in_array('*', $list, true)) return true;  // override "grant all"

        return in_array((int)$resourceId, $list, true);
    }
}

if (!function_exists('assertScopeForRecordHtml')) {
    /**
     * Same as assertScopeForRecord() but die()s with plain text rather
     * than JSON. Use this for HTML print pages — a JSON body there
     * would render as raw text in the browser.
     *
     *   assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);
     */
    function assertScopeForRecordHtml(string $table, string $pkColumn, $id): void
    {
        if (empty($id)) return;
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table))    return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $pkColumn)) return;
        try {
            $stmt = $pdo->prepare("SELECT project_id FROM `$table` WHERE `$pkColumn` = ? LIMIT 1");
            $stmt->execute([(int)$id]);
            $project_id = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($project_id === false || $project_id === null || $project_id === '') return;
        if (!userCan('project', (int)$project_id)) {
            if (!headers_sent()) http_response_code(403);
            die('Access denied: this record belongs to a project not in your scope.');
        }
    }
}

if (!function_exists('assertScopeForRecord')) {
    /**
     * Look up a record's project_id by table + PK column + id, then
     * gate via userCan('project', ...). Sends a 403 JSON response and
     * exits if access is denied.
     *
     *   assertScopeForRecord('purchase_orders', 'purchase_order_id', $id);
     *
     * Behaviour:
     *   - Admin always passes (userCan returns true).
     *   - Empty/missing id → no-op (caller's own existence check applies).
     *   - Table missing project_id column → no-op (silent).
     *   - Project_id is set and user can't access → exits with 403 JSON.
     *
     * Saves repeating the same 5-line lookup pattern in every write API.
     */
    function assertScopeForRecord(string $table, string $pkColumn, $id): void
    {
        if (empty($id)) return;
        global $pdo;
        if (!($pdo instanceof PDO)) return;

        // Whitelist table/column names — they are caller-supplied strings
        // so we can't bind them as parameters.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table))    return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $pkColumn)) return;

        try {
            $stmt = $pdo->prepare("SELECT project_id FROM `$table` WHERE `$pkColumn` = ? LIMIT 1");
            $stmt->execute([(int)$id]);
            $project_id = $stmt->fetchColumn();
        } catch (Throwable $e) {
            // Table or column doesn't exist or doesn't have project_id — silent no-op.
            return;
        }
        if ($project_id === false || $project_id === null || $project_id === '') return;

        if (!userCan('project', (int)$project_id)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied: this record belongs to a project not in your scope.',
            ]);
            exit;
        }
    }
}

if (!function_exists('scopeFilterSqlNullable')) {
    /**
     * Like scopeFilterSql() but tolerates NULL project_id values.
     *
     *   "AND (alias.project_id IS NULL OR alias.project_id IN (1,2,3))"
     *
     * Use this for tables where project_id is OPTIONAL — e.g. products,
     * where many catalogue items are global (project_id = NULL) and only
     * some are project-tagged. A non-admin should still see the global
     * rows plus the project-tagged ones in their scope.
     *
     * Admin → empty string. Default-deny → " AND 0 " (same as the strict
     * variant — a non-admin with no assignments sees nothing, including
     * global rows; otherwise stale scope after losing all projects would
     * still show global catalogue).
     */
    function scopeFilterSqlNullable(string $resourceType, string $alias = ''): string
    {
        if (!isset($_SESSION['scope'])) {
            if (isset($_SESSION['user_id'])) loadUserScope((int)$_SESSION['user_id']);
        }
        if (!empty($_SESSION['scope']['is_admin'])) return '';

        $key = _scope_list_key($resourceType);
        if ($key === null) return ' AND 0 ';

        $list = $_SESSION['scope'][$key] ?? [];
        if (in_array('*', $list, true)) return '';
        if (empty($list))               return ' AND 0 ';

        $col = _scope_column($resourceType);
        $prefix = $alias !== '' ? "`$alias`." : '';
        $ids = implode(',', array_map('intval', $list));
        return " AND ({$prefix}{$col} IS NULL OR {$prefix}{$col} IN ($ids)) ";
    }
}

if (!function_exists('assertScopeForEmployee')) {
    /**
     * Gate via an employee_id by resolving the employee's project_id.
     * Sends 403 JSON and exits if the employee belongs to a project
     * outside the caller's scope. Use this on leaves/payroll/attendance
     * writes that accept an employee_id in $_POST.
     */
    function assertScopeForEmployee($employee_id): void
    {
        if (empty($employee_id)) return;
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        try {
            $stmt = $pdo->prepare("SELECT project_id FROM employees WHERE employee_id = ? LIMIT 1");
            $stmt->execute([(int)$employee_id]);
            $project_id = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($project_id === false || $project_id === null || $project_id === '') return;
        if (!userCan('project', (int)$project_id)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => "Access denied: this employee belongs to a project not in your scope.",
            ]);
            exit;
        }
    }
}

if (!function_exists('assertScopeForEmployeeRecord')) {
    /**
     * Lookup a leave/payroll/attendance record by PK, follow employee_id
     * through to employees.project_id, then gate via userCan('project').
     *
     *   assertScopeForEmployeeRecord('leaves',     'leave_id',      $id);
     *   assertScopeForEmployeeRecord('payroll',    'payroll_id',    $id);
     *   assertScopeForEmployeeRecord('attendance', 'attendance_id', $id);
     */
    function assertScopeForEmployeeRecord(string $table, string $pkColumn, $id): void
    {
        if (empty($id)) return;
        global $pdo;
        if (!($pdo instanceof PDO)) return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table))    return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $pkColumn)) return;
        try {
            $stmt = $pdo->prepare(
                "SELECT e.project_id FROM `$table` t
                   JOIN employees e ON t.employee_id = e.employee_id
                  WHERE t.`$pkColumn` = ? LIMIT 1"
            );
            $stmt->execute([(int)$id]);
            $project_id = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($project_id === false || $project_id === null || $project_id === '') return;
        if (!userCan('project', (int)$project_id)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied: this record belongs to a project not in your scope.',
            ]);
            exit;
        }
    }
}

if (!function_exists('scopeFilterSql')) {
    /**
     * Returns a SQL fragment suitable for appending to a WHERE clause:
     *
     *   "AND alias.project_id IN (1,2,3)"
     *
     * Empty string for admin (no filter). Empty IN list (= 0 rows
     * accessible) returns "AND 0" so the query yields nothing — the
     * default-deny posture.
     *
     *   $sql = "SELECT * FROM purchase_orders WHERE status != 'deleted' "
     *        . scopeFilterSql('project', 'purchase_orders');
     *
     * Phase A ships this helper but no SELECT is changed yet.
     */
    function scopeFilterSql(string $resourceType, string $alias = ''): string
    {
        if (!isset($_SESSION['scope'])) {
            if (isset($_SESSION['user_id'])) loadUserScope((int)$_SESSION['user_id']);
        }
        if (!empty($_SESSION['scope']['is_admin'])) return '';

        $key = _scope_list_key($resourceType);
        if ($key === null) return ' AND 0 ';  // unknown resource type → no rows

        $list = $_SESSION['scope'][$key] ?? [];
        if (in_array('*', $list, true)) return '';          // grant-all override
        if (empty($list))               return ' AND 0 ';   // default-deny

        $col = _scope_column($resourceType);
        $prefix = $alias !== '' ? "`$alias`." : '';
        $ids = implode(',', array_map('intval', $list));
        return " AND {$prefix}{$col} IN ($ids) ";
    }
}

// ── Internal helpers (underscore-prefixed; not part of the public API) ──

if (!function_exists('_empty_scope')) {
    function _empty_scope(): array
    {
        return [
            'is_admin'    => false,
            'projects'    => [],
            'warehouses'  => [],
            'suppliers'   => [],
            'customers'   => [],
            'employees'   => [],
            'computed_at' => time(),
        ];
    }
}

if (!function_exists('_scope_list_key')) {
    /**
     * Map a resource type (singular) to the session-scope list key (plural).
     */
    function _scope_list_key(string $resourceType): ?string
    {
        static $map = [
            'project'   => 'projects',
            'warehouse' => 'warehouses',
            'supplier'  => 'suppliers',
            'customer'  => 'customers',
            'employee'  => 'employees',
        ];
        return $map[$resourceType] ?? null;
    }
}

if (!function_exists('_scope_column')) {
    /**
     * Map a resource type to the DB column name used in the IN clause.
     */
    function _scope_column(string $resourceType): string
    {
        static $map = [
            'project'   => 'project_id',
            'warehouse' => 'warehouse_id',
            'supplier'  => 'supplier_id',
            'customer'  => 'customer_id',
            'employee'  => 'employee_id',
        ];
        return $map[$resourceType] ?? 'project_id';
    }
}

if (!function_exists('_scope_distinct_ids')) {
    /**
     * Run a SQL fragment that returns a single column of IDs and
     * return a deduped int[]. Returns [] on any failure (defensive).
     */
    function _scope_distinct_ids(PDO $pdo, string $sql, array $params): array
    {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('_scope_distinct_ids: ' . $e->getMessage());
            return [];
        }
    }
}
