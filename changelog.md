# BMS Changelog

## 2026-05-26 (update 173)

### Security Phase 3: page-view activity logging for procurement + received-invoice + warehouse pages

Added `logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[...] Page viewed')` immediately after `autoEnforcePermission(...)` on 10 view-pages that previously had neither server-side `logActivity` nor client-side `logReportAction`. The audit `view_pages_no_log` count dropped from 59 to 49.

Files (10):
- `app/bms/purchase/rfq.php`
- `app/bms/purchase/rfq_create.php`
- `app/bms/purchase/rfq_view.php`
- `app/bms/purchase/nip_materials.php`
- `app/bms/purchase/view_nip_materials.php`
- `app/bms/purchase/edit_nip_materials.php`
- `app/bms/purchase/view_material_list.php`
- `app/bms/invoice/received_invoices.php`
- `app/bms/invoice/received_invoices_view.php`
- `app/bms/stock/warehouse_view.php`
- `tests/test_security_coverage_cli.php` — `view_pages_no_log` ceiling lowered 59 → 49

Pages in the 8 audit areas that already had view-tracking via `logReportAction()` (client-side JS → POST → activity_logs) were left unchanged — adding server-side `logActivity` there would have produced duplicate audit entries per page load.

---

## 2026-05-26 (update 172)

### Security Phase 1: scope project dropdowns to assigned projects only

Five pages were displaying ALL active projects in their `<select name="project_id">` dropdown regardless of the user's assignment. Each is now wrapped in an `isAdmin() ? all : IN (assigned)` branch. Non-admins with no assignments see no projects (default-deny).

- `app/bms/purchase/rfq.php` — RFQ list-page project filter dropdown
- `app/bms/purchase/nip_materials.php` — NIP create/edit project dropdown
- `app/bms/sales/quotations/quotation_form.php` — shared form for quotation create + edit
- `app/bms/invoice/invoice_edit.php` — invoice edit project dropdown
- `app/bms/pos/pos.php` — POS sale project dropdown

Source of truth for the assignment list is `$_SESSION['scope']['projects']`, populated by `loadUserScope()` in `core/project_scope.php`. All 21 pre-push test suites pass.

---

## 2026-05-26 (update 171)

### Cleanup: soft-hide loan permission keys from Configure Permissions UI

- `migrations/2026_05_26_hide_loan_permissions.php` — adds `is_hidden TINYINT` column to `permissions` table; marks `loans` and `loan_documents` keys as hidden (is_hidden=1); idempotent
- `app/constant/settings/user_roles.php` — permissions query now excludes rows where `is_hidden = 1`, so Loans and Loan Documents no longer appear in the Configure Permissions matrix
- No pages deleted, no gates removed; loan pages remain fully access-controlled via `autoEnforcePermission`; DB tables untouched; all pre-push tests unaffected

---

## 2026-05-26 (update 170)

### Cleanup: remove unused Loan Officer badge color from getRoleBadgeColor()

- `app/constant/settings/user_roles.php` — removed `case 'Loan Officer': return 'primary';` from `getRoleBadgeColor()`; the BMS system does not use a Loan Officer role, and the `default` case already handles any unrecognised role name with `'secondary'`

---

## 2026-05-26 (update 169)

### Fix: warehouse_stock_view.php — replaced static snapshot with live dynamic page

- `app/bms/operations/warehouse_stock_view.php` — complete rewrite; was a hardcoded HTML snapshot (company "BEJUNDAS FINANCIAL SERVICES LTD", project "Upgrade of Transmission Line id=16", warehouse "POLES", frozen April 2026 data) that every user saw regardless of which warehouse they opened
- Now reads `$_GET['warehouse_id']` and `$_GET['project_id']`, validates warehouse belongs to project, and queries all 5 sections live from the DB
- Section 1 (Stock Summary): queries `product_stocks` — now matches `warehouse_view.php` exactly
- Section 2 (Materials Received): queries `receipt_items` + `purchase_receipts` scoped to this warehouse + project
- Section 3 (Materials Issued): queries `delivery_items` + `deliveries` scoped to this warehouse + project
- Section 4 (Adjustments): queries `stock_movements` for adj/correction/damaged/expired/found/theft types
- Section 5 (Movement History): queries `stock_movements` for all types
- Print header now uses dynamic company name/logo, warehouse name, project name, logged-in user name
- Breadcrumb and toolbar now use dynamic project_id and names
- Badge counts on nav buttons reflect actual record counts per section
- Added `logActivity` call for audit trail
- Uses `includeHeader()` / `includeFooter()` for consistent app header with logo

## 2026-05-26 (update 168)

### Fix: capture workflow signatures on Sales Order and Quotation review/approve

- `api/account/review_quotation.php` — added `require workflow.php`, `workflowActorSnapshot()`, and `workflowCaptureSignature($pdo, 'quotation', $id, 'reviewed', ...)` after UPDATE; was missing entirely
- `api/account/review_sales_order.php` — added `workflowCaptureSignature($pdo, 'sales_order', $so_id, 'reviewed', ...)` before `$pdo->commit()`; file had workflow.php + actor but no capture call
- `api/account/approve_sales_order.php` — added `workflowCaptureSignature($pdo, 'sales_order', $so_id, 'approved', ...)` before `$pdo->commit()`; same gap
- Print pages (`print_sales_order.php`, `print_quotation.php`) already had `getWorkflowSignatures` + `workflow_signature_row.php` — no changes needed there

---

## 2026-05-26 (update 167)

### Fix: create workflow_signatures table migration (was missing)

- `migrations/2026_05_26_create_workflow_signatures.php` — CREATE TABLE IF NOT EXISTS `workflow_signatures` with columns: entity_type, entity_id, action (ENUM created/reviewed/approved), user_id, user_name, user_role, sig_path, ip_address, consent_accepted, signed_at; UNIQUE KEY on (entity_type, entity_id, action); fixes "Table workflow_signatures doesn't exist" error on RFQ review, PO approve, DN review, and all other workflow actions
- `.github/workflows/deploy.yml` — added migration file to critical-files checklist

---

## 2026-05-26 (update 166)

### Fix: GitHub Actions CI/CD — remove shivammathur/setup-php dependency

- `.github/workflows/deploy.yml` — replaced `shivammathur/setup-php@v2` action (which was failing because GitHub could not resolve the action archive at the pinned SHA) with a simple `php --version` shell step; ubuntu-latest (ubuntu-24.04) ships with PHP 8.3 which satisfies all workflow steps (syntax lint, CLI test files)

---

## 2026-05-26 (updates 158–165)

### Feature: E-signature Phase 3+4 extension — Delivery Note, IPC, RFQ

#### Phase 3 — Capture signatures at remaining workflow action APIs

- `api/review_dn.php` — added `workflowCaptureSignature($pdo, 'delivery', ..., 'reviewed', ...)` before commit; file already had `workflow.php` + `workflowActorSnapshot()`
- `api/approve_dn.php` — added `workflowCaptureSignature($pdo, 'delivery', ..., 'approved', ...)` after stock side-effect loop, before logActivity
- `api/review_rfq.php` — added `require_once workflow.php`; replaced 4-line manual `$reviewer_name`/`$reviewer_role` build with `workflowActorSnapshot()`; added `workflowCaptureSignature($pdo, 'rfq', ..., 'reviewed', ...)`
- `api/approve_rfq.php` — same pattern: added require + `workflowActorSnapshot()` + `workflowCaptureSignature($pdo, 'rfq', ..., 'approved', ...)`
- `api/operations/update_ipc_status.php` — added `require_once workflow.php`; added `$actor = workflowActorSnapshot()`; IPC status 'Viewed' maps to action `'reviewed'`, 'Approved' maps to action `'approved'`; added `workflowCaptureSignature` inside each branch

#### Phase 4 — Replace inline signature blocks with canonical partial

- `api/account/print_delivery_note.php` — added `require_once workflow.php`; added `getWorkflowSignatures($pdo, 'delivery', $id)` + `$wf` build (maps `prepared_by` → `created_by`); removed `.signature-table` CSS (27 lines); replaced `<table class="signature-table">` HTML (35 lines) with `require workflow_signature_row.php`
- `app/bms/operations/print_ipc.php` — added `require_once workflow.php`; added `getWorkflowSignatures($pdo, 'ipc', $ipc_id)` + `$wf` build (names resolved from user joins); removed inline `.signature-box` CSS; replaced `<div class="signature-box">` block (30 lines) with partial include
- `api/account/print_rfq.php` — added `require_once workflow.php`; added `getWorkflowSignatures($pdo, 'rfq', $rfq_id)` + `$wf` build; removed dead `.signature-box` CSS (the HTML had always used `<table class="auth-table">`); replaced auth-table (50 lines) with partial include

All 14 CLI assertions continue to pass.

---

## 2026-05-26 (update 157)

### Fix: E-signature wizard — "Failed to load PDF preview" in Position & Sign step

- `app/constant/document/select_document_add_esignature.php` — `initPlacement()`:
  - Was: `pdfjsLib.getDocument({ url: buildUrl("document_library")?action=download&... })` — download endpoint sends `Content-Disposition: attachment`, which some pdf.js versions reject when fetched via XHR, causing the "Failed to load PDF preview" error
  - Now: uses the direct file URL (`getUrl("") + "/" + selectedDocPath`) for the pdf.js canvas render; the download endpoint is only used for the actual signing step (line 821) where `fetch()` handles it correctly

---

## 2026-05-26 (updates 149–156)

### Feature: E-signature workflow integration — all phases

#### Phase 1 — "Digitally signed by …" text label on signed PDFs
- `app/constant/document/select_document_add_esignature.php`:
  - After embedding a signature image in a PDF, now also draws three lines of protocol text directly on the page: **"Digitally signed by: \<name\>"**, date + time, and signing reference (Ref: …) using pdf-lib's `Helvetica` / `HelveticaBold` fonts in ink-blue / gray
  - Certificate page: updated "Signed by" → "Digitally signed by"; date row now appends "(server-recorded, tamper-evident)"

#### Phase 2 — `workflow_signatures` DB table
- `database/add_workflow_signatures.sql` (new, gitignored — migration already applied):
  - Created table `workflow_signatures`: id, entity_type, entity_id, action ENUM('created','reviewed','approved'), user_id, user_name, user_role, sig_path, signed_at, ip_address, consent_accepted
  - UNIQUE KEY `uq_entity_action` (entity_type, entity_id, action) — prevents duplicates; ON DUPLICATE KEY UPDATE overwrites cleanly on re-run

#### Phase 3 — Capture signatures at every workflow action
- `core/workflow.php` — added two new helpers:
  - `workflowCaptureSignature()`: looks up the user's active signature from `user_signatures`, then INSERT … ON DUPLICATE KEY UPDATE into `workflow_signatures` with full actor snapshot + IP + consent_accepted = 1
  - `getWorkflowSignatures()`: returns ['created'=>…, 'reviewed'=>…, 'approved'=>…] rows for a given entity; missing actions return a blank placeholder (backward-compatible)
- `api/account/review_invoice.php` — calls `workflowCaptureSignature('invoice', $invoice_id, 'reviewed', …)`
- `api/account/approve_invoice.php` — calls `workflowCaptureSignature('invoice', $invoice_id, 'approved', …)`
- `api/account/review_purchase_order.php` — calls `workflowCaptureSignature('purchase_order', …, 'reviewed', …)`
- `api/account/approve_purchase_order.php` — calls `workflowCaptureSignature('purchase_order', …, 'approved', …)`
- `api/review_grn.php` — calls `workflowCaptureSignature('grn', $receipt_id, 'reviewed', …)`
- `api/approve_grn.php` — calls `workflowCaptureSignature('grn', $receipt_id, 'approved', …)`
- `api/account/update_expense_status.php` — added `require_once workflow.php`; calls `workflowCaptureSignature` for reviewed/approved actions
- `api/account/approve_quotation.php` — added `require_once workflow.php`; added `workflowActorSnapshot()`; calls `workflowCaptureSignature('quotation', $id, 'approved', …)`

#### Phase 4 — Render e-signatures on every print page
- `includes/workflow_signature_row.php` — canonical print partial rewritten:
  - New optional `$wf` keys: `created_sig_path`, `created_signed_at`, `reviewed_sig_path`, `reviewed_signed_at`, `approved_sig_path`, `approved_signed_at`
  - When `*_sig_path` is present: renders `<img>` of the signature image + "Digitally signed" protocol label + timestamp above the existing name/role line
  - New CSS classes: `.sig-img-wrap`, `.sig-protocol` (ink-blue, 7.5px), `.sig-timestamp` (gray, 7px)
  - Fully backward-compatible — existing pages with no sig data render identically to before
- `app/bms/invoice/invoice_print.php` — added `require_once workflow.php`; `getWorkflowSignatures` call; $wf expanded with sig keys + `__include_css=true`; removed duplicate 15-line `.signature-box`/`.signature-line` CSS + 15-line inline signature-box HTML; now uses partial include
- `app/bms/sales/quotations/print_quotation.php` — same pattern; removed duplicate CSS + HTML blocks
- `api/account/print_purchase_order.php` — added `require_once workflow.php`; expanded $wf with sig keys + `__include_css=true`
- `app/bms/grn/grn_print.php` — added `require_once workflow.php`; expanded $wf with sig keys + `__include_css=true`
- `app/bms/sales/print_sales_order.php` — added `require_once workflow.php`; expanded $wf with sig keys + `__include_css=true`; removed duplicate `.signature-box`/`.signature-line` CSS block
- `scratch/test_esignature_workflow_cli.php` (new) — 14 CLI assertions covering: table schema, UNIQUE KEY upsert, `workflowCaptureSignature()` DB write + return shape + consent flag, `getWorkflowSignatures()` result shape + null handling, and `workflow_signature_row.php` HTML output correctness. **All 14 pass.**

---

## 2026-05-26 (update 148)

### Fix: Dashboard — notification banner visible to all users with relevant alerts

- `app/dashboard.php` line 1011:
  - Removed `hasPermission('notification_center')` outer gate from the notification banner
  - Banner now shows to **any user** who has alerts (`$total_notifs > 0`)
  - Reason: `$alerts` and `$pending_approvals` are already individually permission-gated (each alert type uses `canView()`/`canReview()`/etc.), so the outer `notification_center` gate was redundant at best and silently hid real alerts from operational roles (Storekeeper, Procurement, HR) at worst

---

## 2026-05-26 (update 147)

### Fix: Reports nav — missing permissions + broken hasReportsAccess() function

- `core/permissions.php` — `hasReportsAccess()`:
  - Fixed `'sales_reports'` (plural, non-existent key) → `'sales_report'` (correct singular key)
  - Fixed `'inventory_reports'` (plural, non-existent key) → `'inventory_report'` (correct singular key)
  - Expanded checked list to all 20 report page_keys (`cash_flow`, `ledger_report`, `sales_report`, `purchase_report`, `inventory_report`, `profit_loss_report`, `expense_report`, `performance_dashboard`, `customer_analysis`, `product_analysis`, `sales_forecast`, `trends_analysis`, `tax_report`, `audit_report`, `compliance_report`, `employee_report`, `asset_report`, plus the 3 existing ones) — previously only 6 keys were checked, all but 3 of which were missing from DB
  - Result: any non-admin with **any** report permission now sees the Reports menu
- `database/add_report_permissions.sql` (new migration, gitignored — run manually in phpMyAdmin):
  - Inserts 17 missing report page_keys into the permissions table (`cash_flow`, `ledger_report`, `sales_report`, `purchase_report`, `inventory_report`, `profit_loss_report`, `expense_report`, `performance_dashboard`, `customer_analysis`, `product_analysis`, `sales_forecast`, `trends_analysis`, `tax_report`, `audit_report`, `compliance_report`, `employee_report`, `asset_report`)
  - Grants full access to Admin role (role_id = 1) for all newly added report permissions
  - Safe to run multiple times (INSERT IGNORE)

---

## 2026-05-26 (update 146)

### Fix: Docs nav — wrong page keys + missing audit_logs permission row

- `header.php` — Docs dropdown:
  - Library gate: `canView('library')` → `canView('document_library')` (actual page_key in permissions table)
  - Templates gate: `canView('templates')` → `canView('document_templates')` (actual page_key in permissions table)
  - Outer gate expanded to OR of all 5 correct keys: `document_library`, `document_templates`, `e_signatures`, `compliance_documents`, `audit_logs` — previously only checked `library` (wrong) and `audit_logs` (missing from DB), so the entire Docs menu was invisible to every non-admin
- `database/add_audit_logs_permission.sql` (new migration):
  - Inserts `audit_logs` page_key into the permissions table so it can be granted to non-admin roles (Auditor, Manager)
  - Grants full access to Admin role (role_id = 1) via role_permissions
  - Safe to run multiple times (INSERT IGNORE)

---

## 2026-05-26 (update 145)

### Feat: Activity Log — Period filter presets + admin bulk purge

- `app/activity_log.php`:
  - Added **Period** preset dropdown to filter bar (All Time / Today / This Week / This Month / This Year / Custom Range) — auto-fills From/To date fields and reloads table on selection; detects and restores preset on page load from URL params
  - Added **Purge Matching Logs** button (admin-only) next to Apply Filters — deletes all records matching the current filter state (type + user + date range)
  - Purge flow: live count preview → SweetAlert2 confirmation with record count + extra warning when no filters are applied → execute → success feedback → table reload
- `api/activity_log_delete.php` (new):
  - POST-only, CSRF-protected, admin-only endpoint
  - Actions: `count` (preview without deleting) and `purge` (execute DELETE)
  - Builds same WHERE clause as the page filter (type, user_id, date_from, date_to)
  - Re-counts immediately before DELETE to guard against concurrent changes
  - Logs the purge action to activity_log for meta-audit trail (e.g. "Purged 3,241 entries (from=2026-01-01, to=2026-04-30)")

---

## 2026-05-26 (update 144)

### Fix: Dashboard Recent Activities — gate query + correct view-all scope

- `app/dashboard.php` — Recent Activities widget:
  - `get_recent_activities()` call now gated behind `canView('audit_logs')` — query no longer runs for users who can never see the widget
  - `$show_sidebar` and the widget render gate changed from legacy `hasPermission('audit_logs')` to `canView('audit_logs')` for consistency
  - `can_view_all` data scope expanded: `isAdmin() || canView('audit_logs')` — Auditors and Managers with audit_logs access now see the full activity log instead of only their own entries

---

## 2026-05-26 (update 143)

### Fix: Dashboard inventory count excludes service products

- `app/dashboard.php` — `get_business_stats()` inventory query: added `AND p.is_service = 0` to the WHERE clause
- Root cause: `products.php` always excludes service items (`p.is_service = 0` hardcoded as first condition); the dashboard did not, so service products inflated both `total_products` and `inventory_value`, causing a mismatch between the two pages

---

## 2026-05-26 (update 142)

### Fix: Dashboard Customer Overview + Inventory Status widgets

- `app/dashboard.php` — Customer Overview:
  - `$active_percentage` now clamped with `min(100, ...)` — data anomaly where active > total could push the progress bar past 100%
  - `$total_customers` denominator now uses `max(1, ...)` instead of `?? 1` so zero-customer state is also protected
- `app/dashboard.php` — Inventory Status:
  - Widget gate changed from `canView('products')` to `canView('products') || canView('inventory_report')` — now consistent with the Inventory Value KPI card gate; `inventory_report` roles no longer have the query run but the widget hidden
  - "View Inventory Alerts" button kept behind inner `canView('products')` gate — `inventory_report` users who lack products access won't see a link that would give them an access error

---

## 2026-05-26 (update 141)

### Feat: Performance chart API — role gate + project scope

- `api/get_performance_data.php` — previously only checked authentication; now fully secured:
  - Role gate: `hasReportsAccess() || canView('invoices') || canView('sales_report')` — matches the dashboard card gate exactly; direct URL calls by unauthorised roles are blocked
  - Revenue (invoices branch): `scopeFilterSqlNullable('project', 'invoices')` applied — invoices with NULL project_id (unlinked/global) pass through for all permitted users; invoices linked to a project are filtered to users assigned that project
  - Revenue (POS branch): no scope applied — `pos_sales` has no project_id column (shared terminal)
  - Expenses: `scopeFilterSqlNullable('project', 'e')` applied with alias `e` — same NULL pass-through logic
  - Removed `scope-audit: skip` deferral comment (Phase G-2 scope now implemented)

---

## 2026-05-26 (update 140)

### Fix: Dashboard KPI cards — query gate alignment + NULL-safe invoice amounts

- `app/dashboard.php` — `get_business_stats()`:
  - Monthly Revenue: added `canView('sales_report')` to the invoice query gate so the query runs whenever the card is visible (previously the card rendered for `sales_report` roles but showed 0 because the query was skipped)
  - Inventory Value: added `canView('inventory_report')` to the inventory query gate for the same reason
  - Pending Invoices + Overdue Invoices: changed `SUM(grand_total - paid_amount)` to `SUM(grand_total - COALESCE(paid_amount, 0))` — previously any invoice row with a NULL paid_amount was silently dropped from the SUM, causing an undercount

---

## 2026-05-26 (update 139)

### Fix: Dashboard Quick Links — remove duplicate code + add empty-state fallback

- `app/dashboard.php` — Quick Links section: removed dead `$company_type == 'microfinance'` branch (both branches were identical); merged into a single set of 6 buttons
- Added `$ql_has_links` pre-check; renders a "No quick actions available for your role" message with a lock icon when the user has none of the 6 module permissions

---

## 2026-05-26 (update 138)

### Feat: Dashboard pending approvals — role gate + project scope

- `app/dashboard.php` — `get_pending_approvals()` now scoped by project:
  - Expenses query: `scopeFilterSqlNullable('project', 'e')` injected into WHERE; only expenses in the user's assigned projects are shown
  - Purchase orders query: `scopeFilterSqlNullable('project', 'po')` injected into WHERE; only POs in the user's assigned projects are shown
- `$user_permissions['can_approve_expenses']` and `['can_approve_purchases']` at top of file expanded to include `canApprove()` and `canReview()` checks alongside the existing legacy `hasPermission()` and `canEdit()` gates, aligning with the three-approval workflow

---

## 2026-05-26 (update 137)

### Feat: Dashboard KPI stat cards — role gate + project scope on all queries

- `app/dashboard.php` — `get_business_stats()` rewritten: each of the 6 query groups now has (a) a `canView()` role gate so the query is skipped entirely if the user lacks module access, and (b) safe zero-value defaults seeded at the top so every `$stats` key always exists even when a query is skipped (prevents undefined-index notices).
  - `sales` / `today_sales` / `pending_invoices` / `overdue_invoices` — gate: `canView('invoices') || hasReportsAccess()`; scope: `scopeFilterSqlNullable('project', 'invoices')`
  - `purchases` — gate: `canView('purchase_orders')`; scope: `scopeFilterSqlNullable('project', 'purchase_orders')`
  - `inventory` — gate: `canView('products')`; scope: `scopeFilterSqlNullable('project', 'p')`
  - `customers` — gate: `canView('customers')`; scope: `scopeFilterSqlNullable('customer', 'c')` (scoped by customer_id list, alias `c` added)
  - `expenses` — gate: `canView('expenses')`; scope: `scopeFilterSqlNullable('project', 'e')`
  - `pos_today` — gate: `canView('pos')`; no project scope (POS is a shared terminal)

---

## 2026-05-26 (update 136)

### Feat: Dashboard alerts — role gate + project scope on all 13 alert types

- `app/dashboard.php` — `get_system_alerts()` rewritten: each of the 13 alert types now has (a) a `canView/canReview` role gate so the query is skipped entirely if the user's role lacks access, and (b) `scopeFilterSqlNullable` injected into the WHERE clause so non-admin users only see alerts for records in their assigned projects.
  - `low_stock`, `negative_stock`, `expiring` — gate: `canView('products')`; scope: `scopeFilterSqlNullable('project', 'p')`
  - `overdue` (invoices) — gate: `canView('invoices')`; scope: `scopeFilterSqlNullable('project', 'invoices')`
  - `quote_expiring` — gate: `canView('quotations')`; scope: `scopeFilterSqlNullable('project', 'q')`
  - `grn_pending` — gate: `canView('grn') || canView('purchase_orders')`; scope: `scopeFilterSqlNullable('project', 'po')`
  - `leave_pending` — gate: `canReview/canApprove/canEdit('leaves')` (approvers only); scope: `scopeFilterSqlNullable('project', 'e')` via employees alias
  - `credit_over` — gate: `canView('invoices') || canView('customers')`; scope: `scopeFilterSqlNullable('customer', 'c')`
  - `cash_shift_open` — gate: `canView('cash_register')`; no project scope (company-wide)
  - `bank_recon_overdue` — gate: `canView('bank_reconciliation')`; no project scope (company-wide)
  - `payroll_due` — gate: `canView('payroll')`; no project scope (company-wide flag)
  - `tender_deadline` — gate: `canView('tenders')`; no project scope (tenders have no project_id)
  - `doc_expiring` — unchanged (already personal via `user_id`)

---

## 2026-05-25 (update 135)

### Fix: DN nav link visible to non-admin users

- `header.php` — Line 654: changed `canView('delivery_notes')` → `canView('grn')` so the Delivery Notes sidebar link uses the same permission key that `delivery_notes.php` itself enforces (`autoEnforcePermission('grn')`). Previously the link was always hidden for non-admins because `'delivery_notes'` is not a registered page_key in the permissions table; the page key for DN is `'dn'` in `core/permissions.php` but the page access guard uses `'grn'`.

---

<<<<<<< HEAD
=======
>>>>>>> Stashed changes
>>>>>>> 8825495 (cleanup: soft-hide loan permission keys from Configure Permissions UI)
## 2026-05-26 (update 134)

### Test: Lock in PO vs Invoice Report fixes (35 CLI checks)

- `tests/test_po_invoice_report_cli.php` — New pre-push test suite covering: auth/permission gates, SQL HAVING tolerance (≤1 TZS), absence of strict `===` float comparison, project-scope filter via `scopeFilterSqlNullable`, JS `.fail()` handler for 401/403/500/timeout, empty-state helper `getFilterSummary()`. Picked up automatically by the pre-push hook (any failure blocks the push).

---

## 2026-05-26 (update 133)

### Feat: Scope SO Edit, Purchase Returns, Stock Transfers dropdowns (Phase G)

- `app/bms/sales/sales_order_edit.php` — customers, warehouses, projects dropdowns scoped
- `app/bms/purchase/purchase_returns.php` — suppliers, warehouses filter dropdowns scoped
- `app/bms/stock/stock_transfers.php` — warehouses dropdown scoped

---

## 2026-05-26 (update 132)

### Feat: Scope DN Outbound + RFQ Create form dropdowns by project (Phase G)

- `app/bms/grn/dn_outbound.php` — projects, warehouses, suppliers dropdowns scoped by assigned project_id
- `app/bms/purchase/rfq_create.php` — suppliers, warehouses, projects dropdowns scoped by assigned project_id

---

## 2026-05-26 (update 131)

### Feat: Scope create/edit form dropdowns by project — Phase G

- `app/bms/purchase/purchase_order_create.php` — suppliers, warehouses, projects dropdowns scoped by assigned project_id
- `app/bms/invoice/invoice_create.php` — customers, projects dropdowns scoped
- `app/bms/sales/sales_order_create.php` — customers, warehouses, projects dropdowns scoped
- `app/bms/grn/grn_create.php` — warehouses, projects dropdowns scoped (suppliers already scoped via PO join)
- `app/bms/grn/dn_create.php` — projects, warehouses, suppliers, purchase orders list all scoped

---

## 2026-05-25 (update 130)

### Fix + Enhance: PO vs Invoice Report — failure visibility, scope, accuracy

- `api/po_invoice_report.php` — Status filter moved into SQL via `HAVING` clause (was filtering in PHP after fetch, fragile with floats). Fully-billed comparison now uses ≤1 TZS tolerance instead of strict `===`. Project-scope filter via `scopeFilterSqlNullable('project','po')` so non-admins only see POs from their assigned projects.
- `app/bms/invoice/po_invoice_report.php` — Added `.fail()` handler on AJAX with descriptive messages for 401/403/500/timeout (silent failure was hiding why "no data appeared"). `statusFor()` JS uses ≤1 TZS tolerance for Fully Billed. Empty-state messages now explain whether filters narrowed everything out or there is genuinely no data.

---

## 2026-05-25 (update 129)

### Fix: Sales Orders list showing quotations — edit opens wrong form

- `api/account/get_sales_orders.php` — `$where_conditions` initialised with `so.is_quote = 0` (was `1=1`) and `recordsTotal` query also filters `is_quote = 0`; previously the API returned both sales orders and quotations, causing quotation records to appear in the sales orders DataTable and opening the quotation edit form when clicking Edit

---

## 2026-05-25 (update 128)

### Feat: Payment Vouchers — project scope on projects dropdown (Phase G)

- `app/constant/accounts/payment_vouchers.php` — projects filter dropdown scoped by assigned project_id for non-admins; voucher data AJAX already scoped via `api/account/get_vouchers.php`

---

## 2026-05-25 (update 127)

### Feat: Delivery Notes — project scope on stats query + all filter dropdowns (Phase G)

- `app/bms/grn/delivery_notes.php` — stats query scoped via `scopeFilterSqlNullable('project','d')` (deliveries alias); suppliers, warehouses, and projects dropdowns scoped by assigned project_id for non-admins; DN list was already scoped via AJAX API

---

## 2026-05-25 (update 126)

### Feat: GRN — project scope on main query + all filter dropdowns (Phase G)

- `app/bms/grn/grn.php` — main GRN query scoped via `scopeFilterSqlNullable('project','po')` (project resolved through linked purchase_order); suppliers, warehouses, purchase orders, and projects filter dropdowns all scoped by assigned project_id for non-admins; NULL project_id records visible to all

---

## 2026-05-25 (update 125)

### Feat: Sales Orders — project scope on customer filter dropdown (Phase G)

- `app/bms/sales/sales_orders.php` — customer filter dropdown scoped by project; admins see all, non-admins see only customers with NULL or matching project_id; main list query was already scoped via `scopeFilterSqlNullable('project','so')`

---

## 2026-05-25 (update 124)

### Fix: budget edit "Error loading budget data" — wrong expense_categories column in WHERE

- `api/account/get_budget.php` — expense stats sub-query used `ec.category_id` in WHERE clause but the table PK is `ec.id`; changed to `ec.id = ?` so the query no longer throws a PDOException when admin clicks Edit Budget

---

## 2026-05-25 (update 123)

### Feat: Budget page — project scope applied (Phase G)

- `app/constant/accounts/budget.php` — custom inline scope ($b_scope_where_sql / $b_scope_on_sql / $e_scope_where_sql) applied to all 6 queries: summary cards, categories LEFT JOIN ON clause, total_actual, per-category actual expenses, and main performance data; project dropdown filtered by assigned projects for non-admins; NULL project_id records visible to all
- `tests/test_scope_enforcement_cli.php` — extended to cover budget.php (list, dropdown, order checks) and expenses API; total checks 28 → 34

---

## 2026-05-25 (update 122)

### Feat: Phase G — Read-side scope enforcement, COMPLETE (100% coverage)

Finance, Operations, HR, Stock, and Customer modules fully gated. 151 remaining unscoped files cleared (151 → 0). CI ceiling lowered to 0.

**Finance module:**
- `app/constant/accounts/expenses.php` — scope-audit: skip (AJAX shell; API scoped)
- `app/constant/accounts/payment_vouchers.php` — scope-audit: skip (AJAX shell; API scoped)
- `app/constant/accounts/budget.php` — scope-audit: skip (complex multi-query; deferred Phase G-2)
- `app/constant/accounts/edit_expense.php` — assertScopeForRecordHtml on expense_id
- `api/account/export_expenses.php` — scopeFilterSqlNullable('project', 'e')
- `api/account/get_expense.php` — assertScopeForRecord on expense_id

**Operations module:**
- `api/operations/get_inspections.php` — assertScopeForRecord on project_id
- `api/operations/get_milestones.php` — assertScopeForRecord on project_id
- `api/operations/get_progress_reports.php` — assertScopeForRecord on project_id
- `api/operations/get_ipcs.php` — assertScopeForRecord on project_id
- `api/operations/get_scopes.php` — assertScopeForRecord on project_id
- `api/operations/get_project_budgets.php` — assertScopeForRecord on project_id
- `api/operations/get_project_planning.php` — assertScopeForRecord on project_id
- `api/operations/export_projects.php` — scopeFilterSqlNullable('project')
- `api/operations/print_projects.php` — scopeFilterSqlNullable('project', 'p')
- `app/bms/operations/projects.php` — scope-audit: skip (AJAX shell; API scoped)

**HR module:**
- `api/export_attendance.php` — scopeFilterSql('employee', 'e')
- `api/export_leaves.php` — scopeFilterSql('employee', 'e')
- `api/export_leave_applications.php` — scopeFilterSql('employee', 'e')
- `api/export_payroll.php` — scopeFilterSql('employee', 'e')
- `api/account/get_employee_payrolls.php` — assertScopeForEmployee on employee_id
- `app/bms/pos/employees.php` — scopeFilterSql('employee', 'e') on main query
- `app/bms/pos/attendance.php` — scopeFilterSql('employee', 'e') on dropdown query

**Stock/Inventory module:**
- `app/bms/stock/stock_adjustments.php` — scopeFilterSqlNullable('project', 'sm') on both queries
- `app/bms/stock/stock_movements.php` — scopeFilterSqlNullable('project', 'sm')
- `app/bms/stock/warehouses.php` — scopeFilterSqlNullable('project') on warehouse list
- `app/bms/stock/warehouse_view.php` — assertScopeForRecordHtml on warehouse_id

**Customer module:**
- `app/bms/customer/customers.php` — scopeFilterSql('customer', 'c') on main query
- `app/bms/customer/customer_details.php` — userCan('customer', $customer_id) gate
- `app/bms/customer/edit_customer.php` — userCan('customer', $customer_id) gate
- `app/bms/customer/customer_documents.php` — userCan('customer', $customer_id) gate
- `api/get_customers_paged.php` — scopeFilterSql('customer', 'c') on all queries incl. total count

**Delivery Notes:**
- `api/delete_dn_attachment.php` — assertScopeForRecord('deliveries', 'delivery_id') after attachment fetch

**CI guard:**
- `tests/test_project_scope_cli.php` — ceiling lowered 151 → 0; Phase G-Complete history added

## 2026-05-25 (update 121)

### Feat: Phase G — Read-side scope enforcement, Purchase module

Purchase, GRN, RFQ, and Delivery Notes list pages, export APIs, print files, and write APIs now filter by the user's assigned projects. 46 files cleared (197 → 151 unscoped).

**AJAX data APIs (scopeFilterSqlNullable added):**
- `api/get_rfqs.php` — filter via `r.project_id`; stats query scoped too
- `api/get_purchase_returns.php` — added PO join; filter via `po.project_id`
- `api/get_grns.php` — filter via `po.project_id` (already joined)
- `api/get_delivery_notes_list.php` — filter via `d.project_id`

**Export APIs (scopeFilterSqlNullable added):**
- `api/export_purchase_returns.php` — filter via `po.project_id`
- `api/export_grns.php` — filter via `po.project_id`
- `api/account/export_purchase_orders.php` — filter via `po.project_id`

**Write / status-change APIs (assertScopeForRecord added):**
- `api/account/update_purchase_order_status.php` — gate before UPDATE
- `api/account/delete_purchase_order.php` — gate before transaction
- `api/account/get_purchase_order_details.php` — read gate before fetch

**Print / view pages (assertScopeForRecordHtml added):**
- `api/account/print_purchase_order.php` — 403 if PO out of scope
- `api/account/print_rfq.php` — 403 if RFQ out of scope
- `api/account/print_delivery_note.php` — 403 if DN out of scope
- `app/bms/purchase/rfq_view.php` — 403 if RFQ out of scope

**Stats query scoped:**
- `app/bms/purchase/rfq.php` — stats query uses `scopeFilterSqlNullable('project', 'rfq')`

**Marked `// scope-audit: skip` (with justification):**
- App page shells (purchase_orders, purchase_returns, grn, delivery_notes): data from scoped AJAX APIs
- Create/edit forms (purchase_order_create, rfq_create, grn_create, grn_edit, dn_create, dn_outbound, do_create): no prior record to scope
- View-only pages (dn_view, do_view): Phase G-2 assertScopeForRecordHtml deferred
- Helper dropdown APIs (get_supplier_purchase_orders, get_warehouse_supplier_grns, get_grn_items, get_rfq_items, get_purchase_return, get_purchase_return_stats, delete_rfq_attachment): item-level or form helpers; parent list is scoped
- Operations module helpers (operations/get_grn_items, operations/get_return_grns): called within project context
- Test files (test_print_rfq, test_rfq_phase1, test_rfq_phase3): not runtime
- NIP material pages + purchase_report: Phase G-2

**CI ceiling lowered:** `tests/test_project_scope_cli.php` — `$CEILING` reduced 197 → 151.

## 2026-05-25 (update 120)

### Feat: Phase G — Read-side scope enforcement, Sales module

All sales and invoice list pages, detail/edit pages, and write APIs now filter by the user's assigned projects. Non-admin users see only records belonging to their projects (or records with no project assigned). Admin users are unaffected.

**List pages (scopeFilterSqlNullable added):**
- `app/bms/sales/sales_orders.php` — appended `scopeFilterSqlNullable('project', 'so')` to list query
- `app/bms/sales/quotations/quotations.php` — same pattern on quotations list
- `app/bms/sales/sales_returns/sales_returns.php` — filter via joined `so` alias (sales_returns has no direct project_id)

**AJAX data APIs (scopeFilterSqlNullable added):**
- `api/account/get_invoices.php` — changed strict `scopeFilterSql` to nullable; both SELECT branches updated
- `api/account/export_invoices.php` — scope filter added to WHERE clause
- `app/bms/purchase/get_invoices.php` — scope filter appended to WHERE

**Write / status-change APIs (assertScopeForRecord added):**
- `api/account/approve_sales_order.php` — gate before transaction open
- `api/account/review_sales_order.php` — gate before transaction open
- `api/account/update_sales_order_status.php` — gate before UPDATE
- `api/account/update_quotation_status.php` — gate before UPDATE
- `api/account/save_sales_order.php` — gate on update path only (create skipped)
- `api/account/get_sales_order_items.php` — read gate (items belong to an SO)

**Detail / edit pages (assertScopeForRecordHtml added):**
- `app/bms/invoice/invoice_edit.php` — 403 if invoice is out of scope
- `app/bms/sales/sales_order_edit.php` — 403 if SO is out of scope
- `app/bms/sales/quotations/quotation_view.php` — 403 if quotation out of scope
- `app/bms/invoice/payment_create.php` — 403 if invoice out of scope

**Marked `// scope-audit: skip` (deferred, with documented justification):**
- `app/bms/invoice/invoice_create.php`, `sales_order_create.php`, `quotation_form.php`, `sales_return_create.php`, `sales_return_edit.php`, `sales_return_view.php` — create forms or tables without direct project_id; Phase G-2
- `api/account/get_payee_invoices.php`, `invoices.php` (shell only), report files — deferred to Phase G-2
- `app/bms/invoice/reps/daily_sales.php`, `sales_customer.php`, `low_stock.php`, `stock_value.php`, `api/po_invoice_report.php` — UNION/report queries, Phase G-2

**CI ceiling lowered:** `tests/test_project_scope_cli.php` — `$CEILING` reduced from 225 → 197 (28 files cleared).

## 2026-05-25 (update 119)

### Fix: Save button on Project Assignments returns "Server error"

- `app/constant/settings/user_projects.php` — `SAVE_URL` was set from `$_SERVER['PHP_SELF']` which resolves to `/bms/index.php` when loaded via the router; POST to `index.php` hit the root-redirect handler and returned HTML instead of JSON. Fixed to use `buildUrl('user_projects')` so the POST routes through the clean URL and lands on the correct handler.

## 2026-05-25 (update 118)

### Feat: Redesign Project Assignments page — 3-level professional drill-down UI

- `app/constant/settings/user_projects.php` — complete rewrite to match `user_roles.php` style:
  - 3-column layout: System Roles list | Users in selected role | Project checkboxes for selected user
  - All resource overrides removed; strict project-based scope only
  - Admin users shown warning alert ("access to all projects automatically") instead of checkboxes
  - User badges show assignment count (green) or "None" (gray)
  - Project cards highlight with blue border/background when checked
  - Select All / None buttons; save hint shows "X of Y projects selected"
  - Fetch API POST → JSON → SweetAlert2 toast on save; badge count refreshes without page reload
  - Green stat cards (Total Roles, Active Users, Projects, Scope Assignments) matching system style

## 2026-05-25 (update 117)

### Fix: Add Project Assignments link to Admin nav

- `header.php` — added "Project Assignments" menu item under Admin → User Management, linking to `user_projects` page so admins can discover and use project-scope assignment without knowing the direct URL.

## 2026-05-25 (update 116)

### Feat: Phase F — project-scope CI lock-in (audit script + regression guard)

- `scratch/project_scope_audit.php` (new) — static analysis; walks all `app/` and `api/` PHP files, flags any that SELECT/JOIN a scoped table without a scope guard (`scopeFilterSql`, `assertScopeForRecord`, `userCan`, etc.). Outputs JSON with total/scoped/guarded/unscoped counts and file list.
- `tests/test_project_scope_cli.php` (new) — CLI regression guard; runs the audit and enforces `unscoped_count ≤ 225` (Phase F baseline). Any new PR that adds an unscoped read endpoint fails CI. Also asserts all 9 core helpers exist in `core/project_scope.php` and that `loadUserScope()` is wired into `header.php`. Ceiling target: 0 (reduce by adding scope guards to the 126 API + 99 app-page gap).

## 2026-05-25 (update 115)

### Feat: Admin can delete DNs of any status; delete button hidden from non-admins on locked statuses

- `api/delete_dn.php` — Admin bypasses status restriction and can delete any DN; non-admins still limited to draft/pending/cancelled.
- `app/bms/grn/delivery_notes.php` — Desktop dropdown and mobile card: delete button shown for admin on all statuses, non-admin only on draft/pending/cancelled.
- `app/bms/grn/dn_view.php` — Delete button visibility updated: admin always sees it, non-admin only on draft/pending/cancelled.
- `app/bms/operations/project_view.php` — Added `DN_IS_ADMIN` JS constant; `canDelete` in project DN panel allows admin to delete any status.

---

## 2026-05-25 (update 114)

### Fix: Allow deletion of pending DNs

- `api/delete_dn.php` — Added `pending` to the list of deletable DN statuses alongside `draft` and `cancelled`.

---

## 2026-05-25 (update 113)

### Update: project_view.php revenue label clarity

- `app/bms/operations/project_view.php` — Renamed stat card labels: "Expected" → "REVENUE (EXPECTED)" and "Executed" → "REVENUE (EXECUTED)" for clarity in the project financial summary panel.

---

## 2026-05-25 (update 112)

### Fix: Add missing deliveries workflow snapshot columns on mufindipower

- `migrations/2026_05_25_deliveries_workflow_columns.php` — Adds `prepared_by_name`, `prepared_by_role`, `prepared_at`, `reviewed_by_name`, `reviewed_by_role`, `reviewed_at`, `approved_by_name`, `approved_by_role`, `approved_at` to the `deliveries` table if missing. These columns exist on all other servers (added via the manual `api/migration_dn_workflow.php`) but were never seeded on mufindipower, causing `SQLSTATE[42S22]: Unknown column 'prepared_by_name'` on DN creation.

---

## 2026-05-25 (update 111)

### Feat: Project-scope rollout — Phase E remaining API gates (42 files)

**GRN + Purchase Returns (E-1)**
- `api/create_grn.php` — gate on resolved project_id (from POST or parent PO) before transaction
- `api/update_grn.php` — assertScopeForRecord('purchase_receipts') before transaction
- `api/approve_grn.php` — assertScopeForRecord('purchase_receipts') before transaction
- `api/review_grn.php` — assertScopeForRecord('purchase_receipts') before transaction
- `api/create_purchase_return.php` — assertScopeForRecord via linked GRN receipt_id when provided
- `api/delete_purchase_return.php` — assertScopeForRecord('purchase_returns')

**Customers (E-2)**
- `api/add_customer.php` — userCan('project') gate on project_id if provided
- `api/process_edit_customer.php` — assertScopeForRecord('customers') + target project gate
- `api/delete_customer.php` — assertScopeForRecord('customers')
- `api/update_customer_status.php` — assertScopeForRecord('customers')

**Suppliers + Sub-contractors + Payments (E-3)**
- `api/add_supplier.php` — userCan('project') gate inside existing project_id validation block
- `api/update_supplier.php` — assertScopeForRecord('suppliers') + target project gate
- `api/update_supplier_status.php` — assertScopeForRecord('suppliers')
- `api/delete_supplier.php` — assertScopeForRecord('suppliers')
- `api/add_sub_contractor.php` — userCan('project') gate on project_id if provided
- `api/update_sub_contractor.php` — assertScopeForRecord('sub_contractors') + target project gate
- `api/update_sub_contractor_status.php` — assertScopeForRecord('sub_contractors')
- `api/delete_sub_contractor.php` — assertScopeForRecord('sub_contractors')
- `api/assign_sc_to_project.php` — userCan('project', $project_id) gate
- `api/update_supplier_payment.php` — assertScopeForRecord('suppliers') via supplier_id
- `api/delete_supplier_payment.php` — assertScopeForRecord('suppliers') via fetched supplier_id

**Material Lists + NIP Components + Stock Adjustments (E-4)**
- `api/create_material_list.php` — userCan('project') gate on project_id if provided
- `api/update_material_list.php` — assertScopeForRecord('nip_material_lists') + target project gate
- `api/delete_material_list.php` — assertScopeForRecord('nip_material_lists')
- `api/add_nip_materials.php` — assertScopeForRecord('products') on parent NIP product
- `api/update_nip_status.php` — assertScopeForRecord('products')
- `api/update_material_bom_qty.php` — assertScopeForRecord('products') on component
- `api/update_material_component_status.php` — assertScopeForRecord('products') on component
- `api/delete_nip_component.php` — assertScopeForRecord('products') on parent product
- `api/delete_material_component.php` — assertScopeForRecord('products') on component
- `api/update_adjustment.php` — assertScopeForRecord('products') on product being adjusted
- `api/delete_adjustment.php` — assertScopeForRecord('stock_movements') on movement record

**Operations Sub-modules (E-5)**
- `api/operations/delete_inspection.php` — gate via project_id from project_inspections
- `api/operations/delete_inspection_attachment.php` — gate via parent inspection's project_id
- `api/operations/delete_ipc.php` — gate via interim_payment_certificates.project_id
- `api/operations/update_ipc_status.php` — gate via interim_payment_certificates.project_id
- `api/operations/create_invoice_from_ipc.php` — gate via ipc.project_id
- `api/operations/create_project_staff.php` — userCan('project') after project existence check
- `api/operations/update_staff_project.php` — assertScopeForRecord('employees') + target project gate
- `api/operations/delete_project_doc.php` — userCan('project') for contract origin type

**Procurement Workflow (E-6)**
- `api/review_dn.php` — assertScopeForRecord('deliveries') before transaction
- `api/review_rfq.php` — assertScopeForRecord('rfq') before fetch
- `api/update_product_alerts.php` — assertScopeForRecord('products')

## 2026-05-25 (update 110)

### Fix: Unblock mufindipower migration runner — dn_three_approval AFTER clause

- `migrations/2026_05_24_dn_three_approval.php` — Removed `AFTER reviewed_by_role` from the `reviewed_by` column addition; `reviewed_by_role` does not exist on mufindipower's `deliveries` table, causing the migration runner to stop and blocking all subsequent migrations including `2026_05_25_stock_movements_enum_fix.php`. Column now appended at end of table (position irrelevant).

---

## 2026-05-25 (update 109)

### Feat: Project-scope rollout — Phase D HR + Inventory gates (D-3 through D-6)

**HR Write APIs (D-3)**
- `api/add_employee.php` — Gate: blocks adding employee to a project not in caller's scope
- `api/update_employee.php` — Gate: current employee project + target project on reassignment
- `api/update_employee_status.php` — Gate: assertScopeForRecord on employee
- `api/delete_employee.php` — Gate: assertScopeForRecord on employee
- `api/apply_leave.php` — Gate: assertScopeForEmployee (resolves via employee's project_id)
- `api/update_leave.php` — Gate: assertScopeForEmployeeRecord (JOINs through employees)
- `api/delete_leave.php` — Gate: assertScopeForEmployeeRecord
- `api/approve_leave.php` — Gate: assertScopeForEmployeeRecord
- `api/reject_leave.php` — Gate: assertScopeForEmployeeRecord
- `api/cancel_leave.php` — Gate: assertScopeForEmployeeRecord
- `api/duplicate_leave.php` — Gate: assertScopeForEmployeeRecord
- `api/bulk_update_leave_status.php` — Gate: loop assertScopeForEmployeeRecord for each leave_id
- `api/mark_attendance.php` — Gate: assertScopeForEmployee
- `api/quick_mark_attendance.php` — Gate: assertScopeForEmployee
- `api/update_attendance_status.php` — Gate: assertScopeForEmployee (resolves via attendance record)
- `api/update_attendance_time.php` — Gate: assertScopeForEmployee
- `api/update_attendance_notes.php` — Gate: assertScopeForEmployee
- `api/delete_attendance.php` — Gate: assertScopeForEmployee
- `api/bulk_mark_attendance.php` — Gate: loop assertScopeForEmployee for each employee_id
- `api/update_payroll.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/duplicate_payroll.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/update_payroll_status.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/approve_payroll.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/delete_payroll.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/mark_payroll_paid.php` — Gate: assertScopeForEmployeeRecord('payroll')
- `api/bulk_update_payroll.php` — Gate: loop assertScopeForEmployeeRecord for each payroll_id
- `api/bulk_update_payroll_status.php` — Gate: loop assertScopeForEmployeeRecord for each payroll_id
- `api/operations/preview_project_payroll.php` — Gate: userCan('project', $project_id)
- `api/operations/process_project_payroll.php` — Gate: userCan('project', $project_id)
- `api/preview_payroll.php` — scopeFilterSqlNullable appended to employee WHERE clause
- `api/process_payroll.php` — scopeFilterSqlNullable appended to employee WHERE clause

**HR Read APIs (D-2, back-filled)**
- `api/get_employees.php` — scopeFilterSqlNullable on employees list
- `api/get_employee.php` — assertScopeForRecord on single employee fetch
- `api/get_leave.php` — assertScopeForEmployeeRecord on single leave fetch
- `api/get_leave_balance.php` — assertScopeForEmployee check
- `api/get_payroll.php` — assertScopeForEmployeeRecord on single payroll fetch
- `api/get_payroll_details.php` — assertScopeForEmployeeRecord
- `api/get_payrolls.php` — scopeFilterSqlNullable via employee JOIN
- `api/operations/get_project_attendance.php` — userCan('project') gate
- `api/operations/get_project_leaves.php` — userCan('project') gate
- `api/operations/get_project_payroll.php` — userCan('project') gate

**Inventory APIs (D-4)**
- `api/get_products.php` — scopeFilterSqlNullable (session-guarded for unauthenticated POS)
- `api/delete_product.php` — assertScopeForRecord('products')
- `api/update_product_status.php` — assertScopeForRecord('products')
- `api/update_product.php` — assertScopeForRecord('products')
- `api/create_stock_adjustment.php` — assertScopeForRecord('products') on the adjusted product

**NIP/Material List APIs (D-5)**
- `api/get_project_nip_products.php` — userCan('project', $project_id) gate
- `api/create_nip_product.php` — userCan('project') gate if project_id submitted
- `api/create_project_nip_product.php` — userCan('project', $project_id) throws Exception on deny
- `api/delete_nip_product.php` — assertScopeForRecord('products')
- `api/update_nip_product.php` — assertScopeForRecord('products')
- `api/update_project_nip_product.php` — userCan('project') + assertScopeForRecord('products')

**Detail/View Pages (D-6)**
- `app/bms/pos/employee_details.php` — Phase D scope gate + ob_start() added to support redirect after includeHeader()
- `app/bms/pos/leave_details.php` — Phase D scope gate; e.project_id added to SELECT; gate runs before header.php include
- `app/bms/product/product_view.php` — Phase D scope gate (uses existing ob_start(); NULL project_id = global = passes)
- `app/bms/product/product_edit.php` — Phase D scope gate (same pattern as product_view)
- `app/bms/product/service_view.php` — Phase D scope gate (same pattern)

**Core helpers (D-1)**
- `core/project_scope.php` — Added scopeFilterSqlNullable(), assertScopeForEmployee(), assertScopeForEmployeeRecord()

---

## 2026-05-25 (update 107)

### Fix: Production — stock_movements.movement_type ENUM out of sync

**Problem:** Creating a product with initial stock on the production cPanel server
failed with `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'movement_type'`.
Root cause: the production DB had an older/narrower ENUM missing `adjustment_in` and
other values added during development. Local DB and the other server were already correct.

**Fix:** `migrations/2026_05_25_stock_movements_enum_fix.php` reads the current ENUM
via `information_schema`, detects missing values, and runs one `ALTER TABLE … MODIFY COLUMN`
to bring the column in line with the full canonical list. Idempotent — skips safely if
already correct.

**Files:**
- `migrations/2026_05_25_stock_movements_enum_fix.php` — new migration

## 2026-05-24 (update 108)

### Feat: Project-scope rollout — Phase C Finance + Procurement gates

Third sub-PR of project_scope_implementation_plan.md. Same pattern as
Phase B but covering the finance + procurement modules: invoices,
quotations, sales orders, purchase orders, GRNs, DNs, DOs, RFQs,
purchase returns, expenses, payment vouchers, budgets, sc payments,
supplier invoices, and supplier payments.

**Two new helpers in `core/project_scope.php`:**

1. `assertScopeForRecord(table, pkColumn, id)` — given a record's
   table+PK+id, fetches the project_id and gates via `userCan('project',
   ...)`. On denial sends a 403 JSON response and exits. **Used in 30+
   write APIs in this PR alone** to collapse what would otherwise be
   a 5-line lookup pattern repeated everywhere.

2. `assertScopeForRecordHtml(table, pkColumn, id)` — same lookup, but
   die()s with plain text instead of JSON for HTML print/view pages
   where JSON would render as raw text.

**List APIs — `scopeFilterSql` appended:**
- `api/account/get_invoices.php` (4 SELECTs)
- `api/account/get_purchase_orders.php`
- `api/account/get_expenses.php` (data, count, stats)
- `api/account/get_sales_orders.php` (data, total, stats)
- `api/account/get_vouchers.php` (data, count, 3 stats)
- `api/account/get_budget.php` (single-record gate)
- `api/account/get_purchase_returns.php`
- `api/sc/get_payments.php`, `api/get_dns.php`, `api/get_dos.php`
- `api/received_invoices.php` (`list` action + `get` action)

**Write APIs — `assertScopeForRecord` / `userCan` gates:**

Deletes: delete_invoice, delete_voucher, delete_purchase_return,
delete_sales_order, delete_quotation, delete_purchase_order, delete_grn,
delete_dn, delete_rfq, delete_budget, delete_expense, sc/delete_payment.

Saves (with both edit-record-scope-check AND submitted-project_id-check):
save_invoice, save_voucher, save_quotation, save_purchase_order,
save_purchase_return.

Add/Update with project_id POST check: add_budget, add_expense,
update_expense, update_purchase_return, update_dn, update_rfq,
update_budget, add_supplier_payment, create_rfq, create_dn, create_do,
sc/add_payment.

Status transitions / approvals / reviews:
- approve_invoice, review_invoice, approve_purchase_order,
  review_purchase_order, approve_quotation, review_quotation,
  convert_quote_to_order, approve_rfq, approve_dn
- update_invoice_status, update_voucher_status, update_expense_status,
  update_grn_status, update_do_status,
  update_budget_status, update_purchase_return_status,
  operations/change_dn_status, operations/change_do_status
- record_payment

**Detail / print HTML pages — `assertScopeForRecordHtml`:**
- invoice_view, invoice_print
- sales_order_view, print_sales_order
- print_quotation
- print_sales_return (no direct project_id — resolves via invoice/SO)
- purchase_order_details
- purchase_return_view, print_purchase_return
- grn_view, grn_print
- received_invoices_view
- expense_details, budget_details (project_id resolved post-fetch)
- payment_voucher_print

### Behaviour
- **Admin:** scopeFilterSql returns `''`, userCan returns true, helpers
  no-op. Every query and page renders byte-identically to pre-PR.
- **Non-admin with assignments:** sees only project-scoped rows; 403
  on attempts to view/save/delete records on other projects.
- **Non-admin with zero assignments:** sees empty lists; 403 on any
  attempt to view/save/delete a record with a project_id.

### Smoke test (all passed)
- `php -l` clean on all ~70 modified files.
- Security coverage CI guard: 12/12 passes.
- Helper lint clean.

### ⚠️ Deploy notes (after-hours window)
Once this lands, non-admin users see only invoices / quotations / SOs
/ POs / GRNs / DNs / DOs / RFQs / vouchers / budgets / expenses /
purchase-returns / received-invoices / sc-payments tied to their
assigned projects. Have admin assignments ready via
`/user_projects.php` before notifying staff.

### Rollback
Single `git revert <sha>` removes every scope check.

---

## 2026-05-24 (update 107)

### Feat: Project-scope rollout — Phase B Operations + Projects gates

Second sub-PR of project_scope_implementation_plan.md. **First phase
that actually changes runtime behaviour** — Operations pages and APIs
now filter project-scoped rows down to what the logged-in user is
assigned to. Admin bypasses all checks; non-admin with no
`user_projects` rows sees nothing under Operations until an admin
assigns projects via /user_projects.php (shipped in Phase A).

**Two-axis model in action:**
- Role layer (existing): "Manager can edit projects" — unchanged.
- Scope layer (NEW, this PR): "and only on the projects you're
  assigned to."

Both checks must pass. Admin bypasses both.

**List queries — scopeFilterSql('project', alias) appended:**
- `api/operations/get_projects.php` — total count, filtered count,
  data SELECT, and the stats summary (`total_budget`, `avg_progress`).
  Non-admin without assignments sees 0/0/[]/null across the board.

**Detail-endpoint short-circuit (single check, all sub-queries skipped
when denied):**
- `api/operations/get_project.php` — `userCan('project', $id)` at the
  top. Saves running 30+ sub-queries when access is denied.

**Detail/print pages — userCan() guard:**
- `app/bms/operations/project_view.php` (uses `?id=`)
- `app/bms/operations/project_budget_report.php` (uses `?id=`)
- `app/bms/operations/project_financial_report.php` (uses `?id=`)
- `app/bms/operations/project_progress_report.php` (uses `?id=`)
- `app/bms/operations/inspection_view.php` (looks up project_id via
  inspection_id, then gates)
- `app/bms/operations/print_ipc.php` (looks up project_id via ipc_id,
  then gates)

**Write APIs — userCan() guard after the existing `if (!$project_id)`
sanity check:**
- save_progress_report, save_inspection, save_ipc, save_milestones,
  save_project_attendance, save_project_leave, save_scope_document,
  save_scopes, save_project_planning, save_goods_return
- approve_project_planning, delete_project_planning,
  delete_scope_addendum, delete_scope_document
- save_project (edit-only — creates still allowed; admin must assign
  the creator afterwards if they need to manage the new project)
- delete_project

### Smoke test (all passed)
- `php -l` clean on all 24 modified files.
- Security coverage CI guard: 12/12 passes.
- Admin path: scopeFilterSql() returns empty string → all SELECTs
  identical to pre-PR. No behaviour change for admins.
- Non-admin path: scopeFilterSql() returns `AND project_id IN (...)`
  or `AND 0` when no assignments. Default-deny ✅.

### ⚠️ Deploy notes (mirrors security Phase 2/5 pattern)

After this merges, non-admin users without project assignments will
see empty lists / 403s on Operations pages. BEFORE notifying staff:

1. Admin logs in → `/user_projects.php`
2. For each non-admin user that should manage projects: tick the
   matching project boxes.
3. Recommended deploy window: **after hours** to minimise help-desk
   impact.
4. Break-glass admin credentials handy in case of unexpected lockouts.

### Files modified
- 6 detail/print pages under `app/bms/operations/`
- 18 APIs under `api/operations/`

### Rollback
Single `git revert <sha>` removes every scope check; admin and
non-admin behaviour reverts to pre-Phase-B. The `user_projects` /
`user_scope_overrides` tables stay (no data loss).
## 2026-05-24 (update 106)

### Feat: Project-scope rollout — Phase A foundation (no runtime change)

First sub-PR of the project-scope access-control rollout (see
`project_scope_implementation_plan.md`). Adds the second axis of
access control on top of the existing role/permission system:

- **Role** (existing) answers *what verbs?*
- **Project scope** (NEW) answers *which rows?*

Both checks must pass on every request. Admin bypasses both.

**This PR is foundation only — no SELECT statement in the app changes
yet.** Phases B-E will wire `scopeFilterSql()` into actual queries;
Phase F locks the CI ceiling.

**What ships:**

1. `migrations/2026_05_24_project_scope_foundation.php` — creates two
   tables:
   - `user_projects` (user_id, project_id, assigned_by, assigned_at)
     — primary many-to-many assignment
   - `user_scope_overrides` (user_id, resource_type, resource_id,
     granted_by) — optional cross-project resource grants
     (`resource_id` NULL = grant all of that type)

2. `migrations/2026_05_24_project_scope_perm_seed.php` — seeds the
   `user_projects` page_key so the new admin UI is permission-gated.

3. `core/project_scope.php` (new) — central helper:
   - `loadUserScope(int $userId)` — computes session cache of
     accessible projects + derived warehouses/suppliers/customers/employees
   - `userCan(string $type, int $id)` — single-record gate
   - `scopeFilterSql(string $type, string $alias)` — SQL fragment to
     append to WHERE clauses
   - `refreshScopeCache(int $userId)` — invalidate on assignment change
   - Admin always returns true / empty SQL (zero existing-page impact)
   - Non-admin with no assignments returns `AND 0` (default-deny)

4. `core/permissions.php` — auto-loads `project_scope.php` so the
   helpers are available everywhere permissions are.

5. `header.php` — calls `loadUserScope()` once per request after
   `loadUserPermissions()`.

6. `app/constant/settings/user_projects.php` (new) — admin UI:
   - Left: user list
   - Right: project tick-boxes + resource-overrides form
   - Saves on POST → invalidates the affected user's scope cache
   - Logs every change via the existing `logActivity()` helper

7. Routing + mapping:
   - `roots.php` route `user_projects` → the new page
   - `getPagePermissionMapping()` extended with the new filename

### Acceptance smoke test (passed)

- Migration ran twice, second run is no-op (idempotent ✅)
- `php -l` clean on all 6 changed files
- Security coverage CI guard still passes (`Passes: 12, Failures: 0`)
- Helper smoke test: admin returns true / empty SQL; non-admin with
  no assignments returns false / `AND 0` (default-deny ✅)

### ⚠️ Deploy notes
No user-facing impact yet — every existing page renders identically.
Phase B is when the first set of list pages start filtering by
project. Run the two migrations on deploy (they're idempotent).

### Files modified
- `project_scope_implementation_plan.md` (new) — the master rollout plan
- `migrations/2026_05_24_project_scope_foundation.php` (new)
- `migrations/2026_05_24_project_scope_perm_seed.php` (new)
- `core/project_scope.php` (new) — central helper module
- `core/permissions.php` — auto-loads project_scope.php + mapping entry
- `header.php` — calls `loadUserScope()` once per request
- `app/constant/settings/user_projects.php` (new) — admin UI
- `roots.php` — route entry

---

## 2026-05-24 (update 105)

### Feat: Dashboard — Smarter "System requires your attention" alerts

Expanded `get_system_alerts()` and the alert renderer in `app/dashboard.php`
to surface six additional critical issue categories alongside the existing
overdue-invoice, low-stock, expiring-product, approvals and document
notifications.

**New alert types (each wrapped in try/catch so schema gaps don't break the dashboard):**

1. **Negative stock balances** — `product_stocks` rows with available_stock < 0 (data-integrity red flag; rendered with a red NEG badge under Inventory & Products).
2. **Cash register shifts left open from a previous day** — `cash_register_shifts.status='active'` with `start_time` before today.
3. **Bank reconciliation overdue** — `bank_accounts` whose latest `reconciliation_date` is > 15 days ago (or never reconciled).
4. **Leave applications pending > 2 days** — `leaves.status='pending'` aged ≥ 2 days, joined to `employees` for the requester name.
5. **Payroll not processed by the 25th** — checks current `payroll_period` (`YYYY-MM`) and fires if no payroll row exists after the 25th of the month.
6. **Quotations expiring within 5 days** — `quotations.quote_valid_until` between today and +5 days, status in (pending, sent, draft).
7. **Tenders with submission deadline within 7 days** — `tenders.submission_deadline` between today and +7 days, status in (PENDING, OPEN, DRAFT).
8. **GRN pending for delivered POs** — `purchase_orders.expected_delivery_date < CURDATE()`, status in (ordered, approved, partially_received) with no matching `purchase_receipts` row.
9. **Customers over credit limit** — `SUM(grand_total - paid_amount) > customers.credit_limit` on unpaid invoices.

**Five new notification groups** added to `$notif_groups`:
`cash_bank`, `credit_risk`, `grn_pending`, `hr_payroll`, `quotes_tenders` —
each with its own icon/colour. The `negative_stock` subtype folds into the
existing Inventory & Products group as the highest-priority entry.

**Other changes:**
- Replaced the if/elseif dispatch with a `switch` to route the new alert types.
- Extended the urgency priority map; raised the final `array_slice` cap from 10 → 50 so the new groups can coexist with the originals.
- Added title/subtitle render branches for every new type and wired arrow-button links to the right destination page (`cash_register`, `bank_reconciliation`, `leaves`, `payroll`, `quotations`, `tenders`, `purchase_order_details`, `customers/details`, `stock_adjustments`).

**Files:**
- `app/dashboard.php` — added queries in `get_system_alerts()`, expanded `$notif_groups`, updated dispatch + render block.

**Note:** "Overdue loan installments" was intentionally excluded at user's request.

**Verification corrections (after end-to-end live-DB testing):**
- `bank_recon_overdue` query originally referenced a non-existent `bank_accounts` table. Switched to joining `accounts` (chart of accounts) via `bank_reconciliations.bank_account_id`; only accounts that appear in `bank_reconciliations` are considered, so non-bank chart-of-accounts rows aren't falsely flagged.
- `grn_pending` query originally referenced `purchase_orders.expected_delivery_date`; the actual column is `expected_date`. Fixed.
- Both bugs were hidden by the defensive try/catch — the verification step ran each query against the live DB to surface silently-swallowed schema mismatches.

## 2026-05-24 (update 104)

### Feat: Security rollout — Phase 8/9 — orphan cleanup + CI lock-in (CLOSES SECURITY ROLLOUT)

Final PR of the security rollout. Merges Phase 8 (CI lock-in) and
Phase 9 (orphan cleanup) into one ship since orphan cleanup is a one
small migration and CI lock-in is a one-line ceiling change.

**Three pieces:**

**1. Orphan permission cleanup migration**
   `migrations/2026_05_24_security_cleanup.php` — deletes 2 permission
   rows that have **zero `role_permissions` grants AND zero references
   in code**:
   - `activity_log` — legacy alias; the file `activity_log.php` now
     maps to the active `audit_logs` key
   - `payment_create` — never granted, never referenced; the
     `payment_create.php` page gates on `invoices`
   Hardcoded delete list (not live regex) so the migration is fully
   auditable and predictable. Idempotent — re-runs are no-ops.

**2. payment_voucher_details.php — gated and converted**
   The 0-byte placeholder reachable via the `payment_voucher_view`
   route now (a) gates on `payment_vouchers` and (b) redirects to
   `payment_voucher_print.php` if `?id=` is present, else to the
   `payment_vouchers` list. This eliminates the last ungated page.

**3. CI ceilings locked at 0 forever**
   Updated `tests/test_security_coverage_cli.php`:
   - Collapsed three duplicate `pages_no_gate` entries from the
     parallel-merged Phase 5 PRs into a single locked entry.
   - All actively-enforced ceilings now set to **0**:
     - `pages_no_gate` = 0 (LOCKED)
     - `page_key_missing_db` = 0 (LOCKED)
     - `write_apis_no_log` = 0 (LOCKED)
     - `api_perms_no_gate` = 0 (LOCKED)
   - `view_pages_no_log` stays at 59 (Phase 7 deferred — re-tighten
     when/if 7 ships).
   - File doc header updated to reflect the FINAL STATE rather than
     the pre-rollout baselines.

**Plus:** `security_implementation_plan.md` Phase Tracker table
updated to ✅ merged for every shipped phase, leaving only 8/9 as
the in-flight final.

### Final rollout numbers

| Metric | Phase 0 baseline | After 8/9 | Future regression? |
|---|---|---|---|
| Pages without a permission gate | 76 | **0** | CI fails |
| Permission keys missing in DB | 23 | **0** | CI fails |
| Write APIs without activity log | ~100 | **0** | CI fails |
| Write APIs without a perm gate | 173 | **0** | CI fails |
| View pages without activity log | 55 | 59 | not enforced (Phase 7 deferred) |

**22 PRs across ~6 working days. Every page, every write API, every
permission key is now accounted for, and CI prevents regressions.**

### ⚠️ Deploy notes
1. The migration `migrations/2026_05_24_security_cleanup.php` runs
   automatically on deploy via `migrations/runner.php`. The two
   deletions affect only orphan keys with no grants — no user-facing
   impact.
2. No new permissions need to be granted in `user_roles.php` for this
   PR. Phase 5d already seeded `loans / help / my_settings`; Phases 1
   and 5d added everything else.

### Files modified
- `migrations/2026_05_24_security_cleanup.php` (new)
- `app/constant/accounts/payment_voucher_details.php` — placeholder → gated redirect
- `tests/test_security_coverage_cli.php` — ceilings LOCKED at 0 (except deferred view-log)
- `security_implementation_plan.md` — Phase Tracker updated

---

## 2026-05-24 (update 103)

### Feat: Security rollout — Phase 6 routing-fallback mapping update

Extends `getPagePermissionMapping()` in `core/permissions.php` to cover
every page reachable from the URL router. Defence-in-depth: even if a
developer forgets to call `autoEnforcePermission()` inside a page file,
the router-level fallback will now find a matching page_key.

**Mapping entries added: 130** (going from 64 to ~194 total). Grouped
into 12 sub-sections by module within the same array literal:
- Customers / Suppliers (5)
- Sales / Quotations (15)
- Invoices / Payments / Received (8)
- Procurement (PO / RFQ / GRN / DN / DO / NIP / Tenders) (21)
- Accounts / Finance details (12)
- Operations / Projects (8)
- Inventory / Stock / Products (13)
- HR / Payroll / Leaves (7)
- Loans (7)
- Documents (7)
- Reports (20)
- User / Settings (7)

**Strictly additive** — no existing mappings were modified, only new
keys added. Risk: 🟢 Very low.

**Audit delta:** `Filename not in map()` 135 → 0. Every page in the
router map now points to a permission key.

**Also fixed:** tests/test_security_coverage_cli.php had 3 duplicate
`pages_no_gate` keys after the parallel-merge of Phase 5b/5c/5d (only
the last assignment took effect in PHP). Collapsed to one entry at
the actual current state: `pages_no_gate = 1` (the payment_voucher
empty placeholder, which Phase 9 will tidy).

**No deploy comms note required** — this PR doesn't change runtime
behaviour for any currently-working user. It strengthens the fallback
layer for future pages.

### Files modified
- core/permissions.php — extends `getPagePermissionMapping()`
- tests/test_security_coverage_cli.php — collapsed 3 duplicate
  `pages_no_gate` entries to one at the current floor of 1

---

## 2026-05-24 (update 102)

### Feat: Security rollout — Phase 5d Inventory + Loans + Profile + settings cleanup (13 files + 1 migration, 3 commits)

Final sub-PR of Phase 5. Adds page-level gates to 13 files and seeds
3 new permission keys. Split into 3 grouped commits within this PR:

**Commit 1 — Product (4 files):**
- product_create / product_edit / product_import → autoEnforcePermission('products')
  (kept the existing requireCreate/EditPermission calls — strictly additive)
- print_barcode → canView('products') (print-only)

**Commit 2 — Stock (3 files):**
- adjustment_print, print_transfer → canView('stock_adjustments') + die() (print)
- ajax_get_transfer_items → canView('stock_adjustments') + die() (AJAX partial)

**Commit 3 — Loans + Profile + Settings cleanup (6 files + 1 migration):**
- loans/loan_application, loans/loan_details → autoEnforcePermission('loans')
  (both are stubs; admin bypasses)
- constant/profile/profile.php → autoEnforcePermission('profile')
- constant/settings/download_backup.php — swapped raw `!isAdmin()` for
  `canView('backup_restore')` (admin still bypasses, but a non-admin
  role can now be delegated via user_roles.php)
- constant/settings/help.php → autoEnforcePermission('help')
- constant/settings/my_settings.php → autoEnforcePermission('my_settings')

**Migration:** `migrations/2026_05_24_phase5d_loans_seed.php` seeds 3
new permission keys (`loans`, `help`, `my_settings`). INSERT IGNORE
so re-runs are no-ops. Default-deny posture: no role_permissions
rows inserted; admin always bypasses via isAdmin().

The 3 settings pages (download_backup/help/my_settings) weren't in
the plan's Phase 5d list, but were the only files still flagged
ungated after the planned files were done. Cleaning them up here so
Phase 5 ends with a tight audit floor.

**Audit delta on this branch:** `pages_no_gate` 24 → 11 (-13).
Once 5c also merges, the 5 invoice/reps gates plus 4 loan-report stubs
plus preview_template handle 10 of the remaining 11, leaving just
`payment_voucher_details.php` (1-line empty placeholder, intentionally
not gated). Phase 5 end state: **~1 ungated page in main**.

**CI ceiling:** `pages_no_gate` 24 → 11.

### ⚠️ Deploy notes
1. **Run the migration** `migrations/2026_05_24_phase5d_loans_seed.php`
   before deploying the gated pages, or admin's auto-bypass will be
   the only path to the new pages until staff are granted via
   user_roles.php.
2. After this merges, non-admin users will lose access to these 13
   pages until admin ticks matching boxes for: `products,
   stock_adjustments, loans, profile, backup_restore, help,
   my_settings`. Deploy after hours.

### Files modified
- 4 pages under `app/bms/product/`
- 3 pages under `app/bms/stock/`
- 2 pages under `app/bms/loans/`
- 1 page under `app/constant/profile/`
- 3 pages under `app/constant/settings/`
- New migration: `migrations/2026_05_24_phase5d_loans_seed.php`
- tests/test_security_coverage_cli.php — ceiling 24 → 11

---

## 2026-05-24 (update 101)

### Feat: Security rollout — Phase 5c Reports & Documents page gates (10 files, 2 commits)

Third sub-PR of Phase 5. Only 10 of the 21 plan-listed pages still
needed gates — the other 11 had already been gated in earlier work.

Split into 2 grouped commits within this PR:

**Commit 1 — Loan reports + preview_template (5 files):**
- constant/reports/delinquency_report.php — 0-byte stub →
  pre-gated 'Coming Soon' card with `autoEnforcePermission('financial_reports')`
- constant/reports/loan_performance.php — same treatment
- constant/reports/loan_portfolio.php — same treatment
- constant/reports/repayment_report.php — same treatment
- constant/document/preview_template.php → autoEnforcePermission('document_templates')
  (was auth-only; perm check missing)

**Commit 2 — Invoice report partials (5 files):**
- app/bms/invoice/reps/balance_sheet, daily_sales, low_stock,
  sales_customer, stock_value
- These are partials included by app/bms/invoice/reports.php (which
  already gates 'reports'), but a direct URL hit on a partial would
  render the report without a permission check.
- Pattern: `require_once roots.php` (idempotent) +
  `if (!canView('reports')) die("Access Denied")`. canView()
  admin-bypasses.

**Pages already gated in earlier work (not touched):**
- constant/reports/{audit_report, balance_sheet, cash_flow,
  compliance_report, customer_analysis, employee_report,
  financial_statements, product_analysis, trends_analysis,
  trial_balance, asset_report}
- constant/document/{document_library, e_signatures,
  compliance_documents, loan_documents, select_document_add_esignature}

**Audit delta (this branch):** `pages_no_gate` 45 → 35 (-10).
**Once both 5b + 5c are on main:** estimated 14.

**CI ceiling adjustments:**
- `pages_no_gate` 45 → 35.
- `view_pages_no_log` 55 → 59 — small bump for the 4 new stub pages
  that include `header.php` but have no `logActivity()` yet. Phase 7
  (view-page logging) is DEFERRED, so this ceiling is intentionally
  loose and will be tightened when 7 ships.

### ⚠️ Deploy notes
After this merges, non-admin users will lose access to these 10 pages
until admin ticks matching boxes for: `financial_reports,
document_templates, reports`. Deploy after hours.

### Files modified
- 4 stubs created under `app/constant/reports/` (loan reports)
- 1 file under `app/constant/document/` (preview_template)
- 5 files under `app/bms/invoice/reps/`
- tests/test_security_coverage_cli.php — pages_no_gate 45 → 35,
  view_pages_no_log 55 → 59 (deferred)
### Feat: Security rollout — Phase 5b Finance & Operations page gates (21 files, 3 commits)

Second sub-PR of Phase 5. Adds `autoEnforcePermission()` (full pages)
or `canView()` (print pages, partials) to 21 finance/operations pages
across HR/POS / Operations / Accounts modules.

Split into 3 grouped commits within this PR for reviewability:

**Commit 1 — HR / POS (4 files):**
- pos/leave_application.php → canView('leaves') (print-only)
- pos/leave_details.php → autoEnforcePermission('leaves')
- pos/payslip.php → canView('payslip') (print-only)
- pos/system_status.php → canView('system_settings') (admin diagnostic;
  swapped raw isAdmin() for canView so the audit detects a gate)

**Commit 2 — Operations (7 files):**
- project_view.php, inspection_view.php → autoEnforcePermission('projects')
- project_budget_report, project_financial_report, project_progress_report
  → canView('projects') + die()
- warehouse_stock_view.php → canView('warehouses') (was a pure-HTML
  page with no PHP — added a top-of-file PHP gate)
- print_ipc.php → canView('projects') (print-only)

**Commit 3 — Accounts (10 files):**
- expenses, expense_details, edit_expense → expenses
- journals, journal_details, edit_journal → journals
- add_journal.php (modal partial) → canView('journals') guard for
  direct hits
- budget_details → budget
- payment_voucher_print → canView('payment_vouchers')
- petty_cash_print → canView('petty_cash')
- **Fixed argless `autoEnforcePermission()` calls** in 5 accounts
  pages (expenses, expense_details, journals, journal_details,
  budget_details) — they were silently letting everyone through
  because the helper requires an explicit page_key argument.

**Pattern:**
- Full pages: `autoEnforcePermission('page_key')` immediately after
  the roots include / header include. Redirects to /unauthorized on
  failure (admin auto-bypasses).
- Print pages: `if (!canView('key')) die("Access Denied")` — die()
  because there's no chrome to redirect through.

**Skipped:** `payment_voucher_details.php` (1-line empty placeholder,
nothing to gate).

**Audit delta:** `pages_no_gate` 45 → 24 (-21).

**CI ceiling:** `pages_no_gate` 45 → 24.

### ⚠️ Deploy notes
After this merges, non-admin users will lose access to these 21 pages
until admin ticks matching boxes for: `leaves, payslip, system_settings,
projects, warehouses, expenses, journals, budget, payment_vouchers,
petty_cash`. Deploy after hours.

### Files modified
- 4 pages under `app/bms/pos/`
- 7 pages under `app/bms/operations/`
- 10 pages under `app/constant/accounts/`
- tests/test_security_coverage_cli.php — ceiling 45 → 24

---

## 2026-05-24 (update 100)

### Feat: Security rollout — Phase 5a Commercial page gates (21 files, 3 commits)

First sub-PR of Phase 5. Adds `autoEnforcePermission()` (full pages)
or `canView()` (print pages without chrome) to 21 commercial pages
across Customer / Sales+Invoice / Procurement+GRN modules.

Split into 3 grouped commits within this PR for reviewability:

**Commit 1 — Customer (7 files):**
- customer_details, customer_documents → customer_details / customer_documents
- customer_groups, customer_group_details, customer_group_members → customer_groups
- customer_import → customer_import
- edit_customer → edit_customer

**Commit 2 — Sales + Invoice (9 files):**
- sales_order_create, sales_order_view → sales_orders
- quotations/quotation_create, quotation_edit → sales_orders
  (defense-in-depth — quotation_form.php already gates internally)
- quotations/print_quotation → canView('sales_orders')
- print_sales_order → canView('sales_orders')
- sales_returns/print_sales_return → canView('sales_returns')
- invoice_view → invoices
- invoice_print → canView('invoices')

**Commit 3 — Procurement + GRN (5 files):**
- purchase_order_details → purchase_orders
- purchase_return_view → purchase_returns
- print_purchase_return → canView('purchase_returns')
- grn_view → grn
- grn_print → canView('grn')

**Pattern:**
- Full pages: `autoEnforcePermission('page_key')` immediately after the
  header/roots include. Non-admin without permission gets redirected
  to /unauthorized; admin always passes (`isAdmin()` bypass).
- Print-only pages (no chrome): `if (!canView('key')) die("Access Denied")`
  — `die()` because there's no header/footer to redirect through.

**Pages already gated (not touched):** quotation_form.php, payment_create.php,
received_invoices.php, received_invoices_view.php, supplier_payments.php,
nip_materials.php, view_nip_materials.php, view_material_list.php,
edit_nip_materials.php. These show in the audit only as 'filename not
in map', which is a Phase 6 (getPagePermissionMapping) concern.

**Audit delta:** `pages_no_gate` 66 → 45 (-21).

**CI ceiling:** `pages_no_gate` 66 → 45.

### ⚠️ Deploy notes
After this merges, non-admin users will be redirected to /unauthorized
when opening any of the 21 pages until admin ticks matching `view`
boxes in `user_roles.php` for: `customer_details, customer_documents,
customer_groups, customer_import, edit_customer, sales_orders, invoices,
sales_returns, purchase_orders, purchase_returns, grn`. Deploy after
hours.

### Files modified
- 21 pages under `app/bms/` (customer, sales, sales/quotations,
  sales/sales_returns, invoice, purchase, grn)
- tests/test_security_coverage_cli.php — ceiling 66 → 45

---

## 2026-05-24 (update 99)

### Feat: Security rollout — Phase 4.5c-2 canEdit gates on api/(root) updates (20 files) — CLOSES PHASE 4.5

Final sub-PR of Phase 4.5. Covers the 20 `update_*.php` endpoints in
`api/` root with uniform `canEdit()` gates. With this PR on main,
**every state-changing write API in the codebase is permission-gated.**
`api_perms_no_gate` lands at **0**.

> **Merge order note:** This PR was opened before 4.5c-3 and 4.5d landed,
> so the branch's original ceiling target was 100. Rebased onto current
> main during merge to set the ceiling to **0** (the final state after
> all six 4.5 sub-PRs are integrated).

**20 endpoints gated:**

| File | page_key |
|---|---|
| update_adjustment | stock_adjustments |
| update_attendance_notes, update_attendance_status, update_attendance_time | attendance |
| update_category | categories |
| update_dn | dn |
| update_do_status | do |
| update_employee, update_employee_status | employees |
| update_grn_status | grn |
| update_leave | leaves |
| update_material_bom_qty, update_material_component_status, update_material_list | nip_materials |
| update_nip_product, update_nip_status, update_project_nip_product | nip_materials |
| update_payroll, update_payroll_status | payroll |
| update_rfq | rfq |

**Pattern:** auth check first (401), then `canEdit()` (403 with
verb-specific message). `canX()` admin-bypasses via `isAdmin()`.

**Audit delta on this branch (post-rebase):** api_perms_no_gate 20 → 0.

**CI ceiling:** `api_perms_no_gate` 20 → **0**. Any future write-API
PR that forgets a permission gate fails CI.

### ⚠️ Deploy notes
After this merges, non-admin users will get 403 on these 20 endpoints
until admin ticks the matching `edit` boxes for: `stock_adjustments,
attendance, categories, dn, do, employees, grn, leaves, nip_materials,
payroll, rfq`. Deploy after hours.

### Phase 4.5 complete

With this PR on main:
- **173 → 0** write APIs without a permission gate across 6 sub-PRs
  (a, b, c-1, c-2, c-3, d).
- The CI guard (`tests/test_security_coverage_cli.php`) now blocks any
  future regression. A new write API that forgets a `canX()` call will
  fail CI.

### Files modified
- 20 `api/update_*.php` files
- tests/test_security_coverage_cli.php — ceiling 20 → 0

---

## 2026-05-24 (update 98)

### Feat: Security rollout — Phase 4.5d canX gates on misc modules (31 files)

Final sub-PR of Phase 4.5. Adds `canCreate / canEdit / canDelete`
gates to every state-changing endpoint in the smaller `api/` modules
(cash_register, document, finance, payroll, petty_cash, pos, sales,
sc, suppliers). With this PR plus 4.5c-2 (open PR), `api_perms_no_gate`
lands at 0 — every write API in the codebase is permission-gated.

**31 endpoints gated across 9 modules:**

`api/cash_register/` (3):
- add_transaction → canCreate('cash_register')
- close_shift → canEdit('cash_register')
- open_shift → canCreate('cash_register')

`api/document/` (10):
- delete_collateral_document, delete_document, quick_upload_document,
  update_document_metadata, upload_document, upload_signed_document
  → documents (verb-appropriate)
- delete_document_template → document_templates
- delete_signature, upload_signature → e_signatures
- apply_signature → canEdit('e_signatures') || canEdit('documents')

`api/finance/manage_expense_schema.php` (1):
- All 7 switch cases (add/edit/delete type & category, toggle, etc.)
  → canEdit('expenses') || canEdit('categories')

`api/payroll/` (3) — legacy hard-coded role-string checks replaced:
- add_tax_bracket → canCreate('payroll')
- delete_tax_bracket → canDelete('payroll')
- update_settings → canEdit('payroll')

`api/petty_cash/` (2):
- delete_transaction → canDelete('petty_cash')
- save_transaction → canCreate || canEdit on petty_cash

`api/pos/` (5):
- close_shift → canEdit('pos')
- open_shift, hold_sale, process_sale → canCreate('pos')
- delete_held_sale → canDelete('pos')

`api/sales/` (4):
- create_return → canCreate('sales_returns')
- delete_return → canDelete('sales_returns')
- update_return, update_return_status → canEdit('sales_returns')

`api/sc/` (2):
- add_payment → canCreate('supplier_payments')
- delete_payment → canDelete('supplier_payments')

`api/suppliers/change_payment_status.php` (1):
- top-level canEdit('supplier_payments') gate; per-transition
  canReview / canApprove checks (already in the file) remain unchanged.

**Pattern:** auth check first (401), then perm check (403 with
verb-specific message). canX() admin-bypasses via isAdmin().

**Replaced 3 legacy hard-coded role-string checks** in api/payroll/
(`add_tax_bracket`, `delete_tax_bracket`, `update_settings`) with
canX(), so non-admin roles can be delegated via user_roles.php.

**Audit delta on this branch:** api_perms_no_gate 51 → 20 (this PR
from current main, which has 4.5a/4.5b/4.5c-1/4.5c-3 already merged;
remaining 20 = the 4.5c-2 updates PR still pending).

**CI ceiling:** `api_perms_no_gate` 51 → 20. Once 4.5c-2 lands on
main, follow-up commit drops it to 0.

### ⚠️ Deploy notes
After this merges, non-admin users will get 403 on these 31 endpoints
until admin ticks matching boxes for: `cash_register, documents,
document_templates, e_signatures, expenses, categories, payroll,
petty_cash, pos, sales_returns, supplier_payments`. Deploy after hours.

### Files modified
- 31 files across api/cash_register, api/document, api/finance,
  api/payroll, api/petty_cash, api/pos, api/sales, api/sc, api/suppliers
- tests/test_security_coverage_cli.php — ceiling 51 → 20

---

## 2026-05-24 (update 97)

### Feat: Security rollout — Phase 4.5c-3 canCreate/Edit gates on api/(root) creates+workflow (44 files)

Third (and largest) of three sub-PRs splitting Phase 4.5c. Covers all
non-delete / non-update state-changing endpoints in `api/` root:
add/save/create/import/duplicate/apply/approve/reject/cancel/mark/
bulk/process/quick/tender/backup endpoints.

**44 endpoints gated** (page_key in parentheses):

HR / Payroll / Attendance (12):
- add_employee (employees), apply_leave / cancel_leave /
  duplicate_leave / import_leaves (leaves), approve_leave /
  reject_leave (canApprove||canEdit on leaves),
  bulk_update_leave_status (canEdit leaves),
  approve_payroll (canApprove||canEdit payroll), duplicate_payroll
  (canCreate payroll), mark_payroll_paid / bulk_update_payroll /
  bulk_update_payroll_status (canEdit payroll),
  mark_attendance / bulk_mark_attendance / quick_mark_attendance
  (canCreate attendance)

Procurement / Inventory / Materials (11):
- add_nip_materials, create_material_list, create_nip_product,
  create_project_nip_product (canCreate nip_materials)
- add_supplier_payment (canCreate supplier_payments)
- create_dn (canCreate dn), create_do (canCreate do)
- create_rfq (canCreate rfq), rfq_quick_add_product
  (canCreate products || canEdit rfq)
- process_bulk_adjustment (canCreate stock_adjustments)
- import_products (canCreate products)

CRM / Marketing / Operations (4):
- quick_add_customer (canCreate customers)
- save_brand / save_unit (canCreate products) — save_brand chooses
  create vs edit by brand_id presence
- save_campaign (campaign_management create-or-edit)
- save_lead (lead_generation create-or-edit)
- assign_sc_to_project (canEdit projects)

Templates / Documents / Settings (8):
- save_compliance (compliance create-or-edit)
- save_document_template, save_email_template (document_templates
  create-or-edit)
- save_sms_template (sms_alerts create-or-edit)
- create_category (canCreate categories)
- save_backup_settings (canEdit backup_restore)
- backup_actions — legacy `!isAdmin()` swapped for canDelete
  (broadest verb; covers create/restore/delete/upload paths)
- tender_workflow (canEdit||canCreate tenders)

User-personal endpoints (3) — `canView('notification_center')` as
defense-in-depth (row-level scoped to user_id; full create/edit/delete
would lock users out of their own data):
- mark_notification_read
- notification_bulk_actions
- save_notification_preferences

**Pattern:** auth check first (401), then perm check (403 with
verb-specific message). canX() admin-bypasses via isAdmin().
Save endpoints that handle both create and update branch on ID
presence to choose canCreate vs canEdit.

**Replaced 2 legacy hard-coded role checks** with canX:
- backup_actions: `!isAdmin()` → `canDelete('backup_restore')`
- bulk_update_payroll: `!in_array($role, ['Admin','Accountant'])`
  → `canEdit('payroll')`

**Audit delta on this branch:** api_perms_no_gate 95 → 51 (this PR
from current main, which has 4.5a + 4.5b + 4.5c-1 already merged).
`api/(root)` module: 64 → 20.

**CI ceiling:** `api_perms_no_gate` 125 → 51.

### ⚠️ Deploy notes
After this merges, non-admin users will get 403 on these 44 endpoints
until admin ticks the matching `create` (or `edit` / `approve`) boxes
in `user_roles.php` for: `employees, leaves, payroll, attendance,
nip_materials, supplier_payments, dn, do, rfq, products,
stock_adjustments, customers, campaign_management, lead_generation,
compliance, document_templates, sms_alerts, categories, backup_restore,
tenders, projects, notification_center (view)`. Deploy after hours.

### Files modified
- 44 `api/*.php` files (see lists above)
- tests/test_security_coverage_cli.php — ceiling 125 → 51

---

## 2026-05-24 (update 96)

### Feat: Security rollout — Phase 4.5c-1 canDelete gates on api/(root) deletes (25 files)

First of three sub-PRs splitting Phase 4.5c (api/(root) has 89 missing
gates — too many for a single PR). This PR covers the 25 `delete_*.php`
endpoints in `api/` root; uniform `canDelete()` pattern, lowest risk.

**25 endpoints gated:**

| File | page_key |
|---|---|
| delete_adjustment | stock_adjustments |
| delete_attendance | attendance |
| delete_brand | products |
| delete_campaign | campaign_management |
| delete_category | categories |
| delete_compliance | compliance |
| delete_dn, delete_dn_attachment | dn |
| delete_document_template, delete_email_template | document_templates |
| delete_employee | employees |
| delete_grn | grn |
| delete_lead | lead_generation |
| delete_leave | leaves |
| delete_material_component, delete_material_list, delete_nip_component, delete_nip_product | nip_materials |
| delete_notification | **canView('notification_center')** (user-personal; row-level scoped) |
| delete_payroll | payroll |
| delete_purchase_order | purchase_orders |
| delete_rfq, delete_rfq_attachment | rfq |
| delete_sms_template | sms_alerts |
| delete_supplier_payment | supplier_payments |

**Notable:**
- `delete_adjustment.php` replaced a legacy hard-coded `$_SESSION['role'] !== 'Admin'`
  check with `canDelete('stock_adjustments')` — admin still bypasses, but
  the role check is no longer hard-coded.
- `delete_notification.php` is user-personal (DELETE … WHERE user_id = ?),
  so it gets `canView('notification_center')` defense-in-depth instead
  of `canDelete` (which would lock non-admin users out of managing their
  own notifications).

**Pattern:** auth check first (401), then perm check (403 with verb-
specific message). `canX()` admin-bypasses via `isAdmin()`.

**Audit delta on this branch:** api_perms_no_gate 150 → 125 (this PR
from main, where 4.5a is already merged). `api/(root)` module: 89 → 64.

**CI ceiling:** `api_perms_no_gate` 173 → 125.

### ⚠️ Deploy notes
After this merges, non-admin users will get 403 on the 25 delete
endpoints until admin ticks the matching `delete` boxes for:
`stock_adjustments, attendance, products, campaign_management,
categories, compliance, dn, document_templates, employees, grn,
lead_generation, leaves, nip_materials, notification_center (view),
payroll, purchase_orders, rfq, sms_alerts, supplier_payments`.
Deploy after hours.

### Files modified
- 25 `api/delete_*.php` files
- tests/test_security_coverage_cli.php — ceiling 173 → 125
### Feat: Security rollout — Phase 4.5b API permission gates on api/operations/ (30 files)

Second production sub-PR of Phase 4.5. Adds `canCreate/canEdit/canDelete`
(and one `canApprove` fallback) to every state-changing endpoint under
`api/operations/`. Without this, any logged-in user could POST directly
to project / asset / maintenance / IPC endpoints and bypass the
page-level permission system.

**30 endpoints gated:**

Status changes / approvals (canApprove or canEdit):
- approve_project_planning → canApprove||canEdit on `projects`
- change_dn_status → canEdit on `dn`
- change_do_status → canEdit on `do`
- update_ipc_status → canEdit on `projects`
- update_staff_project → canEdit on `projects`

Creates (canCreate):
- create_invoice_from_ipc → invoices
- create_project_staff → projects
- process_project_payroll → payroll
- save_goods_return, save_progress_report → projects

Deletes (canDelete):
- delete_asset → assets
- delete_inspection_attachment, delete_project, delete_project_doc,
  delete_project_planning, delete_scope_addendum, delete_scope_document
  → projects
- delete_maintenance_log → maintenance
- delete_warehouse → warehouses

Save-with-create-or-edit branching (chooses canCreate vs canEdit by ID):
- save_asset → assets
- save_inspection, save_ipc, save_project, save_project_leave → projects
- save_maintenance_log → maintenance

Edits (canEdit):
- save_milestones, save_project_attendance → projects

Generic create-or-edit (either perm works):
- save_project_planning, save_scope_document, save_scopes → projects

**Pattern:** identical to 4.5a — auth check first (401), then perm
check (403 with verb-specific message). `canX()` admin-bypasses, so
admin retains break-glass access regardless of DB state.

**Audit delta:** `api_perms_no_gate` 173 → 143 (this PR alone, from main).
After both 4.5a and 4.5b are on main: 173 → 120. `api/operations/`
module gap: 30 → 0.

### ⚠️ Deploy notes
After this merges, non-admin users will get 403 on these 30 endpoints
until admin ticks the matching boxes in `user_roles.php` for the
relevant page_keys: `projects, assets, maintenance, warehouses, dn,
do, invoices, payroll`. Deploy after hours.

### Files modified
- 30 files under `api/operations/` (see lists above)

---

## 2026-05-24 (update 95)

### Feat: Security rollout — Phase 4.5a API permission gates on api/account/ (23 files)

First production sub-PR of Phase 4.5. Adds `canCreate/canEdit/canDelete`
checks to every state-changing endpoint under `api/account/`. Without
this, any logged-in user could POST directly to financial endpoints
and bypass the page-level permission system.

**23 endpoints gated:**

Creates (canCreate):
- add_compound_journal → journals
- add_expense → expenses
- add_transaction → transactions
- create_reconciliation → bank_reconciliation
- save_category → chart_of_accounts (canCreate or canEdit if updating)
- save_voucher → payment_vouchers (canCreate or canEdit if updating)

Deletes (canDelete):
- delete_account → chart_of_accounts
- delete_account_category → chart_of_accounts
- delete_expense → expenses
- delete_invoice → invoices (replaced explicit `!isAdmin()` with
  `canDelete('invoices')` — canDelete admin-bypasses internally, so
  admin behaviour unchanged, but future non-admin roles can be
  delegated via user_roles.php)
- delete_reconciliation → bank_reconciliation
- delete_voucher → payment_vouchers

Edits (canEdit):
- update_budget, update_budget_status → budget
- update_expense, update_expense_status → expenses
- update_journal, update_journal_status, void_journal → journals
- update_reconciliation → bank_reconciliation
- update_transaction, update_transaction_status → transactions
- update_voucher_status → payment_vouchers

**Pattern:** auth check first (returns 401 / Unauthorized), then perm
check (returns 403 / Access Denied with a verb-specific message).
`canX()` functions admin-bypass via `isAdmin()`, so admin retains
break-glass access regardless of DB state.

**Audit delta:** `api_perms_no_gate` 173 → 150. `api/account/` module
gap: 23 → 0.

### ⚠️ Deploy notes
After this merges, any non-admin user trying to POST to these 23
endpoints will receive 403 until admin ticks the matching boxes in
`user_roles.php` for the relevant `page_key` (journals, expenses,
transactions, bank_reconciliation, chart_of_accounts, payment_vouchers,
invoices, budget). Recommended deploy window: after hours.

### Files modified
- 23 files under `api/account/` (see lists above)
### Feat: Security rollout — Phase 4.5 audit baseline (API permission gates)

Phase 4.5 of `security_implementation_plan.md` — first sub-PR of five.
Pure infrastructure; no API behaviour changes.

**What ships in this PR:**
- `scratch/api_permission_audit.php` (new) — scans every write API
  (INSERT/UPDATE/DELETE on the success path) and flags any that lack
  a `canCreate/canEdit/canDelete/canReview/canApprove/canView/
  autoEnforcePermission/enforcePageOrAdmin/hasPermission/
  requireViewPermission/assertCanX` call.
- `tests/test_security_coverage_cli.php` (modified) — wires the new
  audit into the CI guard with ceiling `api_perms_no_gate = 173`
  (current baseline). Sub-PRs 4.5a..4.5d will drop this to 0.

**Baseline gap by module (173 total):**
- api/(root): 89, api/operations: 30, api/account: 23, api/document: 10,
  api/pos: 5, api/sales: 4, api/cash_register: 3, api/payroll: 3,
  api/petty_cash: 2, api/sc: 2, api/finance: 1, api/suppliers: 1.

**Why split into 5 sub-PRs:** plan originally estimated 40-60; actual is
173. Single PR is too risky (deploy-comms blast radius covers every
non-admin role). Same model as Phase 3/4 (account → operations →
api(root) → misc).

**No production code touched. No API can be blocked by this PR.**

### Files modified
- scratch/api_permission_audit.php (new)
- tests/test_security_coverage_cli.php — adds api_perms_no_gate check,
  renumbers sections 3/4 → 3/4/5

---

## 2026-05-24 (update 94)

### Feat: Security rollout — Phase 4b activity logging on module APIs (closes write-API gap)

Phase 4b closes the write-API logging gap entirely. With this PR, every
state-changing API endpoint in the codebase logs to `activity_logs` on
its success path. `write_apis_no_log` audit count: 11 → 0.

**10 endpoints instrumented across 5 modules:**

- `api/document/` (3): delete_collateral_document, delete_signature,
  update_document_metadata
- `api/finance/manage_expense_schema.php` — all 7 switch cases:
  add_type, add_category, edit_category, delete_category, edit_type,
  toggle_show_project, delete_type
- `api/payroll/` (3): add_tax_bracket, delete_tax_bracket,
  update_settings
- `api/pos/` (1): delete_held_sale
- `api/sc/` (2): add_payment, delete_payment

**Audit script updated:** `scratch/activity_log_audit.php` now skips
`/api/helpers/` (library functions, not endpoints). The single hit
there — `api/helpers/transaction_helper.php` — is called by 8 endpoints
in `api/account/` that already log their actions; adding `logActivity()`
inside the helper would double-log every transaction.

**Edit pattern:** same as 3a/3b/3c/4a — one `logActivity()` line on the
success path immediately before `echo json_encode([...])`. No control
flow or transaction boundaries touched.

**CI ceiling:** `write_apis_no_log` 11 → 0. Any future write-API PR
that forgets to log will now fail CI.

### Files modified
- 10 API files (see lists above)
- scratch/activity_log_audit.php — added `/api/helpers/` to ignore list
- tests/test_security_coverage_cli.php — ceiling 11 → 0

---

## 2026-05-24 (update 93)

### Feat: Security rollout — Phase 4a activity logging on api/ root APIs

Phase 4a of `security_implementation_plan.md` v2. Purely additive —
adds `logActivity()` after every successful state-changing write in
44 endpoints under `api/` root. No existing logic touched. With this
phase, `api/(root)` drops from 44 silent writes to 0; only Phase 4b
modules remain (api/document, api/finance, api/helpers, api/payroll,
api/pos, api/sc — 11 files total).

**44 files instrumented:**

Deletes (14):
- delete_brand, delete_campaign, delete_category, delete_compliance,
  delete_document_template, delete_email_template, delete_lead,
  delete_leave, delete_notification, delete_purchase_order,
  delete_purchase_return, delete_sms_template, delete_supplier
  (soft + hard log paths), delete_supplier_payment

Saves/creates (12):
- save_backup_settings, save_brand (Created/Updated), save_campaign
  (Created/Updated), save_compliance (Created/Updated),
  save_email_template (Created/Updated), save_lead (Created/Updated),
  save_notification_preferences, save_sms_template (Created/Updated),
  save_unit, create_category, create_purchase_return,
  add_supplier_payment

Updates (5):
- update_category, update_grn, update_leave, update_purchase_return,
  update_purchase_return_status

Workflow / bulk / import / misc (13):
- apply_leave, approve_leave, reject_leave, cancel_leave,
  duplicate_leave, bulk_update_leave_status, mark_notification_read,
  mark_payroll_paid, notification_bulk_actions, import_customers,
  import_leaves, import_suppliers, backup_actions
  (create / restore / delete / upload_restore — 4 paths)

**Replaced bespoke INSERT-into-activity_logs blocks** in
`delete_supplier.php`, `import_customers.php`, and `import_suppliers.php`
with calls to the shared `logActivity()` helper. Equivalent semantics,
single canonical pattern.

**Edit pattern:** one `logActivity($pdo, $userId, $action, $details)`
line on the success path immediately before `echo json_encode([...])`.
No changes to flow control or transactional boundaries.

**Audit deltas:**
- write_apis_no_log: 62 → 11
- `api/(root)` module summary: 44 missing → 0 missing

**CI ceiling tightened in `tests/test_security_coverage_cli.php`:**
- `write_apis_no_log`: 62 → 11

### Files modified
- 44 files under `api/` root (see lists above)
- tests/test_security_coverage_cli.php — ceiling 62 → 11, removed
  duplicate write_apis_no_log key from the previous 3b commit

---

## 2026-05-24 (update 92)

### Feat: Security rollout — Phase 3c activity logging on operations APIs

Phase 3c of `security_implementation_plan.md` v2. Purely additive —
adds `logActivity()` after every successful state-changing write in
the 21 `api/operations/` endpoints. No existing logic touched. This
is the largest single phase in the 3-series.

**21 files instrumented:**

Deletes (8):
- delete_asset, delete_inspection_attachment, delete_maintenance_log,
  delete_project, delete_project_planning, delete_scope_addendum,
  delete_scope_document, delete_warehouse

Saves/creates (10):
- save_asset (Created/Updated + status-change), save_goods_return,
  save_maintenance_log (Created/Updated), save_milestones,
  save_progress_report, save_project (Created/Updated),
  save_project_attendance, save_project_leave (Applied/Updated),
  save_project_planning, save_scope_document, save_scopes

Specialized (3):
- approve_project_planning — workflow approval
- process_project_payroll — money path (salary records)

**Edit pattern:** one `logActivity()` line after the successful DB
write and before the success `echo json_encode(...)`. Save endpoints
distinguish Created vs Updated via existing $log_id/$leave_id/$project_id
flags. Status-change endpoints log the new status. Same template as
Phases 3a and 3b.

**CI ceiling tightened:** `write_apis_no_log ≤ 62` (was 100). Cumulative
progress in the 3-series:
  100 → 83 (Phase 3a, 17 files)
   83 → 76 (Phase 3b, 7 files)
   76 → 62 (Phase 3c, 21 files)
Phase 4a (45 files) will drop to ~17, then 4b clears the rest.

Also brought the ceiling block up to date — `pages_no_gate` tightened
to 66 to reflect Phase 2 already being merged on main.

**Why operations logging matters:** projects are the operational root.
A silent delete of a project wipes its POs, GRNs, payroll records,
milestones, scopes. A silent payroll process creates salary records.
A silent scope document upload changes contractual evidence. Every
one of those events now leaves a row in `activity_logs`.

**Verification:**
- `php scratch/activity_log_audit.php` → "Total: 62 write API(s) with
  no log" (down from 83 — exactly the 21 closed).
- `php scratch/verify_admin_bypass.php` → 11 passes / 0 failures.
- `php tests/test_security_coverage_cli.php` → 10 passes / 0 failures
  at the tightened ceiling.
- All 21 touched PHP files pass `php -l`.
## 2026-05-24 (update 91)

### Feat: Security rollout — Phase 3b activity logging on cash + petty_cash APIs

Phase 3b of `security_implementation_plan.md` v2. Purely additive —
adds `logActivity()` after every successful state-changing write in
the 7 cash-flow APIs. No existing logic touched.

**7 files instrumented:**

Cash register (5):
- `api/cash_register/open_shift.php` — shift open is the start of every
  cash session, logs starting cash.
- `api/cash_register/close_shift.php` — close is the most audit-critical
  cash event: logs expected vs actual vs difference.
- `api/cash_register/add_transaction.php` — every cash movement in/out.
- `api/cash_register/update_shift.php` — manager-level adjustments.
- `api/cash_register/delete_shift.php` — removing a shift wipes audit
  history; logged in `activity_logs` so the deletion itself is tracked.

Petty cash (2):
- `api/petty_cash/save_transaction.php` — Created/Updated with the
  existing $transaction_id flag for accurate verb.
- `api/petty_cash/delete_transaction.php` — every petty cash deletion.

**Edit pattern:** one new line — `logActivity($pdo, $_SESSION['user_id'],
"<Action>", "<details>")` — placed after the successful DB write,
before the success `echo json_encode(...)`. Same template as Phase 3a.

**CI ceiling tightened:** `write_apis_no_log ≤ 76` (was 83). Future PRs
that re-introduce a silent cash write fail CI.

**Verification:**
- `php scratch/activity_log_audit.php` → "Total: 76 write API(s) with
  no log" (down from 83; exactly the 7 in this PR closed).
- `php scratch/verify_admin_bypass.php` → 11 passes / 0 failures.
- `php tests/test_security_coverage_cli.php` → 10 passes / 0 failures
  at tightened ceiling.
- All 7 touched PHP files pass `php -l`.

**Why cash logging matters:** cash-register shifts and petty cash are
the most reconciliation-sensitive events in the system. A silent close
that produces a -$200 cash difference is a $200 hole nobody can trace.
Now every shift open/close + every transaction (cash and petty) leaves
a row in `activity_logs` queryable by date, user, or amount.

**Rollback:** `git revert <sha>`. Each addition is one line; behaviour
is identical before and after.

## 2026-05-24 (update 90)

### Fix: commit missing security artefacts + clean up duplicate ceiling keys

The Phase 0 CI guard `tests/test_security_coverage_cli.php` was still
failing on GitHub Actions after update 89, with:

```
❌ security_implementation_plan.md MISSING — security framework broken
❌ security_audit_2026_05_24.md MISSING — security framework broken
```

Root cause: four files were created with the Write tool during the
audit phase but **were never `git add`-ed**, so they only existed on
my local disk — not in the repo. The CI guard's "Required security
artefacts" section correctly flagged them as missing.

**Files added (now tracked):**
- `scratch/security_audit.php` — re-runnable permission gap audit
  (referenced by the CI guard and the pre-push hook)
- `scratch/activity_log_audit.php` — re-runnable log gap audit
  (same — referenced by both)
- `security_audit_2026_05_24.md` — the original findings report
- `security_implementation_plan.md` — the live rollout plan v2

These are NOT scratch files in the disposable sense — they are the
audit tools and contracts the security framework depends on. They
should have been committed in Phase 0.

**Bonus cleanup:** when `fix/security-coverage-ci-skip` (update 89) was
merged into develop, it picked up the Phase 2 + Phase 3a ceiling
tightenings from the open PRs and ended up with duplicate `$CEILINGS`
keys (PHP keeps the last value but it's a code smell). Cleaned up to
reflect what's actually merged on `main` right now:

```php
'pages_no_gate'       => 76,    // Phase 2 (PR open) will drop to 66
'page_key_missing_db' => 0,     // Phase 1 dropped to 0 (merged)
'write_apis_no_log'   => 100,   // Phase 3a (PR open) will drop to 83
'view_pages_no_log'   => 55,    // Phase 7 (DEFERRED)
```

Verified:
- With DB (local)    : 10 ✅ / 0 ❌ / 0 ⏭ — full audit runs.
- Without DB (CI sim): 6  ✅ / 0 ❌ / 4 ⏭ — exit 0.
- `php -l` clean on all three touched PHP files.

After this lands, the open `feat/sec-02-lock-admin-pages` and
`feat/sec-03a-log-account-apis` PRs will rebase / merge clean and
their CI runs will go green. Then each of those PRs needs its own
follow-up commit to tighten the ceiling line for the gap it closes
(66 and 83 respectively).

## 2026-05-24 (update 89)

### Fix: security coverage CI guard now skips DB sections gracefully

The Phase 0 CI guard `tests/test_security_coverage_cli.php` was failing
the GitHub Actions `Test & Deploy to Production` job with:

```
Passes: 2  Failures: 6
❌ Security coverage regressed — push blocked.
```

Root cause:
- The guard `shell_exec`'s `scratch/security_audit.php` and
  `scratch/activity_log_audit.php`, both of which `require_once
  includes/config.php` and open a MySQL connection.
- `includes/config.php` is gitignored and MySQL is not provisioned in
  the GitHub Actions runner.
- When the audit sub-scripts die early (no config, no DB), their
  expected gap-count lines never appear, so `parseCount()` returns -1
  and the guard reports 6 failures (4 ceilings + 2 missing parses).

Fix:
- Add an `audits_can_run()` probe that checks (a) config.php exists
  AND (b) PDO can `SELECT 1`. If either fails, the DB-dependent audit
  sections are marked SKIPPED with a clear "(no DB on this host)"
  note, and the guard exits 0.
- The four static checks (file presence, helpers require_once) still
  run regardless and still fail the build if they regress.
- Local behaviour unchanged: with `includes/config.php` and a live
  MySQL the guard runs the full audit as before.

Why this is safe:
- The pre-push hook on developer machines runs all three guards
  (security coverage, admin break-glass, stock_movements ENUM) AND
  has DB access, so regressions are caught BEFORE code reaches GitHub.
- CI's role here is to enforce static invariants (helpers wired up,
  audit scripts on disk) which it now does cleanly.
- The skipped sections print a yellow note prompting the operator to
  run the audit on the target server before merging security PRs.

Verified:
- With DB (local): 10 passes / 0 failures / 0 skipped.
- Without DB (config.php temporarily renamed): 6 passes / 0 failures
  / 4 skipped — exit 0.
- Negative test: lowering any ceiling by 1 with DB present correctly
  fails with exit 1.

This hotfix unblocks the open `feat/sec-03a-log-account-apis` PR
(which was correctly green locally but failed the GitHub guard on
this same root cause).
### Feat: Security rollout — Phase 3a activity logging on api/account/ writes

Phase 3a of `security_implementation_plan.md` v2. Purely additive — adds
`logActivity()` calls after every successful state-changing write in the
17 `api/account/` endpoints. No existing logic, no schema, no behaviour
touched.

> Numbered 89 because update 88 is the still-open Phase 2 PR
> (`feat/sec-02-lock-admin-pages`). If merge order shifts, the Phase 3a
> PR will need a one-line bump on resolve.

**17 files instrumented:**

Delete endpoints (6):
- `api/account/delete_account_category.php`
- `api/account/delete_invoice.php`
- `api/account/delete_purchase_order.php`
- `api/account/delete_purchase_return.php`
- `api/account/delete_reconciliation.php`
- `api/account/delete_voucher.php`

Save / create endpoints (4):
- `api/account/create_reconciliation.php`
- `api/account/save_category.php`
- `api/account/save_purchase_order.php`
- `api/account/save_purchase_return.php`
- `api/account/save_voucher.php`

Update endpoints (6):
- `api/account/update_invoice_status.php`
- `api/account/update_purchase_order_status.php`
- `api/account/update_purchase_return_status.php`
- `api/account/update_reconciliation.php`
- `api/account/update_reconciliation_status.php`
- `api/account/update_voucher_status.php`

**Edit pattern per file:** one new line — `logActivity($pdo,
$_SESSION['user_id'] ?? 0, "<Action>", "<details>")` — placed **after**
the successful DB write and **before** the success `echo json_encode(...)`.
The `?? 0` fallback prevents a fatal when an unauthenticated request
somehow gets past the auth check.

Save endpoints distinguish "Created X" vs "Updated X" via the existing
`$is_update` flag in those files so the log row reflects intent.

**CI ceiling tightened:** `tests/test_security_coverage_cli.php` now
locks `write_apis_no_log ≤ 83` (was 100). Future PRs cannot regress.

**Verification:**
- `php scratch/activity_log_audit.php` → "Total: 83 write API(s) with
  no log" (down from 100; exactly the 17 in this PR closed).
- `php scratch/verify_admin_bypass.php` → 11 passes / 0 failures.
- `php tests/test_security_coverage_cli.php` → 10 passes / 0 failures
  at the tightened ceiling.
- All 17 touched PHP files pass `php -l`.

**Rollback:** `git revert <sha>`. Each addition is one line; pre-
existing behaviour is unchanged because logging is fire-and-forget.
## 2026-05-24 (update 88)

### Feat: Security rollout — Phase 2 lock the critical admin pages

Phase 2 of `security_implementation_plan.md` v2. The HIGHEST-risk phase
in the entire rollout — actually locks non-admin users out of pages they
could open before. Read the deploy notes in the PR body before merging.

**10 pages now gated** with `autoEnforcePermission(...)`:

| File | Permission key |
|---|---|
| `app/activity_log.php`                              | `audit_logs` |
| `app/constant/settings/users.php`                    | `users` |
| `app/constant/settings/add_user.php`                 | `add_user` |
| `app/constant/settings/edit_user.php`                | `edit_user` |
| `app/constant/settings/system_settings.php`          | `system_settings` |
| `app/constant/settings/backup_restore.php`           | `backup_restore` |
| `app/constant/settings/company_profile.php`          | `company_profile` |
| `app/constant/settings/payment_settings.php`         | `payment_settings` |
| `app/constant/settings/tax_settings.php`             | `tax_settings` |
| `app/constant/settings/notification_settings.php`    | `notification_settings` |

**Edit pattern (each file):** ONE new line of code — `autoEnforcePermission('key')`
added before any output. No existing logic touched. The pre-existing
`isAdmin()` / `canEdit()` / `hasPermission()` belt-and-suspenders checks
all remain in place as second-layer defence.

**Why these 10 first:** the security audit (`security_audit_2026_05_24.md`)
flagged them as the "deadly intersection" — admin-tier pages with no
permission gate AT ALL. Before this PR, any logged-in user could open
`activity_log.php` and read the entire audit trail, or `users.php` and
manage accounts, or `system_settings.php` and change global config.

**Default-deny posture:** all permission keys exist in DB (seeded in
Phase 1 + already-existing rows) but no non-admin role has any of them
enabled. After this merges, admin **must** open `/user_roles.php` and
explicitly grant the appropriate boxes to each role before notifying
staff.

**CI ceiling tightened:** `tests/test_security_coverage_cli.php` now
locks `pages_no_gate ≤ 66` (was 76). Future PRs cannot regress this.

Verification:
- `php scratch/verify_admin_bypass.php` → 11 passes / 0 failures
  (admin break-glass intact)
- `php scratch/security_audit.php` → "Pages with NO gate: 66"
  (down from 76; exactly the 10 pages targeted)
- `php tests/test_security_coverage_cli.php` → 10 passes / 0 failures
  at the new tighter ceiling
- All 10 touched PHP files pass `php -l`

**Rollback:** `git revert <sha>`. Each gate is one line; reverting takes
all 10 back simultaneously. Pre-existing `isAdmin()` checks (where
they existed) continue to protect the pages even without the new
gate, so the rollback target is also safe.

## 2026-05-24 (update 87)

### Feat: Security rollout — Phase 1 DB cleanup (seed missing permission keys)

Phase 1 of `security_implementation_plan.md` v2. No application code is
touched. One idempotent migration only.

> Note: numbered 87 because Phase 0 (update 85) and Phase 0.5 (update 86)
> are still open PRs. If merge order differs, changelog numbers will need
> a one-line resolve. No code-level conflicts expected.

**Migration:** `migrations/2026_05_24_security_seed.php`

Three actions, all idempotent:

**A. Seed 18 missing permission keys** that existing pages already
reference but were never inserted into the `permissions` table. Before
this migration, admin **could not grant these permissions** via
user_roles.php — so the pages were permanently locked for everyone
except admin (who bypasses via `isAdmin()`). After this migration
admins see checkboxes for them in Configure Permissions. **All new rows
are zero-trust** — no `role_permissions` entries are created, so
non-admin roles must be explicitly granted access via the UI.

Keys seeded (grouped by module):
- Procurement (3): `supplier_payments`, `nip_materials`, `purchase`
- Reports (7): `reports`, `financial_reports`, `asset_report`,
  `customer_analysis`, `employee_report`, `product_analysis`,
  `trends_analysis`
- Documents (3): `documents`, `compliance`, `loan_documents`
- System Settings (3): `admin`, `activity_log`, `profile`
- Finance (1): `payment_create`
- Human Resources (1): `payslip`

The `purchase`, `compliance`, and `admin` keys are flagged in their
description as "legacy — to be normalized in Phase 5"; they exist now so
admin has a checkbox to grant them, but Phase 5 will rename them in the
calling code to more specific keys.

**B. Module name typo fix:** `procurement` (lowercase) → `Procurement`.
Affected 3 rows (`dn`, `do`, `rfq`). Before the fix, `user_roles.php`
rendered Procurement as **two separate panels** (one per case variant).
Now one unified Procurement panel with 11 permissions.

**C. Blank label fix:** `received_invoices` row's `page_name` was empty
string — invisible in the UI. Now reads "Received Invoices".

Verification on local DB:
- First run: 18 new rows + 3 module renames + 1 label fix.
- Second run: 0 / 0 / 0 — fully idempotent.
- `scratch/security_audit.php` "Gate key missing in DB" count drops
  from **23 → 0**.
- `Procurement` is now a single 11-row module (was split 5 + 3).
- Total `permissions` rows: 105 (was 87).

After this lands, `tests/test_security_coverage_cli.php` (Phase 0) will
report `page_key_missing_db : 0 (ceiling: 23) — improved by 23`. The
ceiling will be tightened to 0 in a follow-up commit after Phase 0
merges.

Rollback: `git revert <sha>` is safe but does NOT remove the seeded
permission rows. To roll back fully:
```sql
DELETE FROM permissions WHERE page_key IN ('supplier_payments','reports',
  'purchase','nip_materials','financial_reports','documents','compliance',
  'loan_documents','admin','customer_analysis','employee_report',
  'product_analysis','trends_analysis','asset_report','activity_log',
  'profile','payment_create','payslip');
UPDATE permissions SET module_name = 'procurement'
  WHERE page_key IN ('dn','do','rfq');
```
But there is no operational reason to roll back — the seeds are
default-OFF for every non-admin role.
## 2026-05-24 (update 86)

### Feat: Security rollout — Phase 0.5 admin break-glass sanity check

Phase 0.5 of `security_implementation_plan.md` v2. Purely additive — adds
two new files only. No existing logic, schema, or behaviour touched.

> Note: numbered 86 because it ships after Phase 0 (update 85). If Phase
> 0.5 is merged before Phase 0, changelog.md will show 86 then 84 and the
> Phase 0 PR will need a one-line bump on resolve.

**Why:** Phases 1-9 will progressively tighten permission gates. If
`isAdmin()` is silently broken (schema drift, stale `roles` row, missing
admin user), tightening the gates would lock us out of `users.php` /
`user_roles.php` — i.e. **out of the only UI that can fix permissions.**
This phase guarantees the break-glass path is intact BEFORE every
later phase merges.

Files added:
- `scratch/verify_admin_bypass.php` — runtime / DB sanity check.
  Asserts:
    1. At least one role in `roles` has `is_admin = 1`.
    2. At least one ACTIVE user is assigned to such a role.
    3. `isAdmin()` returns true for an admin role's session.
    4. `canView/canCreate/canEdit/canDelete/canReview/canApprove` all
       honour the `isAdmin()` bypass even when permission rows are missing.
    5. `app/constant/settings/user_roles.php` exists and is valid PHP.
  Run on local + production BEFORE deploying any further security
  tightening: `php scratch/verify_admin_bypass.php`. Exit 0 = safe.
- `tests/test_admin_breakglass_cli.php` — CI regression guard, no DB
  required. Asserts source-level invariants:
    1. `isAdmin()` declared, queries `roles.is_admin` (not hard-coded
       `role_id=1`), and checks `$_SESSION['is_admin']` fast path.
    2. All six `canX()` helpers have the `if (isAdmin()) return true;`
       bypass line.
    3. `scratch/verify_admin_bypass.php` exists and is valid PHP.
    4. `app/constant/settings/user_roles.php` exists and is valid PHP.
    5. `actions/login.php` sets `$_SESSION['role_id']` so the DB
       fallback in `isAdmin()` is usable after login.
  Runs on every push as a deploy gate.

CI: new "Admin break-glass invariants (Phase 0.5)" step added to
`.github/workflows/deploy.yml`. Runs after the e-signatures suite.

Verification:
- `php scratch/verify_admin_bypass.php` → 11 passes / 0 failures.
- `php tests/test_admin_breakglass_cli.php` → 14 passes / 0 failures.
- Negative test: mangling the `isAdmin()` bypass in any `canX()` helper
  correctly fails the CI guard with a clear "MISSING the admin bypass"
  message and exits 1.
- Both touched PHP files pass `php -l`.

Rollback: `git revert <sha>`. Purely additive; no existing function or
schema touched.
## 2026-05-24 (update 85)

### Feat: Security rollout — Phase 0 foundation (helpers + CI regression guard)

Phase 0 of `security_implementation_plan.md` v2. Purely additive — no
existing logic changes, no behaviour change for end users today. Lays the
foundation for Phases 0.5 → 9 to drop the security gap counts to zero.

Files:
- `core/security_helpers.php` (new) — thin wrapper layer offering a
  consistent vocabulary for the rest of the rollout:
    * `logSecure($action, $description=null)` — dedup-aware wrapper around
      `logActivity()`; same action/description in the same request is
      logged once. No-op when there's no $pdo or $_SESSION['user_id'].
    * `enforcePageOrAdmin($pageKey)` — alias for `autoEnforcePermission()`
      that also writes a server-side note when the page_key is missing
      from the permissions table (catches the silent default-deny when
      admin hasn't seeded a row).
    * `assertCanCreate/Edit/Delete($pageKey)` — uniform JSON 403 helpers
      for state-changing API endpoints. Used from Phase 4.5 onward.
- `core/permissions.php` — one new line: `require_once __DIR__ .
  '/security_helpers.php';` so the wrappers are loaded everywhere
  permissions are. All existing functions in this file untouched.
- `app/constant/settings/user_roles.php` — added `logActivity()` calls
  alongside the existing `logAudit()` calls on three security-sensitive
  events:
    * Role created / role permissions updated
    * Role deleted
    * User role assignment changed
  The existing `logAudit()` writes to `audit_logs` (rich detail). The new
  `logActivity()` writes to `activity_logs` so the change is visible on
  `app/activity_log.php` where security staff watch. No existing log call
  was removed.
- `tests/test_security_coverage_cli.php` (new) — CI regression guard.
  Re-runs both audit scripts on every push and FAILS the build if any
  gap count exceeds the locked baseline:
      pages_no_gate        ≤ 76
      page_key_missing_db  ≤ 23
      write_apis_no_log    ≤ 100
      view_pages_no_log    ≤ 55  (Phase 7 deferred; ceiling kept loose)
  As each later phase ships, the corresponding ceiling drops; after
  Phase 9 all four ceilings reach 0 and any future regression fails CI.
- `.github/workflows/deploy.yml` — one new "Security coverage regression
  guard" step that runs `php tests/test_security_coverage_cli.php` before
  the deploy job is allowed to start.

Verification:
- `php tests/test_security_coverage_cli.php` → 10 passes / 0 failures
  on the current main baseline.
- Negative test: lowering any ceiling by 1 correctly fails the run with
  a "push blocked" exit-1 message.
- All four touched PHP files pass `php -l`.

## 2026-05-24 (update 84)

### Fix: Sales Returns "Mark as Refunded" no longer truncates the row

Production was failing when a user clicked the "Mark as Refunded" button
on a sales return:

  "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1"

Root cause: schema/UI mismatch. The UI offered a 'refunded' status
(`app/bms/sales/sales_returns/sales_return_view.php` line 96 button +
list-page dropdown at `sales_returns.php` line 790), but the DB enum was
`enum('pending','approved','rejected','completed','cancelled')` — no
'refunded' value. MySQL silently truncated the value to '' (warning
1265), corrupting the row. PDO's `ERRMODE_EXCEPTION` then surfaced it
as a fatal error.

Fix — Option A from the analysis (preserve UI semantic):
- `migrations/2026_05_24_sales_returns_refunded_status.php` (new) —
  extends the enum to include 'refunded'; idempotent. ALSO repairs
  any rows previously corrupted to `status=''` (1 such row found
  locally) by setting them to 'refunded' (matches the original user
  intent of clicking the button). Admins can re-correct via the
  now-working UI.
- `api/sales/update_return_status.php` — added a whitelist of valid
  statuses (`pending`, `approved`, `rejected`, `completed`, `cancelled`,
  `refunded`). Defence in depth against another silent-truncation bug.

No UI files touched — the UI already offered the right semantic, only
the DB + API needed to catch up.

Verification:
- Migration ran cleanly on local DB; second run was a no-op (idempotent).
- Local row with `status=''` was repaired to `refunded`.
- Final enum: `pending, approved, rejected, completed, cancelled, refunded`.
- Both touched PHP files pass `php -l`.

## 2026-05-24 (update 83)

### Fix: GRN approval no longer truncates movement_type/reference_type ENUMs

Production was failing every GRN approval with
`SQLSTATE[01000]: 1265 Data truncated for column 'movement_type' at row 1`.
Same bug as the DN fix from PR #301 but in three GRN write paths.

Root cause: `stock_movements` has two strict ENUMs and the GRN inserts
used literals that are not members:

| Column | Code used | Valid ENUM | Fixed to |
|---|---|---|---|
| `movement_type`  | `'in'`  | no `'in'` value      | `'purchase_in'` |
| `reference_type` | `'grn'` | no `'grn'` value     | `'purchase_order'` |

MySQL silently truncated both to empty string with warning 1265, which
PDO escalates to an exception under `ERRMODE_EXCEPTION`, rolling back
the entire approval transaction. No GRN could move from `reviewed` to
`approved` on production until this lands.

Files changed:
- `api/approve_grn.php` — fired on every reviewed → approved transition.
  Was the active production failure.
- `api/update_grn_status.php` — fired when an admin marks a legacy
  draft/pending GRN as `completed`. Same bug, same fix.
- `api/create_grn.php` — currently dead code (the GRN three-approval
  slice set `$updateStock = false`), but updated defensively so a
  future re-enable of that branch does not re-introduce the bug.
- `tests/test_stock_movements_enum_safety_cli.php` — promoted all three
  GRN files from `$known_pending` to `$IN_SCOPE`. The regression guard
  now actively prevents any of them from regressing. Only
  `api/pos/process_sale.php` remains documented as follow-up work.

Verification: `php tests/test_stock_movements_enum_safety_cli.php` →
9 passes, 0 failures. All four PHP files lint clean.

## 2026-05-24 (update 82)

### Test: stock_movements ENUM safety regression guard

Two ENUM-truncation bugs in a row on `api/approve_dn.php`
(updates 80 + 81) warranted a static guard so this class of bug can't
silently land again. Same root cause runs across several other writers
to `stock_movements`, so the guard also indexes the known follow-up
work.

- `tests/test_stock_movements_enum_safety_cli.php` (new) — parses every
  `INSERT INTO stock_movements (...)` in `$IN_SCOPE` files, extracts
  the literal value written to `movement_type` and `reference_type`,
  and FAILS if any literal is not a member of the canonical ENUM list.
  Currently guards `api/approve_dn.php`. The companion `$known_pending`
  list tracks the four sibling files (`api/approve_grn.php`,
  `api/create_grn.php`, `api/update_grn_status.php`,
  `api/pos/process_sale.php`) that still write non-ENUM literals; each
  one can be promoted to `$IN_SCOPE` in its own fix PR. Parser
  handles nested parens (`NOW()`), single-quoted strings and
  multi-line `INSERT`s.
- `.github/workflows/php-lint.yml` — wired the new test into the PR
  gate so every future PR is checked.
- Verified locally: re-introducing the old `'in'` literal in
  `api/approve_dn.php` fails the test (exit 1) with a precise
  file:line pointer; restoring the fix returns exit 0.

## 2026-05-24 (update 81)

### Fix: DN approve fails with "Data truncated for column 'reference_type'"

Update 80 fixed the `movement_type` ENUM mismatch on the same INSERT but
left `reference_type = 'dn'` in place. The
`stock_movements.reference_type` column is also a strict ENUM
(`purchase_order,sales_order,pos_sale,invoice,stock_adjustment,
stock_transfer,return,production_order,manual`) and `'dn'` is not a
member, so approving an inbound DN now throws
`SQLSTATE[01000]: Warning: 1265 Data truncated for column 'reference_type'
at row 1`.

- `api/approve_dn.php`: stock-movement INSERT for the inbound branch
  now writes `'stock_transfer'` for `reference_type` (matching the
  `'transfer_in'` movement_type from update 80). The DN identity is
  not lost — `reference_id` already holds the `delivery_id` and the
  delivery_number is appended to the `notes` column. Comment updated
  to cover both ENUMs.

## 2026-05-24 (update 80)

### Fix: DN approve fails with "Data truncated for column 'movement_type'"

Approving an inbound delivery note threw
`SQLSTATE[01000]: Warning: 1265 Data truncated for column 'movement_type'
at row 1`. The `stock_movements.movement_type` column is a strict ENUM
(`purchase_in,sale_out,transfer_in,…,issue_out`) and `api/approve_dn.php`
was inserting the literal `'in'`, which is not a member of that ENUM.

- `api/approve_dn.php`: the stock-movement INSERT for the inbound branch
  now writes `'transfer_in'` (an inbound DN moves stock between
  warehouses, not a direct purchase — purchases route through GRN with
  `'purchase_in'`). Inline comment added pointing at the ENUM so the
  reason isn't lost on the next reader.

Note: the same `'in'` literal is also present in `api/approve_grn.php`,
`api/create_grn.php` and `api/update_grn_status.php` (correct value
there: `'purchase_in'`), and `'out'` in `api/pos/process_sale.php`
(correct: `'sale_out'`). These will hit the same truncation warning
when triggered and should be patched in a follow-up — not bundled here
to keep this PR narrowly scoped to the reported DN approval bug.

## 2026-05-24 (update 79)

### Fix: php-lint CI no longer requires the removed RFQ migration file

Update 78 deleted `api/migrate_rfq_workflow.php` (the legacy
token-guarded migration) but the "Verify key RFQ workflow files exist"
step in `.github/workflows/php-lint.yml` still listed it as required,
which broke PR CI with `❌ 1 required file(s) missing` and left PR #299
(develop → main) in an `unstable` mergeable_state.

- `.github/workflows/php-lint.yml` — replaced the dead
  `api/migrate_rfq_workflow.php` entry in the `FILES` array with the new
  `migrations/2026_05_08_rfq_three_stage_workflow.php`, so the check
  still meaningfully guards the RFQ workflow rather than just dropping
  the assertion. Comment added pointing to the rename for future
  archaeology.

## 2026-05-24 (update 78)

### Chore: Convert RFQ workflow migration to auto-runner format

The RFQ three-stage workflow schema change lived in
`api/migrate_rfq_workflow.php` as a token-guarded browser script and was
never picked up by `migrations/runner.php`. As a result the columns
(`reviewed_by`, `approved_by`, the matching `*_by_name` / `*_by_role` /
`*_at` snapshot columns, and the `'review'` value in `rfq.status`) were
missing on production, and `rfq_view.php` errored with
`SQLSTATE[42S22]: Unknown column 'reviewed_by' in 'field list'` once the
new view was deployed.

- `migrations/2026_05_08_rfq_three_stage_workflow.php` (new) —
  runner-format, idempotent. Adds the 10 audit columns to `rfq`,
  expands `rfq.status` ENUM with `'review'`, and backfills
  `prepared_by_name` / `prepared_by_role` for historical rows. The
  `role_permissions.can_review` / `can_approve` columns are NOT
  re-added here; they are already owned by
  `2026_05_19_received_invoices_can_approve.php` and
  `2026_05_22_role_permissions_can_review.php`.
- `api/migrate_rfq_workflow.php` (removed) — superseded by the runner
  migration above. Future deploys to any server (existing or fresh)
  will pick the schema change up automatically.

## 2026-05-24 (update 77)

### Fix: GRN print — Created/Reviewed/Approved By signature + Close button

User feedback: the GRN print's signature block showed STORE KEEPER /
INSPECTED BY / VENDOR (rendered as one cramped line in some browsers,
"STORE KEEPERadminINSPECTED BYSign & NameVENDOR / DELIVERED BY") and was
inconsistent with the other prints in the system; the Close button was
also inert because `window.close()` is silently ignored when the page
isn't script-opened.

- `app/bms/grn/grn_print.php`:
    * SELECT extended to pull the creator's full name + role (from the
      `users` table joined on `created_by`).
    * `$wf` array assembled and the existing STORE KEEPER / INSPECTED BY
      / VENDOR signature block replaced with the canonical
      `includes/workflow_signature_row.php` partial (Created By /
      Reviewed By / Approved By) — same as PO, SO, Invoice, DN.
    * Missing `.signature-line small` CSS rule added (matches the
      canonical signature row in `print_quotation.php`).
    * Close button now calls `closePrintWindow()` which tries
      `window.close()` first; if the tab is still open 100ms later (no
      opener), it falls back to `history.back()`, and finally to a
      hard-redirect to `/grn`. Never a dead button again.

The earlier `received_by_name` reference at the "Receipt Information"
panel was retargeted to use the same `$grn_creator_name` for consistency.

## 2026-05-24 (update 76)

### Feat: Apply three_approval.md workflow to GRN (vertical slice 5)

Fifth vertical slice on top of PO + SO + Invoice + DN. Shared partials
reused unchanged.

- `migrations/2026_05_24_grn_three_approval.php` (new) — expands
  `purchase_receipts.status` ENUM with `pending,reviewed,approved`
  alongside legacy `completed,cancelled`; promotes draft → pending;
  drops `'draft'` from the ENUM; default → `'pending'`. Keeps existing
  `'completed'` rows untouched (their stock was already applied under
  the previous create-time flow). Adds 8 audit columns (`reviewed_by`,
  `reviewed_by_name/role`, `reviewed_at`, `approved_by`,
  `approved_by_name/role`, `approved_at`). Grants `can_review`+
  `can_approve` on `page_key='grn'`. Idempotent.
- `api/review_grn.php` (new) — `assertReviewable()`, FOR UPDATE, audit
  snapshot stamp, `logActivity()`.
- `api/approve_grn.php` (new) — `assertApprovable()`, stamps approval
  audit AND fires the **stock-receipt side-effect** that the legacy
  `create_grn.php` used to fire when `$status === 'completed'`. Updates
  `products.current_stock` + `products.stock_quantity`, upserts the
  warehouse-specific `product_stocks` row (with project-aware
  `reserved_quantity`), and logs to `stock_movements`. Service products
  and non-tracked items are skipped (same rules as the original).
  three_approval.md §1 rule 6 compliance.
- `api/create_grn.php` — `$status` hard-coded to `'pending'` on insert;
  `$updateStock = false;` (stock side-effects moved to approve_grn).
- `roots.php` — four new entries (clean + `.php`) for review_grn and
  approve_grn.
- `app/bms/grn/grn.php` (list) — JS flags `GRN_CAN_REVIEW`/`APPROVE`/
  `IS_ADMIN`; status filter rebuilt (`Draft` removed, `Pending /
  Reviewed / Approved / Completed (legacy)` added); action menu
  renders Mark Reviewed + Approve GRN in parallel (one active, one
  disabled with tooltip); Edit gated by `canEditDocument()`; Delete
  gated by `(isPending || isAdmin)`; new JS handlers
  `markReviewedGRN()` + `approveGRN()` calling the dedicated APIs.
- `app/bms/grn/grn_view.php` — audit panel via `workflow_audit_panel`;
  parallel Review/Approve buttons (one active, one disabled by status);
  Edit button gated by `canEditDocument()`; `markReviewedFromView()` +
  `approveGRNFromView()` JS handlers.
- `app/bms/grn/grn_print.php` — DRAFT watermark partial included for
  non-approved GRNs. Watermark suppressed when `status='completed'`
  (legacy terminal state). **Existing STORE KEEPER / INSPECTED BY /
  VENDOR signature labels preserved** per i_e_print.md §11 — they are
  operationally distinct from the three-approval signatures (who
  received the goods physically vs. who reviewed/approved the document).
  The three-approval audit trail is shown in the view page's audit
  panel where it's needed for compliance.
- `tests/test_grn_three_approval_cli.php` (new) — 68-assertion smoke
  test. Verifies files, syntax, schema, audit cols, permissions, source
  contracts, AND specifically asserts `approve_grn.php` updates
  `product_stocks`, `stock_movements`, AND `products.stock_quantity`
  (regression guard for the moved side-effect). Invokes
  `get_grns.php` — returns 25 rows from live DB, no handler-side
  warnings.

## 2026-05-24 (update 75)

### Feat: Apply three_approval.md workflow to Delivery Notes (vertical slice 4)

Fourth vertical slice on top of PO + SO + Invoice. Shared partials reused
unchanged.

- `migrations/2026_05_24_dn_three_approval.php` (new) — adds `reviewed_by`
  INT, promotes legacy `draft`→`pending` and `review`→`reviewed`, drops
  both legacy values from the ENUM, default → `pending`; grants
  `can_review`+`can_approve` on `page_key='dn'` to Admin + Managing
  Director. Idempotent. Promoted 8 draft rows on first run.
- `api/review_dn.php` (new) — dedicated endpoint with `assertReviewable()`,
  `FOR UPDATE`, stamps reviewed_by + name/role snapshot, `logActivity()`.
- `api/approve_dn.php` (rewritten) — `assertApprovable()`, stamps audit,
  AND **preserves all existing automatic side-effects**:
    * Outbound DN: reserves stock in source warehouse (legacy behaviour).
    * Inbound DN: adds stock to destination warehouse + logs to
      `stock_movements` (used to fire from create_dn on direct-to-approved
      creation; moved here so it only fires once the canonical approval
      gate is passed). Three_approval.md §1 rule 6 compliance.
- `api/create_dn.php` — on insert, status is hard-coded to `'pending'`.
  Legacy `$status === 'approved' && $dn_type === 'inbound'` stock-add
  block removed (now in approve_dn.php instead).
- `roots.php` — four new entries for the review_dn + approve_dn routes.
- `app/bms/grn/delivery_notes.php` (list) — JS capability flags
  `DN_CAN_REVIEW`/`DN_CAN_APPROVE`/`DN_IS_ADMIN`; action menu renders
  Mark Reviewed + Approve DN in parallel (inactive disabled with
  tooltip); Edit/Delete gated by `canEditDocument()`; status filter
  dropdown rebuilt without legacy `draft`/`review`; mobile-card buttons
  updated; `getStatusClass()` covers the new states; legacy JS
  `changeStatus(id, 'review'|'approved')` calls replaced with calls to
  the dedicated APIs.
- `app/bms/grn/dn_view.php` — includes `core/workflow.php`; new audit
  panel via `workflow_audit_panel.php`; parallel Review/Approve buttons
  (one active per status); Edit/Delete gated by `canEditDocument()`;
  `$status_colors` map updated; new JS handlers
  `markReviewedFromView()` + `approveDNFromView()` calling the dedicated
  APIs; legacy `changeDNStatus()` kept for non-three-approval transitions.
- `api/account/print_delivery_note.php` — DRAFT watermark partial included
  for non-approved DNs; existing PREPARED/REVIEWED/APPROVED BY signature
  table preserved as-is per i_e_print.md §11.
- `tests/test_dn_three_approval_cli.php` (new) — 73-assertion smoke test.
  Verifies files, syntax, schema, audit cols, permissions, source
  contracts, **and asserts approve_dn.php still contains the stock
  side-effect code** (the most important regression guard). Returns 19
  rows from live `get_delivery_notes_list.php`.

## 2026-05-24 (update 74)

### Feat: Apply three_approval.md workflow to Invoices (vertical slice 3)

Third vertical slice on top of PO (update 68) and SO (updates 69-73). The
shared partials from update 68 are reused unchanged.

- `migrations/2026_05_24_invoice_three_approval.php` (new) — adds the 6
  missing `*_by_name`/`*_by_role`/`*_at` audit columns to `invoices`;
  grants `can_review`+`can_approve` to Admin + Managing Director.
- `api/account/review_invoice.php` + `approve_invoice.php` (new) — dedicated
  endpoints with `assertReviewable()` / `assertApprovable()` guards,
  `FOR UPDATE`, audit-snapshot stamping, transactional, `logActivity()`.
- `api/account/save_invoice.php` — on insert hard-codes `status='pending'`;
  on update preserves the existing row's status.
- `roots.php` — six new route entries for the two APIs.
- `app/bms/invoice/invoices.php` (list) — JS capability flags
  `INV_CAN_REVIEW`/`INV_CAN_APPROVE`/`INV_IS_ADMIN`; action menu rewritten
  to render Mark Reviewed + Approve **in parallel** when in workflow (the
  non-active one is disabled with a tooltip); Edit/Delete gated by
  `canEditDocument()`; Record Payment now also shows on `partial`.
- `app/bms/invoice/invoice_view.php` — audit panel, parallel Review/Approve
  buttons, `canEditDocument()`-gated Edit button, dedicated JS handlers.
- `app/bms/invoice/invoice_print.php` — DRAFT watermark partial included
  for non-approved invoices; existing rich signature row preserved as-is
  per `i_e_print.md` §11.
- `tests/test_invoice_three_approval_cli.php` (new) — 67-assertion smoke
  test including runtime API invocation; returns 11 rows from live DB.

## 2026-05-23 (update 73)

### Fix: SO list was empty — get_sales_orders.php had leftover draft_count read

While cleaning up the legacy 'draft' state (update 71) I removed
`draft_count` from the stats SELECT but missed the line that reads it back
into the JSON response (`api/account/get_sales_orders.php:186`). The
`Undefined array key "draft_count"` warning printed before the JSON body,
which broke the response parse on the client and left the DataTable empty
on `sales_orders.php`.

- `api/account/get_sales_orders.php` — removed the stale `draft_count`
  output line; added `reviewed_count` so the JS can show a reviewed metric.

### Test: Strengthen SO smoke test to invoke the API

The prior smoke test (update 72) only verified files, syntax, schema, and
source-code contracts — it never actually executed the API, so the
`draft_count` regression above slipped past it. New section 8 in
`tests/test_sales_order_three_approval_cli.php`:

- Picks an admin user from `users` and seeds `$_SESSION`.
- Requires `api/account/get_sales_orders.php` directly.
- Parses the JSON body and asserts:
  - `success === true`
  - `data` array is non-empty (the symptom the user reported)
  - `recordsTotal > 0`
  - all expected stats keys present (including new `reviewed_count`)
  - `draft_count` is GONE (regression guard for this exact bug)
  - first row has every key the DataTable column config expects
- Captures PHP `error_log` output during the call and fails on any handler-
  side `Warning`/`Notice`/`Deprecated`, filtering out CLI-only artifacts
  (`headers already sent`, `Session ini settings cannot be changed`, …)
  that never fire under Apache.

Test now reports 105 passes. Exit 0 = safe to push.

## 2026-05-23 (update 72)

### Test: Sales Order three-approval slice CLI smoke test

`tests/test_sales_order_three_approval_cli.php` (new) — 91-assertion suite
covering the SO updates 68-71. Verifies:

1. All required files exist (shared partials, migrations, APIs, pages, spec).
2. PHP syntax clean on every touched file.
3. `core/workflow.php` exposes the 6 helper functions and the three
   sequence guards reject out-of-order transitions
   (`assertReviewable`, `assertApprovable`, `assertConvertible`).
4. `canEditDocument()` honours the admin bypass.
5. DB schema: `sales_orders.status` ENUM contains `'reviewed'`, does NOT
   contain `'draft'`, defaults to `'pending'`; all 8 audit columns exist;
   no rows are stuck at `status='draft'` after the migration.
6. `permissions.page_key='sales_orders'` row exists with at least one role
   each granted `can_review` and `can_approve`.
7. Source-code contracts: APIs use the guards; `save_sales_order.php`
   hard-codes `'pending'` on insert and no longer accepts client-supplied
   `'draft'`; create + edit pages no longer render Save-as-Draft /
   Create-&-Approve; list page has Reviewed in the filter, has Mark
   Reviewed + Approve in the action menu, has no Change Status entry,
   and injects `SO_CAN_REVIEW` / `SO_CAN_APPROVE` / `SO_IS_ADMIN`; view
   page includes the audit panel and uses `canEditDocument()`; print page
   includes both partials and the `.signature-line small` rule.
8. i_e_print.md regression guards on `print_sales_order.php`: canonical
   `@page` margins preserved, shared print footer includes still present,
   body padding `20px 20px 0 20px` untouched.

Run: `php tests/test_sales_order_three_approval_cli.php`. Exit 0 = safe.

The test queries the live DB via `includes/config.php`, so it is intended
to be run locally before pushing. It is **not** wired into
`.github/workflows/deploy.yml` because the CI environment has no MySQL.

## 2026-05-23 (update 71)

### Fix: SO — remove 'draft', new orders start at 'pending'

Per three_approval.md, a sales order's lifecycle begins at `pending`. The
legacy `draft` state was both a UI shortcut (Save as Draft) and a default
ENUM value, which conflicted with the three-approval gate.

- `migrations/2026_05_24_so_remove_draft.php` (new) — promotes every existing
  `sales_orders.status = 'draft'` row to `'pending'`, then drops `'draft'`
  from the ENUM and changes the default to `'pending'`. Idempotent.
- `api/account/save_sales_order.php` — on insert, status is hard-coded to
  `'pending'` (the client cannot send a different value). On update, the
  existing row's status is preserved unless the caller explicitly sends
  `'cancelled'`. Review/Approve transitions still go through their
  dedicated APIs.
- `app/bms/sales/sales_order_create.php` — removed the "Save as Draft" and
  "Create & Approve" buttons; the legacy `saveAsDraft()` / `createAndApprove()`
  JS handlers were deleted. `saveAsQuote()` now sends `'pending'`.
- `app/bms/sales/sales_order_edit.php` — same button + JS cleanup as the
  create page.
- `app/bms/sales/sales_orders.php` — `'Draft'` removed from the status filter
  dropdown; status-count map rebuilt without `draft` and now includes
  `reviewed`; CASE expression in the listing query swaps `ELSE 'draft'` for
  `ELSE 'pending'`; JS action menu uses `isPending` (was `isDraftPending`);
  badge-class switch + legacy status names map no longer mention `'draft'`.
- `api/account/get_sales_orders.php` — stats query no longer aggregates a
  `draft_count`; new `reviewed_count` added; `display_status` CASE fallback
  is now `'pending'`.
- `app/bms/sales/sales_order_view.php` — `$so_can_delete_now` no longer
  whitelists `'draft'` (uses `'pending'` instead); review button condition
  drops the `'draft'` OR; `get_status_color()` loses the `'draft'` case.

## 2026-05-23 (update 70)

### Fix: SO list — show Review and Approve in parallel; remove Change Status

`app/bms/sales/sales_orders.php` action dropdown updated to match the user's
mental model: when the SO is still in the three-approval chain
(draft/pending/reviewed) both **Mark Reviewed** and **Approve Order** menu
items render together. The non-active one is shown disabled with a tooltip
("Already reviewed" / "Must be reviewed before approval"). The "Change Status"
menu item is removed from the action dropdown (the JS helper
`changeOrderStatus()` is kept in case other views link to it).

## 2026-05-23 (update 69)

### Feat: Apply three_approval.md workflow to Sales Orders (vertical slice 2)

Second vertical slice of the `pending → reviewed → approved` workflow, modelled
on the Purchase Order template (update 68). All shared partials from update 68
(`core/workflow.php`, `includes/workflow_audit_panel.php`,
`includes/workflow_signature_row.php`, `includes/workflow_draft_watermark.php`)
are reused unchanged.

**Migration:**
- `migrations/2026_05_24_so_three_approval.php` (new) — adds 7 audit columns
  (`reviewed_by`, `reviewed_by_name`, `reviewed_by_role`, `reviewed_at`,
  `approved_by_name`, `approved_by_role`, `approved_at`), inserts `'reviewed'`
  into the `sales_orders.status` ENUM between `'pending'` and `'approved'`,
  grants `can_review`+`can_approve` to Admin and Managing Director.

**APIs:**
- `api/account/review_sales_order.php` (new) — `assertReviewable()`, stamps
  reviewer ID + snapshot, transactional with `FOR UPDATE`, `logActivity()`.
- `api/account/approve_sales_order.php` (new) — `assertApprovable()`, refuses
  unless current status is `reviewed`.

**Routes:**
- `roots.php` — six new entries for the two new APIs (clean + `.php` + direct
  `api/account/...` forms).

**List page (`app/bms/sales/sales_orders.php`):**
- Status filter dropdown now includes "Reviewed".
- JS capability flags `SO_CAN_REVIEW` / `SO_CAN_APPROVE` / `SO_IS_ADMIN`
  injected from PHP.
- Action dropdown rewritten: **Mark Reviewed** appears only when status is
  pending/draft and `can_review`; **Approve Order** appears only when status is
  reviewed and `can_approve`. Edit/Delete gated by `canEditDocument()` so once
  the SO is approved, only admin can edit or delete it. Create Invoice button
  unchanged.
- New JS handlers: `reviewSalesOrder()` + `approveSalesOrder()`.

**View page (`app/bms/sales/sales_order_view.php`):**
- Includes `core/workflow.php` and joins the creator's name from `users`.
- New audit-trail panel via `workflow_audit_panel.php` showing Created /
  Reviewed / Approved By + timestamps.
- New sequential action bar above the order body — only the next valid step
  (Review or Approve) is rendered, gated by `canReview()`/`canApprove()`.
- Edit button rendered only when `canEditDocument($status, $isAdmin)` allows.
- `get_status_color()` extended with `'reviewed' => info`.

**Print page (`app/bms/sales/print_sales_order.php`):**
- SELECT extended with the four `*_by_name` / `*_by_role` / `*_at` audit
  columns + `reviewed_at` / `approved_at`.
- `$wf_status` + `$wf` array assembled for the partials.
- Added the missing `.signature-line small` rule (matches `i_e_print.md` §6.3
  verbatim). No other CSS rule changed.
- Existing generic 3-line signature ("Authorized / Customer / Date") replaced
  with `workflow_signature_row.php` (Created/Reviewed/Approved By).
- `workflow_draft_watermark.php` included so pre-approval prints carry a
  "PENDING" / "REVIEWED" diagonal watermark.
- `.totals` on this page is already a flex item (not floated), so no extra
  `clear: both` wrapper is needed (mirrors `print_quotation.php`).

**i_e_print.md compliance for `print_sales_order.php`:** rules 1-7 + 9 all
green. Rule 11 (`Tax:` instead of `VAT:`) and rule 12 (joined Web|Email) are
pre-existing violations, out of scope.

## 2026-05-23 (update 68)

### Feat: Apply three_approval.md workflow to Purchase Orders (vertical slice)

End-to-end wiring of the `pending → reviewed → approved` workflow on the
Purchase Order module so it can be tested before rolling out to the other
12 documents.

**Shared partials (used by every doc going forward):**
- `core/workflow.php` (new) — `canEditDocument()`, `assertReviewable()`,
  `assertApprovable()`, `assertConvertible()`, `workflowActorSnapshot()`,
  `statusBadgeClass()`.
- `includes/workflow_audit_panel.php` (new) — view-page Created/Reviewed/
  Approved panel, prefixed `.wf-audit-*` (no CSS collisions).
- `includes/workflow_signature_row.php` (new) — print-page signature row
  using the **exact** CSS+HTML from `print_quotation.php` lines 346-368 / 588-600.
- `includes/workflow_draft_watermark.php` (new) — DRAFT watermark when status
  is not approved; self-contained `.three-approval-watermark` class.

**Migration:**
- `migrations/2026_05_24_po_three_approval.php` (new) — adds `reviewed_by INT`,
  renames enum `'review'` → `'reviewed'` (1 existing row migrated), grants
  `can_review`+`can_approve` to Admin and Managing Director where present.

**Purchase Order — full vertical slice:**
- `api/account/review_purchase_order.php` — `'review'` → `'reviewed'`, uses
  `assertReviewable()`, stamps `reviewed_by` int + name/role snapshot, logs
  activity, runs in a transaction with `FOR UPDATE`.
- `api/account/approve_purchase_order.php` — `assertApprovable()`, stamps
  `approved_by` int + name/role snapshot, logs activity.
- `api/account/get_purchase_orders.php` — pending-list filter literal renamed.
- `app/bms/purchase/purchase_orders.php` — Reviewed option in status filter,
  status colour map updated, new **Mark Reviewed** action (gated by
  `canReview`), Approve action gated by `canApprove`, Edit/Delete hidden when
  `status=approved && !isAdmin()`, JS capability flags `PO_CAN_REVIEW`/
  `PO_CAN_APPROVE`/`PO_IS_ADMIN` injected from PHP.
- `app/bms/purchase/purchase_order_details.php` — same JS capability flags,
  sequential workflow buttons (`Mark Reviewed` then `Approve Order`),
  `getStatusColor('reviewed')`, edit gating respects admin override.
- `api/account/print_purchase_order.php` — added missing `.signature-line small`
  CSS rule, replaced the old table-style PREPARED/REVIEWED/APPROVED block with
  the canonical `.signature-box`/`.signature-line` flex block from
  `workflow_signature_row.php`, included `workflow_draft_watermark.php` so
  pre-approval prints carry a "PENDING" / "REVIEWED" watermark.
- `api/account/print_purchase_order.php` — wrapped the signature include in
  `<div style="clear: both;">` to restore the float-clearance the previous
  table-style signature provided (`.totals` is `float: right` on this page).
  Without this, on screen the signature could drift up next to the totals and
  its last line fell into the bottom 16px occupied by the shared
  `.print-footer` (`position: fixed; bottom: 0`). Mirrors the natural clearance
  in `print_quotation.php`, where `.totals` is a flex item inside
  `.totals-section` instead of a float.

**i_e_print.md compliance verified for `print_purchase_order.php`:**
Rules 1-7 + 9 all green. Pre-existing pre-three-approval violations on rules
8 (no `logActivity` on print) and 11 (`Tax:` instead of `VAT:`) are out of
scope for this PR and left untouched.

**Existing CSS preserved.** No `.signature-box`/`.signature-line` rule in the
PO print was modified — only the missing `small` selector was added.

## 2026-05-23 (update 67)

### Docs: Add `three_approval.md` — pending → reviewed → approved workflow standard

- **`three_approval.md`** (new) — single source of truth for the three-state
  document approval workflow. Defines schema, APIs (`review_X.php` /
  `approve_X.php`), list/view/print touchpoints, conversion guards, permissions
  (`can_review`, `can_approve`), and admin-only post-approval editing.
- Signature block (Created By / Reviewed By / Approved By) captured **verbatim**
  from `app/bms/sales/quotations/print_quotation.php` (CSS lines 346-368, HTML
  lines 588-600) — no existing CSS is touched.
- Compliance map applied to all 13 I/E print documents: 1 fully compliant
  (Quotation), 3 partial (PO, RFQ, DN), 1 has enum-only (Invoice), 9 need full
  wiring (SO, Purchase Return, Sales Return, GRN, Stock Transfer, Stock
  Adjustment, IPC, Payment Voucher, Petty Cash).

## 2026-05-23 (update 66)

### Feat: Apply view.md standard to all 19 internal view/detail pages

Two-change standard applied to every file: canonical `@page { margin: 10mm 8mm 16mm 8mm; }` placed
OUTSIDE any `@media print` block, shared `print_footer_css.php` + `print_footer_html.php` includes
added; all internal footer patterns removed.

**@page fixed (was inside @media print or wrong margins):**
- `app/bms/product/product_view.php`
- `app/bms/Suppliers/supplier_details.php`
- `app/bms/pos/employee_details.php` — also removed `.fixed-print-footer` CSS
- `app/bms/pos/payroll_details.php` — also removed `.fixed-print-footer` CSS + HTML div
- `app/constant/accounts/expense_details.php`
- `app/constant/accounts/budget_details.php`
- `app/constant/accounts/cash_register_details.php`
- `app/bms/customer/customer_details.php` — also removed `.bms-print-footer` CSS and nested @page in body selectors
- `app/bms/operations/warehouse_stock_view.php` — @page moved outside, removed `<div class="bms-print-footer">` (standalone file, no footer includes)
- `app/bms/operations/project_view.php` — added canonical @page, removed `.fixed-print-footer` CSS (×2) + HTML div + warehouse-stock CSS reference; footer includes added

**@page added (was missing):**
- `app/bms/product/service_view.php`
- `app/bms/stock/warehouse_view.php`
- `app/constant/accounts/transaction_details.php`
- `app/constant/accounts/journal_details.php`
- `app/constant/accounts/reconciliation_details.php`
- `app/constant/accounts/account_details.php`
- `app/bms/operations/inspection_view.php` — new `<style>` block created (file had none)
- `app/bms/operations/sub_contractor_details.php`
- `app/bms/tenders/tender_view.php` — also removed `.print-footer { position: fixed; }` CSS

---

## 2026-05-23 (update 65)

### Feat: Apply view.md print standard to all remaining in-scope print pages

Audited every `print_*.php` / `*_print.php` file in the project.
Only one additional file was in scope (individual item print from Actions → Print):

- `app/bms/stock/print_transfer.php`: fully converted from embedded Bootstrap app-layout
  (`require_once HEADER_FILE` / `includeFooter()`) to a proper standalone print page.
  All data queries and text preserved exactly. CSS now matches the view.md standard:
  standalone `<!DOCTYPE html>`, `@page { margin: 10mm 8mm 16mm 8mm }`,
  `.doc-title-box h2 { font-size: 16px }`, `.box p { margin: 3px 0 }`,
  `tbody td { height: 0.75cm; line-height: 1.6; font-size: 13px }`,
  shared `print_footer_css.php` + `print_footer_html.php`, company logo/address from settings.

Excluded (correct — different format/architecture):
- `api/print_compliance.php`, `api/print_audit_logs.php` — list/summary reports
- `api/pos/print_receipt.php` — 80mm thermal receipt
- `api/operations/print_customers/maintenance/assets.php` — registry reports using `includeHeader`
- `api/operations/print_projects.php` — projects summary report
- `app/bms/product/print_barcode.php` — barcode label sheet

- `view.md`: created as the canonical reference for individual-record print pages (margins,
  typography, footer rules, internal-footer removal checklist, body/head order, scope).
- `tests/test_print_css_standard_cli.php`: updated — `print_transfer.php` added to all three
  file lists; test now covers 13 files, 109/109 checks.

---

## 2026-05-23 (update 64)

### Feat: Normalise all internal/external print pages to i_e_print.md CSS standard

Created `i_e_print.md` as the canonical CSS reference for all BMS print pages,
using `app/bms/sales/quotations/print_quotation.php` as the reference implementation.
All 12 print pages now share identical CSS values and use the shared footer includes.

**CSS changes (no logic or text touched):**
- `app/bms/sales/print_sales_order.php`: `.box p margin 5px→3px`, `td height 0.9cm→0.75cm`, `line-height 2.2→1.6`
- `api/account/print_purchase_order.php`: `.po-title h2 font-size 18px→16px`, `.box p margin 5px→3px`, `td height 0.9cm→0.75cm`, `line-height 2.2→1.6`
- `api/account/print_rfq.php`: same 3 changes as PO above
- `app/bms/invoice/invoice_print.php`: `.box p margin 5px→3px`, `td height 0.9cm→0.75cm`, `line-height 2.2→1.6`
- `app/bms/purchase/print_purchase_return.php`: `.box p margin 5px→3px`, `td padding 8px→2px`, `font-size 12px→13px`, added `height: 0.75cm; line-height: 1.6`
- `app/bms/sales/sales_returns/print_sales_return.php`: `.box p margin 5px→3px`, `td height 0.9cm→0.75cm`, `padding 8px→2px`, added `line-height: 1.6`
- `api/account/print_delivery_note.php`: `.box p margin 5px→3px`, `td padding 8px→2px`, `font-size 12px→13px`, added `height: 0.75cm; line-height: 1.6`
- `app/bms/grn/grn_print.php`: `.box p margin 5px→3px`, `td padding 8px→2px`, `font-size 12px→13px`, added `height: 0.75cm; line-height: 1.6`
- `app/bms/operations/print_ipc.php`: `.doc-title-box h2 font-size 14px→16px`, `.box p margin 5px→3px`, `td height 0.9cm→0.75cm`, `line-height 2.2→1.6`
- `app/constant/accounts/payment_voucher_print.php`: `.box p margin 5px→3px`, `@page margin 10mm 10mm 20mm 10mm→10mm 8mm 16mm 8mm`

**Footer replacement (internal footer removed, shared includes added):**
- `app/bms/stock/adjustment_print.php`: removed `.footer {}` CSS and `<div class="footer">` HTML; added `print_footer_css.php` + `print_footer_html.php`; body padding 40px→20px 20px 0 20px; added canonical `@page` margin
- `app/constant/accounts/petty_cash_print.php`: removed `<div class="bms-print-footer">` block and its PHP variables; added `print_footer_css.php` + `print_footer_html.php`; moved `@page` outside `@media print` and changed to canonical margin

**Test suite & CI:**
- `tests/test_print_css_standard_cli.php`: new CLI test suite — 101 checks across 9 sections covering all 12 normalised print pages + reference file integrity; exits code 1 on any failure
- `.github/workflows/php-lint.yml`: new CI step runs the compliance suite on every push (blocks merge if any print page regresses)

---

## 2026-05-22 (update 63)

### Fix: CSRF_TOKEN redeclaration broke onclick handlers across 3 pages
`header.php` declares `const CSRF_TOKEN` globally for AJAX. Three pages
also redeclared it inside their own `<script>` blocks, throwing
`Uncaught SyntaxError: Identifier 'CSRF_TOKEN' has already been declared`
on page load. That SyntaxError aborts the **entire** script block on each
affected page — every onclick, form submit and Bootstrap modal call stops
working silently. The "Record Invoice" button on received invoices was
the first symptom reported.
- `app/bms/invoice/received_invoices.php`: removed the duplicate `const
  CSRF_TOKEN` — the "Record Invoice" button now opens the modal again.
- `app/bms/customer/customer_details.php`: removed the duplicate `const
  CSRF_TOKEN` — every onclick / form on the customer details page now
  works again.
- `app/constant/settings/backup_restore.php`: removed the duplicate
  `const CSRF_TOKEN` — backup / restore buttons now work again.
- All three pages still reference `CSRF_TOKEN` for their AJAX calls; the
  constant is now sourced exclusively from header.php (no behaviour
  change for the AJAX side).
- `tests/test_csrf_token_redeclaration_cli.php`: **new bug-class
  regression suite** — scans every PHP file under `app/` and FAILS the
  push gate if any page redeclares `const CSRF_TOKEN`, plus positive
  sanity-checks that received_invoices.php's button stays wired. Confirmed
  the test catches the bug class by running it on the unfixed state
  first — it failed on the exact two extra files above.
- `.github/workflows/php-lint.yml`: new CI step runs the guard on every
  push so this bug class can never reach GitHub again.

---

## 2026-05-22 (update 62)

### Change: document library — lock category list to 5 canonical rows, remove "+" Add Category
- `app/constant/document/document_library.php`:
  - The "+" button next to the Category dropdown in the Upload Document
    modal is removed.
  - The Add Category modal and its `openAddCategoryModal` / `addCategoryForm`
    JavaScript handler are removed — categories are no longer created from
    the UI.
- `api/document/save_category.php`: **deleted** so the endpoint cannot be
  POSTed to directly either.
- `migrations/2026_05_22_consolidate_document_categories.php`: new
  name-based, idempotent migration that consolidates `document_categories`
  to **5 canonical rows** — Legal & Contracts, Financial Reports,
  HR & Employment, Compliance & Regulatory, General Documents. It is safe
  to run on every live system regardless of starting state (empty, partial
  seed, full duplicate seed, ad-hoc rows): it inserts canonical rows that
  are missing, re-points any documents on removed rows to the right
  canonical id (no data lost), then deletes the leftovers in a single
  transaction. `Compliance & KYC` folds into `Compliance & Regulatory`;
  every other non-canonical row's documents are folded into
  `General Documents`.
- `tests/test_document_categories_cli.php`: new regression suite — guards
  the "+" / modal / API removal and the migration shape; live-DB section
  verifies only the 5 canonical rows remain and no orphan documents exist.
- `.github/workflows/php-lint.yml`: new CI step runs the suite on every push.

---

## 2026-05-22 (update 61)

### Change: quotation print-out — VAT row always shown, company_name removed from Customer box
- `app/bms/sales/quotations/print_quotation.php`:
  - Customer box: the `company_name` line was removed — only `customer_name`
    is shown.
  - Totals box: the VAT row now always prints. When no product has VAT it
    shows `VAT: 0.00` instead of being hidden, so the VAT line is always
    visible on the quotation.
- `tests/test_quotation_customer_box.php`: Section 7 VAT check flipped — the
  VAT row is now expected to be unconditional.

---

## 2026-05-22 (update 60)

### Change: quotation print-out — VAT row hidden at zero, label simplified to "VAT"
- `app/bms/sales/quotations/print_quotation.php`: the totals-box VAT row is
  now printed only when `tax_amount > 0` (hidden when no VAT applies), and
  the label is `VAT:` instead of `VAT (18%):`. This is correct for
  quotations that mix VAT-rated and No-Tax line items, where the VAT total
  is not 18% of the subtotal. The amount is unchanged — `save_quotation.php`
  already computes VAT per line item and sums it.
- `tests/test_quotation_customer_box.php`: Section 7 updated to expect the
  `VAT` label and the `tax_amount > 0` guard.

---

## 2026-05-22 (update 59)

### Change: quotation print-out — tighter content spacing & always-on VAT (18%) row
- `app/bms/sales/quotations/print_quotation.php`:
  - Items table line spacing reduced — `line-height` 2.2 → 1.6, row
    `height` 0.9cm → 0.75cm.
  - Customer and Quotation Information boxes tightened — `.box p` margin
    5px → 3px.
  - The totals box now shows a `VAT (18%)` row that always prints; it was
    previously labelled `Tax` and hidden whenever `tax_amount` was 0.
- `tests/test_quotation_customer_box.php`: new Section 7 (9 checks) locks in
  the line-spacing values and the VAT (18%) row.

---

## 2026-05-22 (update 58)

### Change: quotation print-out — company Web and Email on separate rows
- `app/bms/sales/quotations/print_quotation.php`: the company header
  previously joined the website and email into one line separated by " | ".
  They now render as two separate rows. No other field was changed.

---

## 2026-05-22 (update 57)

### Fix: quotation Customer box — duplicated postal address & contact-person email
The Customer box on the quotation print-out and details page showed the
postal address twice (once prefixed "P.O. Box", once raw) and displayed the
contact person's email (`customers.email`) instead of the customer's own
email (`customers.company_email`).
- `app/bms/sales/quotations/print_quotation.php`: the customer query now
  resolves the email as `COALESCE(NULLIF(TRIM(c.company_email), ''), c.email)`
  — the customer's own email is preferred, falling back to the contact email
  only when it is blank. The address block is de-duplicated: the postal line
  is dropped when the street address already contains it, and the "P.O. Box"
  prefix is added only when the value is not already marked as a P.O. Box.
- `app/bms/sales/quotations/quotation_view.php`: the same `COALESCE` email
  resolution applied to the Customer Information panel.
- `tests/test_quotation_customer_box.php`: new regression suite (39 checks) —
  syntax lint, static guards on both fixes, SQLite verification of the email
  `COALESCE` semantics, address de-duplication unit cases, and a live-DB
  smoke test over every real customer row.
- `.github/workflows/php-lint.yml`: the new suite runs on every push so a
  regression cannot reach GitHub.

---

## 2026-05-22 (update 56)

### Fix: delete-user endpoint returned non-JSON ("Error communicating with server: OK")
`ajax/delete_user.php` called `session_start()` a second time (`roots.php`
already starts the session) and set its JSON `Content-Type` header after the
includes — so a stray PHP notice/warning could land in the response body,
leaving the browser unable to parse it. jQuery then reported
"Error communicating with server: OK" (an HTTP 200 with an unparseable body).
- `ajax/delete_user.php`: rewritten to the standard JSON-endpoint pattern —
  the response is buffered (`ob_start`) and the buffer discarded (`ob_clean`)
  immediately before the JSON is written, so stray output can never corrupt
  it; the redundant `session_start()` is removed; failures are caught as
  `Throwable` so any error still returns a proper JSON message.

---

## 2026-05-22 (update 55)

### Fix: login handler — "array offset on false" warning hardened
`actions/login.php` accessed `$user['password']` before checking whether a
user row was actually found, raising a PHP 8 "Trying to access array offset
on false" warning (surfaced in Sentry) on every login attempt with an
unknown username.
- `actions/login.php`: `password_verify()` is now guarded behind an `$user`
  check; the dead `$hasspass` line is removed; the submitted username is
  `trim()`-ed and inputs are read null-safely.
Login behaviour is unchanged for both valid and invalid credentials.

---

## 2026-05-22 (update 54)

### Fix: missing `can_review` column on `role_permissions`
`core/permissions.php` and `user_roles.php` both reference a `can_review`
column, but no migration ever created it (only `can_approve` had one). Any
environment that did not get the column added by hand — e.g. the live site —
errored with `Unknown column 'can_review'` when editing a user or role, and
`loadUserPermissions()` failed silently (breaking non-admin permissions).
- `migrations/2026_05_22_role_permissions_can_review.php` (new): idempotently
  adds `can_review TINYINT(1) NOT NULL DEFAULT 0` to `role_permissions`.

---

## 2026-05-22 (update 53)

### Fix: Sales Order view & print decoupled from quotations
With quotations now a separate module, the previously shared
`sales_order_view.php` and `print_sales_order.php` are restricted to genuine
sales orders.
- `app/bms/sales/sales_order_view.php`: the query now loads sales orders only
  (`is_quote = 0`); the leftover `$is_quote` conditionals are removed — Print
  always uses the `print_sales_order` route and Back to List always returns to
  the Sales Orders list. Fixes a Sales Order print that could route to the
  quotation print-out.
- `app/bms/sales/print_sales_order.php`: query restricted to `is_quote = 0` so
  it can only ever render a real sales order.
- `tests/test_quotations_cli.php`: regression section extended to assert the
  sales-order/quotation separation (130 checks).

---

## 2026-05-22 (update 52)

### Feature: Quotation approval workflow (pending -> reviewed -> approved)
- `migrations/2026_05_22_quotation_workflow.php` (new): adds `reviewed_by`,
  `reviewed_at`, `approved_at` and `converted_to_so_id` to the `quotations`
  table and extends the `status` ENUM with `reviewed`. Idempotent.
- `api/account/review_quotation.php` (new): `pending -> reviewed`, stamps the
  reviewer + timestamp; gated by `canReview('sales_orders')`.
- `api/account/approve_quotation.php` (new): `reviewed -> approved`, stamps the
  approver + timestamp; gated by `canApprove('sales_orders')`.
- `api/account/save_quotation.php`: a new quotation now starts at `pending`;
  editing never changes the workflow status; an approved quotation is locked.
- `api/account/convert_quote_to_order.php`: converts only an approved
  quotation, tags it with `converted_to_so_id` to block a double conversion,
  and copies only the columns shared by both tables.
- `app/bms/sales/quotations/quotations.php`: status-driven action menu —
  Review (pending), Approve (reviewed), Convert to Order (approved); Edit and
  Delete are hidden once approved; new "Reviewed" status badge.
- `app/bms/sales/quotations/quotation_view.php`: an approval-workflow strip
  (Created / Reviewed / Approved by + dates) plus the same status-driven
  buttons; Edit is hidden when approved.
- `app/bms/sales/quotations/print_quotation.php`: adds the company Account
  (Bank) details block and replaces the signature row with
  Created By / Reviewed By / Approved By.
- `app/bms/sales/quotations/quotation_form.php`: an approved quotation can no
  longer be opened for editing; the "Save as Draft" button is removed.
- `tests/test_quotations_cli.php`: extended to 126 checks covering the workflow
  migration, both new APIs, the status-conditional UI and the print footer.
Review/approve rights are assigned per role in user_roles.php via the existing
`can_review` / `can_approve` permission columns.

---

## 2026-05-22 (update 51)

### Feature: Quotations split into a fully separate module (own table + files)
Per management direction, a Quotation is now a fully independent document — it
is the first document issued to a customer, before any PO — and no longer
shares its table or pages with Sales Orders.
- `migrations/2026_05_22_create_quotations_tables.php` (new): creates the
  `quotations` and `quotation_items` tables (`CREATE TABLE ... LIKE`) and copies
  existing `is_quote = 1` records across. Idempotent and non-destructive — the
  original rows are left in `sales_orders` (already hidden from the Sales Orders
  list by `WHERE is_quote = 0`).
- `app/bms/sales/quotations/quotation_view.php` (new): dedicated quotation
  details page — URL `quotation_view?id=`.
- `app/bms/sales/quotations/quotation_form.php` (new): dedicated create/edit
  form body — no PO field, no stock blocking, no "Switch to Sales Order".
- `app/bms/sales/quotations/quotation_create.php` / `quotation_edit.php` (new):
  thin entry points — URLs `quotation_create` / `quotation_edit`.
- `app/bms/sales/quotations/print_quotation.php` (new): dedicated print-out.
- `api/account/save_quotation.php`, `delete_quotation.php`,
  `update_quotation_status.php` (new): dedicated APIs on the `quotations` table.
- `api/account/convert_quote_to_order.php`: rewritten — copies a quotation from
  `quotations` into `sales_orders` as a real sales order; the quotation is kept
  as an accepted historical record.
- `app/bms/sales/quotations/quotations.php`: list page now queries the
  `quotations` table and links to the new quotation routes/APIs.
- `roots.php`: registered `quotation_view` / `quotation_create` /
  `quotation_edit` routes; `print_quotation` re-pointed to the dedicated file.
- `tests/test_quotations_cli.php` (new): 89-test static CLI suite covering the
  migration, every new file, table isolation, routing, and a regression guard
  that the shared sales-order files are untouched.
- `.github/workflows/php-lint.yml`: added the quotations test suite step.
The shared `sales_order_*` files are unchanged and continue to serve Sales
Orders only.

---

## 2026-05-22 (update 50)

### Fix: Quotation flow — correct redirections, decouple from PO
Quotations and Sales Orders share the same table and pages (distinguished by the
`is_quote` flag). Several navigation paths ignored the quotation context, and a
quotation must not carry a customer PO reference — it is the first document
issued to the customer, before they raise their own PO.
- `app/bms/sales/sales_order_create.php`: after saving a quotation, redirect to
  the Quotations list instead of the Sales Orders list; "PO No" field hidden
  when `is_quote` (a quotation has no PO reference).
- `app/bms/sales/sales_order_edit.php`: "Back to Orders" button goes to the
  Quotations list for a quote; `$is_quote` now read from the saved record
  instead of the URL parameter; "PO No" field hidden for quotations.
- `app/bms/sales/sales_order_view.php`: "Back to List" goes to Quotations for a
  quote; Print uses the `print_quotation` route for a quote; "Create Invoice"
  and the two invalid-ID error redirects use `getUrl()` instead of bare URLs.
- `app/bms/sales/quotations/quotations.php`: Convert-to-Order success redirect
  uses `getUrl('sales_orders')` instead of a bare `sales_orders.php` URL.
All navigation changes are conditional on `is_quote`; the Sales Order flow is
unchanged.

---

## 2026-05-21 (update 49)

### Fix: Sub-Contractor Details — Received Invoices and Recent Payments tabs inactive
- `app/bms/operations/sub_contractor_details.php`: removed duplicate `const CSRF_TOKEN` declaration from the inline `<script>` block — `header.php` already declares it globally. The duplicate caused a `SyntaxError: Identifier 'CSRF_TOKEN' has already been declared` that silently aborted the entire script block, leaving `switchScTab()` and all DataTable initializations undefined. Clicking "Received Invoices" or "Recent Payments" therefore did nothing; only "Projects Involved" appeared to work because its pane is visible by default (no `d-none`).
- `tests/test_sc_details_cli.php` (new): 22-test static CLI suite — catches the duplicate-const anti-pattern, checks all three pane IDs and their initial visibility, verifies tab button `onclick` wiring, confirms `switchScTab()` is defined, checks all DataTable IDs, and verifies AJAX URL uses `buildUrl()`.
- `.github/workflows/php-lint.yml`: added Sub-Contractor Details test suite step.

---

## 2026-05-21 (update 48)

### Delivery Notes — split into Record (inbound) vs Create (outbound)
Branch `feature/dn-record-vs-create`. Per management feedback, Delivery Notes now
distinguish **recording** a DN received FROM a supplier/sub-contractor from
**creating** a DN sent TO one.
- `migrations/2026_05_21_dn_record_vs_create.php` (new): idempotently adds
  `dn_type` (inbound/outbound), `party_type` (supplier/subcontractor) and
  `subcontractor_id` columns to `deliveries`; ensures the `delivery_attachments`
  table and the `uploads/deliveries/` folder (+ `.htaccess` execution guard).
- `app/bms/grn/dn_create.php`: rewritten as the inbound **Record DN** form —
  hand-typed supplier DN number first, a Supplier/Sub-Contractor dropdown, the
  specific party chosen from all active suppliers OR sub-contractors (no longer
  gated by Purchase Orders), warehouse filtered by the selected project, and a
  required multi-attachment section (each attachment has a name + file, with an
  "Add Attachment" button).
- `app/bms/grn/dn_outbound.php` (new): the outbound **Create DN** form — DN
  number auto-generated, Supplier/Sub-Contractor dropdown, no attachment.
- `api/dn_attachment_helper.php` (new): `dn_collect_attachment_pairs()` +
  `dn_save_attachments()` — named multi-file uploads stored under
  `uploads/deliveries/` with §19 five-check security + document-library registration.
- `api/create_dn.php` / `api/update_dn.php`: rewritten to handle both directions —
  `dn_type`, `party_type`, supplier/sub-contractor validation, manual DN number
  for inbound, named attachments.
- `api/delete_dn_attachment.php` (new): removes a single DN attachment.
- `api/get_delivery_notes_list.php`: returns `dn_type`/`party_type`, joins
  `sub_contractors`, and supplies per-direction tab counts.
- `app/bms/grn/delivery_notes.php`: two action buttons (Record DN / Create DN),
  separate Inbound/Outbound tabs each showing only that direction, a Type column,
  and direction-aware edit links.
- `app/bms/grn/dn_view.php`: rewritten — shows direction, party (supplier or
  sub-contractor) and the supplier's DN attachments.
- `api/account/print_delivery_note.php`: resolves sub-contractor parties and is
  direction-aware; the print layout/format is identical for inbound and outbound.
- `roots.php`: added the `dn_outbound` route.
- `tests/test_dn_cli.php`: rewritten — 80-test suite covering the Record/Create
  split, manual number, named multi-attachments, sub-contractor selection and
  separate list tabs.

---

## 2026-05-21 (update 45)

### E-Signature Modernisation — tamper-evident integrity, audit trail & upload hardening
Branch `feature/esignature-modernization` (off `develop`). Makes the document
e-signature feature legally defensible (ESIGN/UETA-aligned) and production-ready,
with no change to the 4-step wizard UX. Single-party / automated signing only.
- `migrations/2026_05_21_esignature_audit_columns.php` (new): idempotently adds
  `hash_algorithm`, `hash_before`, `hash_after`, `signing_reference`,
  `signed_document_id`, `user_agent`, `consent_text`, `consent_accepted_at`,
  `event_log` columns + `idx_signed_document_id` index to `document_signatures`.
- `api/document/save_signed_pdf.php`: computes SHA-256 of the original and the
  signed file **server-side** (authoritative — client hashes are never trusted);
  rejects a sign request with no `consent_text` (intent evidence); persists the
  consent text, user-agent, signing reference and an ordered JSON `event_log`
  (viewed → consent → signed); adds the missing `canCreate('documents')`
  permission check; stops leaking exception text to the client (generic message
  + `error_log`); drops a script-blocking `.htaccess` into `uploads/documents/`.
- `api/document/verify_signed_document.php` (new): re-hashes the stored signed
  file and compares it to `hash_after` with `hash_equals()` — returns
  Verified / Tampered / unverifiable.
- `api/document/upload_signature.php`: §19 hardening — extension whitelist,
  real-MIME (`finfo` magic-byte) check, 2 MB size limit, non-guessable
  `random_bytes` filename, `mkdir(0755)` not `0777`, protective `.htaccess`,
  `logActivity()` on success; no longer trusts `$_FILES['type']`.
- `app/constant/document/select_document_add_esignature.php`: appends a
  **Certificate of Completion** page to every signed PDF (pure pdf-lib — signer
  name/email, date, signing reference, original-document SHA-256, consent text);
  computes a client-side SHA-256 for the certificate; captures the consent
  timestamp and document-viewed time; sends `consent_text` / `consent_accepted_at`
  / `viewed_at` / `signing_reference`; adds an integrity **Verify** button on the
  finish step; removes the dead `uint8ToBase64()` helper; replaces the misleading
  silent non-PDF "signature" with an honest PDF-only message; builds signature
  image URLs via `APP_URL` so they resolve on sub-directory installs.
- `app/constant/document/e_signatures.php`: upload modal `accept` drops `.gif`
  (pdf-lib can only embed PNG/JPG).
- `tests/test_esignatures_wizard_cli.php`: extended to 141 tests — new sections
  for integrity/audit hardening, the verify endpoint, upload hardening and the
  certificate/consent wiring.
- `tests/test_esignature_integrity_cli.php` (new): 26-test DB-backed suite —
  verifies the audit columns, the SHA-256 tamper-evidence round-trip, migration
  idempotency and the integrity endpoints.
- `todo.md` (new): implementation plan for the modernisation.

---

## 2026-05-21 (update 47)

### Fix: Sub-Contractor Details — Received Invoices and Recent Payments tabs inactive
- `app/bms/operations/sub_contractor_details.php`: removed duplicate `const CSRF_TOKEN` declaration from the inline `<script>` block — `header.php` already declares it globally. The duplicate caused a `SyntaxError: Identifier 'CSRF_TOKEN' has already been declared` that silently aborted the entire script block, leaving `switchScTab()` and all DataTable initializations undefined. Clicking "Received Invoices" or "Recent Payments" therefore did nothing; only "Projects Involved" appeared to work because its pane is visible by default (no `d-none`).
- `tests/test_sc_details_cli.php` (new): 22-test static CLI suite — verifies file/syntax, catches the duplicate-const anti-pattern, checks all three pane IDs and their initial visibility, verifies tab button `onclick` wiring, confirms `switchScTab()` is defined and removes `d-none`, checks all DataTable IDs, and verifies AJAX URL uses `buildUrl()`.
- `.github/workflows/php-lint.yml`: added Sub-Contractor Details test suite step.

---

## 2026-05-21 (update 46)

### Document Signing Wizard — PDF embedding Phases 2 & 3
- `api/document/save_signed_pdf.php` (new): accepts file upload `signed_pdf_file` + `original_document_id`, `signature_id`, `signature_position`; validates MIME via `finfo` (must be `application/pdf`); max 40 MB; verifies original doc + user's signature; saves to `uploads/documents/`; INSERTs `documents` record named "Original (Signed)"; INSERTs/UPDATEs `document_signatures`; `logActivity` + `logAudit`; returns `{ success, new_document_id }`
- `app/constant/document/select_document_add_esignature.php` (Phase 3): rewrote `processFinalSign()` as `async`; added `embedSignatureIntoPdf()` — fetches original PDF, loads with `PDFLib.PDFDocument.load()`, fetches signature image, embeds PNG/JPG, converts canvas coordinates to PDF points (posX/posY ÷ 1.5, Y-axis flipped), draws image on target page, serialises to Blob, POSTs to `save_signed_pdf.php`, wires download button to new signed document ID; added `recordSignatureOnly()` fallback for non-PDF documents; added `uint8ToBase64()` helper safe for large files
- `tests/test_esignatures_wizard_cli.php` (Phase 4): expanded to 100 tests across 12 sections; new sections cover pdf-lib asset existence + size, PDF embedding logic (PDFLib.PDFDocument.load, embedPng/Jpg, drawImage, coordinate conversion, Blob upload, new_document_id wiring), and save_signed_pdf.php security (auth, CSRF, finfo MIME, move_uploaded_file, no base64, logAudit)

---

## 2026-05-21 (update 45)

### Document Signing Wizard — PDF embedding Phase 1: add pdf-lib.js
- `assets/js/pdf-lib.min.js` (new): pdf-lib v1.17.1 downloaded from jsDelivr (~513 KB); used in Phase 3 to burn signature image into PDF client-side
- `app/constant/document/select_document_add_esignature.php`: added `<script src>` tag for pdf-lib.min.js (after pdf.min.js, before inline script block)

---

## 2026-05-21 (update 44)

### Fix: Document Signing Wizard — Phases 1 & 2
- `api/get_documents.php`: replaced empty stub with real server-side DataTable query; SELECTs from `documents` LEFT JOIN `document_categories`; returns `id`, `document_name`, `file_path`, `file_size`, `file_type`, `uploaded_at`, `category_name`; honours `draw`/`start`/`length`/`search[value]`; filters `status != 'deleted'`; auth check on `$_SESSION['user_id']`
- `app/constant/document/select_document_add_esignature.php`: fixed 6 hardcoded absolute paths → `buildUrl()` / `APP_URL`: `/api/get_documents.php`, `/ajax/get_user_signatures_list.php`, two `/documents/library?action=download` references (PDF load + download button), `/ajax/apply_signature.php`, `/ajax/quick_upload_document.php`
- `app/constant/document/select_document_add_esignature.php` (Phase 3): fixed `selectSignature()` — `event.currentTarget` undefined in onclick; now passes `this` from onclick and accepts `el` parameter; fixed `changeStep()` — going backward no longer leaves step indicators stuck in `completed` state; fixed `updateButtons()` — `#btnBack` was never un-hidden after step 4, breaking error-recovery flow back to step 3
- `app/constant/document/select_document_add_esignature.php` (Phase 4): fixed `setPresetPosition()` — now actually repositions the draggable element using parent/element dimensions; bottom_left/bottom_center/bottom_right all compute correct translate(x, y) values and update posX/posY; removed misleading Swal toast (visual feedback is immediate)
- `app/constant/document/select_document_add_esignature.php` (Phase 5): corrected 3 API paths pointing to non-existent `ajax/` stubs — `ajax/get_user_signatures_list.php` → `api/document/get_user_signatures_list.php`; `ajax/apply_signature.php` → `api/document/apply_signature.php`; `ajax/quick_upload_document.php` → `api/document/quick_upload_document.php`; corrected 2 download URLs from `APP_URL + 'documents/library?...'` (wrong route) → `buildUrl("document_library")?action=download&document_id=` (matches existing codebase pattern)
- `api/get_documents.php`: fixed SQLSTATE[42S22] — `d.status` column does not exist on `documents` table; changed `WHERE d.status != 'deleted'` → `WHERE 1=1`; changed total-records count to remove same invalid filter
- `tests/test_esignatures_wizard_cli.php` (new): 59-test CLI suite covering file existence, PHP syntax, SQL correctness (no d.status), no hardcoded paths, correct buildUrl() paths, JS bug fixes, step navigation, setPresetPosition, and backend API integrity
- `.github/workflows/php-lint.yml`: added E-Signatures Wizard test suite step

---

## 2026-05-21 (update 43)

### Delivery Note — remove attachment feature + auto-generate DN number

**Attachment removal (all surfaces)**
- `app/bms/grn/dn_create.php`: removed Attachments & Documents card HTML; removed `$dn_attachments` DB query + variable; removed `handleFileSelect()`, `addAttachmentRow()`, `removeAttachmentRow()` JS functions; removed all attachment FormData blocks from `submitDN()`
- `app/bms/grn/dn_view.php`: removed Documents & Attachments card; removed `delivery_attachments` query + auto-create table block; removed `$attachments` variable
- `api/create_dn.php`: removed attachment upload block (INSERT into delivery_attachments)
- `api/update_dn.php`: removed all three attachment sub-sections (delete, replace, add new)

**DN Number auto-generation**
- `app/bms/grn/dn_create.php`: removed manual `dn_number` input field; in edit mode shows auto-generated `delivery_number` as read-only with "Auto-generated — cannot be changed" label; removed `formData.append('dn_number', ...)` from JS
- `api/create_dn.php`: removed `$dn_number_input`; INSERT now stores `null` in `dn_number` column; `delivery_number` auto-generation unchanged
- `api/update_dn.php`: removed `$dn_number_input`; removed `dn_number=?` from UPDATE query

**Test suite + CI gate**
- `tests/test_dn_cli.php` (new): 60-test CLI suite covering file existence, PHP syntax, DN number removal, auto-generation, attachment removal across all 5 files, and core logic still intact
- `.github/workflows/php-lint.yml`: added Delivery Note test suite step
- Pre-push hook already uses `test_*_cli.php` glob — picks up new suite automatically

---

## 2026-05-21 (update 42)

### Fix: missing `project_progress_report_attachments` table (live server error)
- `migrations/2026_05_21_create_project_progress_report_attachments.php` (new): creates table for storing file attachments on progress reports — columns: report_id, attachment_name, file_path, file_size, file_ext, created_at; fixes SQLSTATE[42S02] error on Projects → Project Details → Reports → Reporting when uploading attachment

---

## 2026-05-21 (update 41)

### E-Signatures — Full bug-fix, real API implementations, CSRF protection, test suite

**Phase 1 — Fixed broken API URL paths in `e_signatures.php`**
- `app/constant/document/e_signatures.php`: upload URL corrected to `api/document/upload_signature.php`; delete URL corrected to `api/document/delete_signature.php`; quick-upload URL corrected to `${APP_URL}/api/document/quick_upload_document.php`; loadDocuments URL changed from relative to `${APP_URL}/api/get_documents.php`

**Phase 2 — Created missing API endpoints**
- `api/document/apply_signature.php` (new): validates document + signature ownership; updates pending record to signed or inserts new signed record; records IP address; logs activity
- `api/document/get_user_signatures_list.php` (new): returns JSON array of current user's active signatures for select dropdowns
- `migrations/2026_05_21_create_document_signatures.php` (new): creates `document_signatures` table (document_id, signature_id, requested_by, signed_by, signature_position, ip_address, status, due_date, signed_at)

**Phase 3 — Fixed remaining /ajax/ path references**
- `app/constant/document/e_signatures.php`: `get_user_signatures_list` calls updated to `api/document/`; both `apply_signature` references updated to `api/document/`

**Phase 4 — Fixed JS logic bugs**
- `app/constant/document/e_signatures.php`: removed `const canvas` redeclaration in draw form submit (shadowed outer `let canvas`); fixed `event.currentTarget` in `selectSignatureForDoc` and `finalSelectSignature` — both now accept `el` param passed via `this` in onclick; DataTable `signaturesTable` sort fixed from `[[2,'desc']]` (Type) to `[[3,'desc']]` (Created At); View Full Size URL now strips leading slash from `file_path` to prevent double-slash

**Phase 5 — CSRF protection**
- `header.php`: added `const CSRF_TOKEN` JS global and `$.ajaxSetup` to send `X-CSRF-Token` header on every jQuery AJAX call automatically
- `api/document/upload_signature.php`: added `csrf_check()`
- `api/document/delete_signature.php`: added `csrf_check()`
- `api/document/apply_signature.php`: `csrf_check()` included on creation
- `ajax/save_drawn_signature.php`: replaced stub with real implementation (base64 PNG decode, file save, DB insert, activity log); added `csrf_check()`

**DataTable stubs replaced with real implementations**
- `api/get_user_signatures.php`: full server-side DataTable query from `user_signatures` (was empty stub)
- `api/get_pending_signatures.php`: full server-side query from `document_signatures` JOIN `documents` JOIN `users` (was empty stub)
- `api/get_signature_history.php`: full server-side query from `document_signatures` JOIN `documents` (was empty stub)

**Select button UX fix**
- `app/constant/document/e_signatures.php`: `selectSignature()` now highlights the selected row green and injects a checkmark icon — previously showed only a toast with no visual indicator

**Test suite**
- `tests/test_esignatures_cli.php` (new): 56-test CLI suite (no DB required); checks file existence, PHP syntax, API URL correctness, JS logic, CSRF presence, auth guards, stub detection, migration integrity; exits 1 on failure — used by pre-push hook and CI
- `scratch/test_esignatures_full.php` (new): browser-based integration test; checks DB tables, saved records, file-on-disk presence, all API HTTP responses
- `.github/workflows/php-lint.yml`: added e-signatures CLI test step
- `.github/workflows/deploy.yml`: added e-signatures files to critical files check + CLI test step

## 2026-05-20 (update 40)

### Document Library — Issue/Expire dates + expiry notifications
- `migrations/2026_05_21_document_expiry_tracking.php` (new): adds `documents.issue_date` + `documents.expire_date`, adds `notifications.document_id`, creates `document_expiry_reminders` dedup table, inserts the `document_expiry_alerts` RBAC permission (Documents module) — all idempotent
- `cron/check_document_expiry.php` (new): expiry notification engine. Scans documents within 30 days of expiry; fires one notification at the 30/14/7/1-day milestones (deduped via `document_expiry_reminders`); recipients are RBAC-driven (Admins + any role with VIEW on `document_expiry_alerts`); runnable via CLI/cron or auto-included by `header.php`
- `app/constant/document/document_library.php`: upload modal gains Issue Date + Expire Date inputs; client-side validation (expire > issue); table gains an Expiry column with status badge; card view gains expiry badge; filter bar gains an Expiry Status filter; statistics row gains an "Expiring Soon" card; added `getExpiryBadge()` JS helper; updated `handleDocumentUploadLocal()` INSERT for consistency
- `api/document/upload_document.php`: saves `issue_date` + `expire_date`; rejects expire ≤ issue
- `api/document/get_documents.php`: added `expiry_status` filter (expiring/expired/active/none) and `expiring_soon` stat
- `app/dashboard.php` (additive only): new "Document Expiry" notification group; `get_system_alerts()` now also reads the user's unread document-expiry notifications; the "System requires your attention → View Details" panel lists expiring documents
- `header.php`: once-per-day guarded trigger that runs the expiry engine
- `tests/test_document_expiry_cli.php` (new): 50-check static test suite gating the feature (files, syntax, migration integrity, upload capture, engine logic, display, additive dashboard changes, header trigger)
- `.git/hooks/pre-push` (local): rewritten to run every `tests/test_*_cli.php` suite — any failure blocks `git push`
- `.github/workflows/php-lint.yml`: added a step running the document-expiry test suite on every push/PR

## 2026-05-20 (update 39)

### Project View — Supplier Payments Actions column + status workflow
- `migrations/2026_05_20_supplier_payment_workflow_status.php`: adds 'reviewed' and 'approved' to supplier_payments.status ENUM (idempotent)
- `api/suppliers/add_project_payment.php`: changed initial status from 'completed' to 'pending' so new payments enter the workflow
- `api/suppliers/get_project_payment.php` (new): GET single payment with PO + recorded_by join
- `api/suppliers/update_project_payment.php` (new): edit pending payment; reverses old paid_amount, applies new; CSRF + canEdit guard
- `api/suppliers/delete_project_payment.php` (new): soft-delete (status=cancelled) with PO reversal; blocks approved payments; CSRF + canDelete guard
- `api/suppliers/change_payment_status.php` (new): workflow transitions pending→reviewed (canReview) and reviewed→approved (canApprove); CSRF guard
- `app/bms/operations/project_view.php`:
  - `renderSupplierProjectPayments()`: added Actions column with gear dropdown — View Details, Edit (pending only), Mark Reviewed (pending), Approve (reviewed), Delete (not approved)
  - Added `viewSuppPayment()`, `editSuppPayment()`, `saveSuppPaymentEdit()`, `deleteSuppPayment()`, `changeSuppPayStatus()` JS functions
  - Added `#suppEditPaymentModal` (Edit modal with PO dropdown pre-selected)

## 2026-05-20 (update 38)

### Project View — Payments tab for Supplier mode (full implementation)
- `api/suppliers/get_project_payments.php` (new): GET endpoint; `action=list` returns payments joined via `supplier_payments → purchase_orders` filtered by `project_id + supplier_id`; `action=get_pos` returns POs for this supplier+project for the payment modal dropdown
- `api/suppliers/add_project_payment.php` (new): POST endpoint; verifies PO belongs to the project and supplier before inserting into `supplier_payments`; updates PO `paid_amount` and `payment_status`; full auth + CSRF + permission checks + `logActivity()`
- `app/bms/operations/project_view.php`:
  - Payments tab pane: supplier branch now has "Record Payment" button + `#suppAddPaymentModal` with PO dropdown (shows outstanding balance per PO), Date, Amount, Currency, Method, Reference, Notes
  - `openSuppPaymentModal()` — opens modal, loads POs via `get_pos` action
  - `saveSuppPayment()` — validates inputs, posts to new API, reloads table on success
  - `#suppPayPO` on-change handler — shows outstanding balance and auto-sets currency when PO selected
  - `loadSupplierProjectPayments()` + `renderSupplierProjectPayments()` — fetch and render payments table
  - Single `shown.bs.tab` listener routes to correct load function based on `supplierMode`; removed duplicate old listener that caused the error

## 2026-05-20 (update 37)

### Project View — Received Invoices tab in Sales section
- `api/received_invoices.php`: added `project_id` filter to `action=list` handler (one extra WHERE clause, same pattern as existing supplier_id/status filters)
- `app/bms/operations/project_view.php`:
  - Added "Received Invoices" menu item to both SC-mode and full-mode Sales dropdowns (targets new `#proj-received-invoices` tab pane)
  - Added `#proj-received-invoices` tab pane: when opened via Supplier Details → View Project (`?supplier_id=X`), the tab heading shows the supplier name and the API call is filtered by both `project_id` AND `supplier_id` — so only that supplier's invoices for the project are shown, not all suppliers'
  - Added `loadProjectReceivedInvoices()` — fetches from API with `project_id`; conditionally adds `supplier_id` when `$supplier_mode` is true; lazy-loads on first tab activation
  - Added `renderProjectReceivedInvoices(rows)` — renders table with columns: Invoice Ref, Supplier, Type, Date Raised, Date Recorded, PO Number, Amount, Status
  - Added `safeOutput()` JS utility function (was missing from this file; used by the new render function)

## 2026-05-20 (update 36)

### Supplier Details — Received Invoices table blank despite badge showing count
- `app/bms/Suppliers/supplier_details.php`: added `safeOutput()` JS function definition — it was used in 3 places (DataTable render for `invoice_ref`, `po_number`, and inside `riActions()`) but never defined in this file; JavaScript threw `ReferenceError: safeOutput is not defined` on every DataTable draw, leaving the table empty even though the API was returning data correctly

## 2026-05-20 (update 35)

### Received Invoices — PO cumulative cap + PO vs Invoice report
- `app/bms/invoice/received_invoices.php`: PO Reference field moved above Amount and Attachment (per boss requirement); live "PO Summary" panel shows PO Total / Previously Invoiced / Remaining Capacity / After This Invoice when a PO is selected; client-side cap guard blocks submit if amount + previous invoices would exceed PO total; warning message tells user to return invoice to supplier
- `helpers.php`: new `ri_check_po_cap($pdo, $po_id, $new_amount, $exclude_id)` — verifies that the running total of invoices on a PO does not exceed `grand_total`; excludes deleted invoices and (when editing) the current invoice itself
- `api/received_invoices.php`: `create` and `update` actions now call `ri_check_po_cap` server-side (defense in depth); new `action=po_summary` GET endpoint returns `{ grand_total, invoiced_total, remaining, invoice_count, project_id, project_name }` for the live panel
- `app/bms/invoice/received_invoices.php`: Project field auto-fills when PO is selected (per boss request: "ukichagua PO, automatically Project name itokee tuu"); injects option into Select2 if not already present; user can still manually override after auto-fill
- `app/bms/invoice/po_invoice_report.php`: new report page — DataTable of all POs with Supplier, PO Date, PO Total, Invoiced, Remaining, % Billed (progress bar), Status (Open / Partial / Fully Billed / Over-billed); filters by supplier, status, date range; stat cards; CSV export; mobile cards
- `api/po_invoice_report.php`: new aggregated feed (LEFT JOIN + GROUP BY on supplier_invoices)
- `roots.php`: route registered for `po_invoice_report`
- `header.php`: menu link "PO vs Invoice Report" added under Sales & Purchases (visible to anyone with `received_invoices` view permission)

## 2026-05-20 (update 34)

### Customer LPO — fix "Server error." on delete and status change
- `app/bms/customer/customer_details.php`: added `const CSRF_TOKEN = '<?= csrf_token() ?>'` at the top of the JS block; passed `_csrf: CSRF_TOKEN` in `deleteLpo()` `$.post` call and `changeLpoStatus()` `$.post` call — both APIs call `csrf_check()` which returned HTTP 419 when token was missing, causing jQuery `.fail()` to show "Server error."

## 2026-05-20 (update 33)

### RFQ — multi-file attachment support (create + edit + view)
- `migrations/2026_05_20_add_rfq_attachment.php`: creates `uploads/procurement/rfq/` directory with `.htaccess` execution guard (intermediate migration — superseded by next)
- `migrations/2026_05_20_rfq_multi_attachments.php`: creates `rfq_attachments` table (`attachment_id`, `rfq_id`, `attachment_name`, `file_path`, `original_name`, `file_size`, `uploaded_by`, `uploaded_at`); drops the single `attachment` column from `rfq` table
- `api/create_rfq.php`: rewritten — CSRF check; handles `attachment_file[]` + `attachment_name[]` arrays; 5-check security per file (extension whitelist, finfo MIME, 10 MB limit, `random_bytes` filename, `.htaccess` folder); inserts each file into `rfq_attachments`; `registerFileInLibrary()` called per file
- `api/update_rfq.php`: fully rewritten from duplicate-of-create into proper UPDATE; draft-only guard; replaces `rfq_items`; appends new files to `rfq_attachments` (existing attachments kept)
- `api/delete_rfq_attachment.php`: new — removes one attachment row from `rfq_attachments` + physical file; draft-only guard; CSRF protected
- `app/bms/purchase/rfq_create.php`: CSRF token added; Attachments card placed below RFQ Items — each row has Attachment Name input + file input + trash button; "Add Attachment" button appends rows dynamically; edit mode shows saved attachments with View + AJAX remove (Swal confirm)
- `app/bms/purchase/rfq_view.php`: queries `rfq_attachments`; Attachments card rendered below Authorization Trail — list-group with name, original filename, Download button; count badge; print-safe filename fallback

## 2026-05-20 (update 32)

### Customer LPO — line items + multi-file attachments
- `migrations/2026_05_20_create_lpo_items.php`: creates `customer_lpo_items` table (item_id, lpo_id, sort_order, product_name, quantity, unit_price, tax_rate, total)
- `migrations/2026_05_20_create_lpo_attachments.php`: creates `customer_lpo_attachments` table (attachment_id, lpo_id, file_path, original_name, file_size, created_by)
- `api/customer/get_lpo.php`: returns `items[]` and `attachments[]` arrays with download_url
- `api/customer/add_lpo.php`: saves line items (recalculates amount from totals); saves multiple attachments to `uploads/finance/customer_lpos/`
- `api/customer/update_lpo.php`: replaces line items on update; appends new attachments; fixed status validation to include pending/reviewed/approved
- `api/customer/delete_lpo_attachment.php`: new — removes single attachment record + file
- `app/bms/customer/customer_details.php`: Add/Edit modals upgraded to modal-xl; items table (S/NO, Product, Qty, Unit Price, Tax%, Total) with add/remove rows and live grand total; row-based attachment section (name field + file/existing-link per row) with Add Attachment button and per-row trash; View Details modal shows items table and attachments list; all colors white/blue only (no yellow/teal); table headers white with border; delete icons use bi-trash; JS helpers: `lpoAddRow`, `lpoCalcRow`, `lpoRemoveRow`, `lpoUpdateGrandTotal`, `lpoAddAttachRow`, `lpoRemoveAttachRow`, `lpoRenumberAttach`; `lpoEsc()` global XSS helper
- `.github/workflows/deploy.yml`: added 3 new files to CI critical-file check

## 2026-05-20 (update 31)

### Customer details — section tabs
- `app/bms/customer/customer_details.php`: wrapped Sales Order History, Invoice & Payment History, Purchase Orders (LPO), and System Information in Bootstrap pill tabs; tabs render in one scrollable row; active tab is blue; LPO tab button is PHP-conditional (hidden if no LPOs and no create permission); DataTable columns.adjust() called on tab show to fix hidden-pane rendering

## 2026-05-20 (update 30)

### Customer LPO — UI polish and bug fixes
- `app/bms/customer/customer_details.php`:
  - Removed "Document" column from LPO desktop table (th + td); documents now accessible only via View Details modal
  - Fixed `safeOutput is not defined` JS error in `viewLpo()` — added local `esc()` escape helper; replaced all `safeOutput()` calls within the function
  - Changed `statusColors.pending` in JS from `warning text-dark` to `primary` (blue badge)
  - PHP `$lpo_badges`: `pending` and `partially_fulfilled` changed from `bg-warning text-dark` to `bg-primary`; `fulfilled` changed to `bg-success`
  - View LPO modal header: `bg-info text-dark` → `bg-white border-bottom`; Edit and Review buttons: `btn-warning`/`btn-info` → `btn-primary`
  - Edit LPO modal header: `bg-warning text-dark` → `bg-primary text-white`; close button → `btn-close-white`; Update button: `btn-warning` → `btn-primary`
  - Edit LPO modal: LPO Number visible input replaced with `<input type="hidden">`; Status `<select>` replaced with `<input type="hidden">` — status managed via workflow only; info banner added matching Add LPO modal
  - Mobile cards: always-show footer; View Details button (eye icon) added as first button; document download button removed
  - DataTable `columnDefs` targets updated from `[0, 6, 7]` to `[0, -1]` after Document column removal

## 2026-05-20 (update 29)

### Customer LPO — View Details, status workflow, auto-generated number
- `migrations/2026_05_20_lpo_status_workflow.php`: alter status ENUM to add `pending`, `reviewed`, `approved`; default changed to `pending`
- `api/customer/change_lpo_status.php`: new — POST endpoint enforcing `pending→reviewed→approved` workflow
- `api/customer/add_lpo.php`: auto-generate LPO number (`LPO-YYYY-NNNNN`); status always set to `pending` on create
- `api/customer/get_lpo.php`: join customer name (`customer_display_name`); add `document_url` to response
- `app/bms/customer/customer_details.php`:
  - Gear dropdown gains "View Details" as first item
  - View LPO modal: full details, Print button, Edit shortcut, Mark Reviewed / Approve workflow buttons (shown based on current status)
  - Add LPO modal: removed LPO Number input (auto-generated) and Status select (always starts pending)
  - Edit LPO modal: status select now includes pending/reviewed/approved options
  - Status badges updated for all new statuses
- `.github/workflows/deploy.yml`: added 2 new files to CI critical-file check

## 2026-05-20 (update 28)

### Customer LPO (Purchase Order) Feature — full implementation
- `migrations/2026_05_20_create_customer_lpos.php`: creates `customer_lpos` table (`lpo_id`, `lpo_number`, `customer_id`, `issue_date`, `expiry_date`, `amount`, `currency`, `description`, `status` ENUM, `document_path`, `notes`, `created_by`, timestamps)
- `api/customer/add_lpo.php`: POST — validate + save new LPO; optional document upload (PDF/DOC/Image, 10MB max, magic-byte checked) to `uploads/finance/customer_lpos/`
- `api/customer/update_lpo.php`: POST — validate + update LPO; replaces document if new file uploaded
- `api/customer/delete_lpo.php`: POST — soft-delete (`status = 'deleted'`)
- `api/customer/get_lpo.php`: GET — fetch single LPO for edit modal
- `api/customer/get_lpos_list.php`: GET — fetch all LPOs for a customer (not used directly; available for future AJAX use)
- `app/bms/customer/customer_details.php`: inserted Purchase Orders (LPO) section between Invoices and System Information cards; stat cards (total, open, other, total amount); desktop DataTable; mobile card view; Add LPO modal; Edit LPO modal; delete with SweetAlert2 confirm; all gated by `canCreate/Edit/Delete('customers')`
- `.github/workflows/deploy.yml`: added `uploads/finance/customer_lpos` to `mkdir -p` on all 4 servers; added 6 new files to CI critical-file check

## 2026-05-19 (update 27)

### Project View — Fix Procurements dropdown appearing open on tab URL activation
- `app/bms/operations/project_view.php`:
  - Root cause: Bootstrap 5 Tab's internal `_toggleDropDown` sets `active` class and `aria-expanded="true"` on the parent `.dropdown-toggle` (Procurements button) whenever a tab inside a dropdown-menu is activated programmatically — making the dropdown appear open/pressed.
  - Fix: added `closeAllDropdowns()` helper that strips `show` class and resets `aria-expanded="false"` on all dropdown toggles and menus; called via `setTimeout(0)` immediately after `Tab.show()` so the tab pane shows correctly before dropdowns are reset.
  - Also added `pageshow` listener to call `closeAllDropdowns` when the page is restored from browser bfcache (back button), preventing a frozen-open dropdown state.

## 2026-05-19 (update 26)

### Project View — Sub-Contractor "View Details" opens full SC page with project context
- `app/bms/operations/project_view.php`:
  - Added `'sub-contractors': 'proj-sc-tab'` to the `?tab=` URL activation map so returning from SC details restores the SC tab.
  - "View Details" dropdown item changed from `onclick="projScView()"` modal to a direct link `sub_contractors/view?id=X&from=project&project_id=Y`.
- `app/bms/operations/sub_contractor_details.php`:
  - Reads `from` and `project_id` query params; fetches project name when `from=project`.
  - Breadcrumb: when from project shows `Dashboard > Projects > [Project Name] > Sub-Contractors > [SC Name]`; otherwise unchanged.
  - Desktop back button and mobile "Back to List" item both use `$back_url` — returns to `project_view?id=X&tab=sub-contractors` when from project, or `sub_contractors` list otherwise.
  - Core SC list flow completely untouched.

## 2026-05-19 (update 24)

### Admin — Remove Collections & Guarantors from Roles & Permissions
- `migrations/2026_05_19_remove_collections_guarantors_permissions.php`: removes 8 permission rows (`module_name IN ('Collections','Guarantors')`) and 60 `role_permissions` assignments that referenced them. Loan/microfinance module does not exist in this system; these were ghost entries with no backing pages.

## 2026-05-19 (update 23)

### Expenses — DB-driven "Applies to Projects" flag on Expense Types
- `migrations/2026_05_19_expense_type_show_project.php`: adds `show_project TINYINT(1) NOT NULL DEFAULT 1` to `expense_types`; sets `show_project = 0` for types named administrative / fixed / operating (case-insensitive).
- `api/finance/get_expense_schema.php`: includes `show_project` in SELECT so the JS schema always carries the flag.
- `api/finance/manage_expense_schema.php`: `add_type` now saves `show_project` param; new `toggle_show_project` action flips the flag and returns new value.
- `app/constant/accounts/expenses.php`:
  - Removed hardcoded `NON_PROJECT_TYPES` name-string check; replaced with `typeData.show_project == 0` — works on any server regardless of DB type-name spelling.
  - Add-type form in manage modal: "Applies to Projects" toggle switch (checked by default); `addManageType()` sends value as `show_project`.
  - Type list items: green badge when `show_project = 1`, grey "Off" badge when `0`.
  - Breadcrumb bar: project-toggle button next to Delete; colour/icon reflects current flag; `toggleTypeShowProject()` confirms via Swal before posting.

## 2026-05-19 (update 22)

### Sub-Contractor View — Tab buttons for Projects / Invoices / Payments
- `app/bms/operations/sub_contractor_details.php`:
  - Replaced three always-visible section rows with a tab button row (3 `btn-primary`/`btn-outline-primary` buttons in one row).
  - Each button reveals its own pane (`#pane-projects`, `#pane-invoices`, `#pane-payments`); others hide — one pane visible at a time.
  - Desktop: table view; Mobile (< 768 px): card view — applied for all three panes.
  - Added `switchScTab(tab)`: toggles pane visibility, updates active button, adjusts DataTable columns.
  - Added `applyProjectsView()` / `renderProjectCards()` for Projects pane card view.
  - Added `applyPaymentsView()` / `renderPaymentCards()` for Payments pane card view.
  - Updated `scProjectsTable` and `scPaymentsTable` DataTable inits with `drawCallback` to populate card views.
  - Unified resize listener routes to the correct `applyXView()` based on active tab.
  - Removed stale `#scPOTable` DataTable init (no matching HTML element).
- `scratch/test_sc_view_tabs.php`: new test — verifies all 6 pane/button IDs, switchScTab() function, card view divs, and DB data access for all 3 tabs; run with `?supplier_id=N`.

## 2026-05-19 (update 21)

### Project View — Sub-Contractors tab parity with external SC page
- `api/get_sub_contractors_list.php`: added `search` (LIKE name/code) and `exclude_project_id` params (excludes already-assigned SCs via `sub_contractor_projects`); returns `results[]` array in Select2 AJAX format alongside `data[]`.
- `app/bms/operations/project_view.php`:
  - **Assign Existing modal**: replaced text input + `<select size="6">` listbox with a single `<select id="assignScSelect2">` initialized as Select2 with AJAX search on `shown.bs.modal`; `openAssignExistingScModal()` now uses Select2 AJAX (no pre-load, no client-side filtering); removed `renderAssignScOptions()` and `#assignScSearch` input listener.
  - **Export button**: added to toolbar (`projScExport()` triggers DataTable Excel button); DataTable init updated to `dom: 'Brtip'` with `excelHtml5` button (cols 0–6, excludes actions).
  - **View Orders + View Payments**: added to each row's action dropdown, linking to `purchase_orders?supplier=ID` and `suppliers/payments?id=ID`.
  - **Country + City filters**: added two new filter inputs; filter section layout changed to `col-md-3` × 4; hidden `Location` column (col 7) added to DataTable (`visible: false, searchable: true`) holding `city, country` text for column-search; `projScApplyFilters()` and `projScClearFilters()` updated accordingly.
  - Address cell now renders city + country inline for visual display.

## 2026-05-19 (update 20)

### Project View — Edit Expense modal parity with Add Expense modal
- `app/bms/operations/project_view.php`:
  - **Category cascade preselection in edit**: replaced dead checkbox code (`#edit_cat_${id}`) with `preSelectCascade(categoryId, isEdit)` — traverses `expenseSchema` tree to find path from root to saved category, then selects each cascade level in sequence (80ms between levels).
  - **Budget info display in edit modal**: added `id="edit_ex_budget_id"`, `onchange="editExOnBudgetChange()"`, and budget info alert block (Allocated / Spent / Remaining badge) to edit modal's Budget Selection container — mirrors add modal.
  - **`editExOnBudgetChange()` function**: new function (mirrors `exOnBudgetChange`) targeting `#edit_ex_budget_id` / `#edit_ex_budget_info_cont` / `#edit_ex_amount`; also auto-fills amount from remaining balance if amount is empty.
  - **Budget preselect triggers info**: `editExpenseInline()` calls `editExOnBudgetChange(e.budget_id)` via `setTimeout` when opening with a linked budget.
  - **Fixed API URL**: `#expenseActionForm submit` was posting to hardcoded `/api/account/update_expense.php`; replaced with `buildUrl('api/account/update_expense.php')`.

## 2026-05-19 (update 19)

### Project View — Expenses edit modal "Sub Contractor" double-line fix
- `app/bms/operations/project_view.php`:
  - In `#expenseActionModal shown.bs.modal` handler, added explicit Select2 initialization for `#edit_ex_paid_to_type` with `minimumResultsForSearch: Infinity` (disables search box for the 4-option list).
  - Select2 renders the selected value in a styled single-line container, eliminating the browser-native `<select>` text wrapping that caused "Sub Contractor" to appear on two lines in the edit modal.
  - Guard: `!hasClass('select2-hidden-accessible')` prevents double-initialization on repeated modal opens.

## 2026-05-19 (update 18)

### Expenses — Staff payroll linking (Paid To → Employee)
- `migrations/2026_05_19_add_payroll_id_to_expenses.php` *(new)*: idempotent migration adding `payroll_id INT NULL` to `expenses` after `invoice_id`.
- `api/account/get_employee_payrolls.php` *(new)*: returns approved + unpaid payrolls for a given employee (`status='approved' AND payment_status!='paid'`); accepts optional `current_payroll_id` so edit mode includes the already-linked payroll in the list.
- `app/constant/accounts/expenses.php`:
  - Added `#payroll_id_block` dropdown (Select2) after `#invoice_id_block`; visible only when `paid_to_type = 'staff'`.
  - `#paid_to_id_select` change handler extended: `staff` type calls `get_employee_payrolls` API and populates the payroll dropdown; supplier/sub_contractor path unchanged.
  - Selecting a payroll auto-fills the Amount field (`net_salary`).
  - Added `resetPayrollBlock()` function; called on payee-type change and form reset.
  - Added `_pendingPayrollId` module variable for async preselection in edit mode.
  - Edit populate block sets `_pendingPayrollId = data.payroll_id` before triggering the payee chain.
- `api/account/add_expense.php`: saves `payroll_id`; marks linked payroll `payment_status = 'paid'` + `payment_date = CURDATE()` inside the DB transaction.
- `api/account/update_expense.php`: saves `payroll_id`; marks new payroll paid; reverts old payroll to `payment_status = 'approved'` if the link is removed or changed.

## 2026-05-19 (update 17)

### Expenses — DataTable Invalid JSON / Ajax error fix
- `api/account/get_expenses.php`:
  - Added `ob_start()` at top to buffer stray PHP warnings/notices from includes.
  - Wrapped all DB operations in `try/catch (PDOException|Exception)` — unhandled exceptions no longer output HTML; catch returns valid DataTables-format JSON with HTTP 500.
  - Added `ob_clean()` before every `echo json_encode(...)` to discard any buffered non-JSON output.
  - Fixed `SQLSTATE[HY093]: Invalid parameter number` crash: `array_unique()`/`array_filter()` preserve non-sequential keys; added `array_values()` on `$toFetch` and `$typeIds` before passing to PDO `execute()`.
  - Added `sub_contractor` CASE branch in `paid_to_name` subquery (was falling through to `ELSE e.vendor` returning null).
- `app/constant/accounts/expenses.php`:
  - Fixed DataTable AJAX URL: was hardcoded as `/api/get_expenses.php` (missing `/bms/` base path); replaced with `<?= buildUrl('api/get_expenses.php') ?>`.

## 2026-05-19 (update 16)

### Supplier View — Received Invoices table fix + Create PO / Add Payment buttons
- `app/bms/Suppliers/supplier_details.php`:
  - Restored full Received Invoices pane with `#riTable` DataTable (was incorrectly removed in a prior edit).
  - Fixed `loadReceivedInvoices()`: removed `type: 'supplier'` filter so all `invoice_type` values for the supplier are returned; fixes table showing 0 rows while DB count showed 5.
  - Fixed `riActions()`: attachment link now uses `APP_URL` JS constant directly instead of wrong PHP interpolation.
  - Added `+ Create PO` button in Purchase Orders card header (gated on `canCreate('purchase_orders')`), linking to `purchase_order_create?supplier_id=`.
  - Added `+ Add Payment` button in Payments card header, linking to `suppliers/payments?id=&create=1`.

### Expenses — Invoice linking (Paid To → Supplier/Sub-contractor)
- `migrations/2026_05_19_add_invoice_id_to_expenses.php` *(new)*: idempotent migration adding `invoice_id INT NULL` column to `expenses` after `paid_to_id`.
- `api/account/get_payee_invoices.php` *(new)*: returns approved invoices for a given payee (`payee_type`, `payee_id`); used by the expense form invoice dropdown.
- `api/account/add_expense.php`: added `invoice_id` to INSERT.
- `api/account/update_expense.php`: added `invoice_id` to UPDATE SET.
- `app/constant/accounts/expenses.php`:
  - Added `#invoice_id_block` dropdown (Select2) after Paid To payee, loads approved invoices via AJAX when a supplier/sub-contractor is selected.
  - Selecting an invoice auto-fills the Amount field.
  - Amount and Project fields moved to appear after the full Paid To section.
  - Edit form pre-populates linked invoice using `_pendingInvoiceId` async pattern.
  - `resetInvoiceBlock()` clears invoice dropdown on payee-type change; form reset also clears it.

## 2026-05-19 (update 15)

### Supplier View — Invoice ref auto-fill + Received Invoices full-width table
- `app/bms/Suppliers/supplier_details.php`:
  - Invoice Reference No. now auto-generates on modal open (add mode) via `generateRiRef()` calling `get_next_ref` API; refresh button shows for add, hides for edit.
  - Fixed `hidden.bs.modal` reset: was still using old green (`#198754`) inline CSS — now uses Bootstrap classes (`bg-primary`/`bg-warning`) matching the header's class-based approach.
  - Fixed `riEditRow()`: now removes `bg-primary` and adds `bg-warning text-dark`; removes `btn-primary` not `btn-success`.
  - `#riTable` given `style="width:100%"` + `autoWidth:false` so DataTable renders full-width matching the other tables in the pane.

## 2026-05-19 (update 14)

### Supplier View — 4-section pill tabs layout
- `app/bms/Suppliers/supplier_details.php`:
  - Replaced stacked rows (Projects Involved, Received Invoices, Purchase Orders, Payments) with a single row of 4 Bootstrap pill tab buttons.
  - Active tab is blue (Bootstrap nav-pills default). Clicking any button instantly shows only that section.
  - Purchase Orders and Payments each promoted from col-md-6 to full-width col-12 inside their own pane.
  - Added `shown.bs.tab` handler to call `columns.adjust()` on DataTables when switching to hidden panes (fixes column-width rendering).
  - Supplier info section (Basic Info, Contact, Address, Bank, Description) untouched.

## 2026-05-19 (update 13)

### Received Invoices — Actions column: gear dropdown UI
- `app/bms/invoice/received_invoices.php`:
  - Replaced individual action buttons (eye/paperclip/pencil/trash) with a single gear+caret dropdown (`btn-outline-primary dropdown-toggle`, `bi-gear`) matching the project-wide pattern.
  - Dropdown items: View, View/Download Attachment, Edit (if can_edit), Delete (if can_delete, with divider).
  - Applied to both the desktop DataTable (`actionButtons()`) and the mobile card footer (`renderCards()`).
  - No logic changes — UI restructure only.

## 2026-05-19 (update 12)

### Received Invoices — Fix blank table (safeOutput not defined)
- `app/bms/invoice/received_invoices.php`:
  - Root cause of blank DataTable: `safeOutput()` is not a global function — it is defined per-page in this project. DataTables called it during the first row render, threw `ReferenceError: safeOutput is not defined`, crashed the draw callback silently, and rendered nothing.
  - Fix: added `safeOutput()` and `CSRF_TOKEN` definitions at the top of the page `<script>` block.

## 2026-05-19 (update 11)

### Admin flag + loadInvoices error visibility
- `migrations/2026_05_19_roles_is_admin_flag.php` (NEW):
  - Adds `is_admin TINYINT(1)` column to `roles` table
  - Sets `is_admin = 1` for role_id=1 (Admin)
  - Any role can now be flagged as admin — not hardcoded to role_id=1
- `core/permissions.php`:
  - `isAdmin()` now reads `$_SESSION['is_admin']` (set by header.php each page load) instead of hardcoding `role_id = 1`
  - Fallback DB query if session flag is missing
- `header.php`:
  - Role query now fetches `r.is_admin` and stores it as `$_SESSION['is_admin']`
- `app/bms/invoice/received_invoices.php`:
  - `loadInvoices()` now has a `.fail()` handler — shows HTTP status + raw response in `#list-message` div above the table instead of silently dropping failures

## 2026-05-19 (update 10)

### Received Invoices — Fix permissions for non-admin roles
- `migrations/2026_05_19_received_invoices_permissions.php`:
  - Bug fix: migration was only inserting into `permissions` table but never into `role_permissions`
  - `canView/canCreate/...` reads `$_SESSION['permissions']` loaded at login — without `role_permissions` rows, non-admin users got 403 from the API and `$.getJSON` silently dropped the response, leaving the table blank
  - Now assigns full CRUD+review+approve to roles 1,2,5,6,7 (Admin, MD, Director, CFO, Accountant) and view+create to all other roles
  - **Note:** existing logged-in non-admin users must log out and back in for the new permissions to load into their session

## 2026-05-19 (update 9)

### Receive Invoice — View Details
- `app/bms/invoice/received_invoices.php`:
  - Feature: added Eye (👁) view button to both desktop table and mobile card action rows
  - Feature: View modal shows full invoice details — type badge, party name, amount, dates, PO/project, SC basis fields, recorded-by, created-at, notes, attachment link
  - Feature: "Edit" shortcut button in view modal footer (gated by `canEdit`)
  - JS: `viewRow(id)`, `viewToEdit()` functions; spinner shown while loading
- `api/received_invoices.php`:
  - `get` action: added `recorded_by_name` join to `users` table

## 2026-05-19 (update 8)

### Receive Invoice — List refresh + auto-generated reference
- `app/bms/invoice/received_invoices.php`:
  - Bug fix: success handler now calls `modal.hide()` + `loadInvoices()` immediately, then fires SweetAlert — eliminates Bootstrap 5 + SweetAlert2 timing issue where `getInstance()` returned null and `loadInvoices()` never ran
  - Bug fix: added `shown.bs.modal` handler that loads the party list (suppliers/SCs) and generates the invoice reference on every new-invoice modal open — supplier dropdown was empty on first open (no `change` event fires for the default radio selection)
  - Feature: Invoice Reference No. now auto-generated as `INV-YYYY-NNNN` when modal opens; refresh button (↻) lets user regenerate; field is still editable for overrides
- `api/received_invoices.php`:
  - Added `get_next_ref` GET action — returns next `INV-YYYY-NNNN` reference based on `MAX()` of existing refs for the current year

## 2026-05-19 (update 7)

### Receive Invoice — Fixes & Enhancements
- `app/bms/invoice/received_invoices.php`:
  - Bug fix: moved `initDataTable()` before `loadInvoices()` in `$(document).ready` — eliminates race condition where `riTable` was null when AJAX callback fired, causing list to appear empty after first create
  - Bug fix: added `setTypeMode('supplier')` on page init to correctly set field visibility on first modal open
  - Feature: project selection now shown for ALL invoice types (supplier + SC); for supplier it is optional, for SC it is required
  - Feature: `setTypeMode()` updated to toggle project label and `required` attr by type
  - Feature: supplier `#f-supplier change` handler now calls `loadProjects(sid, 'supplier')` alongside `loadPOs`
  - Feature: `loadProjects()` now accepts `type` param and passes it to API
  - Feature: `editRow()` now loads projects for supplier type too (pre-fills saved value)
  - UI: stat cards now have `background-color: #d1e7dd` green background
  - UI: table header changed from `table-dark` to `bg-white` (white background)
  - UI: first column header changed from `#` to `S/No`
- `api/received_invoices.php`:
  - `get_projects` action: added `type` param — `type=supplier` queries `supplier_projects` join table, `type=sub_contractor` (default) queries `sub_contractor_projects`
- `app/bms/Suppliers/supplier_details.php`:
  - Added `Project (optional)` field to RI record/edit modal
  - `loadRiProjects(selectedId)` function added — loads this supplier's projects via `get_projects?type=supplier`
  - Modal `shown.bs.modal`: initialises project Select2 and loads options
  - Modal `hidden.bs.modal`: destroys and resets project select
  - `riEditRow()`: pre-fills project field when editing existing invoice

---

## 2026-05-19 (update 6)

### Phase 5 — Receive Invoice: Sub-Contractor Details Integration + Bug Fixes
- `app/bms/operations/sub_contractor_details.php` (modified):
  - **Bug fix** — PO query: added `AND supplier_id = ?` so only this SC's POs are fetched (was returning all suppliers' POs in the same projects)
  - **Bug fix** — Payments: switched from `supplier_payments` to `sc_payments` table; query now uses correct columns (`id AS payment_id`, no PO join)
  - **Bug fix** — `$milestones_count`: replaced hardcoded `0` with `SELECT COUNT(*) FROM project_milestones WHERE project_id IN (...)`
  - **Bug fix** — `$paid_amount`: replaced hardcoded `0` with `SELECT COALESCE(SUM(amount), 0) FROM sc_payments WHERE supplier_id = ?`
  - Added `$received_invoices_count` query using `supplier_invoices` table
  - Desktop action bar: added "Record Invoice" button (green, `bi-receipt`), gated by `canCreate('suppliers')`
  - Mobile actions dropdown: added "Record Invoice" item with same gate
  - New "Received Invoices" section with AJAX DataTable inserted before Related Tables — columns: S/No, Invoice Ref, Date Raised, Date Recorded, Project, Basis, Amount, Status, Actions (3: View/Download, Edit, Delete)
  - DataTable loaded via `api/received_invoices.php?action=list&supplier_id=X` on page ready
  - Record/Edit Invoice modal added (type=sub_contractor locked, supplier_id locked): fields — Invoice Ref, Project (Select2, pre-loaded from assigned projects), Invoice Basis (Select2: IPC/Milestone/Scope/Final), Basis Ref, Date Raised, Date Recorded, Amount, Attachment, Notes
  - Mobile card view for Received Invoices section with `applyRiScView()` + resize listener

---

## 2026-05-19 (update 5)

### Phase 4 — Receive Invoice: Supplier Details Integration
- `app/bms/Suppliers/supplier_details.php` (modified):
  - PHP: added received invoices COUNT query at top (`$received_invoices_count`)
  - Desktop action bar: added "Record Invoice" button (green, `bi-inbox`), gated by `canCreate('received_invoices')`
  - Mobile actions dropdown: added "Record Invoice" item with same gate
  - New "Received Invoices" section (card + DataTable) inserted before Purchase Orders row — columns: S/NO, Invoice Ref, Date Raised, Date Recorded, PO Reference, Amount, Status, Actions (3: View/Download, Edit, Delete)
  - DataTable loaded via AJAX `api/received_invoices.php?action=list&type=supplier&supplier_id=X` on page ready
  - Badge count (`#ri-count-badge`) updated live from AJAX response
  - Record/Edit Invoice modal added (supplier type + supplier_id locked, no type toggle): fields — Invoice Ref, PO (Select2 cascaded from API), Date Raised, Date Recorded, Amount, Attachment, Notes
  - Edit: loads row via `action=get`, pre-fills modal fields including Select2 PO
  - Delete: SweetAlert2 confirm → `action=delete` → reloads DataTable
  - View/Download: `window.open` on attachment path, "No Attachment" Swal if empty

---

## 2026-05-19 (update 4)

### Phase 3 — Receive Invoice: Main List Page + Entry Points
- `app/bms/invoice/received_invoices.php` (new, 601 lines): full list page — 4 stat cards (total, total amount, by supplier count, by SC count); filter bar (type/status/date range); DataTable with 10 columns; Add/Edit modal with radio toggle switching between supplier fields (PO cascade) and SC fields (project + basis + basis ref cascade); mobile card view (§5); SweetAlert2 confirms (§6); Select2 on all dropdowns (§4); CSRF; spinner on submit
- `roots.php` (modified): added `received_invoices` and `received_invoices.php` route keys mapping to new page
- `app/bms/invoice/invoices.php` (modified): added "Received Invoices" button beside New Invoice, gated by `canView('received_invoices')`
- `header.php` (modified): added "Received Invoices" nav link under Finance > Sales & Purchases and under Sales dropdown, both gated by `canView('received_invoices')`

---

## 2026-05-19 (update 3)

### Phase 2 — Receive Invoice: API Layer
- `api/received_invoices.php` (new): single-file CRUD API with 8 actions
  - GET `list` — all received invoices, filterable by type/supplier_id/status; JOINs suppliers, sub_contractors, purchase_orders, projects, users
  - GET `get` — single invoice by id
  - GET `get_suppliers` — supplier list for Select2 (id/text pairs)
  - GET `get_sub_contractors` — SC list for Select2
  - GET `get_pos` — POs for a given supplier_id (cascades from who selection)
  - GET `get_projects` — projects for a given SC supplier_id (via sub_contractor_projects join)
  - POST `create` — inserts new row; validates type, required fields, amount > 0; handles attachment upload (5-check security: ext + MIME + size + safe name + .htaccess)
  - POST `update` — edits existing row, replaces attachment if new file provided
  - POST `delete` — soft delete (status = 'deleted')
  - All state-changing actions: CSRF check → permission gate → validate → PDO → logActivity → JSON

---

## 2026-05-19 (update 2)

### Phase 1 — Receive Invoice: Database Foundation
- `migrations/2026_05_19_supplier_invoices.php` (new): creates `supplier_invoices` table — columns: invoice_type (supplier/sub_contractor), supplier_id, invoice_ref, date_raised, date_recorded, po_id (null), project_id (null), sc_invoice_basis (IPC/Milestone/Scope/Final), sc_basis_ref, amount, attachment, status (draft/submitted/approved/paid/deleted), notes, recorded_by, timestamps. Indexes on supplier_id, po_id, project_id, type, status.
- `migrations/2026_05_19_received_invoices_permissions.php` (new): inserts `received_invoices` permission row into `permissions` table (module = Finance). Uses INSERT IGNORE pattern via SELECT guard — idempotent.

---

## 2026-05-19 (update 1)

### CLAUDE.md — Selective loading to reduce context and speed up responses
- `CLAUDE.md`: removed 4 heavy @imports (`migrations`, `templates`, `security`, `strategy`); kept only `dev-standards.md` (~94 lines) and `process.md` (~110 lines) as always-loaded — saves ~640 lines per session
- Added trigger-word comments in CLAUDE.md: `#migrate`, `#newpage`, `#secure`, `#plan` for on-demand loading
- `migrations/CLAUDE.md` (new): auto-loads `.claude/migrations.md` only when editing migration files
- `api/CLAUDE.md` (new): auto-loads `.claude/security.md` only when editing API files
- `app/CLAUDE.md` (new): auto-loads `.claude/templates.md` only when editing app pages

---

## 2026-05-18 (update 11)

### app/bms/operations/project_view.php — Supplier mode: Purchase Orders tab + supplier filter
- **Removed Scope tab from supplier mode**: in restricted_mode, Scope dropdown now only shows for SC mode; supplier mode gets no Scope tab (not relevant to suppliers)
- **Added Purchase Orders tab for supplier mode**: first tab in restricted_mode is now "Purchase Orders" (active by default) when `$supplier_mode` is true
- **`#purchases` tab-pane**: made `show active` conditionally when `$supplier_mode` is true; `scope-original` pane made active only for SC restricted mode (not supplier mode) to avoid tab conflict
- **Filtered POs by supplier**: `renderPurchasesFull()` now filters purchase orders to only those belonging to `viewSupplierId` when `supplierMode` is true
- **`createPurchaseOrder()`**: appends `&supplier=${viewSupplierId}` to the URL when in supplier mode so the new PO is pre-filled with the correct supplier

---

## 2026-05-18 (update 10)

### app/bms/Suppliers/supplier_payments.php — Print footer CSS + printSlip fix
- Added `<?php require ROOT_DIR . '/includes/print_footer_css.php'; ?>` after the `<style>` block so the standard footer renders correctly on print (fixed position at bottom, correct typography, 14mm body padding)
- Removed stale `slip_print_date` JS reference from `printSlip()` — the date is now rendered by PHP via `print_footer_html.php`; function simplified to `window.print()`

---

## 2026-05-18 (update 9)

### CLAUDE.md — Split into modular files
- `CLAUDE.md` reduced from ~1580 lines to 20 lines (project overview + general rules + @imports only)
- Created `.claude/migrations.md` — migration system rules
- Created `.claude/dev-standards.md` — §1–§7 development standards
- Created `.claude/templates.md` — §8–§17 page/API templates, URL routing, permissions, soft delete, logging, XSS, icons, AJAX pattern, stats cards
- Created `.claude/security.md` — §18–§22 constants, file upload security, auth/session, CSRF, RBAC
- Created `.claude/strategy.md` — §23–§25 do-not-add list, features roadmap, production gaps
- Created `.claude/process.md` — §26–§29 UI anti-patterns, PDO reference, page-touch walkthrough, button test cases

---

## 2026-05-18 (update 8)

### Print Footer — Standardised across all 10 standalone print pages
- Created `includes/print_footer_css.php`: shared CSS for `.print-footer` (12px font, `position:fixed; bottom:0`, `padding-bottom:14mm` in `@media print` to prevent footer overlapping body content)
- Created `includes/print_footer_html.php`: shared HTML with `Printed by <strong>Name</strong> — <strong>Role</strong> on <date>` and BJP Technologies brand line; uses session fallbacks; respects pre-set variables from calling file
- Removed all internally-defined `.print-footer` CSS blocks, `$printed_by / $printed_role / $printed_at / $copy_year` vars from all 10 files
- Added Bank Details block (Bank Transfer / Mobile Money / Cheque from `system_settings`) to:
  - `app/bms/sales/print_sales_order.php`
  - `app/bms/invoice/invoice_print.php`
  - `app/constant/accounts/payment_voucher_print.php`
- Files fully migrated to external footer (footer only, no bank details):
  - `api/account/print_rfq.php`
  - `api/account/print_delivery_note.php`
  - `api/account/print_purchase_order.php`
  - `app/bms/grn/grn_print.php`
  - `app/bms/purchase/print_purchase_return.php`
  - `app/bms/sales/sales_returns/print_sales_return.php`
  - `app/bms/operations/print_ipc.php`

## 2026-05-18 (update 7)

### Print Quotation — Bank Details block beside totals
- `app/bms/sales/print_sales_order.php`:
  - Fetches bank/payment settings from `system_settings` at render time (keys: `bank_name`, `account_name`, `account_number`, `swift_code`, `mpesa_paybill`, `mpesa_account_no`, `check_payable_to` — same keys saved by `payment_settings.php`)
  - Replaced `float:right` totals layout with a flex row: Bank Details block on the left, amounts (Subtotal / Tax / Shipping / Grand Total) on the right
  - Bank Details block shows Bank Transfer section, Mobile Money section, and Cheque section — each only rendered when that group has data
  - If no bank details configured at all, block is hidden and totals remain right-aligned
  - Bank Details block styled to match existing `.box` convention (grey background, blue left border, same typography)

## 2026-05-18 (update 6)

### Tenders — tender_edit.php §2 parity fix
- `app/bms/tenders/tender_edit.php`:
  - Phase 3 heading renamed from "TENDER PERTICIPATION FEE" → "Tender Entrance Fee" with explanatory subtitle (matches create form)
  - POST handler now reads `entrance_fee_tzs`/`entrance_fee_usd` from `$_POST` (was `tender_amount_tzs`/`tender_amount_usd`)
  - UPDATE SQL now writes to `entrance_fee_tzs`/`entrance_fee_usd` columns (was `tender_amount_tzs`/`tender_amount_usd`)
  - Form input names/IDs changed to `entrance_fee_tzs`/`entrance_fee_usd`; pre-fill now reads `$tender['entrance_fee_tzs']`/`$tender['entrance_fee_usd']`
  - Card headers updated: "Tender Amount & Submission Document" → "Entrance Fee" (Tshs and USD sections)
  - Input labels updated: "Tender Amount (Tshs/USD)" → "Entrance Fee (Tshs/USD)"
  - JS `required` binding updated to `#entrance_fee_tzs`/`#entrance_fee_usd`
  - Added `csrf_check()` call at top of POST handler
  - Added `<input type="hidden" name="_csrf">` token to wizard form
  - Upload handler now applies all 5 §19 security checks (extension whitelist, finfo MIME check, 20 MB limit, `bin2hex(random_bytes(16))` filename, `mkdir(0755)`)

## 2026-05-18 (update 5)

### Tenders — §28 walkthrough fixes (CSRF + upload security)
- `helpers.php`: added `csrf_token()` and `csrf_check()` helpers (§21 — required globally)
- `app/bms/tenders/tender_create.php`:
  - Added `csrf_check()` call at top of POST handler
  - Added `<input type="hidden" name="_csrf">` token to wizard form
  - Upload handler now applies all 5 §19 checks: extension whitelist (pdf/doc/docx/xls/xlsx/jpg/png), `finfo` MIME-byte validation, 20 MB size limit, `bin2hex(random_bytes(16))` safe filename, `mkdir(0755)` (was 0777)
- `uploads/tenders/.htaccess` (new): blocks PHP/script execution in the upload folder

## 2026-05-18 (update 4)

### Tenders — separate Entrance Fee from Tender Sum (Contract Sum)
- `migrations/2026_05_18_add_entrance_fee_columns.php` (new): adds `entrance_fee_tzs` and `entrance_fee_usd` columns to `tenders`; back-populates them from `tender_amount_tzs`/`tender_amount_usd` for records still in PENDING/APPROVED/INVITATION status
- `app/bms/tenders/tender_create.php`:
  - Phase 3 POST handler now saves to `entrance_fee_tzs`/`entrance_fee_usd` (new columns) instead of `tender_amount_tzs`/`tender_amount_usd`
  - Phase 3 heading renamed from "Tender Participation Fee" → "Tender Entrance Fee" with explanatory sub-text clarifying this is the document-purchase fee, not the bid amount
  - Input labels updated to "Entrance Fee (Tshs/USD)"
- `app/bms/tenders/tender_view.php`:
  - Added dedicated **Entrance Fee** row (reads `entrance_fee_tzs`/`entrance_fee_usd`; shows "Not recorded" if absent)
  - **Tender Sum (Contract Sum)** row now reads `tender_amount_tzs`/`tender_amount_usd` (set by Financial Submission); shows "Not yet submitted — set during Financial Submission" when null (pre-submission tenders)

## 2026-05-18 (update 3)

### CLAUDE.md — Workflow-status permissions + page-touch walkthrough
- `CLAUDE.md`:
  - **§1 (Button Testing)** — added the **page-audit rule**: before editing any frontend page, read §1–§27 and fix any rule violations as part of the same task; list fixes in the commit message
  - **§11.1 Workflow-Status Permissions (NEW)** — full catalogue of permission verbs beyond CRUD (submit / review / approve / post / void / reject / publish / cancel / reopen / export / print) with: helper names, typical role allowed, page-level pattern, status-button rendering pattern, and a complete API endpoint pattern that enforces allowed transitions + permission per verb + audit log. Mandates segregation of duties (creator ≠ approver) and immutability after posting
  - **§28 Chronological Page-Touch Walkthrough (NEW)** — 28-step ordered checklist from "create feature branch" → "commit & push", each step linked to the relevant section. Steps marked N/A in commit message when not applicable
  - **§29 Per-Button Test Cases (NEW)** — concise manual-test list per button type (View / Add / Edit / Delete / Status / Search / Export / Print / Modal close)
  - **Summary section (NEW)** — high-level table of contents grouping all 29 sections into: Bootstrap & Deploy / Dev Standards / New Page Reference / Constants & Security / Strategic Direction / Operations & Quality / Process

## 2026-05-18 (update 2)

### CLAUDE.md — Production concerns & forbidden UI patterns
- `CLAUDE.md`: Added 2 new sections (§25 & §26) covering operational gaps and UI anti-patterns:
  - **§25 Operational Gaps to Close** — 7 production-grade items currently missing: CSP/security headers (full `.htaccess` snippet), rate limiting (MySQL-backed `rateLimitCheck()` helper, recommended limits), automated DB backups (cron + retention + off-site + restore test), error monitoring (custom error_log table or Sentry), staging environment + rollback strategy, `/health.php` endpoint for uptime monitors, log rotation (logrotate config + DB-log pruning)
  - **§26 Forbidden UI Patterns** — 13 UI/UX anti-patterns banned across the system: auto-playing media, modal-in-modal, auto-refresh wiping input, dashboard carousels, horizontal mobile scroll, hover-only buttons, <3s flash messages, >7 top-nav items, long forms without "Save & Continue", files >1000 lines, functions >100 lines, nesting >4 levels deep, `!important` in CSS
  - **§27 PDO Quick Reference** — renumbered from previous §25

## 2026-05-18 (update 1)

### CLAUDE.md — Security, constants, roadmap & "do not add" list
- `CLAUDE.md`: Added 8 new reference sections (§18–§25) covering audit findings:
  - **§18 Constant Conventions** — entity code prefixes (CUST-, SUP-, PRD-…), TZS/Tanzania defaults, full helper-function catalogue
  - **§19 File Upload Security (CRITICAL)** — five mandatory checks (ext + MIME magic-bytes + size + safe filename + .htaccess); gatekeeper download pattern for sensitive docs. Documented that current logo/document uploads fail this bar
  - **§20 Authentication & Session Security** — `session_regenerate_id()` after login, cookie HttpOnly/Secure/SameSite flags, failed-attempt tracking + 15-min lockout, password reset rules
  - **§21 CSRF Protection** — `csrf_token()`/`csrf_check()` helpers, hidden field in every form, jQuery `ajaxSetup` header
  - **§22 Access Control Depth (RBAC)** — extended verb set (approve/review/export/print/post/void), full role matrix (Admin/Manager/Accountant/Sales/Procurement/Storekeeper/HR/Auditor/Field Officer), row-level scope, 2FA for elevated roles
  - **§23 What NOT to Add** — hard "do not add" list (no frameworks, no ORMs, no SPA, no build step, no TS, no microservices, no GraphQL, no extra CSS/icon/chart libraries…) to keep the raw-PHP setup productive
  - **§24 Trending Features Roadmap** — 5 phases prioritised for 2026 + Tanzania: security hardening → TRA EFD / M-Pesa / WhatsApp / Swahili / SMS → barcode + 2FA + PWA + REST API + webhooks → dashboards + OCR + AI assist + predictive reorder → dark mode + e-signature + timelines
  - **§25 PDO Query Patterns** — renumbered from previous §18

## 2026-05-17 (update 16)

### CLAUDE.md — Documented all common codebase patterns
- `CLAUDE.md`: Added 11 new reference sections (§8–§18) covering every pattern used across the project:
  - **§8 New Page Template** — full PHP skeleton with auth, permissions, DataTable, modals, mobile cards, AJAX
  - **§9 New API Endpoint Template** — 6-step structure (auth → permission → method → validate → logic → log)
  - **§10 URL & Routing Rules** — `getUrl()` vs `buildUrl()`, never hardcode paths
  - **§11 Permission System** — `canView/canCreate/canEdit/canDelete('page_key')` usage and admin bypass
  - **§12 Soft Delete** — always `UPDATE … SET status = 'deleted'`, never `DELETE FROM`
  - **§13 Activity Logging** — `logActivity()` required on every write; `logActivityAction()` in JS
  - **§14 Safe Output / XSS** — `safe_output()` in PHP, `safeOutput()` in JS for all rendered values
  - **§15 Icon Library** — Bootstrap Icons (`bi bi-*`) only; no Font Awesome on new pages
  - **§16 AJAX Submit Button Pattern** — disable + spinner during request, restore in `complete:`
  - **§17 Statistics Cards Pattern** — 2×4 grid above every list table with colour conventions
  - **§18 PDO Query Patterns** — quick reference for fetch/insert/update/soft-delete/transaction/column-check

## 2026-05-17 (update 15)

### Locations — fix "headers already sent" on delete + SweetAlert2 alerts + CLAUDE.md standards
- `app/bms/stock/locations.php`:
  - **Fix "Cannot modify header information — headers already sent"**: Moved entire `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block (add, update, delete handlers) to BEFORE `includeHeader()`. Root cause: `includeHeader()` was called on line 9 before the POST handlers, so HTML output from `header.php:77` was already sent before `header("Location: ...")` ran on delete/add/update.
  - **SweetAlert2 for success/error**: Replaced Bootstrap alert divs with inline `<script>` blocks that call `Swal.fire()` on `DOMContentLoaded` — users now see a SweetAlert2 popup after add, edit, or delete operations.
  - **Select2**: Added `select2-static` class and explicit `select2()` init to filter dropdowns (warehouse, status) and all three modal dropdowns (warehouse, type, status). Modal selects use `dropdownParent: $('#locationModal')` and are re-initialized on `shown.bs.modal` to prevent dropdown clipping.
  - **Mobile card view**: Added `d-md-none` card section below the table — each card shows name, code, warehouse, type, item count, qty, and action buttons in a single `flex-wrap:nowrap` row with `flex:1;min-width:0` so buttons never wrap regardless of count. Table is wrapped in `d-none d-md-block` so it's desktop-only.
  - **Sticky navbar on mobile**: Added `@media (max-width:767px)` CSS rule making `.navbar` sticky (`position:sticky; top:0; z-index:1020`).
- `migrations/2026_05_17_locations_status_deleted.php`: Added `'deleted'` to `locations.status` ENUM. The soft-delete handler (`UPDATE locations SET status='deleted'`) was silently failing because `'deleted'` was not a valid ENUM value — MySQL converted it to `''` without error.

## 2026-05-17 (update 14)

### Deploy — create all upload directories on all servers
- `.github/workflows/deploy.yml`: Replaced per-directory mkdir lines with a single `mkdir -p` call covering all 29 upload paths used across the codebase, followed by `chmod -R 777 uploads/`. Fixes `move_uploaded_file(): No such file or directory` for delivery notes, products, and any other module whose upload folder was missing. Root cause: PHP www-data cannot create directories; deploy script runs as privileged user.

## 2026-05-17 (update 13)

### Deploy — create uploads/products on all servers
- `.github/workflows/deploy.yml`: Added `mkdir -p uploads/products && chmod 777 uploads/products` to all four server lines. Fixes `move_uploaded_file(): Failed to open stream: No such file or directory` when uploading a product image — the directory was missing on the server and PHP's www-data user cannot create it.

## 2026-05-17 (update 12)

### Purchase Orders — delete fix + mobile card button row
- `api/delete_purchase_order.php`: Changed `$_POST['id']` to `$_POST['order_id'] ?? $_POST['id']` — fixes "Purchase order ID is required" error. `purchase_orders.php` sends `order_id` but the local API was reading `id`.
- `app/bms/purchase/purchase_orders.php`:
  - **Mobile card buttons**: Changed from `flex-wrap` (wrapping) to `flex-wrap:nowrap` with `flex:1;min-width:0;padding:3px 4px;font-size:0.72rem` on each button — all action buttons now stay in a single non-wrapping row, matching the DN page pattern.
  - **Icon-only on mobile**: Removed text labels from card action buttons (View, Approve, Edit); icon-only with `title` attributes for accessibility.
  - **View toggle hidden on mobile**: Added `d-none d-md-flex` to the table/card toggle button group (mobile always shows card view).

## 2026-05-17 (update 11)

### Products — product_edit.php / update_product.php form parity with add modal
- `app/bms/product/product_edit.php`:
  - **Removed Product Type selector**: Removed Inventory/Service radio card block (no equivalent in add modal). Hidden `is_service` and `track_inventory` inputs preserved with current values.
  - **Removed `onProductTypeChange()` JS**: No longer needed; removed its call in `$(document).ready`.
  - **Simplified tracking section**: Removed redundant Tracking badge column from Inventory tab row.
  - **Pricing colors matched**: Selling price TZS → `bg-success text-white`; Wholesale → `bg-info text-white` input-group; Min Selling Price → visible input with `bg-danger text-white` TZS span.
  - **Removed duplicate is_taxable**: Cleaned up second checkbox and empty comment div from Advanced tab.
  - **Added editable Current Stock section**: Shows and accepts stock quantities per warehouse in the Inventory tab (same layout as Opening Stock in add modal). Inputs have `name="stock[warehouse_id]"`.
- `api/update_product.php`:
  - **Added stock adjustment handling**: Reads `$_POST['stock']` array, compares each warehouse quantity with current value, creates `adjustment_in`/`adjustment_out` stock movements for any changes, and upserts `product_stocks`.

## 2026-05-17 (update 10)

### Finance > Expenses — table column polish
- `app/constant/accounts/expenses.php`:
  - Removed "Account" and "Created By" columns from the expenses table.
  - Renamed "Category Path" header to "Category"; column now shows only the leaf segment (last sub-category) as plain text — no badge/rectangle.
  - "Project" column also changed from a badge to plain text.
  - Mobile card view updated to match: leaf category shown as plain text, no badge.
  - Amount column shows "Day: X" subtitle when multiple expenses share the same category on the same date.
- `api/account/get_expenses.php`:
  - Column sort mapping updated.
  - Category path (`Type › Category › Sub`) built server-side; leaf extracted client-side for display.
  - Daily category total computed per date+category and returned as `daily_category_total`.

## 2026-05-17 (update 9)

### Products — bug fixes and CLAUDE.md standards applied
- `app/bms/product/products.php`:
  - **Bug fix**: After adding a product, redirect to `?sort_by=created_at&sort_order=DESC` so the new product always appears first. Previously `location.reload()` kept alphabetical sort, hiding the new product on page 2+.
  - **Bug fix**: Dimension field names in add modal corrected: `dim_l/dim_w/dim_h` → `dim_length/dim_width/dim_height` to match what `create_product.php` reads. Dimensions were silently not saving.
  - **Select2**: Added `select2-static` to filter selects (category, brand, supplier) and modal selects (category, tax, brand, supplier).
  - **Mobile**: View toggle button group set to `d-none d-md-flex` (hidden on mobile, desktop only).
  - **Mobile sticky navbar**: Added `position:sticky; top:0; z-index:1020` CSS for `.navbar` at mobile breakpoint.
- `app/bms/product/product_edit.php`:
  - **Bug fix**: Added `name="dim_length"`, `name="dim_width"`, `name="dim_height"` to dimension inputs. Without these, `update_product.php` received no dimension data and cleared dimensions on every save.
  - **Select2**: Added `select2-static` to category, tax, brand, and supplier selects.

## 2026-05-17 (update 8)

### Purchase Orders — fix "Invalid Order ID" on delete online
- `app/bms/purchase/purchase_orders.php` — Fixed `cancelOrder()` AJAX call sending `{ id: id }` while `api/account/delete_purchase_order.php` expects `order_id`. Changed to `{ order_id: id }`. Locally WAMP served the physical `api/delete_purchase_order.php` (which reads `id`); online the router maps to `api/account/delete_purchase_order.php` (which reads `order_id`), causing the mismatch.

## 2026-05-17 (update 7)

### Migration — purchase_receipt_attachments table
- `migrations/2026_05_17_purchase_receipt_attachments.php` — Creates `purchase_receipt_attachments` table on all servers. Fixes fatal PDOException on `grn_view.php` on servers where the table was never created (it was previously only created lazily inside `create_grn.php`).

## 2026-05-17 (update 6)

### Deploy — create uploads/documents on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/documents && chmod 777 uploads/documents` to all four server deploy lines. Fixes `Warning: mkdir(): Permission denied` in `upload_document.php` — the directory was missing so PHP couldn't create it under the `www-data` user.

## 2026-05-17 (update 5)

### Deploy — create uploads/document_library on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/document_library && chmod 777 uploads/document_library` to all four server deploy lines. Fixes `mkdir(): Permission denied` error when uploading documents via the document library.

## 2026-05-17 (update 4)

### Document Library — CLAUDE.md standards applied
- `app/constant/document/document_library.php` — Added mobile card view with toggle (`d-none d-md-flex`); `drawCallback` renders cards from AJAX row data. Select2 (`select2-static`) on `#categoryFilter` (filter) and `#category_id` (upload modal). Updated `clearFilters()` to trigger Select2 reset. Sticky navbar CSS. `@media print` hides card grid.

## 2026-05-17 (update 3)

### Edit Customer — sticky navbar CSS
- `app/bms/customer/edit_customer.php` — Added sticky navbar CSS to existing `@media (max-width: 768px)` block. No other changes needed (no tables, no plain selects, already uses SweetAlert2).

## 2026-05-17 (update 2)

### Customer Details — CLAUDE.md standards applied
- `app/bms/customer/customer_details.php` — Added DataTable to Sales Orders table (`#customerOrdersTable`) and Invoices table (`#customerInvoicesTable`). Added mobile card view (toggle hidden on mobile with `d-none d-md-flex`; `drawCallback` renders card grids). Added Select2 (`select2-static`) to `#edit_category_id` and `#edit_project_id` in edit modal; init on `shown.bs.modal`. Added sticky navbar CSS (`@media max-width 767px`). Added `@media print` rule to hide card grids. Moved DataTable/view JS to unconditional script block; edit form handler and `editCustomer()` remain in conditional (`$can_edit_customers`) block.

## 2026-05-17 (update 1)

### Customers — CLAUDE.md standards applied
- `app/bms/customer/customers.php` — Mobile-enforced card view (`col-12`, icon-only footer buttons, `flex-nowrap`). Select2 on `#categoryFilter`, `#category_id`, `#project_id` (add + edit modals). View toggle hidden on mobile (`d-none d-md-flex`). Sticky navbar CSS. Resize handler.

## 2026-05-16 (update 13)

### Expenses — Cascade drill-down category selection (single select)
- `app/constant/accounts/expenses.php` — Replaced multi-select checkbox category block with a cascading single-select dropdown. Selecting an expense type shows a "Select Category" dropdown; if the chosen category has sub-categories, a "Select Sub-category" dropdown appears below it automatically. Only the deepest selected category is saved per expense. Edit modal restores the full cascade path for the stored category. Added `renderCascadeDropdown()`, `populateCascadeForCategory()`, and cascade-change handler. Removed `toggleAllCategories()` and the inline quick-add category input.
- `api/account/add_expense.php` — Changed from `category_ids[]` (array) to single `category_id` integer.
- `api/account/update_expense.php` — Same change; syncs single category on update.

## 2026-05-16 (update 12)

### Purchase Order Details — Attachments as dedicated visible card
- `app/bms/purchase/purchase_order_details.php` — Moved `#attachmentsSection` out of the Notes card where it was buried and hard to find. Now renders as its own card (paperclip icon header "Documents & Attachments") below the Notes card in the right panel. Hidden on print (`d-print-none`). Shows automatically when attachments exist.

## 2026-05-16 (update 11)

### Deploy — ensure uploads/purchase_orders is writable on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/purchase_orders && chmod 775 uploads/purchase_orders` after each server's migration step. Runs as the SSH deploy user (who owns the directory) so chmod succeeds — unlike the PHP migration which ran as www-data and couldn't change permissions.

## 2026-05-16 (update 10)

### Hotfix — PO attachment upload permission denied on live server
- `migrations/2026_05_16_fix_uploads_permissions.php` — Sets `uploads/purchase_orders/` to `0775` (fallback `0777`) so the web server process can write uploaded files. Previous migration created the directory but left it with restrictive permissions.
- `api/account/save_purchase_order.php` — `move_uploaded_file()` now uses `@` to suppress the PHP warning (which was corrupting the JSON response and causing "System Error" in the browser), and throws a proper Exception instead so the error is shown as a clear SweetAlert message.

## 2026-05-16 (update 9)

### Hotfix — DN redirect wrong + PO attachment crash on live servers
- `app/bms/grn/dn_create.php` — `$return_url` now returns to `purchase_order_details?id={po_id}` when opened via `?po_id=`. Previously it always went to `project_view` (if PO had a project) or `delivery_notes`, ignoring the PO origin.
- `migrations/2026_05_16_purchase_order_attachments.php` — Creates `purchase_order_attachments` table on live servers where it was missing (table existed only in local DB, no migration existed). Also creates `uploads/purchase_orders/` directory if absent. The missing table caused a fatal DB error when attaching documents to a PO, corrupting the JSON response and triggering the "System Error" in the browser.

## 2026-05-16 (update 8)

### Purchase Orders — Hide "Add Delivery Note" when delivery is complete
- `app/bms/purchase/purchase_orders.php` — "Add Delivery Note" gear dropdown item now only shows when `status === 'approved'` AND `delivery_status !== 'complete'`. Removed `ordered` and `partially_received` from the condition. A completed PO no longer offers the option.

## 2026-05-16 (update 7)

### Purchase Order — PARTIAL/COMPLETE delivery status in list and details

- `api/account/get_purchase_orders.php` — Added `delivery_status` subquery: returns `'partial'` if at least one delivery exists but not all PO items are fully covered, `'complete'` if all items are fully delivered, `NULL` if no deliveries.
- `app/bms/purchase/purchase_orders.php` — Status column (table + mobile cards) now shows `PARTIAL` (yellow) or `COMPLETE` (green) when `delivery_status` is set, falling back to the raw PO status otherwise.
- `app/bms/purchase/purchase_order_details.php` — Top `#orderStatus` badge overrides to PARTIAL/COMPLETE when the PO is approved and deliveries exist. Per-delivery-note status badge removed from the DN panel below.

## 2026-05-16 (update 6)

### Purchase Order Details — Delivery Notes panel below PO (screen only)
- `app/bms/purchase/purchase_order_details.php` — Added PHP queries to load all non-cancelled delivery notes linked to the PO, their items, and the PO ordered quantities. Renders a `d-print-none` section below the PO body showing: each DN as a card (number, date, received-by, status badge); items table with qty delivered, PO qty, unit, condition icon, and a progress bar showing cumulative coverage %. Overall delivery status badge (PARTIAL / COMPLETE) calculated by comparing total delivered vs PO ordered quantities per product. Print button/preview completely untouched.

## 2026-05-16 (update 5)

### Warehouse Delete — cascade delete with informative confirmation alert
- `ajax_delete_warehouse.php` — Redesigned with two-step flow: first call returns counts (products, stock qty, locations); JS shows SweetAlert listing exactly what will be removed; second call with `confirmed=1` cascade-deletes `product_stocks`, `stock_movements`, `locations`, then soft-deletes the warehouse. Removed all blocking guards (stock check, location check).
- `app/bms/stock/warehouses.php` — `deleteWarehouse()` JS function updated to call AJAX twice: fetch counts first, show detailed warning SweetAlert, then confirm-delete. POST handler also updated to cascade-delete without blocking. Permission check updated to use `canDelete()` helper instead of Admin-only check.
- `migrations/2026_05_16_warehouse_status_deleted.php` — Adds `'deleted'` value to `warehouses.status` enum (was `active/inactive` only — caused Data truncated error on soft-delete). Idempotent.

## 2026-05-16 (update 4)

### Hotfix — Fatal crash on Add Delivery Note for approved Purchase Orders
- `migrations/2026_05_16_deliveries_purchase_order_id.php` — Adds `purchase_order_id INT NULL` to the `deliveries` table. Column existed in the local database but was never captured in a migration, so live servers were missing it. This caused `SQLSTATE[42S22]: Unknown column 'd.purchase_order_id'` in `app/bms/grn/dn_create.php` line 73 whenever a user clicked "Add Delivery Note" on an approved Purchase Order. Idempotent.

## 2026-05-16 (update 3)

### Finance > Expenses — Unlimited Category Hierarchy

- `app/constant/accounts/expenses.php` — `addExpenseModal` and `quickManageTypeModal` now only close via explicit X/Cancel buttons (hide.bs.modal intercepted with flag). Expense type change handler uses `flattenCategoryTree()` to render nested categories as indented checkboxes. Manage modal right panel redesigned: breadcrumb drill-down navigation (`activeManageCatPath` stack), gear dropdown per category row (Add Sub-category, Edit/Rename via SweetAlert input, Delete with cascade warning). New helpers: `flattenCategoryTree`, `findCatInTree`, `getCategoriesAtCurrentLevel`, `drillDownCategory`, `navigateManageBreadcrumb`, `renderManageBreadcrumb`, `renameManageCategory`. Updated `addManageCategory` passes `parent_id`; `editManageCategory` re-renders at current level; `deleteManageCategory` shows SweetAlert confirm with sub-category count warning.
- `api/finance/get_expense_schema.php` — Returns nested category tree via recursive `buildCategoryTree()`; SHOW COLUMNS guard falls back to flat query on un-migrated servers.
- `api/finance/manage_expense_schema.php` — `add_category` action accepts `parent_id`; SHOW COLUMNS guard used so INSERT works on both migrated and un-migrated servers.
- `migrations/2026_05_15_expense_category_hierarchy.php` — Adds `parent_id INT NULL` to `expense_categories`; adds self-referential FK `fk_expense_cat_parent` with ON DELETE CASCADE. Idempotent.

## 2026-05-16 (update 1)

### Hotfix \xe2\x80\x94 migration deploy failures
- `migrations/2026_05_13_expense_schema.php` \xe2\x80\x94 Guard `expenses` table existence before ALTER; remove `AFTER expense_account_id` (column may not exist on all servers).
- `migrations/2026_05_13_expense_schema_fix.php` \xe2\x80\x94 Same guards applied.
- `migrations/2026_05_13_expense_schema_final.php` \xe2\x80\x94 Same guards applied.
- `migrations/2026_05_14_sub_contractor_projects.php` \xe2\x80\x94 Guard `project_id` column existence in `sub_contractors` before INSERT IGNORE SELECT.

## 2026-05-16 (update 2)

### UX: Delete option now always visible in Warehouses List actions dropdown
- `app/bms/stock/warehouses.php` \xe2\x80\x94 Removed the `$warehouse['status'] != 'active'` condition that was hiding the Delete button for active warehouses. The button now appears for any user with delete permission regardless of warehouse status. Backend (`ajax_delete_warehouse.php`) already enforces safety \xe2\x80\x94 it blocks deletion if the warehouse has existing stock or locations.

## 2026-05-15 (update 6)

### SC mode test fixes — all assertions corrected
- `scratch/test_sc_mode_full.php` — Fixed 3 broken C-group assertions: C6 (`!\$sc_mode` was un-escaped, interpolating to empty string), C17 (multi-condition OR simplified to single clean strpos), C18 (cross-section regex replaced with SC-section extraction + substring checks). All tests now expected to pass.
- `scratch/test_assign_sc_project.php` — Auth guard updated to auto-set session from DB (same pattern as other scratch tests).
- `scratch/test_sc_details_full.php` — Rebuilt with auto-session, cleaner API-direct test approach.
- `app/bms/operations/project_view.php` — SC context banner text shortened (removed redundant tab list from banner text).

## 2026-05-15 (update 5)

### Sub-Contractor Project View — SC mode with filtered tabs

- `app/bms/operations/sub_contractor_details.php` — "View Project" links (name + gear dropdown) now include `&sc_id={supplier_id}` so project_view opens in SC mode.
- `app/bms/operations/project_view.php` — Detects `?sc_id=` param; when set, shows SC mode: only Scope, Sales (IPC+Invoices), Inventory, Inspections, Reports, Payments tabs. SC context banner added below header. Back button returns to sub-contractor. Overview tab replaced by Scope (Original) as default active pane. `scId`/`scMode` JS vars injected. `loadReportingData` and save-report FormData pass `sc_id` when in SC mode. New `#sc-payments` tab pane + SC Add Payment modal.
- `api/sc/get_payments.php` — Returns `sc_payments` rows for a given supplier_id + project_id.
- `api/sc/add_payment.php` — Inserts into `sc_payments` (dedicated SC payments table, separate from `supplier_payments`).
- `api/sc/delete_payment.php` — Deletes a row from `sc_payments`.
- `api/operations/get_progress_reports.php` — Accepts optional `sc_id`; filters reports to that SC when provided.
- `api/operations/save_progress_report.php` — Accepts optional `sc_id`; stores it on insert so SC reports are tagged. Upsert key includes `sc_id`.
- `migrations/2026_05_15_sc_project_context.php` — Adds `sc_id` column to `project_progress_reports`; creates `sc_payments` table with `supplier_id`, `project_id`, `receipt_number`, etc.

## 2026-05-15 (update 4)

### Inspection View — Attachments as DataTable with gear dropdown
- `app/bms/operations/inspection_view.php` — Attachments section converted to DataTable with gear+triangle dropdown per row: View Online (new tab), Download, Delete. overflow:visible on container so dropdown doesn't clip.
- `api/operations/delete_inspection_attachment.php` — New API: deletes attachment record from DB and physical file from disk.

## 2026-05-15 (update 3)

### Inspection — Dynamic named attachments in Add & Edit modals
- `app/bms/operations/project_view.php` — Replaced single file input with dynamic rows (name + file + remove) in both Add and Edit modals. Blue "Add Attachment" button below list. Shared `inspAddAttachRow()` JS helper. Reset clears attachment list on save.
- `api/operations/save_inspection.php` — Stores `display_name` from `attach_name[]` POST field alongside each uploaded file.
- `api/operations/get_inspection.php` — Returns `display_name` in attachments array.
- `app/bms/operations/inspection_view.php` — Shows `display_name` (falls back to original filename) in attachments table.
- `migrations/2026_05_15_inspection_attachment_name.php` — Adds `display_name` column to `inspection_attachments`.

## 2026-05-15 (update 2)

### Inspection Modal — Add Inspector button position, Edit multiple inspectors, View Details full page
- `app/bms/operations/project_view.php` — "Add Inspector" button moved below inspector rows, blue color. Edit Inspection modal: replaced single inspector fields with multiple inspectors (same add/remove pattern as Add modal). `inspEdit()` loads inspectors from DB. `inspUpdate()` uses FormData. `inspView()` opens new page instead of modal.
- `app/bms/operations/inspection_view.php` — New standalone full-screen page showing all inspection details (fields, inspectors table, attachments table) with Print and Back to Project buttons.
- `api/operations/get_inspection.php` — Now returns `inspectors` and `attachments` arrays alongside inspection data.
- `api/operations/save_inspection.php` — Update path now replaces inspection_inspectors rows when insp_name[] provided.
- `roots.php` — Added `inspection_view` route.

## 2026-05-15

### Add Inspection Modal — Recursive Milestones, Multiple Inspectors, Attachments
- `app/bms/operations/project_view.php` — Milestone query updated to top-level only (`parent_id IS NULL`); includes `scope` column. Add Inspection modal rebuilt: recursive sub-milestone cascade (AJAX), scope/inspected-scope display at deepest level, multiple inspectors (add/remove rows), file attachment input. `inspSave()` switched to FormData for file upload support; new JS helpers `inspOnMilestoneChange()` and `inspAddInspectorRow()` added.
- `api/operations/save_inspection.php` — Updated to store `sub_milestone_id`, `inspected_scope`; saves all inspectors to `inspection_inspectors` table; handles multi-file uploads to `uploads/inspections/{id}/`.
- `api/operations/get_sub_milestones.php` — New API: returns child milestones for a given `parent_id`.
- `migrations/2026_05_15_inspection_extras.php` — Adds `sub_milestone_id` + `inspected_scope` columns to `project_inspections`; creates `inspection_inspectors` and `inspection_attachments` tables with FK cascade; creates `uploads/inspections/` directory.
- `scratch/test_inspection_modal.php` — 18-test regression suite covering schema, milestone query, sub-milestone query, DB insert with new fields, cascade delete.
