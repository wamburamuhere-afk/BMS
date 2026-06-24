# Plan — Salaries Payable phantom & the "one ledger" Balance Sheet fix

**Date:** 2026-06-24
**Trigger:** "Salaries Payable 2,300,178,497.48" appears on the **Balance Sheet** of systems that have **no employees, no payroll, no expenses** — while the **Chart of Accounts shows 0** for the same account. Identical figure across independent systems.

## Root cause (traced step-by-step through the payroll flow)
1. **Registration** — salary = a fixed monthly `basic_salary` (not day-based unless attendance mode is explicitly on, which ships off).
2. **Processing** — net pay computed on the full monthly salary; an accrual posts **only** if auto-approved.
3. **Approval** (`update_payroll_status.php`) — posts `Dr Salaries Expense / Cr PAYE+NSSF+Salaries Payable` to `journal_entries`, **but best-effort** (try/catch only logs). Approved rows from older/bulk paths or a silent failure have **no accrual posted**.
4. **Payment** — requires a real "Paid From" account; posts `Dr Salaries Payable / Cr Bank (net)` to `journal_entries`, fails loudly. ✅ correct.

**The payroll accounting flow itself reaches `journal_entries` correctly.** The bug is **not** in payroll — it is that the **Balance Sheet page reads Salaries Payable (and AR, AP, VAT, WHT, accruals, refunds) from the operational sub-ledger tables, not from `journal_entries`.** For Salaries Payable it calls `salariesPayablePosition()` = `SUM(net_salary) FROM payroll WHERE payment_status NOT IN ('paid','cancelled','rejected')` — summing every **pending/approved/blank** row, including leftover/cloned rows that were **never posted**. That is the phantom; it exists in no ledger (Chart=0, journal=21M on local, injection=3.7B).

**Confirmed by data (local):** payroll table injection = 3,731,500,072 · journal truth = 21,295,224 · 26 approved rows with **0** posted accruals.

## The fix — make every figure come from `journal_entries` (sequenced)

### Point 1 — Balance Sheet reads the one ledger *(safe; fixes the phantom immediately)*
In `app/constant/reports/balance_sheet.php`: **remove the sub-ledger injection blocks** (`salariesPayablePosition`, `vatNetPosition`, `whtPosition`, `whtReceivablePosition`, `arInvoicesPosition`, `apSupplierInvoicesPosition`, `accruedExpensesPosition`, `refundsPayablePosition`) and **stop adding `accounts.opening_balance`**. Each control account then shows **only** its posted `journal_entries` balance (from the page's own per-account journal SQL). Result: empty system → **0**; live system → the **true posted** net liability; the double-count and the phantom both vanish; the page balances because `journal_entries` balances.

### Point 2 — Complete the ledger for genuinely-approved payroll *(idempotent backfill, guarded)*
Migration: for `approved`/`partial` payroll **whose employee exists and is active** ("must have an id") and that has **no posted accrual**, post `ensurePayrollAccrued()`. This puts real approved liabilities into `journal_entries` so a live Balance Sheet (now reading the ledger) shows them. **Guards:** orphan/no-employee rows are **excluded** (left for cleanup, never posted); `pending` is **not** an approved liability and is **never** backfilled; idempotent; prints before/after totals and asserts the ledger stays balanced.

> **Caveat for empty/cloned systems:** if the leftover "approved" rows are junk (no real staff), they must be **cancelled (Point 4), not backfilled**. The Point-2 guard (valid active employee) prevents posting orphan junk; genuine junk with surviving employee rows should be cleaned first.

### Point 3 — Make approval's accrual non-silent *(prevents recurrence)*
Approval must **fail** (not swallow) if the accrual cannot post, so an `approved` payroll can never again exist without its `journal_entries` accrual.

### Point 4 — Clean orphan / junk payroll *(data hygiene)*
Idempotent migration to cancel payroll rows with no existing employee or stuck blank/null status, so the operational table can't hold phantoms either.

### Point 5 — `current_balance` reconcile *(separate drift, runs everywhere)*
Re-run `reconcileAccountBalances()` so the denormalised `accounts.current_balance` equals the journal truth (no-op on clean systems; corrects drifted ones).

## Verification after each step
Σ Dr = Σ Cr; P&L net = retained earnings; an empty dataset → every Balance Sheet line 0; Balance Sheet = Trial Balance = Chart of Accounts, all sourced from `journal_entries`.

## Status
- [ ] Point 1 — Balance Sheet one-ledger (in progress)
- [ ] Point 2 — guarded accrual backfill (in progress)
- [ ] Point 3 — approval fails loudly
- [ ] Point 4 — payroll cleanup
- [ ] Point 5 — current_balance reconcile
