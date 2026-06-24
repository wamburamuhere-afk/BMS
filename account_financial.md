# Account & Financial Integrity Audit

For every money flow, the chain is verified end-to-end:

1. **Which account(s) are hit as Debit / Credit** (per lifecycle stage).
2. **Does it really post** to the canonical ledger (`journal_entries` + `journal_entry_items`, `status='posted'`)?
3. **Is each entry balanced** (Σ Dr = Σ Cr on the same entry)? → if yes, Trial Balance & Balance Sheet balance too.
4. **Deletion / void:** if the source is deleted/voided, are the ledger + dependent tables + reports unwound?

## How posting reaches the ledger (two writers, one ledger)

- **`postLedgerEntry()`** → writes **directly** to `journal_entries` with a specific `entity_type` (`invoice`, `invoice_cogs`, `payment`, `grn`, `supplier_invoice`, `expense_accrual`, `voucher_accrual`, …). Enforces Σ Dr = Σ Cr before write.
- **`recordGlobalTransaction()`** (used by `postOutflow` / `postInflow` / payroll / bank transfer / manual journals) → writes the legacy `transactions` + `books_transactions`, **mirrored** into `journal_entries` as `entity_type='books_transaction'`. Also balanced.

**Proven baseline (whole posted ledger):** Σ Dr = Σ Cr = 2,445,152,126,266.56 — **0 unbalanced, 0 single-sided** entries, across **every** entity_type. So per-entry Dr=Cr is guaranteed; this audit is about **coverage** (does each stage — including delete — post?) and **economic completeness** (VAT legs).

> The legacy `accounts.opening_balance` field is unbalanced (a local-only 12,000 on inactive "Opening Balance Equity"); canonical reports ignore it (journal-only) and balance. Out of scope here.

---

## Status summary

| # | Flow | Posts to GL? | Dr=Cr? | Delete/void unwinds? | Verdict |
|---|------|:---:|:---:|:---:|---|
| 1 | Expenses | ✅ | ✅ | ✅ | **Fixed (2026-06-23)** — `delete_expense.php` now calls `reverseExpenseAccrual()` for an accrued, unpaid expense; paid stays locked. Test 18/0. |
| 2 | Sales (invoices) | ✅ | ✅ | ✅ | **Fixed (2026-06-23)** — `delete_invoice.php` blocks delete of an invoice with posted, un-reversed revenue or payments (cancel first, which reverses the GL); drafts still delete. Test 12/0. |
| 3 | Credit note | ✅ | ✅ | ✅ (paid locked) | **Gap:** Output VAT not reversed; inventory restock unconfirmed |
| 4 | Received invoice | ✅ | ✅ | ✅ (paid guarded) | **Complete — leave it** |
| 5 | Debit note | ✅ (new) | ✅ | ⚠️ | **Gap:** Input VAT not reversed; 8 legacy settlements off-book |
| 6 | Payment voucher | ✅ | ✅ | ✅ | **Fixed (2026-06-23)** — `delete_voucher.php` reverses the accrual for an approved-unpaid voucher and LOCKS vouchers with payments (was a bare DELETE). Test 19/0. |
| 7 | Payroll | ✅ | ✅ | ✅ | **Complete (verified 2026-06-23)** — statutory-remittance flow EXISTS (`api/remit_statutory.php`: Dr PAYE/NSSF/SDL Payable / Cr Bank); `delete_payroll.php` reverses both accrual + payment. Tests 58/0 + 13/0, ledger balanced. (Audit note was outdated.) Only a misleading docblock comment in remit_statutory.php (says SDL→Expense, code correctly uses SDL Payable). |
| 8 | Adjustments (manual journal) | ✅ | ✅ (validated) | ✅ (void_journal) | **Complete — minor hardening only** |
| 9 | Bank transfer | ✅ | ✅ | ⚠️ verify | **Gap (likely):** void deletes legacy rows but may leave journal mirror |

Legend: ✅ implemented & verified · ⚠️ gap / needs fix.

---

## 1. Expenses — ⚠️ delete of an accrued expense

**Code:** `core/expense_posting.php`, `api/account/update_expense_status.php`, `api/account/delete_expense.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Approved | Expense (P&L) | Accrued Expenses (2-1500) | `expense_accrual` |
| Paid (accrued) | Accrued Expenses | Bank | `books_transaction` |
| Paid + WHT | Expense (gross) | Bank (net) + WHT Payable | `books_transaction` |
| Paid (payroll-linked) | Salaries Payable | Bank | `books_transaction` |
| Reject before pay | Accrued Expenses | Expense | `expense_accrual_void` |
| Paid → rejected (void) | reverse outflow + bank register + restore invoice/payroll | | — |

Dr=Cr: ✅ (data: `expense_accrual` all balanced). Paid expense is **locked from delete** (void first). ✅

**GAP:** an `approved` (accrued) but unpaid expense has an accrual in the GL but **no `transaction_id`**; `delete_expense.php` reverses only when `transaction_id` is set and never calls `reverseExpenseAccrual` → deleting it **orphans** `Dr Expense / Cr Accrued` → P&L expense + accrued liability overstated.

**Fix:** in `delete_expense.php`, before delete: if `expenseIsAccrued()` and not paid → call `reverseExpenseAccrual()` (idempotent). Keep the paid-delete lock. Test: create→approve→delete asserts `expense_accrual_void` exists + nets to zero + ledger balanced.

---

## 2. Sales (invoices) — ⚠️ delete doesn't reverse the GL

**Code:** `core/revenue_posting.php`, `core/sales_posting.php` (POS), `api/account/update_invoice_status.php`, `api/account/record_payment.php`, `api/account/delete_invoice.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Approved — revenue | Accounts Receivable | Sales Revenue (net) + Output VAT | `invoice` |
| Approved — cost | COGS | Inventory | `invoice_cogs` |
| Collected | Bank (net) + WHT Receivable | Accounts Receivable | `payment` |
| Cancelled | reverse of revenue + COGS | | `invoice_void` |
| POS sale/return | Cash/AR + COGS contras | Revenue + VAT / Inventory | `pos_sale`/`pos_cogs`/`pos_return*` |

Dr=Cr: ✅ (invoice 15, invoice_cogs 5, pos_sale 39, pos_cogs 27, payment 33 — all balanced). **Cancel** reverses the GL correctly (migration `2026_06_18`).

**GAP:** `delete_invoice.php` calls only the **legacy** `reverseOutputVat()` (current_balance) — **not** `reverseInvoiceRevenue`/`reverseInvoiceCOGS` — and has **no status guard**. Deleting an approved invoice orphans `Dr AR / Cr Revenue / Cr Output VAT` + `Dr COGS / Cr Inventory`; a paid one also leaves its `payment` entry dangling.

**Fix:** block delete of any invoice with posted revenue/payments → require **cancel-first** (already reverses the GL). Allow hard-delete only for drafts. Test: approved invoice → delete blocked/reversed; draft → clean delete; ledger balanced.

---

## 3. Credit note (sales return refund) — ⚠️ Output VAT not reversed

**Code:** `api/sales/create_credit_note.php`, `api/sales/pay_credit_note.php`, `api/sales/delete_credit_note.php` (built from an approved **sales return**)

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Create (pending) | — (no GL) | — | — |
| Refund (paid) | Sales Returns & Allowances (contra-revenue) | Bank/Cash | `credit_note_refund` (mirror) |
| Delete | soft-delete; **paid is locked** (reverse payment first) | | — |

Dr=Cr: ✅ (2-line balanced via `postOutflow`). Delete is correctly guarded. ✅

**GAPs:**
1. **Output VAT not reversed.** The refund debits Sales Returns by the **gross** `grand_total` (incl. VAT) but never reduces **Output VAT Payable** → after refunding a VAT sale you still owe TRA the VAT. (Mirror of the debit-note VAT gap.)
2. **Inventory restock unconfirmed.** A customer returning goods should restock (`Dr Inventory / Cr COGS`). The refund posts no cost leg; whether the linked **sales return** posts the restock is not yet confirmed — to verify in the fix phase.

**Fix:** split the refund — `Dr Sales Returns (net) / Dr Output VAT (tax) / Cr Bank (gross)`; and ensure the sales-return path restocks `Dr Inventory / Cr COGS`. Test: VAT credit note reduces Output VAT; inventory restored; ledger balanced.

---

## 4. Received invoice (supplier/sub-contractor bill) — ✅ complete (leave it)

**Code:** `core/purchase_posting.php`, `api/received_invoices.php`, supplier-payment paths (`postOutflow`)

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| GRN (goods in) | Inventory | Accounts Payable | `grn` |
| Goods bill approved | Inventory/Cost (net) + **Input VAT** | Accounts Payable (gross) | `supplier_invoice` |
| Sub-contractor bill | COGS (net) + **Input VAT** | Accounts Payable (gross) | `subcontractor_invoice` |
| Paid | Accounts Payable | Bank (net) + WHT Payable | `books_transaction` |
| Deleted / pushed back | reverse accrual (Dr AP / Cr cost+VAT) | | reversal |

Dr=Cr: ✅ (supplier_invoice 93, grn 4 — all balanced). Input VAT correctly split out of cost (Phase 1–3). Delete guarded by `supplierInvoiceHasPayments()` (blocks delete once paid) + `reverseGoodsInvoiceAccrual`/`reverseSubcontractorAccrual`.

**Verdict: fully implemented — no fix.** (This is the reference standard the other flows should match.)

---

## 5. Debit note (supplier return refund) — ⚠️ Input VAT + legacy off-book

**Code:** `api/purchase/create_debit_note.php`, `api/purchase/pay_debit_note.php` (built from an approved **purchase return**)

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Purchase return | Accounts Payable | Inventory | `purchase_return` |
| Refund (paid) | Bank/Cash | Accounts Payable | `debit_note_refund` |
| Delete | soft-delete; paid locked | | — |

Dr=Cr: ✅ per entry. **GAPs:** (a) **Input VAT not reversed** on returns/debit notes (0 VAT lines in data; latent for VAT notes); (b) **8 settled debit notes (≈931.5M) have 0 ledger entries** — legacy/off-book; (c) with VAT, return moves *net* while refund credits AP *gross* → AP residual.

**Fix:** add `Cr Input VAT` reversal to the purchase-return/debit-note posting; backfill the 8 off-book settlements (criteria-based, idempotent, balance-checked).

---

## 6. Payment voucher — ⚠️ delete of an accrued voucher (verify)

**Code:** `core/expense_posting.php` (voucher wrappers), `api/account/record_voucher_payment.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Approved | Expense | Accrued Expenses | `voucher_accrual` |
| Paid (accrued) | Accrued Expenses | Bank | `books_transaction` |
| Paid (direct) | Expense | Bank | `books_transaction` |
| Reject before pay | Accrued Expenses | Expense | `voucher_accrual_void` |

Dr=Cr: ✅ (voucher_accrual 4 — balanced). Payment supports partials, fails loudly. ✅

**GAP (same shape as #1, to confirm):** verify the voucher **delete** path calls `reverseVoucherAccrual` for an approved-but-unpaid voucher; otherwise the accrual is orphaned on delete.

**Fix:** mirror the expense fix in the voucher delete endpoint (reverse `voucher_accrual` before delete; lock delete once paid).

---

## 7. Payroll — ⚠️ statutory remittance + delete-of-accrual

**Code:** `core/payment_source.php` (`postPayrollAccrual` / `postPayrollPayment` / `postSdlAccrual`), `api/update_payroll_status.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Approved (accrual) | Salaries Expense (gross) | PAYE Payable + NSSF Payable + AP (other) + Salaries Payable (net) | `payroll_accrual`→mirror |
| SDL accrual | SDL Expense | SDL Payable | `sdl_accrual`→mirror |
| Paid (net, partial ok) | Salaries Payable | Bank | `payroll`→mirror |
| Void (paid→rejected) | `reverseJournalBalances` (both legs) | | — |

Dr=Cr: ✅ (gross = ΣΣ statutory + net by construction). Partial payments supported; payment fails loudly. ✅

**GAPs:** (1) **No statutory-remittance flow** — PAYE / NSSF / SDL Payable are credited at accrual but there is no "pay TRA/NSSF" entry (`Dr PAYE/NSSF/SDL Payable / Cr Bank`), so those liabilities accumulate forever. (2) Confirm that **deleting** an approved-but-unpaid payslip reverses its accrual (`accrual_transaction_id` via `reverseJournalBalances`).

**Fix:** add a statutory-remittance posting (settle PAYE/NSSF/SDL payables to bank); ensure payroll delete/void reverses the accrual.

---

## 8. Adjustments (manual journal) — ✅ complete (minor hardening)

**Code:** `api/account/save_journal.php`, `add_compound_journal.php`, `update_journal.php`, `void_journal.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Create | user-chosen debit account(s) | user-chosen credit account(s) | `(manual/null)` (+ mirror) |
| Void | `void_journal.php` reverses | | — |

Dr=Cr: ✅ — `save_journal.php` **rejects an unbalanced entry** (`"Journal entry is not balanced"`) before writing; writes `journal_entries`+items directly and mirrors to `books_transactions` (`skip_journal_mirror=true`, no double count).

**Minor hardening (not a balance gap):** it inserts lines directly instead of via `postLedgerEntry`, so it skips that helper's guards (account-exists, amount>0, ≥2 lines). Low risk. Optional: route through `postLedgerEntry` for one validation path.

**Verdict: implemented.** Confirm `void_journal.php` reverses both the journal + mirror.

---

## 9. Bank transfer — ⚠️ void may leave the journal mirror

**Code:** `api/account/update_bank_transfer_status.php`

| Stage | Dr | Cr | `entity_type` |
|---|---|---|---|
| Posted | Destination (amount) + Charge account (charges) | Source (amount+charges) | `transfer`→mirror |
| Void (posted→rejected) | restore both balances + delete `books_transactions`/`transactions` + reverse 2 register rows | | — |

Dr=Cr: ✅ (balanced 3-leg entry; two cash balances moved; two register rows). Posting is **complete**.

**GAP (to confirm):** the void path deletes `books_transactions` + `transactions` but does **not** appear to call `unmirrorTransactionFromJournal()` — so the **mirrored `journal_entries` row may remain**, and since reports read `journal_entries`, a voided transfer could still show. (Contrast: `reverseOutflow` *does* unmirror.)

**Fix:** in the void branch, unmirror/remove the `journal_entries` mirror (or post a balanced reversing entry instead of hard-delete) so canonical reports drop the voided transfer. Test: post transfer → void → assert it's gone from `journal_entries` + both balances restored + ledger balanced.

---

## Deletion principle (applies to all flows)

Your rule — *"if data is deleted from source, the accounts, tables and reports must be reduced too"* — is the **common thread** of the gaps: forward posting and cancel/void are generally correct, but several **hard-delete** paths bypass the canonical reversal (#1, #2, #6, #9) or omit a leg (#3, #5 VAT). The repeating fix shape is: **block delete of a posted/paid document (force cancel/void, which already reverses), or reverse-on-delete** — never a bare row delete.

## Suggested fix order (by impact)
1. **#2 invoice delete** (revenue/COGS orphaning — largest figures) ·
2. **#1 expense delete** + **#6 voucher delete** (shared accrual-reversal fix) ·
3. **#5 debit-note VAT + backfill** & **#3 credit-note VAT** (Tanzania VAT compliance) ·
4. **#7 statutory remittance** ·
5. **#9 bank-transfer void mirror.**
Each fix: criteria-based, idempotent, with a before/after ledger-balance assertion and a CLI test.

_Audit complete for all 9 flows. No code changed — this is the plan. Implementation to follow on approval, one flow at a time._

---

# Additional flows — 6-part PROOF-BY-TRACE (researched 2026-06-23)

> Scouted in the **6 parts requested** (not the 4-check layout above). Every answer is **proven by trace**
> to the live code (file:line) — no assertion. The closing **"Gap location"** line names which of the 6
> parts is the gap. **Fixes are deliberately omitted here** — today is the record/diagnosis; the fix plan
> is written next, on approval. After that we re-scout flows #1–#9 through these same 6 parts.
>
> **The 6 parts:** (1) affects the account on **two sides at once** (double entry)? · (2) **under which
> condition**? · (3) **how many accounts → `journal_entries`** per transaction? · (4) **reports affected**
> per transaction? · (5) **which report(s)**? · (6) on **source delete**, do reports **react/reverse**?

## 10. Cash register — `api/cash_register/{open_shift,add_transaction,close_shift,update_shift,delete_shift}.php`

1. **Two-sided?** ❌ **No.** *Proof:* none of the 5 endpoints reference `journal_entries` / `books_transactions` / `postLedgerEntry` / `recordGlobalTransaction`. `add_transaction.php:38-51` only `INSERT cash_register_transactions`; `open_shift.php:35-40` only `INSERT cash_register_shifts`; `close_shift.php:41-54` only `UPDATE` the shift.
2. **Under which condition?** *Proof:* never — there is no posting branch. `close_shift.php:37-38` computes `cash_difference` (over/short) but only **stores** it (`:41-54`); it is never posted.
3. **How many accounts → `journal_entries`?** **0.**
4. **Reports affected?** **No.**
5. **Which report(s)?** **None** — Trial Balance, Income Statement, Balance Sheet, Cash Flow all untouched. The drawer cash never becomes a GL asset.
6. **Delete → reports react?** **N/A.** *Proof:* `delete_shift.php:35-40` deletes the transactions + shift and detaches POS sales (`:31-32`, `shift_id = NULL`); reports don't change because nothing was ever posted.

**→ Gap location: structural — ALL 6 parts (no posting at all).** Operational-only by design. Wiring a Cash Drawer GL account here **alone** would be one-sided (POS sales don't post yet); must land **with** the POS-GL initiative.

## 11. Petty cash — `api/petty_cash/save_transaction.php`, `delete_transaction.php`; engine `core/payment_source.php`

1. **Two-sided?** ✅ **Yes** (on save). *Proof:* `postPettyCashLedger()` — expense leg `postOutflow(Dr expense / Cr petty cash)` (`payment_source.php:618-624`); top-up leg `journal_items [Dr pettyId, Cr sourceId]` (`payment_source.php:628-642`).
2. **Under which condition?** *Proof:* `amount > 0` (`save_transaction.php:50`) **+** fund resolves or it throws (`:109-112`) **+** expense needs `expense_account_id` / top-up needs `source_account_id` (`:53-58`); a null post **rolls back** (`:182-184`, `:226-228`).
3. **How many accounts → `journal_entries`?** **2.** *Proof:* both branches emit 2 legs, written via `recordGlobalTransaction` → mirrored into `journal_entries` as `entity_type='books_transaction'` (`transaction_helper.php:199-237`).
4. **Reports affected?** ✅ **Yes** (on save).
5. **Which report(s)?** *Proof from the leg accounts:* **expense** (`Dr Expense / Cr Petty Cash`) → Trial Balance + **Income Statement** + Balance Sheet + Cash Flow; **top-up** (`Dr Petty Cash / Cr Bank`) → Trial Balance + Balance Sheet + Cash Flow, **not** Income Statement (asset↔asset).
6. **Delete → reports react?** ❌ **No.** *Proof:* `delete_transaction.php:27` is a bare `DELETE FROM petty_cash_transactions` — never calls `reversePettyCashLedger()`, never even reads `type`/`transaction_id`. The `journal_entries` mirror + legacy `transactions`/`books_transactions` + `current_balance` are all left behind → reports do **not** react. *(Contrast: the update path reverses correctly — `save_transaction.php:179`.)*

**→ Gap location: Part 6 (delete/void). Parts 1–5 pass.**

## 12. Received payment — TWO distinct code paths

### 12a. Customer receipt — `api/account/record_payment.php` (single) · `api/account/save_receipt.php` (multi); engine `core/money_in_posting.php`

1. **Two-sided?** ✅ **Yes.** *Proof:* `postPaymentReceived → postDepositEntry` builds `Dr bank [+ Dr WHT] / Cr AR` (`money_in_posting.php:69-75`) and posts **directly** via `postLedgerEntry`.
2. **Under which condition?** *Proof:* payment `status='completed'` (`record_payment.php:84-86`, `:201`); received-into account mandatory + active (`requireCashBankAccount` `:85`; `gl_account_active` `money_in_posting.php:59`); **idempotent** on (`payment`,`payment_id`) (`money_in_posting.php:50-55`); post failure **rolls back** (`record_payment.php:219-221`).
3. **How many accounts → `journal_entries`?** **2** (`Dr Received-Into / Cr AR`), or **3** with WHT (`Dr Bank net + Dr WHT Receivable / Cr AR gross`) — `money_in_posting.php:69-73`.
4. **Reports affected?** ✅ **Yes.**
5. **Which report(s)?** Trial Balance + **Balance Sheet** (Bank ↑, AR ↓; WHT Receivable ↑) + **Cash Flow** (operating inflow). **NOT Income Statement** — revenue was recognised at invoice approval (`entity_type='invoice'`). Also writes a bank-register deposit row (`record_payment.php:227-234`; `save_receipt.php:136-137`).
6. **Delete → reports react?** ❌ **No path exists.** *Proof:* there is **no** customer-payment void/delete endpoint (only `api/delete_supplier_payment.php` + `api/sc/delete_payment.php`); `money_in_posting.php:19` says *"Reversal = a contra entry, handled by the caller on void"* — **but no caller exists.** No orphan risk (invoice delete is blocked while a completed payment exists), but **no correction path** for a mis-keyed receipt.

**→ Gap location: Part 6 (no void/reverse path). Parts 1–5 pass.**

### 12b. POS credit-sale settlement — `api/pos/receive_payment.php`

1. **Two-sided?** ❌ **No.** *Proof:* header `:11-13` "no GL posting"; body inserts `pos_sale_payments` (`:53-55`), updates `pos_sales.payment_status` (`:61-62`), and for cash drops a `cash_in` row into `cash_register_transactions` (`:69-72`).
2. **Under which condition?** *Proof:* never posts to the GL.
3. **How many accounts → `journal_entries`?** **0.**
4. **Reports affected?** **No.**
5. **Which report(s)?** **None.** Because the POS sale itself never posts AR (`money.md` IN-5 ❌), the whole POS credit cycle (sale → AR → settlement) is invisible to the books.
6. **Delete → reports react?** **N/A** — nothing was posted.

**→ Gap location: structural — ALL 6 parts (no posting).** Belongs to the POS-GL initiative; the settlement must **not** be wired alone (it would credit an AR that was never debited).

---

_Researched batch recorded (flows #10–#12) in the 6-part proof-by-trace format. No code changed. Next, on
approval: write the fix plan for the gaps above, then re-scout flows #1–#9 through the same 6 parts._

---

# FIX PLAN — flows #10–#12 (approved 2026-06-23)

Two are **actionable now** (#11 petty-cash delete, #12a customer-payment void). Two are **structural** and must
ride the POS-GL initiative (#10 cash register, #12b POS settlement) — wiring either alone makes a one-sided
account. Each now-fix is **criteria-based + idempotent** (no hard-coded ids, targets the live DB), wrapped in
ONE transaction, and signed off with `assertLedgerBalanced()` (Σ Dr = Σ Cr) + a CLI test.

## FIX 11 — Petty-cash delete must reverse the ledger  *(HIGH — live data corruption today)*

**Gap (Part 6):** `api/petty_cash/delete_transaction.php:27` is a bare `DELETE` — the mirror + legacy rows +
`current_balance` are orphaned.

**A. Endpoint rewrite — `api/petty_cash/delete_transaction.php`**
1. `require_once core/payment_source.php` (for `reversePettyCashLedger`). Keep `canDelete('petty_cash')`.
2. Read the row first: `SELECT type, transaction_id, receipt_file FROM petty_cash_transactions WHERE id=?`; 404 if missing.
3. `beginTransaction()` →
   - `reversePettyCashLedger($pdo, (string)$type, (int)$transaction_id)` — routes `deposit→reverseJournalBalances` (both legs) / `expense→reverseOutflow` (source leg); each **unmirrors `journal_entries`**, deletes `books_transactions`+`transactions`, and restores `current_balance`.
   - `DELETE FROM petty_cash_transactions WHERE id=?`.
   - `commit()`.
4. After commit: `@unlink` the `uploads/finance/petty_cash/<receipt_file>`; `logActivity`.
   *(Mirrors the existing UPDATE path, which already reverses correctly at `save_transaction.php:179`.)*

**B. Remediation migration (heal existing orphans) — `migrations/2026_06_23_petty_cash_delete_orphan_heal.php`**
- **Criteria:** rows in `transactions` with `transaction_type IN ('petty_cash','petty_cash_topup')` whose
  `transaction_id` is **not** referenced by any surviving `petty_cash_transactions.transaction_id`.
- For each: derive type (`petty_cash→expense`, `petty_cash_topup→deposit`) and call the same reversal; **idempotent**
  (skip if mirror/rows already gone); print a before/after `assertLedgerBalanced()`. No hard-coded ids.

**C. Test — `tests/test_petty_cash_delete_reversal_cli.php`**
- Expense **and** top-up: create → assert posted + balances moved → delete → assert `journal_entries` mirror gone,
  `transactions`/`books_transactions` gone, `current_balance` restored, **ledger balanced**.

## FIX 12a — Customer-payment void/reverse  *(MEDIUM — missing correction path)*

**Gap (Part 6):** no endpoint reverses a posted customer receipt (`entity_type='payment'`).

**A. Helper — `core/money_in_posting.php::reversePaymentReceived($pdo, $paymentId, $userId)`**
- Find the posted entry (`entity_type='payment'`, `entity_id=$paymentId`); if none / already reversed → idempotent no-op.
- Post a balanced **contra** via `postLedgerEntry` (`entity_type='payment_void'`, `reverses_entry_id` → original):
  `Dr Accounts Receivable / Cr Received-Into Bank [+ Cr WHT Receivable]` (legs flipped from the original).

**B. Endpoint — `api/account/void_payment.php`** (gate `canVoid('invoices')`; CSRF; POST)
1. `assertScopeForRecord('invoices', …)` for the linked invoice(s); load the payment (must be `completed`).
2. `beginTransaction()` → `reversePaymentReceived()` → set `payments.status='cancelled'` →
   recompute the invoice(s) `paid_amount`/`balance_due`/`status` (back to `partial`/`approved`/`overdue`) — for
   `save_receipt` receipts, walk `payment_allocations` → reverse the bank-register deposit
   (`recordBankTransaction(... 'withdrawal' ...)` for the net cash) → `commit()`. `logActivity`+`logAudit`.

**C. UI wiring**
- Add a **Void** action wherever payments are listed (invoice view / received-payment history), shown only when
  `canVoid('invoices')` and `status='completed'`, with a SweetAlert confirm.

**D. Test — `tests/test_payment_void_cli.php`**
- invoice → approve → record payment (assert AR ↓, Bank ↑) → void → assert contra posted with `reverses_entry_id`,
  AR + Bank restored, invoice status reverted, **ledger balanced**; re-void is a no-op (idempotent).

## FIX 10 + 12b — Cash register & POS credit settlement  *(STRUCTURAL — fold into POS-GL)*

Not standalone fixes — they belong to `double_entry_integration_plan.md` **P-GL-2** / `money.md` **IN-5**. Defining
the target so it is ready:
- **POS cash sale** → `Dr Cash Drawer / Cr Sales / Cr Output VAT` **and** `Dr COGS / Cr Inventory`.
- **POS credit sale** → `Dr AR / Cr Sales / Cr Output VAT`; **settlement (12b)** → `Dr Cash Drawer/Bank / Cr AR`.
- **Cash register (10)** becomes the **reconciliation layer** over the *Cash Drawer* GL account: opening float
  `Dr Cash Drawer / Cr Bank`; on close, over/short → `Dr/Cr Cash Over-Short / Cr/Dr Cash Drawer`.
- **Rule:** never wire one side alone (one-sided AR/cash). Settlement posts only once the POS credit sale posts AR.

## Fix order (this batch)
1. **FIX 11** (endpoint + heal migration + test) — corrupting data now. ·
2. **FIX 12a** (helper + endpoint + UI + test) — correction path. ·
3. **FIX 10 + 12b** — scheduled with the POS-GL initiative (P-GL-2).
Each: one transaction, idempotent, before/after `assertLedgerBalanced()`, CLI test green, then a changelog entry at commit.

---

_Fix plan recorded for #10–#12. No code changed yet. On approval, implement one fix at a time (FIX 11 first)._

---

# RE-SCOUT — flows #1–#9 in the 6-part format (verification, 2026-06-23)

Re-traced the **current** code (file:line proof) through the same 6 parts. **Headline: every delete/void
(Part 6) is now correct for all 9 flows.** The only remaining real gaps are **VAT-completeness on the forward
post** for #3 (credit note) and #5 (debit note) — *not* delete gaps.

> **Drift caught — the 4-check detail sections above are STALE for #1, #2, #6, #7, #9** (they still describe
> gaps that the live code has since fixed). The status-summary table is correct; the prose under each is not.
> Refresh them when convenient.
>
> **The 6 parts:** (1) two-sided? · (2) under which condition? · (3) accounts → `journal_entries`? · (4) reports
> affected? · (5) which report(s)? · (6) on source delete, do reports react/reverse?

## 1. Expenses — `api/account/delete_expense.php`, `core/expense_posting.php`
1. ✅ Dr Expense / Cr Accrued Expenses (`expense_posting.php:86-89`); pay → Dr Accrued / Cr Bank (`postOutflow`).
2. Approved → accrual; Paid → settlement; idempotent on `expense_accrual`.
3. **2** (accrual); 2–3 (payment, +WHT).
4. Yes.
5. Accrual → TB + **Income Statement** (expense) + Balance Sheet (Accrued liab); payment → Balance Sheet + Cash Flow.
6. ✅ Reverses: `expenseIsAccrued → reverseExpenseAccrual` (`delete_expense.php:64-66`); **paid is locked** (`:50-54`); legacy posted txn reversed (`:71-81`).
**→ Gap location: NONE (fixed). Detail section #1 above is stale.**

## 2. Sales (invoices) — `api/account/delete_invoice.php`, `core/revenue_posting.php`
1. ✅ Dr AR / Cr Sales [/ Cr Output VAT] (`revenue_posting.php:91-98`); Dr COGS / Cr Inventory (`:186-189`); collect → Dr Bank [+WHT] / Cr AR.
2. Approved → revenue + COGS; Paid → collection; idempotent (`invoice` / `invoice_cogs` / `payment`).
3. Revenue **2–3**; COGS **2**; collection 2–3.
4. Yes.
5. Revenue → TB + **IS** (revenue) + BS (AR, VAT); COGS → **IS** (COGS) + BS (Inventory); collection → BS + Cash Flow (not IS).
6. ✅ Blocks delete of posted/paid → **cancel-first** (`delete_invoice.php:57-72`); cancel reverses revenue+COGS (`reverseInvoiceRevenue`/`reverseInvoiceCOGS`); drafts delete; Output VAT un-recognised (`:78`).
**→ Gap location: NONE (fixed). Detail section #2 above is stale.**

## 3. Credit note — `api/sales/delete_credit_note.php`, `core/sales_posting.php`
1. ✅ at settlement: Dr Sales Returns / Cr Bank (refund) **and** Dr Inventory / Cr COGS restock (`postCreditNoteRestock` `sales_posting.php:297-300`).
2. Create → no GL; Refund (paid) → posts; restock at settlement; idempotent (`credit_note_cogs`).
3. Refund **2** (gross); restock **2**.
4. Yes (at settlement).
5. Refund → IS (Sales Returns contra-rev) + BS/Cash Flow; restock → BS (Inventory ↑) + IS (COGS ↓).
6. ✅ Soft-delete; **paid is locked** (`delete_credit_note.php:45-48`); an unpaid note has no GL to orphan.
**→ Gap location: Parts 1/3/5 (economic completeness) — Output VAT NOT reversed on the refund (debits Sales Returns *gross* incl. VAT; no `Dr Output VAT`), so you still owe TRA the VAT. (Restock leg now fixed on the current branch.) Part 6 is fine.**

## 4. Received invoice (Bill) — `api/received_invoices.php`, `core/purchase_posting.php`
1. ✅ GRN Dr Inventory / Cr AP (`postGrnReceipt:125-128`); goods bill Dr Cost/Inventory [+ Dr Input VAT] / Cr AP (`ppAccrualVatLines:66-77`); subcontractor Dr COGS [+Input VAT] / Cr AP; pay Dr AP / Cr Bank [+WHT].
2. GRN approve / bill approve / pay; idempotent; amount-based cutover guard prevents double-count.
3. **2** (no VAT) or **3** (with Input VAT).
4. Yes.
5. Bill → BS (Inventory/AP, Input VAT asset) + IS (if cost account is COGS/expense); pay → BS + Cash Flow.
6. ✅ Blocks if payments (`supplierInvoiceHasPayments:1077-1080`); else reverses Input VAT + subcontractor + goods accrual (`received_invoices.php:1084-1091`), soft-delete.
**→ Gap location: NONE — this is the reference standard. Detail section #4 current.**

## 5. Debit note — `api/purchase/delete_debit_note.php`, `core/purchase_posting.php`
1. ✅ purchase return Dr AP / Cr Inventory (`postPurchaseReturn:460-463`); refund Dr Bank / Cr AP (`pay_debit_note`).
2. Return approve / refund paid; idempotent (`purchase_return`).
3. **2**.
4. Yes.
5. Return → BS (AP ↓, Inventory ↓); refund → BS + Cash Flow.
6. ✅ Soft-delete; **paid is locked** (`delete_debit_note.php:24`).
**→ Gap location: Parts 1/3/5 (economic completeness) — Input VAT NOT reversed on the return (`postPurchaseReturn` deliberately omits VAT, `:415-431`); + legacy off-book settlements (per detail #5). Part 6 is fine.**

## 6. Payment voucher — `api/account/delete_voucher.php`, `core/expense_posting.php`
1. ✅ Dr Expense / Cr Accrued (`postVoucherAccrual` → `postAccrualEntry:86-89`); pay → Dr Accrued / Cr Bank.
2. Approved → accrual; Paid → settlement; idempotent (`voucher_accrual`).
3. **2**.
4. Yes.
5. Accrual → TB + IS (expense) + BS (Accrued); pay → BS + Cash Flow.
6. ✅ Reverses: `voucherIsAccrued → reverseVoucherAccrual` (`delete_voucher.php:50-52`); **locks any paid/partially-paid** (`:35-42`).
**→ Gap location: NONE (fixed). Detail section #6 above is stale ("to confirm").**

## 7. Payroll — `api/delete_payroll.php`, `core/payment_source.php`, `api/remit_statutory.php`
1. ✅ accrual Dr Salaries Exp / Cr PAYE+NSSF+AP+Salaries Payable; SDL Dr SDL Exp / Cr SDL Payable; pay Dr Salaries Payable / Cr Bank; **remit Dr PAYE/NSSF/SDL Payable / Cr Bank**.
2. Approve → accrual; pay → settle net; remit → clear payables; idempotent.
3. accrual **up to 5**; pay 2; remit 2.
4. Yes.
5. Accrual → TB + IS (salaries/SDL) + BS (payables); pay/remit → BS + Cash Flow.
6. ✅ Reverses payment **and** accrual journals (`reverseJournalBalances` `delete_payroll.php:44-45`); refreshes statutory + SDL (`:51-55`).
**→ Gap location: NONE — statutory remittance flow EXISTS (`remit_statutory.php`). Detail section #7 above is stale (says it's missing).**

## 8. Adjustments (manual journal) — `api/account/void_journal.php`, `delete_journal.php`
1. ✅ user-chosen Dr/Cr; **unbalanced entry rejected** before write (`save_journal.php`).
2. Create (balanced) → posted; void → `status='void'`; delete → only draft/void/reversed.
3. **≥2** (user-defined).
4. Yes.
5. Per chosen accounts.
6. ✅ Void marks `status='void'` + deletes legacy mirror (`void_journal.php:27-40`); delete **blocks posted** (`delete_journal.php:30-33`) and removes items + mirror.
**→ Gap location: NONE (complete). Minor: void sets status rather than posting a contra — acceptable (reports filter `status='posted'`). Detail #8 current.**

## 9. Bank transfer — `api/account/update_bank_transfer_status.php`
1. ✅ posted Dr Destination + Dr Bank Charges / Cr Source (auto-post on create); 2–3 legs.
2. Auto-posts on creation; only action is **reverse**.
3. **2–3** (with charges).
4. Yes.
5. Both cash accounts → BS + Cash Flow; charges → IS.
6. ✅ Reverse restores both balances, **unmirrors the journal** (`:82` — the part the old void missed), deletes legacy rows, reverses both bank-register rows (`:76-90`).
**→ Gap location: NONE (fixed). Detail section #9 above is stale ("may leave the journal mirror").**

---

## Re-scout conclusion
- **Part 6 (delete/void) is correct for all 9** flows — the deletion principle is fully honoured here.
- **Only remaining gaps:** #3 credit-note **Output VAT** + #5 debit-note **Input VAT** (and #5 legacy off-book
  backfill) — economic completeness on the forward post, not delete.
- **Doc hygiene:** refresh the stale 4-check prose for #1, #2, #6, #7, #9 to match the live (fixed) code.

_Re-scout recorded. No code changed. Net open work across the whole audit: #3/#5 VAT completeness, plus the
researched batch #11 (petty-cash delete) and #12a (customer-payment void)._

---

# ADDITIONAL BATCH — Asset · Maintenance · Journals (6-part proof-by-trace, 2026-06-23)

Same 6-part format. Each answer proven by trace (file:line); closing line = the gap among the 6.

## 13. Asset — `core/asset_gl_service.php`, `api/operations/{save_asset,dispose_asset,delete_asset}.php`, `api/assets/run_depreciation.php`
Forward posting is wired & correct for all three events; the **delete** is the problem.

**13a. Acquisition** (`save_asset.php:269` → `postAssetAcquisition`)
1. ✅ new: `Dr Fixed Asset / Cr Accounts Payable`; existing: `Dr Fixed Asset / Cr Accum Dep (b/f) / Cr Take-on Equity (NBV)`.
2. On save with cost>0; idempotent (`asset_acquisition`); **best-effort** — asset still saves if the post fails (returns `ledger_warning`), so an asset can exist with no acquisition entry.
3. **2–3**. 4. Yes. 5. Balance Sheet (Fixed Asset / AP / Accum / Equity); not IS.

**13b. Depreciation** (`asset_depreciation_run.php:171` → `postAssetDepreciationGl`, entity `asset`)
1. ✅ `Dr Depreciation Expense / Cr Accumulated Depreciation`.
2. On depreciation run for an FY; idempotent on `depreciation_entries.journal_entry_id`.
3. **2**. 4. Yes. 5. **Income Statement** (Dep. Expense) + Balance Sheet (Accum Dep contra-asset).

**13c. Disposal** (`asset_disposal_service.php:161` → `postAssetDisposalGl`, entity `asset_disposal`)
1. ✅ `Cr Asset (cost) / Dr Accum Dep / Dr Clearing (proceeds) / Cr-or-Dr Gain or Loss`.
2. On disposal; idempotent.
3. **2–4**. 4. Yes. 5. Balance Sheet (Asset ↓, Accum ↓) + **Income Statement** (gain/loss) + clearing.

6. **Delete → reports react? ❌ NO — the worst Part-6 gap.** `delete_asset.php:27` is a bare `DELETE FROM assets` — no reversal, no status guard, no transaction, **not soft-delete** (violates §12). An asset carries an acquisition entry **+ many depreciation entries + possibly a disposal** — deleting it **orphans all of them** (Fixed Asset, AP, Accumulated Depreciation, Depreciation Expense overstated).

**→ Gap location: Part 6 (delete) — SEVERE. Parts 1–5 sound. Minor: acquisition posting is best-effort (asset can exist un-capitalised).**

## 14. Maintenance — `api/operations/save_maintenance.php`, `delete_maintenance_log.php`
1. **Two-sided? ❌ No.** Records `asset_maintenance` with a `cost` field (`save_maintenance.php:51-60`) but posts **nothing** to the GL.
2. Never posts. 3. **0 accounts.** 4. No. 5. **None** — a repair/maintenance cost is a real expense (`Dr Repairs & Maintenance / Cr Bank or AP`) that never reaches the P&L or reduces cash.
6. `delete_maintenance_log.php:22` is a bare `DELETE` — but from `maintenance_logs`, a **different table** than `save_maintenance` writes (`asset_maintenance`). Nothing in the GL to reverse anyway.

**→ Gap location: structural — all 6 (cost off-book). Data-model smell: two parallel maintenance tables (`asset_maintenance` written, `maintenance_logs` deleted).**

## 15. Journals (manual adjustments) — `save_journal.php`, `add_compound_journal.php`, `update_journal.php`, `void_journal.php`, `delete_journal.php`
1. ✅ Two-sided, user-defined Dr/Cr.
2. **Balanced enforced** (Dr=Cr) before write (`save_journal.php:51`, `add_compound_journal.php:49`); writes `journal_entries`+items directly, mirrors to legacy via `recordGlobalTransaction(skip_journal_mirror=true)`. save default `posted`, compound default `draft`.
3. ≥2 (≥1 Dr + ≥1 Cr). 4. Yes. 5. Any report (per chosen accounts).
6. **Mixed:**
   - `void_journal.php:27-40` ✅ sets `status='void'` + unmirrors legacy.
   - `delete_journal.php:30-33` ✅ **blocks posted** ("reverse or void first"); deletes only draft/void/reversed.
   - `update_journal.php:48-72` ⚠️ **GAP — no immutability guard: it edits a POSTED journal in place** (replaces all items + re-syncs the mirror) with no `assertJournalNotPosted()` call. A posted entry is in the reports; editing it silently rewrites history. Delete blocks posted; edit does not — inconsistent.

**→ Gap location: Part 6 / immutability — `update_journal.php` mutates posted entries. Minor: create paths use raw INSERT, skipping `postLedgerEntry`'s guards (balance is checked, so low risk).**

---

## Whole-audit open-work list (priority)
1. **Asset delete** — bare DELETE orphans acquisition + all depreciation + disposal. *(highest)*
2. **#11 Petty-cash delete** — no ledger reversal.
3. **`update_journal` immutability** — block editing posted entries (reverse/contra instead).
4. **#12a Customer-payment void** — add the reverse path.
5. **#3 / #5 VAT** — credit-note Output VAT / debit-note Input VAT (+ #5 backfill).
6. **Maintenance GL** — expense the cost (with the asset/POS-GL work).
7. **#10 / #12b** — cash register & POS settlement (with POS-GL).
8. Doc hygiene — refresh stale prose for #1/#2/#6/#7/#9.

_Batch #13–#15 recorded. Implementation starts now: Asset delete → Petty-cash delete → update_journal guard._

---

# IMPLEMENTATION STATUS (2026-06-23) — branch `fix/ledger-integrity-batch`

Five fixes implemented, each with a CLI test (all green) + changelog entry; committed locally, **push pending**.

| # | Fix | Commit | Test |
|---|---|---|---|
| #13 | **Asset delete** reverses GL (enum + heal migration; 4,729 orphans healed on dev) | `4d8adae` | 18/0 |
| #11 | **Petty-cash delete** reverses GL (+ heal migration) | `7f50161`† | 17/0 |
| #15 | **update_journal** blocks editing a posted entry (immutability guard) | `7f50161` | 7/0 |
| #12a | **Customer-payment void** (`void_payment.php` + `reversePaymentReceived`) | `b0d0596`† | 18/0 |
| #3 | **Credit-note refund reverses Output VAT** (3-leg split) | `b0d0596` | 12/0 |

Regression gate: global ledger **balanced**; Trial Balance 30/0; Balance Sheet 12/0; asset-depreciation 47/0; credit-note-restock 19/0.
*(†commit hashes are indicative; see `git log`.)*

## Still open
- **#5 Debit-note Input VAT + backfill — DEFERRED (do as a focused task).** Two parts of very different risk:
  1. *Input VAT reversal on a purchase return* (`postPurchaseReturn` → `Dr AP gross / Cr Inventory net / Cr Input VAT tax`) — this also fixes the **AP residual** (#5c: return moved net while the refund credits AP gross). BUT it must only reverse VAT **when the related bill actually claimed Input VAT** (a GRN-only receipt claims none), so it needs a "was Input VAT claimed?" guard. **Latent** — no current debit-note data carries VAT, so deferring causes no live harm.
  2. *Backfill the 8 off-book debit-note settlements (~931.5M)* — real historical money with no ledger entries; needs careful criteria-based, idempotent, reviewed migration. **Not to be rushed** at the tail of a batch.
- **#10 Cash register / #12b POS settlement / #14 Maintenance** — structural; belong to the POS-GL initiative (wiring alone creates one-sided accounts).
- **UI:** a "Void" button for #12a (endpoint ready; no customer-payment action list exists today).
- **Doc hygiene:** refresh stale 4-check prose for #1/#2/#6/#7/#9.
