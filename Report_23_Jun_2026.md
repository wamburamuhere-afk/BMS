# Work Report — 23 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 23 June 2026

---

## Overview

Yesterday I unified the financial records into one set of books and corrected how the main documents (bills, invoices, journals) record. Today I went one level deeper: I introduced a **disciplined six-point method** for inspecting every money flow in the system, applied it to every area, and fixed the specific gaps it exposed. The goal was simple — make sure that when money moves *and* when a record is later deleted or corrected, the books react truthfully and stay balanced.

This report covers only what I did since yesterday, and it is written around that six-point method. I am not restating areas that were already sound — I focus on **where something was missing and how I improved it**, and I state the position honestly, including what is still outstanding.

---

## The six-point method I used to inspect every money flow

For each type of transaction I asked the same six questions, in order. These are now the standard checklist recorded in the project's audit file:

1. **Two-sided?** — Does the transaction affect accounts on **two sides at once** (a real debit *and* credit), not just a one-sided note?
2. **Under what condition?** — At which exact moment, and under what rule, does it post (approve, pay, complete)?
3. **How many accounts?** — How many accounts does one transaction actually write into the central ledger?
4. **Do the reports react?** — Does a single transaction genuinely move the financial reports?
5. **Which reports?** — Exactly which statements change (Trial Balance, Income Statement, Balance Sheet, Cash Flow)?
6. **Does deletion reverse it?** — When the source document is **deleted or voided**, do the ledger and the reports **reverse too**, or is the entry left stranded?

Point **6** is where most of the hidden damage lived: a record could be created correctly, but deleting it later left its accounting behind — quietly overstating our assets, costs, or liabilities. Most of today's work was closing those gaps.

**Status key used below:** ✅ correct · ⚠️ posts but with a gap · ❌ was not handled.

---

## Areas I inspected

Using the six points, I went through **every** money area: expenses, customer invoices, credit notes, supplier bills, debit notes, payment vouchers, payroll, manual journals, bank transfers — and then the areas not yet covered: **cash register, petty cash, received payments, fixed assets, maintenance**.

Most of the document flows (points 1–5) were already correct from yesterday's work. The inspection's value was in point **6** and in a few places where a tax step was missing. The table below is the honest summary of what the method found.

| Area | Where it was missing (of the six) | What I did |
|---|---|---|
| Fixed Assets — delete | **#6** delete left all accounting behind | Fixed + healed history |
| Petty Cash — delete | **#6** delete left the entry in the books | Fixed + heal ready |
| Manual Journal — edit | **#6** a *posted* entry could still be edited | Locked (must reverse instead) |
| Customer Payment — void | **#6** no way to reverse a wrong receipt | Built a proper void |
| Credit Note — refund | **#1/#5** the VAT was not reversed | Split the entry to reverse VAT |
| Debit Note / Purchase return | **#1/#5** input VAT + unposted returns | Diagnosed; scheduled separately |
| Cash register / POS settlement / Maintenance | **all six** — no accounting yet | Documented; belongs with the POS-to-ledger project |

---

## 1. Fixed Assets — deletion now reverses the accounting

**What the six points found:** an asset posts correctly when it is added (point 1–5 ✅), but **deleting an asset (#6) removed only the asset record and left every related accounting entry behind** — the purchase, every depreciation charge, and any disposal. That silently overstated our Fixed Assets, Accumulated Depreciation, Depreciation Expense, and Accounts Payable.

**How an asset records when added** (example: equipment bought on credit for 500,000):

| Account | Debit | Credit |
|---|---|---|
| Fixed Asset — Equipment | 500,000 | |
| Accounts Payable (owed to supplier) | | 500,000 |
| **Total** | **500,000** | **500,000** |

**My fix — the professional accounting rule:**
- If an asset has **depreciation or a disposal** already posted (real history, often in closed months), deletion is now **blocked** — the asset must be *disposed*, never deleted, so the history is preserved.
- If an asset was simply **entered in error** (no depreciation yet), deleting it now posts the exact reverse of its purchase and then archives the record:

| Account | Debit | Credit |
|---|---|---|
| Accounts Payable (reversed) | 500,000 | |
| Fixed Asset — Equipment (removed) | | 500,000 |
| **Total** | **500,000** | **500,000** |

**Cleaning up the past:** I also ran a careful, repeatable correction that found and reversed **4,729 stranded asset entries** left behind by the old delete — and confirmed the ledger stayed balanced afterwards. An automated test (18 checks) confirms the new behaviour.

---

## 2. Petty Cash — deletion now reverses the accounting

**What the six points found:** petty cash posts correctly when recorded (✅ for points 1–5), but **deleting a petty cash entry (#6) left its accounting in the books** — so a spent expense kept showing in the profit figures and the petty cash float stayed reduced even after the record was gone.

**How a petty cash expense records** (example: 5,000 for office tea):

| Account | Debit | Credit |
|---|---|---|
| Office Expense | 5,000 | |
| Petty Cash (float reduced) | | 5,000 |
| **Total** | **5,000** | **5,000** |

**My fix:** deleting the entry now posts the exact reverse first, then removes the record:

| Account | Debit | Credit |
|---|---|---|
| Petty Cash (float restored) | 5,000 | |
| Office Expense (removed) | | 5,000 |
| **Total** | **5,000** | **5,000** |

The same reversal works for a petty-cash top-up (both sides restored). A repeatable correction is ready to clean any older stranded entries, and a 17-check automated test confirms it.

---

## 3. Manual Journals — a posted entry can no longer be edited

**What the six points found:** the journal tool (built yesterday) was correct, but under point **#6** I found one inconsistency — a **journal that was already *posted* (already in the reports) could still be edited**, which silently rewrites history. Deleting a posted journal was already blocked; editing was not.

**My fix:** editing is now **only allowed on a draft**. A posted journal is locked — to change it you must **reverse it (post the opposite entry) and create a new one**, which is the correct accounting practice and keeps a full trail. A 7-check automated test confirms a posted entry is blocked while a draft stays editable.

---

## 4. Customer Payments — a wrong receipt can now be voided

**What the six points found:** recording a customer payment was correct (points 1–5 ✅), but under **#6 there was no way to undo a payment entered in error** — no void, no reversal. A mistaken receipt was stuck on the books.

**How a customer payment records** (example: 118,000 received against an invoice):

| Account | Debit | Credit |
|---|---|---|
| Bank / Cash (money received) | 118,000 | |
| Accounts Receivable (debt cleared) | | 118,000 |
| **Total** | **118,000** | **118,000** |

**My fix:** I built a proper **Void** action. Voiding posts the exact reverse, marks the payment cancelled, and **re-opens the invoice** to its correct unpaid balance, and reverses the bank record — all in one safe step:

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable (debt restored) | 118,000 | |
| Bank / Cash (money returned) | | 118,000 |
| **Total** | **118,000** | **118,000** |

An 18-check automated test confirms the bank and receivable both return to zero effect and the ledger stays balanced.

---

## 5. Credit Notes — the refund now reverses the VAT

**What the six points found:** when we refund a customer for returned goods, the refund was charged in full to "Sales Returns," but the **VAT we had originally charged was never reversed** (points 1 and 5). The result: after refunding a VAT sale, we still appeared to owe the tax authority VAT on goods that had come back.

**How it recorded before** (example: refund of 11,800, which includes 1,800 VAT):

| Account | Debit | Credit |
|---|---|---|
| Sales Returns (full amount, VAT buried) | 11,800 | |
| Bank / Cash | | 11,800 |
| **Total** | **11,800** | **11,800** |

**How it records now — VAT correctly reversed:**

| Account | Debit | Credit |
|---|---|---|
| Sales Returns & Allowances (net of VAT) | 10,000 | |
| Output VAT Payable (VAT reversed) | 1,800 | |
| Bank / Cash (refund paid) | | 11,800 |
| **Total** | **11,800** | **11,800** |

A non-VAT credit note keeps its simple two-line entry, so nothing else changed. A 12-check automated test confirms the split and the balance.

---

## 6. The Balance Sheet investigation — the honest position

I also investigated why the **Balance Sheet page** still shows a difference between Assets and Liabilities + Equity. I want to state this plainly and truthfully:

- **The central ledger itself is perfectly balanced.** I confirmed that total debits exactly equal total credits across every posted entry, and that **no single transaction is unbalanced**. The accounting data is sound.
- **The figures are not seeded.** I checked the setup files: every account is created with a **zero** opening balance and zero current balance — no account (including Salaries Payable) is given a default amount. So the large balances come from **real posted activity**, not from a seeded number.
- **The gap is in the older Balance Sheet *page*, not the books.** That page was written in an earlier era when the ledger was empty, so it adds figures from the operational sub-ledgers (VAT, withholding tax, receivables, payables, accruals, salaries) **on top of** the same balances that are now already in the ledger. Because the ledger is now populated, those amounts are **counted twice** — which is why some control accounts (Output VAT, Salaries Payable, etc.) appear **twice** on that page and the totals do not agree.

**The fix (next step):** point the Balance Sheet page at the same single-ledger engine the newer reports already use, and remove the double-counting. This is a reporting-page correction; it changes **no data** and will make the displayed Balance Sheet balance, exactly as the underlying books already do.

---

## What is still outstanding (stated honestly)

- **Debit notes / purchase returns:** the input-VAT reversal and a small number of older purchase returns that never reached the ledger are **diagnosed but deliberately not rushed** — they involve historical money and deserve a focused, separately reviewed correction.
- **Cash register, POS credit settlement, and maintenance costs:** these still record **no accounting** today. By the six-point method they fail all six — but they must be wired **together** with the wider "post the point-of-sale to the ledger" project, because wiring one side alone would create a one-sided entry.
- **Balance Sheet page:** the double-counting correction described in section 6.

---

## What I achieved today

### I introduced a repeatable six-point inspection method
Every money flow is now judged against the same six questions, with special attention to the one that was quietly causing damage: *does deleting a record also reverse its accounting?*

### I closed the deletion gaps that were distorting the books
Fixed assets, petty cash, and customer payments now **reverse their accounting when removed or voided**, and a posted journal can no longer be edited behind the reports' back. Where old records had already been stranded, I cleaned them up (4,729 asset entries) and confirmed the ledger stayed balanced.

### I corrected the VAT on customer refunds
A credit-note refund now correctly reverses the VAT we had charged, so we no longer appear to owe tax on returned goods.

### I told the truth about the Balance Sheet
The books themselves balance; the difference on the Balance Sheet page is a double-counting fault in that older page, not an error in the accounting — and I identified exactly how to fix it.

---

## Summary of results

- A clear **six-point method** is now the standard for checking every money flow.
- **Deleting or voiding** an asset, a petty-cash entry, or a customer payment now reverses the accounting properly — the reports react truthfully.
- **Posted journals are protected** from silent editing.
- **Customer-refund VAT** is now reversed correctly.
- The **central ledger is proven balanced**; the Balance Sheet page's difference is a known double-counting fault with a clear, data-safe fix identified.
- Every change was confirmed by an **automated test**, and historical errors were cleaned up safely.

In short, today's work made the system honest about **corrections and deletions**, not just new entries — which is the difference between books that look right and books that *stay* right.

---

*This report covers the financial-integrity work completed on 23 June 2026, inspected and described through the six-point method.*
