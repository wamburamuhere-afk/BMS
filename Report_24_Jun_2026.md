# Work Report — 24 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 24 June 2026

---

## Overview

At the end of yesterday's report I made one promise: the Balance Sheet was showing a wrong figure because of double-counting, and fixing it was the next step. That fix is the first thing I completed today, and the Balance Sheet now balances.

From there the day moved in three directions. I found and corrected two classification errors that were silently hiding certain expenses and other income from the profit calculation. I extended the same principle — *every money movement must record accounting on both sides* — to stock adjustments, which had never produced any accounting entry at all. And I brought the payroll area into full integrity, fixing five specific gaps in how payroll amounts were reaching the books, adding the employer's share of NSSF, and replacing the hard-delete action with a proper void.

Along the way I also resolved three operations that appeared to work on the local test machine but were hard-failing on the live server whenever a user tried them.

This report covers only what changed since yesterday.

---

## 1. Balance Sheet — the promise from yesterday is kept

**What yesterday's investigation found:** the Balance Sheet page was showing a wrong total even though the underlying books were perfectly balanced. Three specific faults explained it:

1. The page was reading seven separate sub-ledger summaries — payroll pending, VAT, receivables, payables, and more — and adding them *on top of* the same figures already in the posted ledger. This counted every one of those amounts twice. For payroll, it was adding payroll that had never been approved or posted anywhere — money that was still in a pending queue — creating a phantom Salaries Payable balance in the billions.
2. The page was also adding an opening-balance column from the accounts table to every line. That column is never updated by the posting engine, so it drifts silently and does not match the books.
3. Draft and voided entries were leaking into the figures because the status filter was not applied correctly.

**My fix:** all three faults are now closed. The Balance Sheet page reads only the single posted ledger — nothing else. The same source every other financial report already uses. The phantom Salaries Payable is gone. The double-counting is gone. The Balance Sheet now agrees with the books.

An automated test (13 checks) confirms the new behaviour.

---

## 2. Finance Costs and Other Income — two categories were invisible to the Balance Sheet

**What was wrong:** the Balance Sheet calculates Retained Earnings by summing up all profit since the business started. That calculation was reading Revenue, Cost of Goods Sold, and Expenses — but two account types were missing: Finance Costs (for example, bank charges) and Other Income. Both were silently returning zero instead of their real values.

**What this caused on the Balance Sheet:** when a bank charge was posted, the bank account correctly went down by the charge amount. But Retained Earnings did not absorb the charge, because Finance Costs were returning zero. The asset side fell; the equity side did not follow. The Balance Sheet therefore showed "DOES NOT BALANCE — difference = X.XX" where X.XX was exactly the bank charge amount.

**My fix:** both categories are now correctly included in the profit calculation:

| Category | Effect on profit |
|---|---|
| Revenue | + adds to profit |
| Other Income | + adds to profit |
| Cost of Goods Sold | − reduces profit |
| Expense | − reduces profit |
| Finance Cost (bank charges, etc.) | − reduces profit |

The Balance Sheet no longer shows a gap caused by bank charges or other income entries.

An automated test (15 checks) confirms both categories return their correct values.

---

## 3. Bank Transfer charges — the charge account balance was not being updated

**What was wrong:** when a bank transfer is made with a bank charge, the central ledger entry is correct — the charge account is debited, the destination account is debited, and the source account is credited, and the two sides balance. However, the *running balance* on the charge account itself was never being updated when the transfer was posted, and never restored when the transfer was reversed. Every charge was therefore invisible in the charge account's own balance, even though the journal entries behind it were correct.

**How a bank transfer with a charge records** (example: transferring 1,000,000 between accounts with a 5,000 bank charge):

| Account | Debit | Credit |
|---|---|---|
| Destination Bank Account | 1,000,000 | |
| Bank Charge Expense | 5,000 | |
| Source Bank Account | | 1,005,000 |
| **Total** | **1,005,000** | **1,005,000** |

**My fix:** the charge account balance now correctly increases when the transfer is posted, and correctly restores when a transfer is reversed. The journal entry was already sound; this fix keeps the account's running balance in step with it.

An automated test (16 checks) confirms the charge mirrors on both post and reversal, and that the three-leg entry always balances.

---

## 4. Three operations that worked locally were failing on the live server

**What was wrong:** three specific operations — settling a credit note (refunding a customer), settling a debit note (receiving a refund from a supplier), and topping up the petty cash float — were producing the following error when users tried them on the live production server: *"The receipt/payment could not be written to the ledger — the double entry did not post. Nothing was saved."*

The root cause was that these three transaction types had not been registered in a list that the live database enforces strictly. The local test server is lenient and ignores unknown types silently; the live server rejects them with a hard error. The result was that every attempt to complete these three operations failed on the live system.

**My fix:** all three types are now correctly registered. Settling credit notes, settling debit notes, and topping up petty cash all post successfully to the ledger on the live server.

---

## 5. Stock Adjustments — every adjustment now records accounting

**What was wrong:** when we adjust stock — discovering damaged goods, finding extra items in a stocktake, writing off expired products, or making a manual correction — those adjustments change the quantity in stock. Before today they were recorded only in the stock movement log. They created **no accounting entry**. The Inventory figure on the Balance Sheet was therefore always stale after any adjustment, and the books had no record of why the inventory value had changed.

**The correct accounting — two rules covering all adjustment types:**

When inventory goes **up** (goods found or adjusted in):

| Account | Debit | Credit |
|---|---|---|
| Inventory (stock increased) | amount | |
| Historical Balancing Equity | | amount |
| **Total** | **amount** | **amount** |

When inventory goes **down** (damaged, expired, written off, or adjusted out):

| Account | Debit | Credit |
|---|---|---|
| Historical Balancing Equity | amount | |
| Inventory (stock reduced) | | amount |
| **Total** | **amount** | **amount** |

Amount in all cases = quantity × unit cost.

**My fix:** every stock adjustment now produces the correct accounting entry when it is created. Editing an adjustment first reverses the original entry, then posts a fresh one with the new values. Deleting an adjustment reverses the original entry before removing the record. The Balance Sheet Inventory figure now stays accurate after every adjustment. The Trial Balance is also affected, as both sides of the entry appear there.

---

## 6. Payroll — employer NSSF, proper void, and five GL integrity gaps

### The employer's share of NSSF was not being recorded

**What was wrong:** Tanzania law requires both the employee and the employer to contribute to NSSF. The employee's share was being deducted from salary and posted correctly. The employer's share — 10% of gross salary — was not being computed, not stored, and not reaching the books. The NSSF Payable figure on the Balance Sheet was therefore showing only half of what we actually owe.

**How it records now:**

| Account | Debit | Credit |
|---|---|---|
| NSSF Employer Expense | employer's 10% | |
| NSSF Payable (combined total) | | employee share + employer share |
| **Total** | **combined** | **combined** |

The payslip breakdown now also shows the employer's cost as a separate line, visible to both the employee and the accounts team.

---

### Deleting a payroll run now voids it — the history is kept

**What was wrong:** when a payroll run was deleted, the record was permanently removed. The accounting reversal still ran, but the payroll row itself was gone — leaving no audit trail of what had been paid, to whom, or why it was cancelled.

**My fix:** deleting a payroll run now performs a **Void** rather than erasing the record. The payroll stays in the system, marked as voided with a reason and a timestamp. The accounting is still reversed. A voided employee can be re-processed for the same period if needed. On the payroll screen, voided runs are shown with a strikethrough and a "voided" badge — visible for the record but no further actions are available on them.

---

### Five gaps in how payroll amounts reached the books

Using the same six-point inspection method from yesterday, I found and closed five specific gaps in the payroll posting chain.

| Gap | What was wrong | What I fixed |
|---|---|---|
| **NSSF and PAYE were sometimes skipped** | Statutory deductions (NSSF and PAYE) were tied to an optional checkbox. When that checkbox was not ticked, the tax obligations were simply not computed — even though they are not optional by law | Statutory deductions now always compute, regardless of the checkbox. The checkbox controls only voluntary deductions |
| **Marking payroll as Paid recorded no accounting** | The "Mark as Paid" action updated the payroll status to Paid but posted no journal entry — so Salaries Payable was never cleared and the bank account was never reduced | The action now posts the correct entry (Dr Salaries Payable / Cr Bank) and rolls back if the accounting fails |
| **SDL expense was being double-counted** | The Skills Development Levy calculation re-posted its entry each time it ran without removing the previous one, so the SDL expense and liability doubled every time the period was recalculated | The posting now removes any existing entry for the period before creating a new one. One confirmed duplicate was also removed from the live data |
| **32 past payrolls had no accounting entry** | Payrolls from January to June 2026 that were approved and paid had incomplete or missing accounting entries — in many cases because the records they pointed to had been deleted | All 32 were corrected by re-running the accrual calculation. One zero-gross payroll was intentionally skipped |
| **Accounting failures were silent** | When payroll posting failed for any reason, the error was written only to an internal log. The user saw a success message and the payroll appeared to complete normally | All three places where payroll can be approved or paid now surface a real error message if the accounting fails, and roll back the entire operation so nothing is left half-done |

---

## What I achieved today

### The Balance Sheet now balances
The promise made at the end of yesterday's report is kept. The double-counting is gone, the phantom Salaries Payable is gone, the draft-and-void leak is closed, and the Balance Sheet agrees with the books.

### Finance Costs and Other Income now reach Retained Earnings
Bank charges and other income no longer disappear silently from the profit calculation. The gap they created on the Balance Sheet is closed.

### Bank Transfer charges are correctly tracked
The charge account balance now moves in step with every transfer posted and every transfer reversed.

### Three live-server failures are resolved
Settling credit notes, settling debit notes, and topping up petty cash all work correctly on the live server.

### Stock Adjustments now produce correct accounting
Every inventory adjustment creates a balanced ledger entry. Editing and deleting both handle the reversal correctly. The Balance Sheet Inventory figure is now accurate after every adjustment.

### Payroll GL is fully wired
The employer NSSF share is computed and posted. Payroll deletions are now soft voids with an audit trail. All five posting gaps are closed — statutory deductions always compute, marking payroll paid moves money correctly, SDL has one entry per period, the 32 historical gaps are backfilled, and failures surface as real errors rather than silent success messages.

---

## Summary

At the end of today the financial reports are **balanced**. This is the direct result of the mechanism put in place over the past two days: a single central double-entry ledger is the only source any report reads; every money movement writes to it on both sides; and every deletion, void, or correction writes the reverse entry before the record is touched.

The six-point method introduced yesterday identified the gaps; today's work closed the last of them within scope. For every area that has been wired, the books are balanced and all four reports — Trial Balance, Income Statement, Balance Sheet, and Cash Flow — now read from the same single source and agree with each other.

---

*This report covers the work completed on 24 June 2026.*
