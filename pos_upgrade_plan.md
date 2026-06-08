# BMS POS — Professionalisation Plan (WorkDo gap closure)

**Status:** DRAFT for approval · **Owner:** (dev) · **Created:** 2026-06-08
**Benchmark:** WorkDo Dash SaaS — POS add-on
**Related work already shipped:** PR `feat/pos-sales-in-income-statement` (POS sales/COGS now appear in the Income Statement).

---

## 1. Objective

Bring the BMS POS up to a professional, WorkDo-class standard by closing the
gaps found in the code/data audit — **without** re-architecting what already
works. The schema was clearly designed for a full POS (returns, void, partial
payments, loyalty, delivery columns already exist on `pos_sales`); most of this
plan is **building the missing logic on columns that are already there**, not new
tables.

### Guiding principles
- **Reuse the existing rich schema.** `pos_sales` already has `is_return_sale`,
  `original_sale_id`, `return_reason`, `voided_at/by`, `void_reason`,
  `payment_status`, `invoice_id`. Wire logic to them; add columns only where truly absent.
- **Stay consistent with BMS accounting.** Every money movement must reconcile
  with the Income Statement and (Phase 3) the General Ledger. The recognition
  rules already added to `get_income_statement.php` are the contract.
- **Follow BMS conventions** — `.claude/templates.md` (page/API skeleton),
  `.claude/security.md` (CSRF, permissions, soft-delete, logging), project scope,
  Select2, mobile card view, CLI test per phase, branch→PR into `develop`.

---

## 2. Current state (audit summary)

**Live write path:** `api/pos/process_sale.php` → inserts `pos_sales` +
`pos_sale_items`, updates stock via `core/stock_ledger.php::recordStockMovement`,
records cash via `cash_register_transactions`.

**Works today:** cart + customer + warehouse + **project** tagging, discount,
tender/change, receipt (`api/pos/print_receipt.php`), receipt-number generation,
**cash-register shifts** (`open_shift`/`close_shift`), **hold/park** sale,
stock reservations, multiple payment methods (cash/card/mobile_money/bank_transfer/credit/split).

**Gaps vs WorkDo (to fix):**

| # | Gap | Evidence in code/data |
|---|-----|----------------------|
| G1 | **No Sales Return / Refund** | `is_return_sale`,`original_sale_id`,`return_reason` columns exist but **no endpoint/UI** writes them; all rows `is_return_sale=0` |
| G2 | **No Void / Cancel** | `voided_at`,`voided_by`,`void_reason` exist but no mechanism; `sale_status` only ever `'completed'` |
| G3 | **No partial / due (credit) tracking** | `payment_status` hardcoded `'paid'` in `process_sale.php:66`, even for `credit` method |
| G4 | **VAT selection unclear** | `tax_rates` mixes VAT 0/5/18% + WHT; existing POS sales recorded at 0%/5%, never 18% |
| G5 | **No POS dashboard / analytics** | no daily revenue / AOV / trend / top-seller view |
| G6 | **POS never posts to the General Ledger** | no journal entries (Dr Cash, Cr Sales, Cr Output VAT, Dr COGS, Cr Inventory) |
| G7 | **No soft-delete / reversal of a bad sale** | a mistaken/test sale stays in P&L forever (ties to G1/G2) |
| G8 | **Dead duplicate code** | `app/bms/pos/models/POSModel.php` inserts into non-existent `sales`/`sale_items` tables |
| G9 | Nice-to-haves | barcode print, quotation→POS conversion |

---

## 3. Phased plan

> Each phase = its own branch off `develop`, its own CLI test suite, its own PR.
> Phases are ordered by **accounting integrity first**, then compliance, then GL,
> then UX. Each phase is shippable on its own.

### Phase 1 — Sales Return / Refund + Void  ✅ DONE (branch feat/pos-returns-void)

Closes **G1, G2, G7** and makes the recognition guards already in the Income
Statement *live* and meaningful.

**Shipped:** api/pos/void_sale.php, api/pos/create_return.php, api/pos/get_sales.php,
api/pos/get_sale_items.php, app/bms/pos/sales_history.php (UI per ui-constants.md),
P&L contra ("Less: POS Returns" + net POS COGS, pos_returns drill), routes + menu,
tests/test_pos_returns_cli.php (25 checks) + test_income_statement_cli.php §12 (75).
Accounting model: void = excluded both sides + stock/cash reversed; return = original
keeps gross, separate contra row subtracts, COGS net of restocked cost.

**1a. Void a sale** (reverse a wrong/test sale)
- New `api/pos/void_sale.php` (POST, CSRF, `canDelete('pos')` or a new
  `canVoid('pos')` per §11.1):
  - Guard: sale must be `sale_status='completed'` and not already voided.
  - Set `sale_status='voided'`, `voided_at=NOW()`, `voided_by`, `void_reason`.
  - **Reverse stock**: `recordStockMovement` `movement_type='sale_return'`
    (or reverse `sale_out`) restoring quantity to the same warehouse/project.
  - **Reverse cash**: insert a `cash_register_transactions` `type='refund'`
    (or negative) for the shift, so the drawer reconciles.
  - `logActivity` + `logAudit`.
- P&L effect: **automatic** — voided sales are excluded by the existing filter
  `sale_status IN ('completed','partially_refunded')`.

**1b. Sales Return / Refund** (partial or full return of items)
- New `api/pos/create_return.php` (POST, CSRF, `canCreate('pos')` or
  `canRefund('pos')`):
  - Input: `original_sale_id`, list of `{sale_item_id, return_qty}`, `reason`,
    `refund_method`.
  - Validate each `return_qty ≤ (quantity − returned_quantity)` on the line.
  - Create a **return sale row** in `pos_sales` with `is_return_sale=1`,
    `original_sale_id=<orig>`, negative/clearly-marked amounts, `sale_status='refunded'`.
  - Insert matching `pos_sale_items` (return lines); increment
    `pos_sale_items.returned_quantity` and set `is_returned` on the originals.
  - **Restock** returned qty; **cash-out** refund via `cash_register_transactions`.
  - Flip the **original** sale to `partially_refunded` (some lines) or `refunded`
    (all lines).
  - `logActivity` + `logAudit`.
- **Income Statement integration (required in this phase):** extend
  `api/account/get_income_statement.php` so recognised **POS returns** reduce
  revenue (contra) — fold POS returns into the existing
  *"Less: Sales Returns & Credit Notes"* line, and reduce POS COGS by the
  restocked cost. Add a `pos_returns` drill source in
  `get_income_statement_detail.php`. This keeps the P&L self-consistent.

**1c. UI**
- On the POS sales history / receipt view: **Void** button (admin/manager only)
  and **Return** action opening a modal that lists the original lines with a
  "qty to return" input per line, a reason, and a refund-method select.
- Mobile card view per `.claude/templates.md`.

**1d. Permissions** — **reuse the standard CRUD helpers, no new permission keys**
(decision locked):
- View sales history / receipts → `canView('pos')`
- Create a sale **and** create a return/refund → `canCreate('pos')`
  (a return is a new transaction record)
- Settle a due/credit payment (update existing) → `canEdit('pos')`
- Void a sale (reversal / soft-removal) → `canDelete('pos')`

Admins bypass via `isAdmin()`. Each action is still gated in PHP, JS, and the API.

**1e. Tests** — `tests/test_pos_returns_cli.php`:
- void excludes the sale from P&L; stock restored; cash reversed.
- partial return: returned_qty capped; original→`partially_refunded`; P&L nets the contra.
- full return: original→`refunded`.
- transaction-wrapped + rolled back; reconcile to direct SQL.

---

### Phase 2 — VAT: a clean two-option choice  *(compliance)*

Closes **G4**. **Per requirement: VAT is never auto-applied/hardcoded. The user
explicitly chooses one of exactly TWO options per line/sale:**

| Option | Rate | Meaning |
|--------|------|---------|
| **No Tax (0%)** | 0% | zero-rated / exempt / non-VAT sale (default selection) |
| **VAT 18%** | 18% | standard Tanzanian VAT |

**Scope**
- In `app/bms/pos/pos.php` (the tax dropdown built at line ~62 from `tax_rates`),
  **restrict the POS tax selector to exactly these two**: `rate_id` for
  *No Tax (0%)* and *VAT 18%*. Hide the *Reduced Rate 5%* and the WHT rates from
  the POS line selector (WHT is not a POS sales tax; the 5% reduced rate is out of
  scope per requirement).
- **No forced default beyond "No Tax".** The cashier actively switches a line to
  *VAT 18%* when the sale is taxable. Nothing is assumed.
- Keep the existing exclusive (added-on-top) calculation in
  `process_sale.php` — it is already correct (`grand_total − tax_amount = net`).
- **Output-VAT capture:** record the VAT portion so it is available for TRA
  returns — credited to `default_output_vat_account_id` (system_settings = 779).
  (Full GL posting lands in Phase 3; in Phase 2 we at least surface POS output VAT
  in the existing VAT/Tax report.)

**Tests** — `tests/test_pos_vat_cli.php`: only two options exposed; selecting
VAT 18% yields `tax_amount = net × 0.18`; selecting No Tax yields 0; net revenue
in P&L equals `grand_total − tax_amount`.

---

### Phase 3 — SPLIT after architecture review

Investigation (2026-06-08) found **nothing in BMS auto-posts to the general ledger**;
the P&L reads operationally. POS-only GL posting would double-count revenue and leave a
half-ledger system. So Phase 3 was split:

- **Phase 3-A — Credit / partial-payment + Accounts Receivable ✅ DONE** (branch
  `feat/pos-credit-ar`): `pos_sale_payments` table; `process_sale.php` derives
  payment_status (pending/partial/paid), credit requires a customer, records the deposit;
  `receive_payment.php` settles later; history page shows balance + Receive Payment;
  terminal credit flow. Operational — no GL, no double-count. Tests:
  `tests/test_pos_credit_ar_cli.php` (19). This is the WorkDo POS "pay later / settle" model.
- **Full POS → GL posting → MOVED** to the system-wide **`double_entry_integration_plan.md`**
  (as P-GL-2), where it belongs alongside invoices/expenses/payroll — the WorkDo way
  (double-entry is one opt-in layer across all modules, not per-module).

The original design notes below are retained for that future work.

Closes **G6** (and completes **G3**). Largest phase; own branch.

- On a **completed sale**, post a balanced journal via the existing
  `core/ledger_post.php` / `core/auto_post_hook.php`:
  - **Dr** Cash/Bank (or **Dr** Accounts Receivable when `payment_method='credit'`
    / `payment_status` due)
  - **Cr** Sales Revenue
  - **Cr** Output VAT (`default_output_vat_account_id`) — only when VAT 18% chosen
  - **Dr** COGS, **Cr** Inventory (product cost — mirrors the P&L COGS line)
- On **void/return** (Phase 1), post the **reversing** entry.
- **Partial / due payments (G3):** stop hardcoding `payment_status='paid'`.
  For `credit`, set `payment_status='pending'`/`'partial'`, post to AR, and add a
  small **"Receive POS Payment"** action that settles AR later (Dr Cash, Cr AR).
- Account mapping in **Settings** (POS revenue acct, COGS acct, inventory acct,
  cash/till acct, output VAT acct) so it is configurable, not hardcoded.
- **Tests** — `tests/test_pos_ledger_cli.php`: every sale/void/return posts a
  **balanced** entry (Σdebits = Σcredits); AR path for credit sales; reconciles to
  the Income Statement totals.

> Note: once Phase 3 posts POS revenue/COGS to the GL, revisit whether the
> Income Statement should read POS from the **ledger** instead of the operational
> `pos_sales` tables, to avoid any double-count. Decision recorded at Phase 3
> design time. (Today the P&L reads operationally because POS doesn't post.)

---

### Phase 4 — POS Dashboard / analytics  ✅ DONE (branch feat/pos-dashboard)

Closes **G5**. WorkDo-style landing view for POS. Shipped: `api/pos/get_dashboard.php`
(project-scoped, SQL-reconcilable tiles) + `app/bms/pos/pos_dashboard.php` (stat cards,
Chart.js 14-day trend, Top Products / Low Stock / Recent Sales, per ui-constants.md),
route + menu link, `tests/test_pos_dashboard_cli.php` (15).
- Today's revenue, sales count, **average transaction value**, items sold.
- Last-N-days sales trend (chart), top-selling products, low/out-of-stock alerts,
  recent transactions.
- Per-shift summary; project- and warehouse-scoped (respects BMS project scope).
- New `app/bms/pos/pos_dashboard.php` + `api/pos/get_dashboard.php`, statistics
  cards per §17, mobile cards, Chart.js.
- **Tests** — `tests/test_pos_dashboard_cli.php`: each tile reconciles to direct SQL.

---

### Phase 5 — Cleanup  ✅ DONE (branch feat/pos-cleanup) + follow-ups flagged

**Done — dead-code removal:** deleted the legacy v1 POS UI (`pos_modals.php`,
`pos_scripts.php`, `js/pos.js`), superseded by the `_new` files and referenced nowhere
live. Guard: `tests/test_pos_cleanup_cli.php` (9).

**Audit corrected the plan's assumptions:**
- `pos_controller.php` + `models/POSModel.php` are **kept** — the live terminal still
  calls `pos_controller` for `get_cash_balance`. (`POSModel::processSale/holdSale` target
  the non-existent `sales`/`sale_items` tables but are unreachable from the live UI, which
  sells via `api/pos/process_sale.php`. Left in place to avoid risk; trimming/migrating
  `get_cash_balance` to a first-class endpoint is a safe future tidy-up.)

**Flagged follow-ups (NOT done — need a decision / are separate features):**
- **Test-data hygiene:** the ~13B Jan–Apr POS sales look like test data but were NOT
  voided — needs explicit confirmation; if confirmed, void via the Phase 1 path.
- **Barcode scan:** existed only in v1 (now removed); the live v2 POS lacks a scanner
  though `products.barcode` exists — a clean re-add candidate.
- **Quotation → POS** conversion — a separate future feature.

---

## 4. Cross-cutting requirements (every phase)

- **Security (`.claude/security.md`):** CSRF on all writes, permission gate per
  action, soft-delete (never hard DELETE), `logActivity` + `logAudit` on every
  state change, project-scope respected.
- **Workflow permissions (§11.1):** void/refund/settle are status transitions —
  each needs its own `canX('pos')` gate in PHP, JS, and the API.
- **UI standard (`.claude/templates.md`, `.claude/ui-constants.md`):** statistics
  cards, Select2 (AJAX for large lists), mobile card view, Bootstrap Icons,
  spinner-on-submit.
- **Testing:** one `tests/test_pos_*_cli.php` per phase, transaction-wrapped and
  rolled back, reconciled to direct SQL; the pre-push hook must stay green.
- **Branch/PR:** dedicated branch off `develop`, live test, commit, push, PR into
  `develop` (never `main`); `changelog.md` updated at commit time.

---

## 5. Decisions

**Resolved**
1. **Permissions** ✅ — reuse standard CRUD helpers (`canView/canCreate/canEdit/
   canDelete('pos')`); no new permission keys. See §3 Phase 1d for the mapping.
2. **Build order** ✅ — P1 → P2 → P3 → P4 → P5 as recommended.
3. **Phase 3 source of truth** ✅ *(dev call, professional best practice)* — once
   POS posts to the General Ledger, the Income Statement must read POS from the
   **ledger** (single source of truth), and the operational `pos_sales` read added
   in the current PR is **retired at Phase 3** to prevent double-counting. Until
   Phase 3 ships, the operational read stands (POS doesn't post yet, so no
   double-count today). POS journal entries will be tagged (e.g. a `source='pos'`
   marker on the entry) so they remain auditable and distinguishable from manual
   journals. This mirrors how invoices already flow to the ledger.

**Open — needs your confirmation (recommendation attached)**
4. **Test data:** the ~13B of existing POS sales looks like test data. I can't know
   if it's real — only you can. **Recommended:** treat as test data and, once
   Phase 1 ships, **void** those rows through the proper Void path (non-destructive,
   keeps an audit trail, removes them from the P&L) rather than hard-deleting. If
   any are real, tell me which to keep. This is a **Phase 5** item and does **not**
   block Phase 1–4.

---

## 6. Recommended starting point

**Phase 1 (Returns/Refund + Void).** Highest integrity value, the schema already
exists (fast, low risk), and it activates the recognition guards we shipped in the
Income Statement PR. Phase 2 (the two-option VAT) is a quick compliance follow-up.
Phase 3 (GL posting) is the biggest professional leap and should be scoped on its
own once 1–2 are in.
