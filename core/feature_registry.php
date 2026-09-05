<?php
/**
 * core/feature_registry.php
 * ------------------------
 * The catalogue of switchable FEATURE AREAS, and the per-request answer to
 * "is this feature on for the tenant this request belongs to?".
 *
 * WHAT THIS IS, AND WHAT IT IS NOT (ternant.md Phase 11).
 *
 * This is the ENTITLEMENT axis: what a company's subscription includes. It is
 * NOT the permission axis (`role_permissions`, `canView()` and friends), which
 * answers "which of the things this company HAS may this particular user
 * touch?". The two are independent and are checked in this order:
 *
 *     entitlement (platform decides, tenant cannot influence)
 *         -> permission (the tenant's own admin decides)
 *
 * The entitlement check therefore runs BEFORE every `isAdmin()` bypass — a
 * tenant's own administrator must not be able to reach a module the platform
 * has not granted them. That is the whole point, and it is why this data lives
 * in the CONTROL database, which Phase 9 proved tenants cannot read or write.
 *
 * WHY THE REGISTRY IS CURATED IN CODE AND NOT DERIVED FROM THE DATABASE.
 * `permissions.module_name` looks like it would do the job and must not be used
 * for it: it is a human-facing LABEL for grouping checkboxes on user_roles.php,
 * it is inconsistent (`Inventory` vs `Inventory & Products`, `Settings` vs
 * `System Settings`), and it has no POS / Tenders / Warehouses / Projects split
 * at all. A cosmetic rename of a label must never silently change what is
 * gated. The dead `modules` table (0 rows, `permissions.module_id` NULL
 * throughout) is that same idea abandoned halfway; it is deliberately left
 * alone. This array is reviewable in a PR diff — that is the point.
 *
 * DIRECTORY NAMES LIE HERE — READ BEFORE ADDING A `paths` ENTRY.
 * `app/bms/pos/` holds 47 files of which only 5 are POS; the rest is the entire
 * HR module (payroll, employees, leaves, org chart, recruitment...). Declaring
 * `app/bms/pos/` as the POS feature's path would switch HR off with POS.
 * `app/bms/operations/` likewise mixes Projects and Assets, and
 * `app/bms/stock/` mixes Warehouses with always-on inventory pages. Where a
 * directory is mixed, list FILES, never the directory.
 *
 * Public API:
 *   bmsFeatureRegistry(): array          -> the catalogue
 *   allFeatureKeys(): array
 *   featureForPageKey(string): array     -> owning feature keys (0, 1 or more)
 *   featureForPath(string): ?string      -> owning feature for a request path
 *   tenantFeatures(): array              -> ['pos' => true, 'hr' => false, ...]
 *   tenantFeatureEnabled(string): bool
 *   tenantModuleAllowsPage(string): bool -> the one the permission layer calls
 *   bmsPrimeTenantFeatures(?int): void   -> called once per request by the bootstrap
 */

require_once __DIR__ . '/control_db.php';

if (!function_exists('bmsFeatureRegistry')) {
    /**
     * One entry per switchable feature.
     *
     *   page_keys — drives canView()/canCreate()/canEdit()/canDelete() and the
     *               129 nav checks in header.php. Every key here was verified
     *               against the live `permissions` table (156 rows), not
     *               assumed. A page_key may appear under MORE THAN ONE feature
     *               (`dn` serves both the Sales-outbound and Procurement-inbound
     *               flows); access is granted if ANY owning feature is on.
     *   paths     — request-path prefixes for entry points the router does not
     *               cover (api/, ajax/, and direct file hits). Consumed by
     *               Phase 11.B's bootstrap guard, declared here so the two never
     *               drift apart. See the directory warning above.
     *
     * Page keys deliberately ABSENT from every feature are always reachable:
     * dashboard, customers, products, all Finance, all Reports, CRM, Documents
     * (except e_signatures), Settings and System Settings. A company must always
     * be able to invoice, see its own ledger and manage its own staff access,
     * even with every optional module switched off.
     */
    function bmsFeatureRegistry(): array
    {
        return [
            'sales' => [
                'label'       => 'Sales',
                'description' => 'Quotations, sales orders, LPO, returns and credit notes. Invoicing itself is always available.',
                'default'     => true,
                'sort_order'  => 10,
                'page_keys'   => ['quotations', 'sales_orders', 'lpo', 'sales_returns', 'credit_notes', 'dn'],
                'paths'       => ['api/sales/'],
            ],
            'pos' => [
                'label'       => 'Point of Sale',
                'description' => 'POS terminal, POS dashboard and customer display.',
                'default'     => true,
                'sort_order'  => 20,
                'page_keys'   => ['pos', 'pos_config_settings'],
                // FILES, not the directory — app/bms/pos/ is mostly HR. See warning above.
                'paths'       => [
                    'api/pos/',
                    'api/pos_session.php',
                    'app/bms/pos/pos.php',
                    'app/bms/pos/pos_dashboard.php',
                    'app/bms/pos/pos_modals_new.php',
                    'app/bms/pos/pos_scripts_new.php',
                    'app/bms/pos/customer_display.php',
                    'app/bms/pos/api/pos_controller.php',
                ],
            ],
            'procurement' => [
                'label'       => 'Procurement',
                'description' => 'Suppliers, RFQ, purchase orders, GRN, delivery notes, returns and materials. Tenders is separate.',
                'default'     => true,
                'sort_order'  => 30,
                'page_keys'   => [
                    'suppliers', 'supplier_payments', 'rfq', 'purchase', 'purchase_orders',
                    'purchase_returns', 'grn', 'dn', 'do', 'debit_notes', 'nip_materials',
                ],
                'paths'       => ['api/purchase/'],
            ],
            'tenders' => [
                'label'       => 'Tenders',
                'description' => 'Tender opportunities, submissions and their workflow.',
                'default'     => true,
                'sort_order'  => 40,
                'page_keys'   => ['tenders'],
                'paths'       => ['app/bms/tenders/', 'api/tender_workflow.php', 'api/tender_boq.php', 'api/tender_materials.php'],
            ],
            'warehouses' => [
                'label'       => 'Warehouses',
                'description' => 'Warehouses and bin locations. The product catalogue and stock adjustments stay available regardless.',
                'default'     => true,
                'sort_order'  => 50,
                'page_keys'   => ['warehouses', 'locations'],
                'paths'       => [
                    'app/bms/stock/warehouses.php',
                    'app/bms/stock/warehouse_view.php',
                    'app/bms/stock/locations.php',
                    'app/bms/operations/warehouse_stock_view.php',
                ],
            ],
            'hr' => [
                'label'       => 'Human Resources',
                'description' => 'The whole Operations/Workforce menu: employees, payroll, attendance, leave, org chart, performance, recruitment and ESS.',
                'default'     => true,
                'sort_order'  => 60,
                'page_keys'   => [
                    'employees', 'employee_contracts', 'employee_documents', 'employee_lifecycle',
                    'employee_trips', 'employment_types', 'departments', 'designations',
                    'hr_dashboard', 'hr_checklists', 'hr_expiry_alerts', 'hr_performance',
                    'org_chart', 'recruitment', 'trainings', 'announcements', 'meetings',
                    'my_hr', 'company_calendar', 'attendance', 'attendance_badge',
                    'attendance_clockin', 'attendance_kiosk', 'leaves', 'leave_types',
                    'payroll', 'payslip', 'salary_components',
                ],
                'paths'       => ['api/payroll/'],
            ],
            'assets' => [
                'label'       => 'Assets & Maintenance',
                'description' => 'Asset register, verification and maintenance. Split from HR so it survives when HR is switched off.',
                'default'     => true,
                'sort_order'  => 70,
                'page_keys'   => ['assets', 'maintenance'],
                'paths'       => [
                    'api/assets/',
                    'app/bms/operations/assets.php',
                    'app/bms/operations/asset_dashboard.php',
                    'app/bms/operations/asset_verify.php',
                    'app/bms/operations/asset_view.php',
                    'app/bms/operations/maintenance.php',
                ],
            ],
            'projects' => [
                'label'       => 'Projects',
                'description' => 'Project register, progress/financial reporting, sub-contractors, IPC and inspections.',
                'default'     => true,
                'sort_order'  => 80,
                'page_keys'   => ['projects', 'user_projects'],
                'paths'       => [
                    'app/bms/operations/projects.php',
                    'app/bms/operations/project_view.php',
                    'app/bms/operations/project_budget_report.php',
                    'app/bms/operations/project_financial_report.php',
                    'app/bms/operations/project_progress_report.php',
                    'app/bms/operations/sub_contractors.php',
                    'app/bms/operations/sub_contractor_details.php',
                    'app/bms/operations/inspection_view.php',
                    'app/bms/operations/print_ipc.php',
                ],
            ],
            'ai_assistant' => [
                'label'       => 'AI Assistant',
                'description' => 'Ask BMS and AI-assisted analysis.',
                'default'     => true,
                'sort_order'  => 90,
                'page_keys'   => ['ai_assistant'],
                'paths'       => ['api/ai/', 'api/ai_audit_analysis.php'],
            ],
            'esignature' => [
                'label'       => 'E-Signatures',
                'description' => 'Electronic signature requests, including the public token link an external signer receives by email.',
                'default'     => true,
                'sort_order'  => 100,
                'page_keys'   => ['e_signatures'],
                // sign_document.php is PUBLIC and unauthenticated — no session to
                // gate — so Phase 11.B checks it explicitly in that file too.
                'paths'       => ['ajax/save_drawn_signature.php', 'sign_document.php'],
            ],
        ];
    }
}

if (!function_exists('allFeatureKeys')) {
    /** Every feature key in the catalogue. */
    function allFeatureKeys(): array
    {
        return array_keys(bmsFeatureRegistry());
    }
}

if (!function_exists('featureForPageKey')) {
    /**
     * Which feature(s) own a page_key.
     *
     * Returns [] for a page_key that belongs to no feature — the always-on base
     * set (dashboard, customers, Finance, Reports...). Returns more than one for
     * a shared key such as `dn`.
     */
    function featureForPageKey(string $pageKey): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (bmsFeatureRegistry() as $key => $def) {
                foreach ($def['page_keys'] as $pk) {
                    $index[$pk][] = $key;
                }
            }
        }
        return $index[$pageKey] ?? [];
    }
}

if (!function_exists('featureForPath')) {
    /**
     * Which feature owns a request path, if any. Longest prefix wins, so an
     * explicit file always beats a directory that also matches.
     *
     * Consumed by Phase 11.B's bootstrap guard; defined here so the paths and
     * the catalogue cannot drift into separate files.
     */
    function featureForPath(string $path): ?string
    {
        $path  = ltrim(str_replace('\\', '/', $path), '/');
        $best  = null;
        $bestN = -1;
        foreach (bmsFeatureRegistry() as $key => $def) {
            foreach ($def['paths'] as $prefix) {
                $n = strlen($prefix);
                if ($n > $bestN && strncmp($path, $prefix, $n) === 0) {
                    $best  = $key;
                    $bestN = $n;
                }
            }
        }
        return $best;
    }
}

if (!function_exists('bmsPrimeTenantFeatures')) {
    /**
     * Resolve this request's effective feature set, once, into
     * $GLOBALS['__bms_features'].
     *
     * Called by core/tenant_bootstrap.php the moment a tenant is resolved, on
     * the control connection that is already open — one small indexed read of a
     * ten-row table per tenant request.
     *
     * FAILS OPEN, DELIBERATELY. If the control tables do not exist yet (code
     * deployed before scripts/setup_control_db.php was re-run) or the control DB
     * is briefly unreachable, every feature reports ENABLED. Failing closed would
     * lock every tenant out of every module over an infrastructure hiccup — far
     * worse than briefly serving a module someone had switched off. The same
     * "inert until deliberately switched on" discipline Phase 3 shipped with.
     */
    function bmsPrimeTenantFeatures(?int $tenantId): void
    {
        if ($tenantId === null) {
            $GLOBALS['__bms_features'] = null;   // single-tenant / CLI / platform host -> everything on
            return;
        }

        $effective = [];
        try {
            $st = getControlPdo()->prepare("
                SELECT f.feature_key, f.is_available, f.default_enabled, tf.is_enabled
                FROM features f
                LEFT JOIN tenant_features tf
                       ON tf.feature_key = f.feature_key AND tf.tenant_id = ?
            ");
            $st->execute([$tenantId]);

            foreach ($st->fetchAll() as $row) {
                // Platform availability is absolute: a feature removed for
                // everyone stays off however the tenant's own row reads.
                $effective[$row['feature_key']] = ((int)$row['is_available'] === 1)
                    && ($row['is_enabled'] === null
                        ? (int)$row['default_enabled'] === 1
                        : (int)$row['is_enabled'] === 1);
            }
        } catch (Throwable $e) {
            error_log('bmsPrimeTenantFeatures: falling back to all-enabled — ' . $e->getMessage());
            $GLOBALS['__bms_features'] = null;
            return;
        }

        // A feature in the code registry with no row in the catalogue yet (new
        // key deployed before the seed ran) is ON, matching the fail-open rule.
        foreach (allFeatureKeys() as $key) {
            if (!array_key_exists($key, $effective)) $effective[$key] = true;
        }

        $GLOBALS['__bms_features'] = $effective;
    }
}

if (!function_exists('featureTablesReady')) {
    /**
     * Has `scripts/setup_control_db.php` been run since the entitlement tables
     * were added?
     *
     * This is a real state, not a defensive nicety. The control database is
     * PLATFORM infrastructure and is deliberately created by an operator script,
     * never by a deploy migration — a control-DB migration once failed on a host
     * whose app user lacks CREATE, and `script_stop: true` correctly halted the
     * entire release. So code that reads `features` can and does land on a host
     * where the table does not exist yet, and the panel must say which it is
     * rather than reporting a generic failure the operator cannot act on.
     *
     * The application itself does not need this: bmsPrimeTenantFeatures() already
     * fails open, so a host without the tables simply grants everything.
     */
    function featureTablesReady(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            getControlPdo()->query('SELECT 1 FROM features LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('tenantFeatures')) {
    /**
     * This request's effective feature map, ['pos' => true, 'hr' => false, ...].
     *
     * Everything is on when no tenant was resolved: single-tenant installs, CLI
     * (migrations, tests, cron) and the platform's own hosts. Multi-tenancy
     * being switched off must change nothing about how this application behaves.
     */
    function tenantFeatures(): array
    {
        $f = $GLOBALS['__bms_features'] ?? null;
        if (is_array($f)) return $f;
        return array_fill_keys(allFeatureKeys(), true);
    }
}

if (!function_exists('tenantFeatureEnabled')) {
    /** Is one feature on for this request's tenant? Unknown keys are on. */
    function tenantFeatureEnabled(string $featureKey): bool
    {
        $f = $GLOBALS['__bms_features'] ?? null;
        if (!is_array($f)) return true;
        return $f[$featureKey] ?? true;
    }
}

if (!function_exists('bmsFeatureBlockingPath')) {
    /**
     * Which feature BLOCKS this file/request path, or null if nothing does.
     *
     * Two independent lookups, because neither covers everything on its own:
     *   1. the registry's own `paths` — the only thing that reaches api/ and
     *      ajax/ endpoints, which the router never sees;
     *   2. the file's basename through getPagePermissionMapping() — the
     *      filename→page_key map that already covers ~150 application pages, so
     *      a gated page does not need its path spelled out here as well.
     *
     * Lookup 2 is skipped when core/permissions.php has not been loaded (the
     * bootstrap guard runs long before it), which is exactly why lookup 1 exists.
     */
    function bmsFeatureBlockingPath(string $path): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $path), '/');

        // Absolute paths (the router hands us ROOT_DIR-prefixed files) reduced
        // to repo-relative so the registry's prefixes match.
        if (defined('ROOT_DIR')) {
            $root = rtrim(str_replace('\\', '/', ROOT_DIR), '/') . '/';
            if (strncmp($rel, ltrim($root, '/'), strlen(ltrim($root, '/'))) === 0) {
                $rel = substr($rel, strlen(ltrim($root, '/')));
            }
        }

        // Try the path as given, then each suffix starting one segment later, so
        // a SUBDIRECTORY install resolves the same as a root one. Production
        // serves each tenant at its own subdomain root, where REQUEST_URI is
        // '/api/pos/x.php' — but on an install under '/bms/' it arrives as
        // '/bms/api/pos/x.php', matched no registry prefix, and this layer
        // silently gated nothing. Layer 4 is the ONLY layer covering api/ and
        // ajax/, so losing it there loses those endpoints entirely.
        //
        // Over-matching is safe in the one direction that matters: a suffix only
        // ever blocks when its owning feature is switched OFF, so the worst case
        // is a 404 for a URL that merely looks like a disabled module's path.
        $parts = explode('/', $rel);
        $limit = min(count($parts), 5);
        for ($i = 0; $i < $limit; $i++) {
            $candidate = implode('/', array_slice($parts, $i));
            $owner     = featureForPath($candidate);
            if ($owner !== null) {
                return tenantFeatureEnabled($owner) ? null : $owner;
            }
        }

        if (function_exists('getPagePermissionMapping')) {
            $map     = getPagePermissionMapping();
            $base    = basename($rel);
            $pageKey = $map[$base] ?? null;
            if ($pageKey !== null && !tenantModuleAllowsPage($pageKey)) {
                $owners = featureForPageKey($pageKey);
                return $owners[0] ?? 'unknown';
            }
        }

        return null;
    }
}

if (!function_exists('bmsFeatureHalt')) {
    /**
     * End a request for a feature this tenant does not have.
     *
     * 404, never 403: a 403 confirms the module exists and is merely switched
     * off for you, which is information the platform has no reason to give out.
     * Matches how assertSuperadminHost() already behaves.
     *
     * JSON for api/ajax callers so a fetch() gets a parseable body rather than a
     * page of HTML — the status code is what the acceptance gate asserts, and it
     * is 404 either way.
     */
    function bmsFeatureHalt(string $featureKey): void
    {
        error_log('feature gate: blocked ' . ($_SERVER['REQUEST_URI'] ?? '?')
            . ' — feature "' . $featureKey . '" is not enabled for tenant '
            . (function_exists('bmsCurrentTenantId') ? (string)bmsCurrentTenantId() : '?'));

        $uri    = ltrim(str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? '')), '/');
        $isData = str_contains($uri, 'api/') || str_contains($uri, 'ajax/')
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if (!headers_sent()) {
            http_response_code(404);
            header('Cache-Control: no-store');
            if ($isData) header('Content-Type: application/json');
        }

        if ($isData) {
            echo json_encode(['success' => false, 'message' => 'Not found']);
            exit;
        }

        if (function_exists('bmsTenantHalt')) {
            bmsTenantHalt(404, 'Not found', 'The page you asked for is not available.');
        }
        echo 'Not found';
        exit;
    }
}

if (!function_exists('bmsFeatureGuardPath')) {
    /**
     * The guard every layer calls: 404 the request if its path belongs to a
     * feature this tenant does not have. A no-op when nothing is gated, when no
     * tenant is resolved, and on CLI.
     */
    function bmsFeatureGuardPath(string $path): void
    {
        // No SAPI check on purpose. "Is this CLI?" is the wrong question — the
        // right one is "was a tenant resolved for this request?", and the line
        // below asks exactly that. Real CLI (migrations, cron, the test suites)
        // resolves no tenant and so is never gated, while a test that simulates
        // a request by setting HTTP_HOST *is* gated, which is what makes this
        // layer testable at all.
        if (!is_array($GLOBALS['__bms_features'] ?? null)) return;   // no tenant → everything on

        $blocked = bmsFeatureBlockingPath($path);
        if ($blocked !== null) bmsFeatureHalt($blocked);
    }
}

if (!function_exists('tenantModuleAllowsPage')) {
    /**
     * The question the permission layer asks: may this request's tenant reach
     * anything belonging to $pageKey at all?
     *
     * True when the page belongs to no feature (always-on base set), or when AT
     * LEAST ONE owning feature is enabled — the OR rule that keeps a shared key
     * such as `dn` reachable while either Sales or Procurement is on.
     *
     * Phase 11.A ships this enforcing nothing; Phase 11.B is where
     * canView()/canCreate()/canEdit()/canDelete() begin calling it, ahead of
     * their isAdmin() bypass.
     */
    function tenantModuleAllowsPage(string $pageKey): bool
    {
        $owners = featureForPageKey($pageKey);
        if (!$owners) return true;

        foreach ($owners as $featureKey) {
            if (tenantFeatureEnabled($featureKey)) return true;
        }
        return false;
    }
}
