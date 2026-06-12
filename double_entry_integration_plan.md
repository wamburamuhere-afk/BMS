# BMS — System-Wide Double-Entry (General Ledger) Integration Plan

**Status:** FUTURE / not started · **Created:** 2026-06-08
**Why this doc exists:** While building the POS upgrade (see `pos_upgrade_plan.md`),
we found that **nothing in BMS auto-posts to the general ledger today** — only a
couple of manual journal entries exist. Invoices, expenses, payroll, and POS all
feed the Profit or Loss / Balance Sheet **operationally** (read straight from their
own tables). Making POS the *only* module that posts to the GL would (a) double-count
revenue in the Income Statement and (b) leave BMS in an inconsistent half-ledger
state. So full GL posting was deliberately deferred to this dedicated, system-wide
initiative.

---

## 1. The benchmark (WorkDo) — how this should look

WorkDo Dash SaaS treats double-entry as **one opt-in integration layer, not a
per-module feature**:

- It is a separate **"Double Entry" add-on**, built on top of the **Accounting**
  add-on (must enable Accounting first).
- When enabled, journal entries are generated **automatically across ALL modules
  at once** — *"When you create invoices, record sales, pay bills, or record
  expenses, journal entries are automatically created in the background."* — covering
  invoices, POS orders, returns, warehouse transfers, salary payments, commissions, etc.
- When **not** enabled, the system runs operationally (no journal entries) — which is
  exactly where BMS is now.
- Accounts map by standardized code ranges: **1000s** assets (AR = 1100), **2000s**
  liabilities (incl. tax), **4000s** revenue, **5000s** expenses incl. COGS.
- Credit sales map to **Accounts Receivable**; customer payments later settle cash + AR.

Source docs: workdo.io/documents/double-entry-integration-in-dash-saas/.

**Takeaway for BMS:** build GL posting as a single system-wide engine driven by a
configurable account-mapping, switched on once, covering every money-moving module —
never bolted onto one module.

---

## 2. Current BMS state (audit)

- **Ledger tables exist and work:** `journal_entries` + `journal_entry_items`, with a
  clean, validated, transaction-aware poster: `core/ledger_post.php::postLedgerEntry()`
  (enforces Dr=Cr, joins an existing transaction, returns entry_id).
- **A simple 2-line auto-poster exists:** `core/auto_post_hook.php::autoPostEvent()`
  drives a mapping table — but only Dr/Cr single-pair entries (too simple for a sale
  that needs Dr Cash, Cr Sales, Cr VAT, Dr COGS, Cr Inventory).
- **Almost nothing posts:** `journal_entries` has ~2 manual rows; no module auto-posts.
- **The Income Statement reads operationally** (invoices, pos_sales, expenses, payroll
  closures) — NOT from the GL — and already has a "Manual Revenue Journals" line that
  *does* read the ledger (revenue-category accounts). So any module that posts revenue
  to a revenue account would double-count against its operational line.
- **Chart of accounts gaps:** Sales [655] and Inventory [623], Cash Drawer [616],
  Trade Debtors [621] exist, but there is **no COGS account** and the COA is not yet
  organized to the standard 1000–5999 ranges.

---

## 3. Scope of the initiative

A single GL-posting engine + account-mapping that covers, in priority order:

1. **Sales invoices** (approved/posted) → Dr AR / Cr Sales / Cr Output VAT.
2. **Customer receipts** → Dr Cash/Bank / Cr AR.
3. **POS sales** → Dr Cash/AR · Cr Sales · Cr Output VAT · Dr COGS · Cr Inventory
   (+ reversing entries for void/return; AR for the credit/partial sales delivered in
   `pos_upgrade_plan.md` Phase 3-A).
4. **Supplier invoices / bills** → Dr Expense or Inventory / Cr AP (+ Input VAT).
5. **Expenses paid** → Dr Expense / Cr Cash/Bank.
6. **Payroll** → Dr Salaries / Cr Cash + statutory payables (PAYE/NSSF/SDL).
7. **Depreciation, GRN, stock adjustments** as later increments.

---

## 4. Design

- **Enablement flag** (mirrors WorkDo): `system_settings.double_entry_enabled` (default
  off). When off, BMS behaves exactly as today (operational only) — zero regression.
- **Account-mapping registry**: a `gl_account_map` table / settings page mapping each
  event (sales_revenue, output_vat, input_vat, cogs, inventory, cash, bank, ar, ap,
  paye_payable, …) to an `account_id`. Seed sensible defaults from the COA; let an admin
  override. Reuse existing `default_*_account_id` settings where present.
- **Posting engine**: thin per-event functions that build the multi-line entry and call
  `postLedgerEntry()`, tagging `entity_type`/`entity_id` (source doc) and a
  `source` marker (e.g. `'pos'`, `'invoice'`) so entries are auditable and the
  reports can attribute them.
- **Reporting switch-over (critical, avoids double-count):** when `double_entry_enabled`,
  the Income Statement / Balance Sheet read from the **ledger** as the single source of
  truth, and the operational reads (including the POS revenue/COGS/returns closures added
  in `pos_upgrade_plan.md` Phase 0) are **retired in the same change**. One source of
  truth at a time — never both.
- **Historical backfill**: a one-off migration to post journal entries for existing
  recognised documents (invoices, the 37 POS sales, etc.) as at their dates, so the
  ledger-based reports match history when the flag is switched on.
- **COA preparation**: add the missing **COGS** account; (optionally) re-map the chart to
  the standard 1000–5999 ranges; ensure every mapped account exists and is the right type.

---

## 5. Phasing

1. **P-GL-0** — COA prep (COGS account, mapping registry + settings UI, enable flag).
2. **P-GL-1** — Posting engine + invoice & customer-receipt posting; tests (every entry
   balanced; reconciles to operational figures while flag is OFF in a shadow mode).
3. **P-GL-2** — POS posting (sale/void/return/AR) using Phase 3-A data.
4. **P-GL-3** — Supplier invoices/expenses/payroll posting.
5. **P-GL-4** — Backfill migration + flip the reporting source to the ledger and retire
   the operational reads. Full reconciliation sign-off.

---

## 6. Risks / rules

- **Never run both sources at once** — operational AND ledger reads for the same figure
  double-counts. The switch-over (P-GL-4) is atomic per report.
- **Backfill before flip** — flipping reports to the ledger without backfilling makes
  historical revenue vanish.
- **Balanced or nothing** — `postLedgerEntry()` already enforces Dr=Cr; every event must
  produce a balanced entry or fail the whole operation (it joins the caller's transaction).
- **Idempotent posting** — guard against double-posting the same source document
  (entity_type+entity_id unique per posted entry).

---

## 7. Relationship to the POS upgrade

`pos_upgrade_plan.md` Phase 3 was originally "POS → GL posting". That has been **split**:
- **Phase 3-A (done):** POS credit/partial-payment + AR tracking — operational, safe,
  shipped now.
- **Full POS GL posting:** folded into this initiative as **P-GL-2**, where it belongs
  alongside every other module — the WorkDo way.
