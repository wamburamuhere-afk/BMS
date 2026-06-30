# Work Report — 25 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 25 June 2026

---

## Overview

Yesterday closed with the Balance Sheet balancing and the payroll books fully wired. Today built on that foundation in five directions.

I brought the **Budget** module to the same standard as the rest of the finance system: spending is now measured accurately, a budget can no longer be quietly overspent, and cancelling a budget now unwinds the money behind it instead of leaving it on the books. I completed the **Recurring Expenses** flow so generated expenses can be paid and every one traces back to the profile that created it. I reorganised how **customers, suppliers, sub-contractors and employees** appear in the Chart of Accounts — moving them out of the account list and into proper subsidiary ledgers, the way professional accounting systems do, without touching a single posting. I investigated a **Salaries Payable** figure that looked alarming (about 44 billion) and proved it was not real money but leftover test data, then cleaned it. And I **verified the posting engine itself** against live data to confirm every transaction records correctly on both sides.

A small display fix to the header on mobile rounds out the day.

This report covers only what changed since yesterday.

---

## 1. Budget — measured accurately, enforced, and reversible

The Budget module looked complete but had three real weaknesses: it under-counted what had actually been spent, it let a budget be overspent, and cancelling a budget left the linked spending sitting in the books. All three are now closed.

**Spending is now counted correctly.** The old calculation matched expenses to budgets by category name and missed any expense entered through Quick Expense (which links through a different table). The result was budgets showing "Unused" even when money had clearly been spent. Spending is now matched to each budget by a direct link (the budget's own ID), with a safe fallback for older records. A budget that has been spent against now shows its true figure — verified against the live data.

**A budget can no longer be silently overspent.** The Quick Expense screen now shows a live utilisation panel — allocated, already used, and remaining — that updates as the amount is typed. If the amount would push spending over the budget, the screen turns red, warns that the save will be blocked, and refuses to record the expense. Every budget row now also carries a clear spending state — *Unused, Partially Used, Nearly Full, Fully Used, or Over Budget* — with a colour-coded progress bar showing used versus remaining at a glance.

**Quick Expense now records accounting immediately.** A Quick Expense paid on the spot now posts its entry the moment it is saved, and the confirmation shows exactly which accounts moved:

| Account | Debit | Credit |
|---|---|---|
| Expense account | amount | |
| Bank / Cash (paid from) | | amount |

A paid expense is then locked from deletion — it must be voided, which reverses both the ledger entry and the bank record.

**Cancelling a budget now unwinds its spending.** Previously, deleting or rejecting a budget removed the budget but left every expense paid against it still posted in the books. Now, deleting or rejecting a budget first finds every paid expense linked to it, reverses each one's accounting entry, restores the affected account balances, and voids the expense — all inside a single safe transaction that rolls back completely if anything fails. The confirmation reports how many entries were reversed.

---

## 2. Recurring Expenses — payment flow and full traceability

Recurring profiles could generate expenses, but the generated expenses could not be acted on cleanly and could not be traced back to where they came from.

**The generated expenses can now be paid.** From the generated-expenses view, each expense can be approved and then paid, with the payment pre-filled (date, amount, paid-from account) and posted through the same single ledger every other payment uses — no new accounting path was introduced.

**Every generated expense now traces to its profile.** Each expense created by a recurring profile now carries a direct link back to that profile and shows a **Recurring** badge in the expenses list, on both desktop and mobile. This closes a traceability gap — you can now see at a glance which expenses were system-generated and from which schedule.

---

## 3. Chart of Accounts — parties are now subsidiary ledgers, not accounts

Until today, **every customer, supplier, sub-contractor and employee had its own account inside the Chart of Accounts** — nearly three hundred of them — cluttering the chart and appearing in every account dropdown when recording a transaction. This is not how professional accounting systems present this information.

I reorganised them into the standard **control-account + subsidiary-ledger** model:

- The Chart of Accounts now shows only the **control accounts** — *Trade Debtors* (customers), *Trade Creditors* (suppliers and sub-contractors), and *Salaries Payable* (employees).
- Each individual party is no longer treated as an account. It is a **subsidiary-ledger entry**, listed by serial number, that you see only when you open ("drill into") its control account — where each party shows what it was billed, what it has paid, and its outstanding balance.
- These parties no longer appear in any account-selection list when recording a journal or a transaction.

**The most important point: no money logic was changed.** Before making this change I proved, against the live ledger, that these per-party accounts were **never actually used in any posting** — every transaction already posts to the control account, and each party's real balance is tracked from the operational records (invoices, bills, payroll). So paying a salary still records against the right employee, paying a bill still records against the right supplier, and receiving a payment still shows the right customer — exactly as before. The change is purely about how the information is presented.

Suppliers, sub-contractors and customers are fully covered with the subsidiary-ledger drill-in. The employee subsidiary ledger is intentionally **held back** pending one accounting item noted in section 4.

This work has been submitted for the live system through a reviewed change.

---

## 4. Salaries Payable — the 44-billion figure was test data, not real money

While preparing the employee subsidiary ledger, the **Salaries Payable** balance showed roughly **44 billion** — clearly impossible for the business. I traced it carefully through the posted ledger rather than assume a cause.

**What I found:** the figure was **not a real balance and not a posting error**. It was **balanced-but-meaningless test data** — 154 leftover payroll accrual entries created by automated test runs writing into the database. Every one was dated in the future (January 2031), carried a fabricated payroll reference that does not exist in the payroll records, and included a fictitious two-billion test salary. Because each test entry was internally balanced (it had both a debit and a credit), it never broke the books — it simply inflated the totals in an *all-time* view.

**An important reassurance:** because every one of these entries is dated in the future, **the financial reports as they stand today already exclude them** — a Balance Sheet dated today shows the real, small Salaries Payable figure, not the 44 billion. The inflated number only ever appears in an all-time or future-dated view.

**What I did:** I built a safe, repeatable cleanup that removes these leftover entries by their defining trait — payroll accruals that point to a payroll record which does not exist. It removed all 154, the books remained perfectly balanced before and after, and the matching fictitious salary expense came off the profit calculation at the same time. The cleanup is harmless to run anywhere: on a clean database it simply finds nothing to do.

I also identified the **source** of these leftovers: a payroll test that creates entries in the database and, when cleaning up after itself, removed the payroll records but not the matching ledger entries — leaving them orphaned. I confirmed this only affects the local development database; the production system never runs these tests, so the production books are not exposed to this.

---

## 5. Posting integrity — the engine is sound (verified, not assumed)

Before trusting any of the above, I verified the heart of the system directly against the live ledger: for every money flow, does each transaction record on **both** sides and **balance**?

I checked every posted transaction across four flows — payroll payments, supplier bills, customer invoices, and customer receipts — **247 transactions in total**. The result:

| Flow | Transactions | Result |
|---|---|---|
| Payroll payment | 2 | every one balanced |
| Supplier bill (received invoice) | 147 | every one balanced |
| Customer invoice | 15 | every one balanced |
| Customer receipt | 83 | every one balanced |

**Not a single transaction was one-sided, and not a single one was out of balance.** Each posts the expected accounts — for example, a supplier bill records *Inventory and recoverable VAT* against *Trade Creditors*, and a customer receipt records *Cash and withholding tax* against *Trade Debtors*. This confirms the 44-billion issue was never a fault in the posting engine — only stray test data sitting on top of a sound foundation.

---

## 6. Header display on mobile

On phones, the date and location shown in the top header were rendering oversized and crowding the header. The sizing is now corrected so they display compactly on small screens, matching the rest of the header. Desktop is unchanged.

---

## Items identified for follow-up

Two matters were investigated and deliberately left for a dedicated session rather than rushed:

- **Paying a supplier or sub-contractor invoice through the Expenses screen** currently records the cost a second time and does not clear the original amount owed, because that route posts a fresh expense instead of settling the existing bill. The correct path (paying from the supplier's own screen) is unaffected. The fix is to make the Expenses route settle the existing bill rather than create a new cost.
- **The employee subsidiary ledger** is held back until the payroll-posting figure behind Salaries Payable is reconciled, so that the per-employee view ties exactly to the control account.

---

## What I achieved today

**Budget is now trustworthy.** Spending is counted accurately, overspending is blocked at the point of entry, every budget shows a clear used-versus-remaining state, Quick Expense posts its accounting immediately, and cancelling a budget cleanly reverses the money behind it.

**Recurring expenses are complete and traceable.** Generated expenses can be paid through the standard ledger, and each one is linked and badged back to the schedule that produced it.

**The Chart of Accounts is clean and professional.** Nearly three hundred party accounts have been moved out of the chart and into proper subsidiary ledgers — with zero change to how money posts, proven against the live ledger.

**A 44-billion phantom was explained and removed.** It was leftover test data, not real money; today's reports already excluded it, and it has now been cleaned away with the books staying balanced throughout.

**The posting engine is verified sound.** Across 247 live transactions, every one records on both sides and balances to the cent.

---

## Summary

Today extended the same discipline established over the previous days — *one central ledger, every movement recorded on both sides, every cancellation reversed before the record is touched* — into the Budget and Recurring Expense areas, and applied it to how parties are presented in the Chart of Accounts.

Just as importantly, the day proved the foundation is solid: the one figure that looked wrong turned out to be stray test data, not a posting fault, and a direct check of 247 live transactions confirmed the engine records and balances every one. The books are sound; the work now is presentation, enforcement, and tidiness on top of a ledger that already holds.

---

*This report covers the work completed on 25 June 2026.*
