# BMS Changelog

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
