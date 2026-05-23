# BMS — Internal View / Detail Page Print Standard (`view.md`)

**What this covers:** embedded print on internal entity detail pages
(`*_view.php`, `*_details.php`) that print company records for internal use —
products, employees, warehouses, expenses, suppliers, customers, accounts, etc.

**What this does NOT cover:**
- Standalone transactional print pages (`print_*.php`, `*_print.php`) → `i_e_print.md`
- View pages for transactional documents sent to/received from customers or suppliers
  (quotation_view, sales_order_view, invoice_view, grn_view, dn_view, purchase_order_details, etc.)
- `Projects › Project Details › Milestones / Reporting / Reports` tabs

**The company logo and company name header are already provided by the shared
`includeHeader()` external file. Do NOT re-add or alter the header.**

---

## Changes Required — Two Things Only

### 1. Canonical `@page` margin

Every file that has a `@media print` block must contain exactly:

```css
@page { margin: 10mm 8mm 16mm 8mm; }
```

Place it **outside** `@media print` (directly in the `<style>` block) so it applies globally.
If `@page` already exists with different values, change it to match. If no `@page` exists, add it.

The 16mm bottom is mandatory — it reserves space for the shared fixed footer.

### 2. Shared footer — replace internal, add external

#### 2a. Remove internal footer if present

Delete any of these patterns if found in the file:

| Pattern | Where |
|---------|-------|
| `.bms-print-footer { ... }` CSS rule | `<style>` block |
| `<div class="bms-print-footer">...</div>` HTML | Body |
| `.footer { position: fixed; bottom: 0; ... }` | `<style>` block |
| Any PHP block computing `$printed_by`, `$printed_role`, `$printed_at` locally | PHP section |

#### 2b. Add shared footer CSS — in `<style>`, last line before `</style>`

```php
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
```

#### 2c. Add shared footer HTML — immediately before `<?php includeFooter(); ?>`

```php
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php includeFooter(); ?>
```

---

## Files in Scope

Files with print functionality that need normalising (19 files):

| # | File | @page correct | Internal footer | Action |
|---|------|--------------|-----------------|--------|
| 1 | `app/bms/product/product_view.php` | wrong | none | fix @page, add footer |
| 2 | `app/bms/product/service_view.php` | missing | none | add @page, add footer |
| 3 | `app/bms/stock/warehouse_view.php` | missing | none | add @page, add footer |
| 4 | `app/bms/operations/warehouse_stock_view.php` | wrong | yes | fix @page, remove internal, add footer |
| 5 | `app/constant/accounts/expense_details.php` | wrong | yes | fix @page, remove internal, add footer |
| 6 | `app/bms/Suppliers/supplier_details.php` | wrong | none | fix @page, add footer |
| 7 | `app/bms/customer/customer_details.php` | wrong | yes | fix @page, remove internal, add footer |
| 8 | `app/bms/pos/employee_details.php` | wrong | none | fix @page, add footer |
| 9 | `app/bms/pos/payroll_details.php` | wrong | none | fix @page, add footer |
| 10 | `app/constant/accounts/transaction_details.php` | missing | none | add @page, add footer |
| 11 | `app/constant/accounts/journal_details.php` | missing | none | add @page, add footer |
| 12 | `app/constant/accounts/budget_details.php` | wrong | none | fix @page, add footer |
| 13 | `app/constant/accounts/cash_register_details.php` | wrong | none | fix @page, add footer |
| 14 | `app/constant/accounts/reconciliation_details.php` | missing | none | add @page, add footer |
| 15 | `app/constant/accounts/account_details.php` | missing | none | add @page, add footer |
| 16 | `app/bms/operations/inspection_view.php` | missing | none | add @page, add footer |
| 17 | `app/bms/operations/project_view.php` | wrong | none | fix @page, add footer |
| 18 | `app/bms/operations/sub_contractor_details.php` | wrong | none | fix @page, add footer |
| 19 | `app/bms/tenders/tender_view.php` | missing | none | add @page, add footer |

Files skipped (no print button at all — nothing to normalise):
- `app/bms/customer/customer_group_details.php`
- `app/bms/pos/leave_details.php`
- `app/bms/loans/loan_details.php`
- `app/constant/accounts/payment_voucher_details.php`

---

## Compliance Checklist Per File

- [ ] `@page { margin: 10mm 8mm 16mm 8mm; }` present in `<style>` block
- [ ] No internal `.bms-print-footer` CSS rule
- [ ] No internal `.bms-print-footer` HTML div
- [ ] No local PHP footer variable block (`$printed_by`, `$printed_role`, `$printed_at`)
- [ ] `print_footer_css.php` included (in `<style>` context or immediately after it)
- [ ] `print_footer_html.php` included immediately before `includeFooter()`
