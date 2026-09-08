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
                // Verified in code, not assumed: sales_order_create.php has
                // warehouse_id as a REQUIRED form field — a Sales Order cannot
                // be created without a warehouse to fulfil it from.
                'depends_on'  => ['warehouses'],
            ],
            'pos' => [
                'label'       => 'Point of Sale',
                'description' => 'POS terminal, POS dashboard and customer display.',
                'default'     => true,
                'sort_order'  => 20,
                // pos_price_override / pos_discount_override (Phase 16,
                // pos_upgrade_plan.md §8) are loss-control RBAC permissions,
                // not a premium tier feature — every plan/tier that has POS
                // at all needs the ability to grant/withhold them per role.
                'page_keys'   => ['pos', 'pos_config_settings', 'pos_price_override', 'pos_discount_override'],
                // Verified in code: pos.php filters sellable stock through
                // userCan('warehouse', ...) — POS sells FROM a warehouse.
                'depends_on'  => ['warehouses'],
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
                    // Phase 8/9 (pos_upgrade_plan.md §7) — registers + shift Z-report.
                    'app/bms/pos/zreport.php',
                    'app/bms/pos/shift_history.php',
                    'core/pos_shift_reporting.php',
                ],
            ],
            // Phase 13 (pos_upgrade_plan.md §7) — the opt-in upsell tier on top
            // of base 'pos'. Z-report/shift history/email receipt/customer
            // picker stayed in base 'pos' (operational hygiene every tenant
            // needs, not a differentiated premium feature); multi-register
            // management and the loyalty points program are the two genuinely
            // upsell-shaped additions, so those are what this actually gates —
            // see pos_config_settings.php's canView('pos_advanced') wrap around
            // the Registers/Tills and Loyalty Program sections, and
            // core/pos_loyalty.php's tenantFeatureEnabled('pos_advanced') check
            // (so a revoked entitlement disables loyalty even if the
            // pos_loyalty_enabled setting itself is still '1').
            'pos_advanced' => [
                'label'       => 'POS Advanced',
                'description' => 'Multi-register/till management, selling price tiers, and the customer loyalty points program.',
                'default'     => false,
                'sort_order'  => 21,
                'page_keys'   => ['pos_advanced'],
                'depends_on'  => ['pos'],
                'paths'       => [
                    'api/pos/get_registers.php',
                    'api/pos/save_register.php',
                    'api/pos/toggle_register_status.php',
                    'core/pos_loyalty.php',
                    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers.
                    'app/bms/pos/price_groups.php',
                    'api/pos/get_price_groups.php',
                    'api/pos/save_price_group.php',
                    'api/pos/toggle_price_group_status.php',
                    'api/pos/get_price_group_products.php',
                    'api/pos/save_price_group_product_price.php',
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
                // sub_contractors.php gates ITSELF with canView('suppliers') —
                // that page's path belongs here, not under 'projects' (see the
                // 'projects' entry below for the bug this fixes).
                'paths'       => [
                    'api/purchase/',
                    'app/bms/operations/sub_contractors.php',
                    'app/bms/operations/sub_contractor_details.php',
                ],
                // Verified in code: grn_create.php has warehouse_id as a
                // REQUIRED form field — a GRN cannot be created without one.
                'depends_on'  => ['warehouses'],
            ],
            'tenders' => [
                'label'       => 'Tenders',
                'description' => 'Tender opportunities, submissions and their workflow.',
                'default'     => true,
                'sort_order'  => 40,
                'page_keys'   => ['tenders'],
                'paths'       => ['app/bms/tenders/', 'api/tender_workflow.php', 'api/tender_boq.php', 'api/tender_materials.php', 'api/tender_checklist.php', 'api/tender_form_of_tender.php', 'api/tender_print.php'],
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
                // sub_contractors.php/sub_contractor_details.php moved OUT of
                // here — see the 'procurement' entry above. Before this fix, a
                // tenant with Projects on and Procurement off would pass this
                // path's guard, then have the page itself deny access via its
                // own canView('suppliers') check: a confusing half-broken
                // state instead of a clean, consistent "not available".
                'paths'       => [
                    'app/bms/operations/projects.php',
                    'app/bms/operations/project_view.php',
                    'app/bms/operations/project_budget_report.php',
                    'app/bms/operations/project_financial_report.php',
                    'app/bms/operations/project_progress_report.php',
                    'app/bms/operations/inspection_view.php',
                    'app/bms/operations/print_ipc.php',
                ],
                // Verified in code: project_view.php directly queries and
                // displays sub_contractors/suppliers (joined via
                // sub_contractor_projects/supplier_projects) as an inline,
                // required part of a project's own page — not optional data.
                'depends_on'  => ['procurement'],
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
            // ── Added 2026-09-07 (tenant_module_control_plan.md, Phase A) ──────
            // These three ran for every tenant regardless of plan until now — an
            // audit of every permissions.page_key against this registry found
            // they were simply never wired in when built as their own projects,
            // not deliberately exempted like the always-on base set above.
            'crm' => [
                'label'       => 'CRM & Marketing',
                'description' => 'Leads, pipeline, activities and campaign management.',
                'default'     => true,
                'sort_order'  => 110,
                'page_keys'   => [
                    'crm_dashboard', 'crm_leads', 'crm_pipeline', 'crm_activities', 'crm_convert',
                    // permission rows with no page behind them yet (see
                    // permissions.module_name = 'Marketing & CRM') — gated now
                    // so switching this feature off is honest about scope even
                    // before they're built.
                    'crm_bulk', 'crm_import', 'crm_labels', 'crm_reports', 'customer_feedback',
                    // Real, routed pages that share this feature — see the
                    // per-file paths below (their directory is mixed with
                    // 'communication', so the directory itself can't be listed).
                    'campaign_management', 'lead_generation',
                ],
                'paths'       => [
                    'api/crm/',
                    'app/bms/crm/',
                    'app/constant/communication/campaign_management.php',
                    'app/constant/communication/lead_generation.php',
                ],
            ],
            'communication' => [
                'label'       => 'Messaging & Reminders',
                'description' => 'In-app message center, notification center, SMS alerts, payment reminders and collection letters.',
                'default'     => true,
                'sort_order'  => 120,
                'page_keys'   => [
                    'message_center', 'notification_center',
                    // permission rows with no page behind them yet (see
                    // permissions.module_name = 'Communication').
                    'sms_alerts', 'payment_reminders', 'collection_letters',
                ],
                'paths'       => [
                    'app/constant/communication/message_center.php',
                    'app/constant/communication/notification_center.php',
                ],
            ],
            'compliance' => [
                'label'       => 'Compliance',
                'description' => 'Compliance documents and the compliance report. Regular Document Library stays available regardless.',
                'default'     => true,
                'sort_order'  => 130,
                // 'compliance' itself has no page behind it yet — gated now for
                // the same honesty-about-scope reason as the CRM/Marketing stubs.
                'page_keys'   => ['compliance', 'compliance_documents', 'compliance_report'],
                'paths'       => [
                    'app/constant/document/compliance_documents.php',
                    'app/constant/reports/compliance_report.php',
                ],
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

/**
 * ── Module dependency graph (tenant_module_control_plan.md, Phase A) ────────
 *
 * A few features are not independent: `projects` embeds supplier/sub-
 * contractor data inline, `sales`/`procurement`/`pos` each have a REQUIRED
 * warehouse field their primary create-flow cannot work without (see the
 * `depends_on` entries above — every one is verified against the actual page
 * code, not assumed from naming). Reference point: Odoo's app manifests
 * declare a `depends` list the same way — installing an app installs its
 * dependencies, and an app cannot be removed while something else still
 * depends on it. These three helpers give `setTenantFeatures()` and
 * `createPlan()`/`updatePlan()` exactly that, as a general mechanism rather
 * than a one-off check for any single pair.
 */

if (!function_exists('featureDependsOn')) {
    /** Direct declared dependencies of one feature. Empty if none or unknown. */
    function featureDependsOn(string $featureKey): array
    {
        return bmsFeatureRegistry()[$featureKey]['depends_on'] ?? [];
    }
}

if (!function_exists('featureDependencyClosure')) {
    /**
     * Expand a set of "wanted enabled" feature keys to include every
     * transitive dependency, so enabling `sales` alone also enables
     * `warehouses` without the caller having to know the graph itself.
     *
     * @param  string[] $keys
     * @return string[] the input keys plus every transitive depends_on,
     *                   deduplicated. Unknown keys are ignored, never invented.
     */
    function featureDependencyClosure(array $keys): array
    {
        $registry = bmsFeatureRegistry();
        $closure  = [];
        $queue    = array_values(array_unique($keys));
        while ($queue) {
            $key = array_pop($queue);
            if (isset($closure[$key]) || !isset($registry[$key])) continue;
            $closure[$key] = true;
            foreach ($registry[$key]['depends_on'] ?? [] as $dep) {
                if (!isset($closure[$dep])) $queue[] = $dep;
            }
        }
        return array_keys($closure);
    }
}

if (!function_exists('featureAllDependents')) {
    /**
     * Every feature in the WHOLE registry that transitively depends on
     * $featureKey, regardless of whether those features are currently enabled
     * for any tenant — a graph fact, not a per-tenant one. Callers intersect
     * this with "currently/about-to-be enabled for THIS tenant" to find a real
     * conflict before rejecting a disable (see setTenantFeatures()).
     *
     * @return string[] feature keys; $featureKey itself is never included
     */
    function featureAllDependents(string $featureKey): array
    {
        $registry   = bmsFeatureRegistry();
        $dependents = [];
        // Fixed-point over "depends on something already found" so a chain
        // (A depends on B depends on C) names A too when C is the one being
        // disabled, not just the directly-declared B.
        $frontier = [$featureKey];
        while ($frontier) {
            $next = [];
            foreach ($registry as $key => $def) {
                if (isset($dependents[$key])) continue;
                foreach ($def['depends_on'] ?? [] as $dep) {
                    if (in_array($dep, $frontier, true)) {
                        $dependents[$key] = true;
                        $next[] = $key;
                        break;
                    }
                }
            }
            $frontier = $next;
        }
        return array_keys($dependents);
    }
}
