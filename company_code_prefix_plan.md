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

### Group B — documents (re-code only while unlocked; respect existing guards) — PENDING
| Entity | Type | Create file | Edit file | Notes |
|---|---|---|---|---|
| Customer Invoice | INV | `api/account/save_invoice.php` | (same, update branch) | lock once approved/posted |
| Supplier Invoice | SINV | `api/account/po_to_supplier_invoice.php`, `api/received_invoices.php` | — | |
| Purchase Order | PO | `api/account/save_purchase_order.php` | (same) | |
| GRN | GRN | `api/approve_dn.php` | `api/update_grn.php` | |
| Delivery Note | DN | `api/create_dn.php` | `api/update_dn.php` | hard lock at approved |
| Quotation | QT | `api/account/save_quotation.php` | (same) | hard lock at approved |
| Sales Order | SO | `api/account/save_sales_order.php` | (same) | |
| Payment | PAY | `api/account/record_payment.php` | — | |
| Receipt | RCP | `api/account/save_receipt.php` | — | |
| Supplier Payment | SPY | `api/add_supplier_payment.php` | `api/update_supplier_payment.php` | |
| Customer Advance | ADV | `api/account/record_customer_advance.php` | — | |
| Payment Voucher | PV | `api/account/save_voucher.php` | (same) | |
| Purchase Return | PR | `api/account/save_purchase_return.php` | (same) | |
| Material List | ML | `api/create_material_list.php` | `api/update_material_list.php` | no status |
| Stock Adjustment | ADJ | `api/create_stock_adjustment.php` | `api/update_adjustment.php` | user-overridable ref |
| Bank Transfer | TRF | `api/account/add_bank_transfer.php` | — | |
| Revenue | REV | `api/account/add_revenue.php` | — | |
| Reconciliation | REC | `api/account/create_reconciliation.php` | — | |
| Customer LPO | LPO | `api/customer/add_lpo.php` | — | |
| Inspection | INS | `api/operations/save_inspection.php` | — | |
| IPC | IPC | `api/operations/save_ipc.php` | — | |
| RFQ | RFQ | `api/create_rfq.php` | `api/update_rfq.php` | |
| Delivery Order | DO | `api/create_do.php` | `api/operations/edit_do.php` | |

### Excluded (intentional)
Journal entries (`JRNL`, no form), Warehouse codes (manual), POS Shift (`SH`), Chart-of-Accounts (hierarchical).

---

## Final phase
- Tests for each modified create/edit endpoint + one real end-to-end create/save per group.
- `php migrations/runner.php` on deploy seeds the live sequences at 0 (first new code = `0001`).
