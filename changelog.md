# BMS Changelog

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
