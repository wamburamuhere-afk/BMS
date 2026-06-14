# money.md — BMS Money-Movement (Payment In/Out) Audit & Fix Checklist

**Purpose:** every place in BMS where money comes **in** or goes **out** must record a correct
**double entry** so the **Trial Balance** and **Balance Sheet** balance. We fix them **one file
after another**, and for each file we follow the **5 steps** below — *what to improve is always last*.

**Loans:** intentionally **EXCLUDED** — the system does not really do lending; the loan module
is treated as a bug, not a money path. Do not wire it.

---

## The 5 steps (every file follows this, in order)
1. **Where money is required to affect** — the accounts that must move.
2. **Check on double entry** — the exact Dr / Cr that must post.
3. **What is the current situation** — what it does today (verified by scouting).
4. **Research (Tanzania practice)** — how this payment is really made/treated in Tanzania (tax,
   regulatory, payment method) — from the right source.
5. **What is required to be improved** — the fix. **← ALWAYS LAST.**

**Status legend:** ✅ posts correctly · ⚠️ posts but ineffective (gate off / wrong ledger) ·
❌ does not post at all.

---

## 🇹🇿 Tanzania payment & tax context (shared sources for Step 4)
These authoritative facts feed the per-file Step 4. (Verify amounts each tax year.)

- **VAT = 18%**, and **EFD / EFDMS fiscalised receipts are mandatory** for businesses with annual
  turnover **≥ TZS 14M**; a fiscal receipt must be issued **upon receiving payment**, and input-VAT
  claims require fiscalised invoices.
- **VAT-Withholding regime (from 1 July 2025):** appointed agents withhold **3% of the VAT on goods,
  6% on services**, remit to TRA by the **20th** of the next month, and issue a **VAT Withholding
  Certificate**. **Government** withholds **2%** on payments for goods/services.
- **Withholding tax (WHT)** applies on various payments (services, rent, etc.) — already partly
  modelled in BMS (WHT Receivable/Payable).
- **Payment methods:** **mobile money dominates** (M-Pesa, Mixx by Yas/Tigo Pesa, Airtel Money,
  HaloPesa) for both collections and disbursements, alongside **bank transfer, cards, cheque, cash**.
  Currency = **TZS**.
- **Payroll statutory:** **PAYE** 5-band progressive (first TZS 270,000/month tax-free → up to 30%);
  **NSSF 20%** (10% employer + 10% employee); **SDL 3.5%** (employers with ≥10 staff); **WCF 0.5%**.
- **Accounting standards:** regulated by **NBAA**; **IFRS** and **IFRS for SMEs** adopted (IFRS for
  SMEs for turnover ≥ TZS 800M / assets ≥ TZS 400M). Double-entry + a balancing Balance Sheet is the
  baseline expectation.

**Sources:** [TRA – EFD](https://www.tra.go.tz/index.php/e-fiscal-devices-efd/495-efd-offences) ·
[Bowmans – EFD protocol 2.1](https://bowmanslaw.com/insights/tanzania-tra-public-notice-on-electronic-fiscal-devices-with-protocol-2-1-software/) ·
[EDICOM – EFDMS e-invoice](https://edicomgroup.com/blog/the-electronic-invoice-in-tanzania) ·
[EY – Finance Act 2025 (VAT withholding)](https://www.ey.com/en_gl/technical/tax-alerts/tanzanian-finance-act-2025-analysis) ·
[RSM – Tanzania Tax Guide 2025/26](https://www.rsm.global/tanzania/sites/default/files/media/documents/RSMTZ_Tanzania%20Tax%20Guide%202025-26.pdf) ·
[PwC – Tanzania WHT](https://taxsummaries.pwc.com/tanzania/corporate/withholding-taxes) ·
[TRA – WHT on goods](https://www.tra.go.tz/Images/headers/Withholding-Tax-on-payment-for-Goods.pdf) ·
[TanzaniaInvest – Mobile Money](https://www.tanzaniainvest.com/mobile-money) ·
[ClickPesa – Payment gateways 2025](https://clickpesa.com/ultimate-tanzania-payment-gateway-guide-in-2025/) ·
[PwC – Employment taxes](https://www.pwc.co.tz/press-room/tanzanian-employment-taxes.html) ·
[IFRS – Tanzania jurisdiction](https://www.ifrs.org/content/ifrs/home/use-around-the-world/use-of-ifrs-standards-by-jurisdiction/view-jurisdiction.html/tanzania) ·
[NBAA](https://www.nbaa.go.tz/2020/october/techpro32020.pdf)

---

## ⚠️ FOUNDATION (the root — fix conceptually before/with the pages)

### F1 — One ledger, one door
1. **Where money must affect:** every event → the single `journal_entries` GL.
2. **Double entry:** all via one helper `postLedgerEntry` (Dr=Cr enforced).
3. **Current situation:** money is recorded in 4 disconnected stores (formal GL · legacy
   `transactions`/`books_transactions` · denormalized `accounts.current_balance` · raw documents);
   only ~13% reaches the formal GL the Balance Sheet reads.
4. **Tanzania practice:** NBAA/IFRS expects ONE general ledger producing a Trial Balance and a
   Balance Sheet that balances; multiple parallel ledgers are not acceptable for audit.
5. **Improve:** route every event through `postLedgerEntry` into ONE ledger; retire/forward legacy
   `transactions`; stop treating `current_balance` as a separate truth.

### F2 — The posting gate is OFF
1. **Where:** `journal_mappings` (8 events).
2. **Double entry:** each event's mapped Dr/Cr.
3. **Current situation:** all 8 mappings `is_active=0`, accounts NULL → anything via `autoPostEvent`
   is a no-op (why the audit showed **Revenue = 0**).
4. **Tanzania practice:** revenue must be recognised in the books (IFRS 15 / IFRS for SMEs §23) — it
   cannot silently fail to post.
5. **Improve:** activate + configure mappings by account code (idempotent), or replace the gate with
   direct `postLedgerEntry` calls.

### F3 — Reports read different sources + no guardrail   ✅ DONE (2026-06-14)
> **Done:** guardrail = `core/financial_reports.php::assertLedgerBalanced()`; **Trial Balance**, **Balance
> Sheet** AND **Income Statement** all read the ONE ledger (glTrialBalance / glBalanceSheet / glProfitLoss).
> BS balances for real (no plug); the IS ties to the BS (all-time net profit == retained earnings). The
> expense/payroll/voucher/sub-contractor accruals (OUT-1/2/3/4) made the GL P&L consistent accrual, which
> unblocked the IS flip. Remaining cleanup: retire the now-unused legacy `transactions` mirror (F1).
1. **Where:** Balance Sheet, Income Statement, Trial Balance.
2. **Double entry:** n/a (reporting).
3. **Current situation:** routed Balance Sheet reads the GL; the other Balance Sheet + Income
   Statement read raw documents/`current_balance` → they can never agree. **Audit damage:** BS out by
   **1,359,329.99**; Revenue = 0; 163/188 transactions missing; GL 731M vs legacy 13.4B.
4. **Tanzania practice:** statutory financials (NBAA) must tie — P&L profit flows to equity; Assets =
   Liabilities + Equity.
5. **Improve:** all reports read the ONE ledger; add a guardrail asserting **Σ Dr = Σ Cr** and
   **Assets = Liabilities + Equity** after every post.

---

## 💰 MONEY-IN (inflows)

### IN-1 — Invoice payment (single)  ✅  ·  `api/account/record_payment.php`
1. **Where:** Bank/Cash ("Received Into"), Accounts Receivable.
2. **Double entry:** `Dr Bank / Cr Accounts Receivable` (WHT: `Dr Bank (net) + Dr WHT Receivable / Cr AR (gross)`).
3. **Current situation:** Posts via `postInflow`/`recordGlobalTransaction` (legacy ledger + partial mirror).
4. **Tanzania practice:** customer payments commonly by **mobile money / bank transfer / cheque**; a
   **fiscalised (EFD) receipt** must be issued on receipt; sales-side WHT credit where the customer withheld.
5. **Improve:** post once via `postLedgerEntry` into the one GL; record payment method (mobile/bank/cheque);
   ensure EFD receipt reference captured.

### IN-2 — Invoice payment (multi / receipt voucher)  ✅  ·  `api/account/save_receipt.php`
1. **Where:** Bank/Cash, Accounts Receivable (+ allocations).
2. **Double entry:** `Dr Bank / Cr AR` (gross/WHT split as IN-1).
3. **Current situation:** Posts + writes bank-register deposit; same legacy-ledger limitation.
4. **Tanzania practice:** same as IN-1 (EFD receipt, mobile/bank methods, WHT certificate handling).
5. **Improve:** single GL door; reconcile to `payment_allocations`; capture method + EFD ref.

### IN-3 — Invoice approved (revenue recognition)  ✅ FIXED 2026-06-13  ·  `api/account/approve_invoice.php` (+ `update_invoice_status.php`)
1. **Where:** Accounts Receivable, Sales Revenue, Output VAT Payable.
2. **Double entry:** `Dr AR (grand_total) / Cr Sales Revenue (grand−tax) / Cr Output VAT (tax)`.
3. **Current situation:** **FIXED.** New `core/revenue_posting.php::postInvoiceRevenue()` posts one
   balanced entry via `postLedgerEntry` into `journal_entries` at approval (both approval paths),
   idempotent; stamps `output_vat_posted` for the VAT report; no `current_balance` nudge. Verified
   19/19 (`tests/test_invoice_revenue_posting_cli.php`): VAT invoice 1,038,400 → Dr Trade Debtors /
   Cr Sales 880,000 / Cr Output VAT 158,400 (balanced); no-VAT → 2-line; idempotent.
4. **Tanzania practice:** **Output VAT @ 18%** recognised on the tax invoice; EFD fiscalised tax
   invoice required; revenue per IFRS 15. *(Done: revenue = grand−tax = net of VAT.)*
5. **Improve (remaining/next):** retire the now-superseded single-sided `postOutputVat()` in
   `save_invoice.php` (VAT shouldn't post at draft-save); optionally add `default_sales_revenue` /
   `default_accounts_receivable` settings so the accounts are admin-configurable (resolvers already
   fall back to 1-1200 / 4-1000).

### IN-4 — Other revenue  ✅  ·  `api/account/update_revenue_status.php`
1. **Where:** Bank/Cash, Income account.
2. **Double entry:** `Dr Bank / Cr Income` (+ `Cr Output VAT` if taxable).
3. **Current situation:** Posts at the status transition.
4. **Tanzania practice:** if the income is a taxable supply, Output VAT @18% + EFD receipt apply.
5. **Improve:** confirm one-GL landing; add Output VAT leg when taxable; income account from category.

### IN-5 — POS sale  ❌  (HIGH PRIORITY)  ·  `api/pos/process_sale.php`
1. **Where:** Cash/Bank (or POS clearing), Sales Revenue, Output VAT, COGS, Inventory.
2. **Double entry:** `Dr Cash / Cr Sales / Cr Output VAT` **AND** `Dr COGS / Cr Inventory`.
3. **Current situation:** Writes `pos_sales` only — **ZERO accounting**.
4. **Tanzania practice:** retail/POS is exactly where **EFD fiscal receipts** are enforced; **18% VAT**
   on each fiscalised sale; mobile-money/cash are the common tenders.
5. **Improve:** post both legs (revenue + COGS) via `postLedgerEntry`; tie COGS to `stock_movements`
   cost; capture EFD receipt number + tender type.

### IN-6 — POS return / refund  ❌  ·  `api/pos/create_return.php`
1. **Where:** Sales Returns, Cash/Bank, Inventory, COGS.
2. **Double entry:** `Dr Sales Returns / Cr Cash` **AND** reverse COGS `Dr Inventory / Cr COGS`.
3. **Current situation:** No accounting entry.
4. **Tanzania practice:** a credit note / refund must adjust the **Output VAT** previously charged (EFD
   credit note).
5. **Improve:** post the contra of IN-5 incl. the VAT reversal.

### IN-7 — Customer deposit / LPO advance  ❌  ·  customer LPO/advance flow (`customer_lpos`)
1. **Where:** Bank/Cash, Client Deposits (liability).
2. **Double entry:** receive `Dr Bank / Cr Client Deposits`; apply `Dr Client Deposits / Cr AR`.
3. **Current situation:** No accounting entry for advances.
4. **Tanzania practice:** VAT may be due at the **earlier of invoice or payment** — an advance can
   trigger an EFD receipt + Output VAT; treat carefully.
5. **Improve:** post the advance as a liability; release on application; handle VAT timing.

---

## 💸 MONEY-OUT (outflows)

### OUT-1 — Expense paid  ✅  ·  `api/account/update_expense_status.php` (`paid` transition)
1. **Where:** chosen Expense account, Bank/Cash ("Paid From").
2. **Double entry:** `Dr Expense / Cr Bank`.
3. **Current situation:** Posts via `postOutflow` + bank-register row; reversible. ("Paid From" captured at
   creation, not at payment — UX gap.)
4. **Tanzania practice:** expense needs a **valid EFD receipt** to claim input VAT; paid via mobile money /
   bank / cash; **WHT** may apply (e.g. service providers, rent).
5. **Improve:** one-GL landing; capture real payment date/method/account at the pay step; split input VAT
   where a fiscal receipt exists; apply WHT where due.

### OUT-2 — Payment voucher paid  ✅  ·  `api/account/update_voucher_status.php`
1. **Where:** Expense account, Bank/Cash.
2. **Double entry:** `Dr Expense / Cr Bank`.
3. **Current situation:** Posts to a real expense account (fixed earlier).
4. **Tanzania practice:** same as OUT-1 (EFD receipt for input VAT, WHT, mobile/bank methods).
5. **Improve:** one-GL landing; payment date/method at pay step; VAT/WHT handling.

### OUT-3 — Supplier payment  ✅  ·  `api/update_supplier_payment.php` (+ `add_supplier_payment.php`)
1. **Where:** Accounts Payable, Bank/Cash.
2. **Double entry:** `Dr Accounts Payable / Cr Bank`.
3. **Current situation:** Posts. (AP only meaningful if GRN/supplier invoice raised AP first — OUT-7.)
4. **Tanzania practice:** **VAT-withholding (3% goods / 6% services)** and **2% government WHT** may apply
   on supplier payments (Finance Act 2025) — withhold + remit to TRA + issue certificate; mobile/bank common.
5. **Improve:** ensure AP was raised first; add VAT-withholding + WHT legs (Cr WHT/VAT-WH Payable);
   one-GL landing.

### OUT-4 — Payroll paid  ✅  ·  `api/update_payroll_status.php`
1. **Where:** Salaries Expense, PAYE/NSSF/SDL Payable, Salaries Payable, Bank.
2. **Double entry:** accrual `Dr Salaries Expense / Cr PAYE + Cr NSSF + Cr SDL + Cr Salaries Payable`;
   pay `Dr Salaries Payable / Cr Bank`.
3. **Current situation:** Posts accrual + payment via `postPayrollAccrual`/`postOutflow`.
4. **Tanzania practice:** **PAYE** (progressive, first 270K free → 30%), **NSSF 20%** (10%+10%),
   **SDL 3.5%** (≥10 staff), **WCF 0.5%** — each a separate payable remitted to its authority.
5. **Improve:** confirm one-GL landing; ensure SDL + WCF legs post; verify rates match current tax tables.

### OUT-5 — Statutory remittance (PAYE/NSSF/SDL/VAT/WHT → authorities)  ✅  ·  `api/remit_statutory.php`
1. **Where:** the relevant Payable, Bank.
2. **Double entry:** `Dr <Statutory> Payable / Cr Bank`.
3. **Current situation:** Posts.
4. **Tanzania practice:** remit to **TRA** (PAYE/SDL/VAT/WHT) and **NSSF/WCF** by statutory deadlines
   (e.g. VAT-WH by the 20th); paid to designated bank accounts in TZS.
5. **Improve:** confirm it clears the same payable the accrual credited; track due dates.

### OUT-6 — Petty cash disburse / top-up  ✅  ·  `api/petty_cash/save_transaction.php`
1. **Where:** disburse → Expense, Petty Cash; top-up → Petty Cash, Bank.
2. **Double entry:** disburse `Dr Expense / Cr Petty Cash`; top-up `Dr Petty Cash / Cr Bank`.
3. **Current situation:** Posts to the chosen expense account. One bogus test fund ("nothing" → inactive
   bank account) to clean.
4. **Tanzania practice:** small cash spends still need EFD receipts to claim input VAT; imprest/float is
   standard for petty expenses.
5. **Improve:** one-GL landing; guard a fund to an active petty-cash account only; remove the bogus fund.

### OUT-7 — GRN approved (goods received)  ✅  ·  `api/approve_grn.php`
1. **Where:** Inventory, Accounts Payable.
2. **Double entry:** `Dr Inventory / Cr Accounts Payable` (+ `Dr Input VAT` if a fiscal tax invoice).
3. **Current situation:** Posts — but the Inventory chart account (1‑1300) shows 0 in the GL; confirm it
   actually lands vs `product_stocks`.
4. **Tanzania practice:** **input VAT** recoverable only with a **fiscalised supplier invoice**; goods on
   credit create AP.
5. **Improve:** make Inventory a real fed GL account; add input-VAT leg; reconcile to `product_stocks`.

### OUT-8 — Purchase return  ❌  ·  `api/create_purchase_return.php`
1. **Where:** Accounts Payable, Inventory.
2. **Double entry:** `Dr Accounts Payable / Cr Inventory` (+ reverse Input VAT).
3. **Current situation:** No accounting entry.
4. **Tanzania practice:** supplier debit note adjusts the input VAT previously claimed.
5. **Improve:** post the contra of OUT-7 incl. VAT reversal.

### OUT-9 — Credit note paid (customer refund)  ✅  ·  `api/sales/pay_credit_note.php`
1. **Where:** Sales Returns / Credit Note, Bank.
2. **Double entry:** `Dr Sales Returns (or Credit Note) / Cr Bank` (+ Output VAT reversal).
3. **Current situation:** Posts.
4. **Tanzania practice:** EFD credit note reduces the Output VAT originally declared.
5. **Improve:** one-GL landing; include VAT reversal.

### OUT-10 — Debit note paid (received from supplier)  ✅ (net money IN)  ·  `api/purchase/pay_debit_note.php`
1. **Where:** Bank, Accounts Payable / Supplier Credit.
2. **Double entry:** `Dr Bank / Cr Accounts Payable`.
3. **Current situation:** Posts.
4. **Tanzania practice:** supplier credit/refund affects input VAT recovered.
5. **Improve:** one-GL landing; VAT adjustment.

### OUT-11 — Bank transfer  ✅  ·  `api/account/update_bank_transfer_status.php`
1. **Where:** destination Bank, source Bank, Bank Charges.
2. **Double entry:** `Dr Bank-B / Cr Bank-A` (+ `Dr Bank Charges / Cr Bank-A` if a fee).
3. **Current situation:** Posts both legs + register rows.
4. **Tanzania practice:** inter-account / mobile-to-bank transfers are routine; bank/mobile **charges**
   are a real expense to record.
5. **Improve:** confirm one-GL landing; charges to Bank Charges expense.

### OUT-12 — Asset acquisition  ❌  (makes Fixed Assets real)  ·  `api/operations/save_asset.php`
1. **Where:** Fixed Asset (1‑3xxx at Cost), Bank or Accounts Payable.
2. **Double entry:** `Dr Fixed Asset / Cr Bank` (cash) or `Cr Accounts Payable` (credit) (+ Input VAT).
3. **Current situation:** Writes the `assets` register only — no GL posting; `assets` has no account link;
   1‑3xxx accounts read 0.
4. **Tanzania practice:** capital assets capitalised per IFRS/IFRS-for-SMEs (IAS 16 / §17); input VAT
   recoverable with a fiscal invoice; **capital allowances** (tax depreciation) tracked separately.
5. **Improve:** link each asset (category) to its Fixed Asset GL account; post acquisition + input VAT.

### OUT-13 — Depreciation run  ❌  ·  `api/assets/run_depreciation.php`
1. **Where:** Depreciation Expense, Accumulated Depreciation (contra-asset).
2. **Double entry:** `Dr Depreciation Expense / Cr Accumulated Depreciation`.
3. **Current situation:** Computes/writes the register; no GL posting (0 depreciation journal entries).
4. **Tanzania practice:** book depreciation per IFRS; **tax depreciation = capital allowances** under the
   Income Tax Act (separate from book) — BMS already models a tax area, keep both.
5. **Improve:** post each period's book depreciation to the GL accounts.

### OUT-14 — Asset disposal  ❌  ·  `api/operations/dispose_asset.php`
1. **Where:** Cash/Bank, Accumulated Depreciation, Fixed Asset, Gain/Loss on Disposal.
2. **Double entry:** `Dr Cash + Dr Accumulated Depreciation / Cr Fixed Asset (cost)` with balancing
   `Dr/Cr Gain or Loss on Disposal`.
3. **Current situation:** Updates the register; no GL posting.
4. **Tanzania practice:** disposal proceeds may carry **Output VAT**; gain/loss affects taxable income.
5. **Improve:** post the full disposal entry incl. VAT + gain/loss.

### OUT-15 — Project IPC certificate (interim payment certificate)  ✅ FIXED 2026-06-14  ·  `api/operations/update_ipc_status.php`
> **FIXED:** `postIpcRevenue()` (core/ipc_posting.php) posts `Dr AR / Cr Contract Revenue` (net_payable) at the
> **Approved** transition; idempotent (entity='ipc'); IN-3 defers to the IPC for the billing invoice
> (`recognised_via_ipc`) so no double-count. Retention recognised on release (refinement). Original notes below.
1. **Where:** Accounts Receivable / Contract WIP, Revenue (certified amount).
2. **Double entry:** `Dr AR (or WIP) / Cr Revenue` for the certified amount (or recognise on the invoice it raises).
3. **Current situation:** No accounting entry; IPC certified amounts feed the Income Statement directly.
4. **Tanzania practice:** construction/contract revenue per IFRS 15 (over time); EFD tax invoice on
   certification; **2% government WHT** common on construction payments.
5. **Improve:** post certified revenue to the GL (or via the generated invoice); handle WHT.

### OUT-16 — Project payroll  ❌  ·  `api/operations/process_project_payroll.php`
1. **Where:** Salaries Expense (project-scoped), payables/Bank.
2. **Double entry:** as OUT-4 (`Dr Salaries Expense / Cr payables`; pay `Dr payable / Cr Bank`), tagged to the project.
3. **Current situation:** No GL posting.
4. **Tanzania practice:** same statutory set as OUT-4 (PAYE/NSSF/SDL/WCF), per project.
5. **Improve:** post like OUT-4 with `project_id` scope.

---

## Fix order (recommended)
1. **Foundation F1–F3** (one ledger, turn on engine, reports read one source + guardrail).
2. **Revenue:** IN-3 (invoice approved), IN-5 (POS sale + COGS), IN-6 (POS return).
3. **Inventory/COGS:** OUT-7 (verify GRN lands), OUT-8 (purchase return), COGS leg.
4. **Assets:** OUT-12 (acquisition), OUT-13 (depreciation), OUT-14 (disposal).
5. **Project + advances:** OUT-15 (IPC), OUT-16 (project payroll), IN-7 (deposits).
6. **Tighten the ✅ ones** into the one GL (IN-1/2/4, OUT-1..6/9/10/11).
7. **Verify after each:** Trial Balance (Σ Dr = Σ Cr) + Balance Sheet (Assets = Liab + Equity).

## Progress tracker
| ID | Event | Status | Done? |
|----|-------|--------|-------|
| F1 | One ledger / one door | not started | ☐ |
| F2 | Turn on posting engine | not started | ☐ |
| F3 | Reports one source + guardrail | not started | ☐ |
| IN-1 | Invoice payment (single) | ✅ tighten | ☐ |
| IN-2 | Invoice payment (receipt) | ✅ tighten | ☐ |
| IN-3 | Invoice approved (revenue) | ✅ FIXED — Dr AR / Cr Revenue / Cr Output VAT (postInvoiceRevenue) | ☑ |
| IN-4 | Other revenue | ✅ tighten | ☐ |
| IN-5 | POS sale (+COGS) | ❌ no-post | ☐ |
| IN-6 | POS return | ❌ no-post | ☐ |
| IN-7 | Customer deposit/advance | ❌ none | ☐ |
| OUT-1 | Expense paid | ✅ ACCRUAL — approve: Dr Expense / Cr Accrued Expenses (2-1500); pay: Dr Accrued / Cr Bank; reject reverses (core/expense_posting.php) | ☑ |
| OUT-2 | Payment voucher | ✅ ACCRUAL — approve: Dr Expense / Cr Accrued Expenses; pay: Dr Accrued / Cr Bank; cancel reverses (shared accrual engine) | ☑ |
| OUT-3 | Supplier payment | ✅ ACCRUAL — sub-contractor invoice approval posts Dr COGS / Cr AP (postSubcontractorAccrual); payment settles same AP; goods via GRN | ☑ |
| OUT-4 | Payroll paid | ✅ ACCRUAL — approve: Dr Salaries Exp / Cr PAYE/NSSF/Salaries Payable + SDL; pay: Dr Salaries Payable / Cr Bank (update_payroll_status now uses ensurePayrollAccrued + postPayrollPayment) | ☑ |
| OUT-5 | Statutory remittance | ✅ tighten | ☐ |
| OUT-6 | Petty cash disburse/top-up | ✅ tighten | ☐ |
| OUT-7 | GRN approved | ✅ FIXED — Dr Inventory / Cr AP via postGrnReceipt (core/purchase_posting.php); AP resolver aligned so receive→pay nets | ☑ |
| OUT-8 | Purchase return | ❌ no-post | ☐ |
| OUT-9 | Credit note paid | ✅ tighten | ☐ |
| OUT-10 | Debit note paid | ✅ tighten | ☐ |
| OUT-11 | Bank transfer | ✅ tighten | ☐ |
| OUT-12 | Asset acquisition | ✅ FIXED — new: Dr Fixed Asset / Cr AP; existing: Dr Asset / Cr Accum Dep / Cr Take-on Equity (postAssetAcquisition) | ☑ |
| OUT-13 | Depreciation run | ✅ FIXED — Dr Depreciation Expense / Cr Accumulated Depreciation; resolver fallback + journal_entry_id idempotency/backfill | ☑ |
| OUT-14 | Asset disposal | ❌ no-post | ☐ |
| OUT-15 | Project IPC | ❌ no-post | ☐ |
| OUT-16 | Project payroll | ❌ no-post | ☐ |

> **Excluded:** Loans (not a real money path in this system — treat as a bug; do not wire).
