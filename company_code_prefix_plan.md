# Company Code Prefix — Implementation Plan & Rollout Tracker

**Goal (from the boss):** every auto-generated code that a user creates *from a form*
should start with a 3-letter company tag and be **sequential, not random**.
Example: `Mufindi Power Services Ltd` → `MPS-NIP-0001`; `BJP Technologies (T) Ltd` → `BTL-NIP-0001`.

**Format:** `PREFIX-TYPE-NNNN` (4-digit min, no date). e.g. `BFS-INV-0001`.

---

## Decisions (confirmed with owner)

1. **New records** → new format immediately, from a gap-free sequence table.
2. **Existing records** → re-code **only when edited & saved while still editable**
   (status not locked). Locked / GL-posted records keep their old code forever.
3. **Prefix** → editable `system_settings.company_code_prefix`, auto-suggested from
   the company name (Company Profile page). Multi-tenant ready.
4. **Excluded:** `JRNL` (no form), warehouse codes (manually typed), POS shift codes,
   and the hierarchical chart-of-accounts codes.

## Why it's safe for existing data / the GL
`journal_entries` link to source documents by the **integer** `(entity_type, entity_id)`,
never by the display code. Re-coding a display code therefore never affects the ledger,
reports, or audit trail. Codes live in real DB columns, so they can be re-stored.

---

## Foundation — DONE ✅

| Item | File | Status |
|---|---|---|
| Sequence + change-log tables, prefix seed | `migrations/2026_06_27_company_code_sequences.php` | ✅ run + idempotent |
| Central generator (`nextCode`, `codeForEdit`, `deriveCompanyPrefix`, `companyCodePrefix`, `logCodeChange`) | `core/code_generator.php` | ✅ tested |
| Editable prefix field + live auto-suggest | `app/constant/settings/company_profile.php` | ✅ |
| Standard updated (no more `MAX(id)+1`) | `.claude/security.md` §18 | ✅ |

**Core guarantees verified by test:** strictly sequential (no gaps), transaction
rollback releases the number, already-converted codes are not re-burned, manual codes
respected, change-log written.

---

## Per-entity rollout

`C` = create endpoint (use `nextCode`), `E` = edit endpoint (use `codeForEdit`, after the
existing status-lock guard). Legacy regex is what `codeForEdit` treats as "auto" to convert.

### Proof of concept — DONE ✅
| Entity | Type | Create file | Edit file | Legacy regex | Status |
|---|---|---|---|---|---|
| NIP Product | NIP | `api/create_nip_product.php` | `api/update_nip_product.php` | `NIP-\d+` | ✅ done + tested |

### Group A — master data (no status lock, always editable) — DONE ✅ (tested)
| Entity | Type | Create file | Edit file | Legacy regex | Status |
|---|---|---|---|---|---|
| Customer | CUST | `api/add_customer.php` | `api/process_edit_customer.php` | `CUST-\d+` | ✅ |
| Supplier | SUP | `api/add_supplier.php` | `api/update_supplier.php` | `SUP\d+` | ✅ |
| Sub-contractor | SBC | `api/add_sub_contractor.php` | `api/update_sub_contractor.php` | `SBC\d+` | ✅ |
| Lead | LEAD | `api/crm/add_lead.php` | `api/crm/edit_lead.php` | `LEAD-\d+` | ✅ |
| Employee | EMP | `api/add_employee.php` (+ `app/bms/pos/employees.php` preview) | `api/update_employee.php` | `EMP-\d+` | ✅ |

**Employee note:** `employee_number` is HR-editable. Create/edit generate the company
code only when the number is blank or matches the auto pattern; a custom number the user
types is honored. Page-load uses `peekNextCode()` (preview only, no number burned).

### Group B — documents (CREATE side done ✅; runtime-tested ML + QT) — edit re-code PENDING
All CREATE paths now use `nextCode()`. Runtime-verified via real endpoints: `create_material_list.php`→BFS-ML-0001, `save_quotation.php`→BFS-QT-0001.

| Entity | Type | Create file (DONE) | Edit re-code (TODO) | Notes |
|---|---|---|---|---|
| Customer Invoice | INV | `save_invoice.php` (+ `operations/create_invoice_from_ipc.php`) | save_invoice update branch | honors manual number; lock once approved/posted |
| Supplier Invoice | SINV | `po_to_supplier_invoice.php` | — | `received_invoices.php` left: supplier's own ref |
| Purchase Order | PO | `save_purchase_order.php` | (same) | |
| GRN | GRN | `approve_dn.php` | `update_grn.php` | |
| Delivery Note | DN | `create_dn.php`, `create_return_dn.php` | `update_dn.php` | hard lock at approved |
| Quotation | QT | `save_quotation.php` (+ `crm/convert_lead.php`) | (same) | hard lock at approved |
| Sales Order | SO | `save_sales_order.php` (+ `convert_quote_to_order.php`) | (same) | |
| Payment | PAY | `record_payment.php` | — | create-only |
| Receipt | RCP | `save_receipt.php` | — | create-only |
| Supplier Payment | SPY | `add_supplier_payment.php`, `suppliers/add_project_payment.php` | — | create-only |
| Customer Advance | ADV | `record_customer_advance.php` | — | create-only |
| Payment Voucher | PV | `save_voucher.php` | (same) | |
| Purchase Return | PR | `save_purchase_return.php` | (same) | |
| Material List | ML | `create_material_list.php` | `update_material_list.php` | no status |
| Stock Adjustment | ADJ | `create_stock_adjustment.php` | `update_adjustment.php` | keeps manual ref override |
| Bulk Adjustment | BULK | `process_bulk_adjustment.php` | — | |
| Bank Transfer | TRF | `add_bank_transfer.php` | — | create-only |
| Revenue | REV | `add_revenue.php` | — | create-only |
| Reconciliation | REC | `create_reconciliation.php` | — | |
| Customer LPO | LPO | `customer/add_lpo.php` | `customer/update_lpo.php` (manual field) | |
| Inspection | INS | `operations/save_inspection.php` | — | now company-wide seq (was per-project) |
| IPC | IPC | `operations/save_ipc.php` | — | now company-wide seq (was per-project) |
| RFQ | RFQ | `create_rfq.php` | `update_rfq.php` | |
| Delivery Order | DO | `create_do.php` | `operations/edit_do.php` | |
| Customer (alt paths) | CUST | `import_customers.php`, `quick_add_customer.php`, `crm/convert_lead.php` | — | |

**Still TODO for Group B:** the EDIT re-code-on-edit hooks for the doc types that have
edit branches (INV, PO, QT, SO, GRN, DN, PR, PV, ML, ADJ, RFQ, DO, LPO) — place
`codeForEdit()` AFTER each endpoint's existing status-lock guard.

### Excluded (intentional)
Journal entries (`JRNL`, no form), Warehouse codes (manual), POS Shift/receipt (POS scope deferred),
Chart-of-Accounts (hierarchical), Payroll (`PAY-YYMM-empid` structured per-employee),
`received_invoices` ref (supplier's own number).

---

## Final phase
- Tests for each modified create/edit endpoint + one real end-to-end create/save per group.
- `php migrations/runner.php` on deploy seeds the live sequences at 0 (first new code = `0001`).
