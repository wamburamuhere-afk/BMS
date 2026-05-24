# BMS Security Audit — 2026-05-24

**Scope:** strict access control + activity logging across the whole `app/` tree
and every state-changing `api/` endpoint.
**Premise (your spec):** *Admin holds full authority. Every other user must
receive granular permission via `user_roles.php` → Configure Permissions.
Every action must be registered in `activity_log.php`.*

This report is the result of two automated audits (scripts kept under
`scratch/security_audit.php` and `scratch/activity_log_audit.php`):

| Audit | Files walked | Outcome |
|---|---|---|
| Permissions   | 214 pages under `app/`               | **76 pages with NO permission gate**, **23 pages whose key is missing from the DB**, **77 file→key mappings missing from `getPagePermissionMapping()`** |
| Activity logs | 214 pages + 490 API endpoints        | **53 view pages don't call any log helper**, **101 write APIs never write to `activity_logs`** |

---

## PART A — Permissions gaps (Configure Permissions / `user_roles.php`)

`user_roles.php` reads from the `permissions` table (87 rows today, grouped by
`module_name`) and shows them as checkboxes in **Configure Permissions**. So a
permission can only be *granted* by an admin when **both** of these are true:

1. A row with the page's `page_key` exists in the `permissions` table.
2. The page actually enforces that key (via `autoEnforcePermission()`,
   `requireViewPermission()`, or `canView()`).

If condition 1 is missing — admin can't tick a box for it.
If condition 2 is missing — anyone logged in can open the page regardless of
their role.

### A1. 🔴 Pages with **NO permission gate at all** (anyone can open)

These pages have no `autoEnforcePermission()`, no `requireViewPermission()`, no
`canView()` call. Any logged-in user — even a guest contractor — can hit them.

| Group | Files | Risk |
|---|---|---|
| **Customer Module (8)** | `customers/customer_details.php`, `customer_documents.php`, `customer_groups.php`, `customer_group_details.php`, `customer_group_members.php`, `customer_import.php`, `edit_customer.php` | High — PII, exports |
| **System Settings (12)** | `settings/users.php`, `add_user.php`, `edit_user.php`, `system_settings.php`, `company_profile.php`, `backup_restore.php`, `download_backup.php`, `payment_settings.php`, `tax_settings.php`, `notification_settings.php`, `help.php`, `my_settings.php` | **CRITICAL — admin-tier pages exposed** |
| **Loans (2)** | `loans/loan_application.php`, `loan_details.php` | High — financial creation |
| **Finance / Accounts (10)** | `accounts/expenses.php`, `journals.php`, `add_journal.php`, `edit_journal.php`, `edit_expense.php`, `expense_details.php`, `journal_details.php`, `budget_details.php`, `payment_voucher_details.php`, `payment_voucher_print.php`, `petty_cash_print.php` | High — money paths |
| **Sales create/edit/view (6)** | `sales/sales_order_create.php`, `sales_order_view.php`, `quotations/quotation_create.php`, `quotation_edit.php`, `print_quotation.php`, `print_sales_order.php` | Medium |
| **Inventory adjustments / prints (3)** | `stock/adjustment_print.php`, `print_transfer.php`, `ajax_get_transfer_items.php` | Medium |
| **Operations reports + views (6)** | `operations/project_view.php`, `project_budget_report.php`, `project_financial_report.php`, `project_progress_report.php`, `inspection_view.php`, `warehouse_stock_view.php`, `print_ipc.php` | Medium |
| **Procurement views (3)** | `purchase/purchase_order_details.php`, `purchase_return_view.php`, `print_purchase_return.php` | Medium |
| **GRN/DN views + prints (4)** | `grn/grn_view.php`, `grn_print.php`, `invoice/invoice_view.php`, `invoice_print.php`, `sales_returns/print_sales_return.php` | Medium |
| **Reports (4)** | `reports/delinquency_report.php`, `loan_performance.php`, `loan_portfolio.php`, `repayment_report.php` | Medium |
| **Products / Sub-pages (5)** | `product/product_create.php`, `product_edit.php`, `product_import.php`, `print_barcode.php`, `pos/leave_application.php`, `leave_details.php`, `payslip.php`, `system_status.php` | Medium |
| **Documents (1)** | `document/preview_template.php` | Low |
| **System (1)** | `app/activity_log.php` (the audit log itself!) | **CRITICAL** |

> **Total: 76 pages without a permission gate.**
> Full list in `scratch/security_findings.txt`.

### A2. 🟠 Pages whose enforcement key is **missing from the DB**

These pages *do* call `autoEnforcePermission('xxx')` but the key `xxx` was never
inserted into the `permissions` table. The check therefore **always falls back
to "denied"** for non-admins (good), but admin **cannot grant the right** —
non-admin users are permanently locked out, and the page appears as a broken
link.

| File | Calls key | Action |
|---|---|---|
| `app/bms/Suppliers/supplier_payments.php` | `supplier_payments` | INSERT permission row |
| `app/bms/invoice/reports.php` | `reports` | INSERT or rename to `financial_reports` |
| `app/bms/purchase/nip_materials.php`, `view_material_list.php`, `view_nip_materials.php` | `nip_materials` | INSERT permission row |
| `app/bms/purchase/edit_nip_materials.php` | `purchase` | INSERT or pick existing key |
| `app/constant/accounts/trial_balance.php` | `financial_reports` | INSERT row (5 reports use this key) |
| `app/constant/document/document_library.php`, `e_signatures.php`, `select_document_add_esignature.php` | `documents` | INSERT permission row |
| `app/constant/document/compliance_documents.php` | `compliance` | INSERT or rename to `compliance_documents` |
| `app/constant/document/loan_documents.php` | `loan_documents` | INSERT permission row |
| `app/constant/reports/asset_report.php` | `asset_report` | INSERT permission row |
| `app/constant/reports/audit_report.php` | `financial_reports` | use existing key once row added |
| `app/constant/reports/balance_sheet.php`, `cash_flow.php`, `financial_statements.php`, `trial_balance.php` | `financial_reports` | use existing key once row added |
| `app/constant/reports/compliance_report.php` | `admin` | replace with proper key |
| `app/constant/reports/customer_analysis.php` | `customer_analysis` | INSERT permission row |
| `app/constant/reports/employee_report.php` | `employee_report` | INSERT permission row |
| `app/constant/reports/product_analysis.php` | `product_analysis` | INSERT permission row |
| `app/constant/reports/trends_analysis.php` | `trends_analysis` | INSERT permission row |

> **Total: 23 pages broken because their key was never seeded.**

### A3. 🟡 Orphan keys (DB rows nobody enforces)

The `permissions` table has **30 rows that no page actually reads**. Admin can
tick them in Configure Permissions and they do absolutely nothing.

Examples: `customer_registration`, `customer_groups`, `customer_import`,
`customer_details`, `edit_customer`, `financial_statements`, `balance_sheet`,
`trial_balance`, `document_library`, `e_signatures`, `compliance_documents`,
`payment_reminders`, `collection_letters`, `add_user`, `edit_user`,
`policy_management`, `system_settings`, `notification_settings`,
`customer_feedback`, `quotations`, `inventory_valuation`, `maintenance`,
`cash_flow`, `expense_report`, `profit_loss_report`, `audit_report`,
`company_profile`, `backup_restore`, `tax_settings`, `dashboard`.

> Why this happens: A1 (no gate) and A2 (wrong key in code). Same root cause.
> Fix A1 + A2 → most orphans become wired up automatically.

### A4. 🟡 Module typo — `'procurement'` vs `'Procurement'`

`permissions.module_name` has both `'Procurement'` (capitalized, 5 rows) and
`'procurement'` (lowercase, 3 rows: `dn`, `do`, `rfq`). `user_roles.php` groups
by `module_name`, so the UI shows **two separate Procurement panels** instead
of one.

**Fix (one SQL):**
```sql
UPDATE permissions SET module_name = 'Procurement' WHERE module_name = 'procurement';
```

### A5. 🟡 Blank page name — `received_invoices`

`permissions` row id=135, `page_key='received_invoices'`, `page_name=''`.
Admin sees a blank checkbox in the Finance group.

**Fix:**
```sql
UPDATE permissions SET page_name = 'Received Invoices' WHERE page_key = 'received_invoices';
```

### A6. 🟡 `getPagePermissionMapping()` is out of date

The hardcoded array in `core/permissions.php` (function
`getPagePermissionMapping()`) maps filename → page_key for the auto-enforcement
fallback used when a page is opened via the URL router (`index.php`). **77
files have no entry there.** The fallback is a defence-in-depth layer; without
it, the only guard is the explicit `autoEnforcePermission()` call inside the
page itself — and 76 pages don't have one (see A1).

> Fix scope: extend the array in `core/permissions.php` so every accessible
> file is mapped to a real permission key.

---

## PART B — Activity-log gaps (`activity_log.php`)

`activity_log.php` reads from `activity_logs` (16,137 rows so far — system has
been logging, just not consistently). The canonical writer is
`logActivity($pdo, $user_id, $action, $description)` in `helpers.php`.

### B1. 🟠 View pages that don't log the visit (47 pages)

Pages that include the header (rendered as a full page) but never call
`logActivity()` or `logReportAction()` or `logAudit()`. A non-admin can view
sensitive data and leave no trace.

**Top groups:**

| Module | Page count |
|---|---|
| Operations (sub_contractors, inspections, payrolls) | 4 |
| HR / POS (employees, payroll, leaves) | 6 |
| GRN / DN (create, edit, view) | 7 |
| Purchase (RFQ, nip_materials) | 5 |
| Sales / Suppliers (supplier_details, supplier_categories) | 2 |
| Products / Services | 4 |
| Reports (audit_report, compliance_report, sales_forecast, customer_analysis) | 4 |
| Accounts (bank_reconciliation, trial_balance, cash_register_details) | 4 |
| Documents (workflow, preview, e-signatures landing) | 3 |
| Settings (backup_restore, system_settings, notification_settings) | 3 |
| Other | 5 |

> Full list: `scratch/activity_log_findings.txt` lines 4-49.

### B2. 🔴 Write APIs that never log the write (101 endpoints)

This is the highest-impact gap. Endpoints that perform `INSERT`/`UPDATE`/
`DELETE` but never call `logActivity()` — meaning a user can create, modify,
or delete data and **nothing is written to `activity_logs`**.

**By module:**

| API folder | Missing logs | Severity |
|---|---|---|
| `api/(root)` | 45 | High — most user-facing endpoints |
| `api/operations/` | 21 | **CRITICAL — project, scope, asset, milestone writes** |
| `api/account/` | 17 | **CRITICAL — financial writes (PO, voucher, reconciliation, invoice status)** |
| `api/cash_register/` | 5 | **CRITICAL — cash movements untracked** |
| `api/document/` | 3 | High — signature/document deletes |
| `api/payroll/` | 3 | High — tax bracket / settings changes |
| `api/petty_cash/` | 2 | High — petty cash writes |
| `api/sc/` (sub-contractor) | 2 | High |
| `api/finance/`, `api/helpers/`, `api/pos/` | 1 each | Medium |

**Top concerning endpoints (anyone with the page-level permission can do these silently):**

- `api/account/delete_invoice.php`
- `api/account/delete_purchase_order.php`
- `api/account/delete_voucher.php`
- `api/account/update_invoice_status.php`
- `api/account/update_voucher_status.php`
- `api/account/save_purchase_order.php`
- `api/account/create_reconciliation.php` / `update_reconciliation.php` / `delete_reconciliation.php`
- `api/cash_register/open_shift.php` / `close_shift.php` / `add_transaction.php` / `delete_shift.php`
- `api/petty_cash/save_transaction.php` / `delete_transaction.php`
- `api/operations/save_project.php` / `delete_project.php` / `save_milestones.php` / `save_progress_report.php` / `process_project_payroll.php`
- `api/document/delete_signature.php` / `delete_collateral_document.php`
- `api/delete_supplier.php` / `delete_supplier_payment.php` / `add_supplier_payment.php`
- `api/apply_leave.php` / `approve_leave.php` / `reject_leave.php` / `cancel_leave.php` / `bulk_update_leave_status.php`

> Full list: `scratch/activity_log_findings.txt` lines 52-152.

---

## PART C — Cross-module severity matrix

Pages that fail **both** audits are the most dangerous (open access + silent
writes). The intersection:

| File | No permission gate | Page doesn't log | Has write API that doesn't log |
|---|:-:|:-:|:-:|
| `settings/users.php`, `add_user.php`, `edit_user.php` | ✅ | ✅ | (writes through `api/save_user.php`) |
| `settings/system_settings.php`, `backup_restore.php`, `company_profile.php`, `payment_settings.php`, `tax_settings.php`, `notification_settings.php` | ✅ | mostly ✅ | mostly ✅ |
| `app/activity_log.php` | ✅ | n/a (it IS the log) | n/a |
| `loans/loan_application.php`, `loan_details.php` | ✅ | ✅ | n/a |
| `customer/edit_customer.php`, `customer_import.php`, `customer_documents.php` | ✅ | ✅ | partial |
| `accounts/expenses.php` (the list page) | ✅ | partial | ✅ (`save_voucher.php` etc.) |
| `accounts/add_journal.php`, `edit_journal.php`, `edit_expense.php` | ✅ | ✅ | ✅ |
| `accounts/budget_details.php`, `payment_voucher_details.php` | ✅ | ✅ | ✅ |
| `app/bms/grn/grn_view.php`, `grn_print.php`, `dn_view.php`, `invoice_view.php`, `invoice_print.php` | ✅ | ✅ | n/a (read-only) |
| `app/bms/sales/sales_order_create.php`, `sales_order_view.php`, `quotation_create.php`, `quotation_edit.php` | ✅ | partial | ✅ |
| `operations/project_view.php` (21k lines!) | ✅ | ✅ | ✅ (project APIs unlogged) |

---

## PART D — Recommended fixes (ordered by impact)

### 🔴 P0 — Within this sprint

1. **Add `autoEnforcePermission()` to every page in section A1.**
   76 pages. Mechanical change; one line per file. Same template as
   `app/constant/settings/system_settings.php`.
2. **Add `logActivity()` to every write API in section B2.**
   101 endpoints. The migration is one line after each successful
   `INSERT`/`UPDATE`/`DELETE`.
3. **Lock down `app/activity_log.php` itself.**
   Currently anyone can read the entire audit trail.
4. **Lock down `app/constant/settings/users.php` + `add_user.php` + `edit_user.php`.**
   Currently anyone can open these and create accounts.

### 🟠 P1 — Next sprint

5. **Seed the 23 missing permission keys (A2)** so admin can grant them.
6. **Fix the module-name typo (A4)** so the UI groups all Procurement together.
7. **Fix `received_invoices` blank label (A5).**
8. **Update `getPagePermissionMapping()` (A6)** to cover the 77 files it misses,
   giving every file a routing-layer permission fallback.

### 🟡 P2 — Cleanup

9. **Resolve A3 orphan keys.** Once A1 + A2 are done, re-run the audit; any
   orphan still left should be deleted from the DB to keep Configure Permissions
   clean.
10. **Add `logActivity()` to the 47 view pages (B1)** so read-access is also
    audited.

---

## Reproducibility

To re-run these audits at any point:

```bash
php scratch/security_audit.php       > scratch/security_findings.txt
php scratch/activity_log_audit.php   > scratch/activity_log_findings.txt
```

Both scripts are deterministic and only read code + DB schema — no writes.

---

## Two-line takeaway

- **76 pages and 101 write APIs are currently outside the control plane.** A
  non-admin user with even one valid login can reach financial deletes, user
  management, system settings, and the audit log itself — and most of those
  writes leave no trace.
- The fix is mechanical, not architectural: add `autoEnforcePermission()` to
  the 76 pages, add `logActivity()` to the 101 APIs, seed the 23 missing keys,
  fix the module typo. After that, Configure Permissions in `user_roles.php`
  becomes the **single source of truth** you wanted from the start.
