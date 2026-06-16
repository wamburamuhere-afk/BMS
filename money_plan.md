# money_plan.md — BMS Money-Movement IMPLEMENTATION PLAN

**Companion to `money.md`** (the audit). This is the **approved implementation plan** for every
money-in/out file, so we implement them later as one coherent set. Two targets only, per file:

1. **Right source/destination** — does the money save to the correct account?
2. **Double entry** — does the process (approve/pay/sale/etc.) post a balanced Dr=Cr entry?

**Scope rule (locked):** fix only **what already exists**. Do **not** add new tax mechanisms.
**PAYE/NSSF/SDL logic stays unchanged.** Loans excluded (a bug, not a money path).
**Parked advice (not in scope):** A1 input-VAT fiscal gating · A2 VAT-withholding 3/6/2% · A3 WCF.

**Reference implementation (DONE & proven):** **IN-3** — `core/revenue_posting.php` +
`approve_invoice.php`/`update_invoice_status.php`. Every fix below follows this pattern:
post **one balanced entry via `postLedgerEntry` into `journal_entries`**, idempotent on
`(entity_type, entity_id)`, joining the caller's transaction, **no `current_balance` nudge**.

---

## 0. The root fact that drives most of these fixes
The helpers `postOutflow` / `postInflow` / `recordGlobalTransaction` write the **legacy** ledger
(`transactions` + `books_transactions`) and only **partially mirror** to the formal
`journal_entries` GL (≈25 of 188 mirrored). The Balance Sheet reads `journal_entries`. So many
files marked "POSTS" still **don't land in the GL the reports read** → the "**route to GL**" fixes.

## 0b. Payment-method finding (scouted)
- **Method ≠ account.** "Payment method" (cash/mobile/bank/cheque) is **metadata**; the real
  money account is the separate **"Received Into" (in)** / **"Paid From" (out)** field. The method
  **never drives the account** anywhere → double-entry is correct regardless of method.
- **Inconsistent method enums** across 6 modules (`check` vs `cheque`, POS adds
  `loyalty_points`/`voucher`/`mixed`). Not a double-entry bug, a data-quality one.
- **Forms often don't capture the method** even when the table has the column (expense, voucher,
  supplier, revenue); petty cash captures it but has **no column**.
- **Only real double-entry blocker: POS has no "Received Into account"** (IN-5) — it records the
  tender label but not *which* cash/bank account the money enters.

---

## Plan status
| File | Existing problem | Fix | Batch | Done |
|---|---|---|---|---|
| **F Foundation** | scattered ledgers, no shared resolvers | `core/gl_accounts.php` + post convention | B0 | ✅ done |
| IN-1 invoice payment (single) | "posts" but AR mapping NULL → gated no-op; + current_balance nudge | ✅ DONE — `postPaymentReceived` (Dr Bank [+WHT] / Cr AR) → GL; nudge dropped | B1 | ✅ |
| IN-2 invoice payment (receipt) | posted nothing (gated autoPostEvent) | ✅ DONE — `postPaymentReceived` → GL; keeps bank-register + allocations | B1 | ✅ |
| IN-3 invoice approved | — | ✅ DONE (commit 902484c) | — | ✅ |
| IN-4 other revenue | posts via postInflow — **already reaches the formal GL via the journal mirror**, with a working void | ✅ ACCEPTABLE as-is; full one-door migration deferred to avoid breaking the legacy-id void | B1 | ✅ |
| IN-5 POS sale | **no accounting at all**; no COGS | ✅ DONE — `postPosSale`: Dr Cash/AR (split) / Cr Sales / Cr VAT + Dr COGS / Cr Inventory | B2 | ✅ |
| IN-6 POS return | no accounting | ✅ DONE — `postPosReturn`: contra refund + restock | B2 | ✅ |
| IN-7 customer deposit/advance | no accounting | `Dr Bank/Cr Client Deposits`; release on apply | B6 | ☐ |
| OUT-1 expense paid | posts to legacy ledger; form ignores method col | route `Dr Expense/Cr Bank` → GL | B3 | ☐ |
| OUT-2 payment voucher | legacy ledger; no method captured | route → GL | B3 | ☐ |
| OUT-3 supplier payment | legacy; AP may be unraised; no method captured | route `Dr AP/Cr Bank` → GL; ensure AP raised (OUT-7) | B3 | ☐ |
| OUT-4 payroll paid | legacy ledger (accrual logic OK) | route → GL — **PAYE/NSSF/SDL untouched** | B3 | ☐ |
| OUT-5 statutory remittance | legacy + nudge | route `Dr Payable/Cr Bank` → GL | B3 | ☐ |
| OUT-6 petty cash | legacy; method has no column; one bogus fund | route → GL; remove bogus fund | B3 | ☐ |
| OUT-7 GRN approved | posts but Inventory GL reads 0 | confirm `Dr Inventory/Cr AP` lands in GL | B4 | ☐ |
| OUT-8 purchase return | no accounting | `Dr AP/Cr Inventory` | B4 | ☐ |
| OUT-9 credit note paid | legacy ledger | route → GL | B3 | ☐ |
| OUT-10 debit note paid | legacy ledger | route → GL | B3 | ☐ |
| OUT-11 bank transfer | legacy + nudge | route both legs → GL | B3 | ☐ |
| OUT-12 asset acquisition | no accounting; asset has no GL link | link category→1-3xxx; `Dr Fixed Asset/Cr Bank or AP` | B5 | ☐ |
| OUT-13 depreciation run | no accounting | `Dr Dep Expense/Cr Accum Dep` | B5 | ☐ |
| OUT-14 asset disposal | no accounting | `Dr Cash+Accum Dep/Cr Asset` ± gain/loss | B5 | ☐ |
| OUT-15 project IPC | no accounting | `Dr AR/Cr Revenue` (or via generated invoice) | B6 | ☐ |
| OUT-16 project payroll | no accounting | like OUT-4, project-scoped | B6 | ☐ |

**⚠ Verify at implementation (3 items):** IN-5 exact COGS cost source · OUT-7 whether GRN truly
reaches the GL · OUT-15 IPC revenue-recognition model.

---

## Implementation batches (each = one branch + test + PR)

### B0 — Foundation (`core/gl_accounts.php`)  ✅ DONE 2026-06-13
- Resolver library (existing accounts only): `arAccountId · salesRevenueAccountId · outputVatAccountId
  · inputVatAccountId · apAccountId · bankAccountResolve($id) · inventoryAccountId · cogsAccountId ·
  fixedAssetAccountId · accumDepAccountId · depreciationExpenseAccountId · salesReturnsAccountId`;
  **reuse existing PAYE/NSSF/SDL resolvers unchanged.**
- Resolution rule: setting → journal_mapping → sub-type/code fallback → clear "not configured" error.
- Post convention (§F.2): one ledger, `postLedgerEntry`, idempotent, no `current_balance`, contra on void.
- Absorbs: **update the stale Phase-4 tests** (they assert the old gated `autoPostEvent` and block IN-3's push).
- Test: `tests/test_gl_accounts_cli.php`.

### B1 — Money-in clearing (IN-1, IN-2, IN-4)  ✅ DONE 2026-06-13
- **Problem:** IN-1 "posted" but AR came from an empty `payment_received` mapping → gated no-op
  (+ single-sided `current_balance` nudge); IN-2 posted nothing (gated `autoPostEvent`); IN-4
  posts via `postInflow`.
- **Fix:** new `core/money_in_posting.php::postPaymentReceived()` posts ONE balanced entry into the
  canonical ledger — `Dr Received-Into (net) [+ Dr WHT Receivable] / Cr Accounts Receivable (gross)`;
  AR via `gl_accounts.arAccountId()` (no dependence on the empty mapping); idempotent on
  `(entity_type='payment', entity_id)`; no `current_balance` nudge. Wired into both `record_payment.php`
  and `save_receipt.php` (the latter keeps its bank-register deposit + allocations). Existing **WHT
  split preserved**. Method stays metadata.
- **IN-4 finding:** `postInflow` already mirrors into `journal_entries` (via `recordGlobalTransaction →
  mirrorTransactionToJournal`) and has a working void tied to its legacy `transaction_id` — so it
  already reaches the GL with correct double-entry. Left as-is for B1 (full one-door migration deferred
  to avoid breaking the void; low value, higher risk).
- **Conditions:** only `status='completed'` posts; a payment with no chosen Received-Into account is
  not posted; WHT → 3-line. Verified `tests/test_payment_received_posting_cli.php` (16/16) + the
  retired-wiring assertion in `test_phase4_payment_received_cli.php` updated to the new behavior.

### B2 — Sales / POS (IN-5, IN-6)  ✅ DONE 2026-06-13
- **Problem:** POS wrote `pos_sales` only — **zero accounting** (39 sales invisible to the books).
- **Fix:** new `core/sales_posting.php` posts to the canonical ledger, best-effort (never fails a
  sale) + idempotent:
  - **Sale (IN-5):** Revenue `Dr Cash/Bank (paid) + Dr AR (balance) / Cr Sales (net) / Cr Output VAT`
    — the **split-debit** handles cash, credit and partial sales uniformly; plus COGS
    `Dr COGS / Cr Inventory`.
  - **Return (IN-6):** contra — `Dr Sales Returns (net) [+ Dr Output VAT] / Cr Cash` and restock
    `Dr Inventory / Cr COGS`.
  - **Account routing:** `posReceiptAccountId($method)` → Cash Drawer (cash) / Electronic Payments
    (mobile) / Cheque (card/bank); `salesRevenueAccountId`, `outputVatAccountId`, `arAccountId`,
    `cogsAccountId`, `inventoryAccountId` (all B0). VAT folds into revenue if no VAT account so the
    entry always balances.
  - **COGS source (verified):** `Σ pos_sale_items.quantity × products.cost_price` — the same
    convention the Income Statement already uses.
  - Wired into `process_sale.php` (before commit) and `create_return.php` (computes restock cost in
    the restock loop). Verified `tests/test_pos_sale_posting_cli.php` (16/16): cash sale, partial-credit
    split, COGS, return refund + restock, all balanced + idempotent; existing POS tests stay green.
  - *Note:* POS still has no explicit "Received Into" account picker; the method→account routing above
    is the pragmatic stand-in (a future UI refinement, not a double-entry blocker).
- **Option B (payment-method professionalisation, applied):** after researching QuickBooks/Xero/POS
  practice (each tender maps to a configurable Payment+Deposit account; clearing/Undeposited-Funds
  two-step), the pragmatic TZ-fit improvements were applied: `posReceiptAccountId()` is now
  **admin-configurable via `pos_<method>_account_id` settings** (→ code default → first cash/bank leaf),
  not hardcoded; and `process_sale.php`/`create_return.php` **log a GL warning** if a sale/return could
  not post (never silently lost; the sale itself is never blocked). For POS the single method field IS
  the account selector, so method↔account can't mismatch. *(Deferred to a later batch: the full
  Undeposited-Funds two-step + split-tender per account + processor fees — matters most for card-heavy
  retail, less for TZ cash/mobile.)*

### B3 — Tighten the payers (OUT-1,2,3,4,5,6,9,10,11)
- **Problem:** all post, but to the **legacy ledger**; some forms don't capture the method; OUT-5/11
  also nudge `current_balance`.
- **Fix:** route every one into `journal_entries` via the shared convention (the entries themselves
  are already correct: `Dr Expense/Cr Bank`, `Dr AP/Cr Bank`, payroll accrual, `Dr Payable/Cr Bank`,
  petty cash, notes, transfer). **PAYE/NSSF/SDL untouched.** Remove the `current_balance` nudges.
- **Side-cleanups:** OUT-6 remove the bogus "nothing" fund; ensure OUT-3 only pays AP that OUT-7 raised.

### B4 — Inventory / Purchases (OUT-7, OUT-8)
- **Problem:** GRN posts but Inventory GL reads 0 (confirm it lands); purchase return posts nothing.
- **Fix:** make `Dr Inventory / Cr AP` land in the GL on GRN; post `Dr AP / Cr Inventory` on return;
  reconcile Inventory account to `product_stocks`.

### B5 — Assets (OUT-12, OUT-13, OUT-14)
- **Problem:** rich asset register exists but **posts nothing**; `assets` has no GL-account link →
  1-3xxx accounts are empty phantoms.
- **Fix:** link each asset (via its category) to its `1-3xxx` Cost + Accum-Dep accounts; post
  acquisition (`Dr Fixed Asset / Cr Bank or AP`), depreciation (`Dr Dep Expense / Cr Accum Dep`),
  disposal (`Dr Cash + Dr Accum Dep / Cr Fixed Asset` ± gain/loss).

### B6 — Project + advances (OUT-15, OUT-16, IN-7)
- **Problem:** project IPC, project payroll, and customer deposits post nothing.
- **Fix:** IPC → `Dr AR / Cr Revenue` (or via the invoice it raises) *(verify model)*; project payroll
  → like OUT-4 with `project_id`; customer deposit → `Dr Bank / Cr Client Deposits`, released on apply.

### B7 (LAST) — Verification reports
- Only after B1–B6: Trial Balance (Σ Dr = Σ Cr) + Balance Sheet (Assets = Liab + Equity) reading the
  one GL — **this is the "real report"**, valid because the books are now complete.

---

## Payment-method standardisation (advice — accept/skip per item)
Not double-entry bugs; data-quality. Decide later:
| # | Item | Suggestion |
|---|---|---|
| M1 | One canonical method enum (cash, bank_transfer, mobile_money, cheque, card) across all modules | recommend — consistency for reports |
| M2 | Capture method on forms that have the column but ignore it (expense, voucher, supplier, revenue) | recommend — small, useful for reconciliation |
| M3 | Add a `payment_method` column where the form captures it but can't save (petty cash) | recommend |
| M4 | (optional) default the account from the method (cash→Cash, mobile→Electronic Payments) | optional |

---

## Decisions log
- 2026-06-13: Foundation (B0) approved; A1–A3 skipped; PAYE/NSSF/SDL untouched; reports last.
- 2026-06-13: IN-3 implemented (commit `902484c`); push blocked by stale Phase-4 tests → fixed in B0.
