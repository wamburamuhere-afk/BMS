# Work Report — 22 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 22 June 2026

---

## Overview

Today I focused on strengthening the financial core of our Business Management System. My goal was to make sure that every money transaction in the company is recorded accurately, follows proper double-entry accounting, and feeds our financial reports in a way that can be fully trusted. Below is a summary of what I achieved, including how the system now records each transaction account by account.

---

## How the system is structured to keep every transaction balanced

At the centre of the system is one accounting ledger, built from two tables that work together:

- **The transaction record (the header):** one row for every transaction. It holds the date, a short description, the status (only "posted" transactions count in the reports), and a link back to the document it came from (a bill, an invoice, a journal, and so on).
- **The transaction lines (the detail):** several rows beneath each header — one row for every account affected. Each line records **which account**, whether it is a **debit or a credit**, and the **amount**.

One transaction can therefore touch many accounts, but the actual money amounts always live in the lines. Before any transaction is saved, the system adds up all the debits and all the credits and **refuses to save unless the two totals are exactly equal**. This is the foundation of double-entry accounting, and it is what guarantees our reports stay correct.

Because every transaction is stored this way, the reports are produced simply by reading all the posted lines and grouping them by account:

- The **Trial Balance** lists every account's total debits and credits — and because each transaction balances, the grand totals always match.
- The **Balance Sheet** shows the asset, liability, and equity accounts.
- The **Income Statement** shows the income and expense accounts.

The sections below show exactly how the main transactions record into this structure.

---

## A naming change I made: "Received Invoice" is now "Bill"

I renamed the "Received Invoice" feature to **"Bill"** throughout the system. "Bill" is the clearer, standard accounting term for a document that records money we owe a supplier, and it removes the confusion between what *we* send out (a customer invoice) and what we *receive and owe* (a bill). For this reason, the section below now explains it as **"How a supplier bill is recorded."**

---

## 1. How a supplier bill is recorded

A supplier bill is money we owe a supplier. Using an example of goods worth 100,000 plus 18% VAT (18,000), here is exactly how it records.

**When the bill is approved — 3 accounts are involved:**

| Account | Debit | Credit |
|---|---|---|
| Inventory / Cost | 100,000 | |
| Input VAT Recoverable (claimable from the tax authority) | 18,000 | |
| Accounts Payable — Trade Creditors (what we owe the supplier) | | 118,000 |
| **Total** | **118,000** | **118,000** |

**When the bill is paid (with withholding tax of, say, 5,000) — 3 accounts are involved:**

| Account | Debit | Credit |
|---|---|---|
| Accounts Payable — Trade Creditors (debt cleared) | 118,000 | |
| Bank / Cash (actual money paid out) | | 113,000 |
| Withholding Tax Payable (held for the tax authority) | | 5,000 |
| **Total** | **118,000** | **118,000** |

In both steps the debits equal the credits, so the books stay balanced. These entries appear on the **Balance Sheet** (Inventory, Input VAT, Accounts Payable, Withholding Tax Payable, and Bank), and the reclaimable VAT and withholding tax are correctly kept out of our profit figures. My key improvement here was separating the VAT out of the cost so our expenses and our reclaimable tax are both shown accurately.

---

## 2. How a customer invoice is recorded

A customer invoice is money our customers owe us — the mirror image of a supplier bill. Using an example of a sale of 100,000 plus 18% VAT (18,000):

**When the invoice is approved — 3 accounts are involved:**

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable — Trade Debtors (what the customer owes us) | 118,000 | |
| Sales Income (our earnings, excluding VAT) | | 100,000 |
| Output VAT Payable (VAT we owe the tax authority) | | 18,000 |
| **Total** | **118,000** | **118,000** |

**The cost of the goods sold (if the sale is of goods costing, say, 60,000) — 2 accounts are involved:**

| Account | Debit | Credit |
|---|---|---|
| Cost of Goods Sold | 60,000 | |
| Inventory (stock leaving the business) | | 60,000 |
| **Total** | **60,000** | **60,000** |

**When the customer pays (and withholds 5,000 tax) — 3 accounts are involved:**

| Account | Debit | Credit |
|---|---|---|
| Bank / Cash (money actually received) | 113,000 | |
| Withholding Tax Receivable (a tax credit in our favour) | 5,000 | |
| Accounts Receivable — Trade Debtors (debt cleared) | | 118,000 |
| **Total** | **118,000** | **118,000** |

Again, every step balances. The **Sales Income** and **Cost of Goods Sold** appear on the **Income Statement** (showing our true profit on the sale), while **Accounts Receivable, Output VAT, Withholding Tax Receivable, Bank, and Inventory** appear on the **Balance Sheet**.

---

## 3. New feature: the Journal entry tool

This is a **brand-new feature I added** to the system — it did not exist before. A journal is the accountant's tool for recording direct accounting entries and corrections that do not come from a normal bill, invoice, or payment — for example, fixing an amount posted to the wrong account, recording an adjustment, or entering opening balances.

**Its purpose:** to give us a safe, controlled way to make direct accounting entries and corrections, so the books can always be kept accurate without bypassing proper double-entry rules.

**The features I built into it:**

- **Create, view, edit, reverse, void, and delete** journal entries — a complete lifecycle.
- A built-in **balance check** that blocks saving until the debit and credit sides are equal.
- A clear list with a **serial number**, full-width layout, and search/filter by account, status, and date.
- **Friendly confirmation messages** for every action, instead of plain browser warnings.
- Account selections that show the **account code beside the name** for accuracy.
- A clean **printable view** of each entry.
- It is reached from the **Finance menu**.

**What is available now — how a journal records:** the person creating it chooses the account for the **debit side** and the account for the **credit side** and enters the amount. For example, correcting an amount of 50,000 that was posted to the wrong account:

| Account | Debit | Credit |
|---|---|---|
| The correct account | 50,000 | |
| The account it was wrongly posted to | | 50,000 |
| **Total** | **50,000** | **50,000** |

A journal can involve two accounts (one debit, one credit) or several, but the system **will not allow it to be saved unless the debit total equals the credit total**. Once saved, it records straight into the central ledger like every other transaction and appears immediately on whichever reports those accounts belong to. This is why a journal can be used to record adjustments and corrections safely, without ever putting the books out of balance.

---

## What I achieved today

### I unified all financial records into one reliable set of books
Previously, financial information was kept in several separate places that did not always agree. I made every transaction record into the one central ledger described above, so all our reports now draw from a single, consistent source and always agree with each other.

### I corrected how supplier bills are recorded
I separated the reclaimable VAT out of the cost (it was previously hidden inside it), so our expenses are no longer overstated and the tax we can reclaim is clearly shown. I also made sure withholding tax is correctly set aside when a bill is paid.

### I aligned customer invoices to the same standard
I confirmed invoices record as the exact mirror of bills — sales income separate from VAT, VAT owed correctly set aside, and customer-withheld tax treated as a credit in our favour.

### I built a complete and reliable Journal tool
I added it to the Finance menu and made create, view, edit, reverse, void, and delete all work correctly, with a strict balance check. I also improved the experience: a serial number on the list, full-width layout, friendly confirmation messages, fixed broken links and buttons, a corrected printout (the company name and logo no longer appear twice), and account selections that show the account code beside the name.

### I reviewed every money flow and cleaned up old errors
I reviewed every type of transaction — expenses, sales, credit notes, supplier bills, debit notes, payment vouchers, payroll, adjustments, and bank transfers — and confirmed each records correctly and balances. I also removed old leftover entries that had been knocking the Balance Sheet slightly out of balance, so the books now balance precisely.

---

## Summary of results

- All financial records now come from **one reliable, consistent set of books**.
- **Supplier bills and customer invoices** record their costs, income, and taxes correctly, account by account.
- Every transaction is stored so that its **debits equal its credits**, which keeps the reports balanced automatically.
- **VAT and withholding tax** are handled properly and shown on the correct reports.
- A complete, easy-to-use **Journal tool** is ready for daily use.
- The books **balance correctly**, and our financial reports can be fully trusted.

In short, the financial foundation of the system is now accurate, consistent, and dependable.

---

*This report covers all work completed on 22 June 2026.*
