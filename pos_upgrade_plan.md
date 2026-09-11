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
| G10 | **No per-warehouse access control** — a cashier can operate POS against *any* warehouse, not just the one they're assigned to; the same gap exists in sales/procurement reports and the dashboard | Confirmed by full-repo grep (2026-07-17): `userCan('warehouse', …)` / `scopeFilterSql('warehouse', …)` exist and are resource-type-ready in `core/project_scope.php` but have **zero call sites anywhere**. `api/pos/simple_products.php` and `api/pos/process_sale.php` take `warehouse_id` straight from client input with no verification. `api/get_warehouse_stock_detail.php` and `api/get_product_warehouses.php` have no scope check of any kind. |

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

### Phase 6 — Warehouse Access Control (per-warehouse ACL, closes G10)

**Status:** SHIPPED (6a–6f complete) · **Added:** 2026-07-17 · **Completed:** 2026-07-17

All of 6a–6f below are done and verified (`tests/test_warehouse_scope_cli.php`,
81 checks; live DB-backed verification of a granted vs. denied warehouse via
`api/pos/simple_products.php`). Branch `feat/warehouse-access-control`, PR into
`develop` opened per 6g and held for review before merge (not auto-merged, per
explicit instruction for this phase).

**Follow-up (2026-07-17, same branch):** two rounds of fixes discovered by
manual testing after the above shipped —
1. `api/pos/simple_products.php` was still leaking every company-wide product
   (and, in a second pass, every service regardless of its own `warehouse_id`
   tag) into a scoped warehouse's POS grid via a `LEFT JOIN` that restricted
   quantities but not row presence. Fixed to exclude products/services with no
   stock record — or warehouse tag — in the selected warehouse.
2. A deeper gap: `loadUserScope()` auto-granted every warehouse a user's
   assigned project had ever transacted through, so a project assignment alone
   could never be narrowed below the project level by a Phase 6 warehouse
   grant — only POS had a manual workaround for this. Fixed at the source
   (`loadUserScope()` + `warehousesForSelect()`) and then wired the same
   project+warehouse composition into create/edit/list/view for RFQ, Purchase
   Order, GRN, Purchase Return, Quotation, Sales Order, LPO, Invoice, and
   Delivery Note (in+out) — the procurement and sales document families this
   plan didn't originally cover. Test suite extended to 137 checks; see
   `changelog.md` (2026-07-17, "Project→warehouse narrowing…") for the full
   write-up.

**Why this is its own phase, not a POS-only fix:** the same gap exists in sales
reports, procurement reports, and the dashboard — anywhere `warehouse_id` is
used to filter data. Fixing only POS would leave a cashier locked out of the
till but still able to pull another warehouse's stock/sales numbers through a
report URL. This phase closes all of them with one mechanism.

**The mechanism — reuses existing, unused infrastructure. No new tables.**

`core/project_scope.php` already treats `'warehouse'` as a first-class
resource type (`userCan('warehouse', $id)`, `scopeFilterSql('warehouse', $alias)`,
`scopeFilterSqlNullable('warehouse', $alias)`) and `loadUserScope()` already
computes `$_SESSION['scope']['warehouses']` by unioning:
1. warehouses derived from the user's assigned projects (existing), with
2. rows from `user_scope_overrides` where `resource_type='warehouse'`
   (existing table, **zero rows in it today** — completely unused).

A row `(user_id, 'warehouse', <warehouse_id>)` grants that one warehouse. A row
with `resource_id = NULL` is already interpreted as "grant **all** warehouses"
(`loadUserScope()` sets the session list to the `['*']` sentinel). **This means
the "other users with their role can see everything" requirement needs no new
permission system at all** — it's the same override table, just with a NULL
`resource_id` instead of a specific one. Admins bypass everything already via
`isAdmin()`, same as every other `canX()`/`userCan()` check in the app.

What's missing is (a) an admin UI to write rows into that table, and (b) actually
calling `userCan('warehouse', …)` / `scopeFilterSql(Nullable)('warehouse', …)` at
every read/write site that touches warehouse-scoped data — today literally
nothing in the codebase does.

**6a. Schema**
- Migration: `ALTER TABLE user_scope_overrides ADD UNIQUE KEY uq_user_resource (user_id, resource_type, resource_id)`.
  Prevents duplicate rows if the assignment UI's save is ever double-submitted.
  Nothing else changes — `user_scope_overrides` and `warehouses.project_id`
  already exist and already work.

**6b. Assignment UI — extend `app/constant/settings/user_projects.php`**
- Column 3 (currently: project checkboxes only) becomes, after picking a
  non-admin user: the existing **Project** checkbox grid (unchanged —
  `user_projects` keeps meaning "full project scope: customers, suppliers,
  financial records"), each ticked project expanding inline to show its own
  warehouses (`warehouses.project_id = <project>`) as sub-checkboxes; a
  separate **External Warehouses** panel listing `warehouses.project_id IS NULL`;
  and a **"Grant access to ALL warehouses"** toggle (writes the single
  `resource_id = NULL` override row instead of individual rows, greyed out /
  disabled once checked since it supersedes the checkbox lists).
- **One combined Save**, not two independent ones. Both panels feed a single
  "desired warehouse set" and the save does the same full-replace pattern
  `user_projects.php` already uses for projects: `DELETE FROM
  user_scope_overrides WHERE user_id=? AND resource_type='warehouse'` then
  re-insert the current full selection, inside the same transaction as the
  existing project save. **This is the one place a naive two-tabs-two-saves
  implementation would silently clobber itself** — call it out in the PR
  description so it doesn't regress later.
- Extend the `get_assignments` AJAX action to return
  `{projects: [...], warehouses: [...], grant_all_warehouses: bool}`.
- `refreshScopeCache($user_id)` after save (already called for projects; same
  call already recomputes the warehouse union too — confirmed by reading
  `loadUserScope()` in full).
- Admin users: keep the existing "full access automatically, assignments
  ignored" messaging, extended to mention warehouses too.

**6c. POS enforcement — closes the actively-exploitable part of G10**

| File | Change |
|---|---|
| `core/warehouse_scope.php` | Add `warehousesForPos(PDO $pdo): array` using `scopeFilterSql('warehouse', 'w')` (strict — a POS warehouse dropdown must show *only* warehouses the user is scoped to, never an "untagged" fallback, since untagged ≠ assigned-to-me). Grant-all users get every active warehouse. |
| `app/bms/pos/pos.php:139-144` | Swap `warehousesForSelect()` (project-based) for `warehousesForPos()`. Exactly one warehouse in scope → auto-select and lock the dropdown (no free choice). More than one → dropdown constrained to just those. |
| `api/pos/simple_products.php` | Add `userCan('warehouse', $warehouse_id)` before querying `product_stocks`; 403 on failure. Removes the `// scope-audit: skip` marker since this is no longer deferred. |
| `api/pos/process_sale.php` | Same guard before accepting the sale — this is the file that currently lets any POS user submit any `warehouse_id` and have it decrement that warehouse's stock. Highest-priority single fix in this phase. |
| `api/pos/create_return.php`, `api/pos/void_sale.php` | Add `userCan('warehouse', $orig['warehouse_id'])` before restocking/reversing — today these trust the *original sale's* warehouse without checking the *current actor* is allowed to touch it. |
| `api/pos/receive_payment.php`, `api/pos/print_receipt.php`, `api/pos/get_sale_items.php` | Add the same warehouse-scope check (join to `pos_sales.warehouse_id`). `print_receipt.php` additionally appears to be missing an `isAuthenticated()` call entirely in its first 40 lines — flagging as a separate pre-existing bug to confirm and fix alongside, not a warehouse-scope issue per se. |
| `api/pos/get_dashboard.php`, `api/pos/get_sales.php` | Both already project-scoped (`scopeFilterSqlNullable('project','ps')`). Append `scopeFilterSqlNullable('warehouse','ps')` to the same `$scope` string so every tile/row also respects warehouse assignment — one-line change per file since they already funnel every query through one shared `$scope`. |
| `app/bms/pos/models/POSModel.php` | Same treatment for `getProducts()`. |
| `api/pos/get_held_sales.php`, `hold_sale.php`, `delete_held_sale.php`, `open_shift.php`, `close_shift.php` | **No change** — these are already scoped to `user_id` ownership only (a user can only see/act on their own held sales and shifts), so cross-warehouse leakage isn't possible here regardless of warehouse assignment. Documented so it doesn't look like an oversight. |
| `api/pos/get_products.php` | **Not touched by this phase** — investigation found this file's actual content is `grn_create.php`'s HTML/JS/PHP, not a POS products endpoint. Looks like a pre-existing copy/paste or build artifact bug, unrelated to warehouse scope. Flagging for a separate fix; touching it here risks conflating two unrelated issues. |

**6d. Sales & procurement reports**

| File | Change |
|---|---|
| `api/account/get_sales_report.php` | Add `warehouse_id` GET param + `userCan('warehouse', $warehouse_id)` guard (mirrors the existing `project_id` guard at lines 47-51) + append `scopeFilterSqlNullable('warehouse','ps')` to `$pos_where_sql` when no specific warehouse chosen. Invoices side (`$inv_where_sql`) is untouched — `invoices` carries no `warehouse_id` column. |
| `app/constant/reports/sales_report.php` | Add a warehouse filter dropdown (via `warehousesForPos()` or a report-flavored equivalent), shown/enabled per the user's scope. |
| `api/account/get_purchase_report.php` | Same treatment: `warehouse_id` param, `userCan('warehouse', …)` guard, `scopeFilterSqlNullable('warehouse','po')` appended to `$where_sql`. |
| `app/constant/reports/purchase_report.php` | Add the matching warehouse filter dropdown (currently has a project dropdown only). |
| `api/account/get_inventory_report.php` | Currently scopes via `w.project_id` (project-derived, coarser than direct warehouse assignment) **and has no `userCan()` check at all when a specific `warehouse_id` is passed** — a real, currently-open gap, not just a nice-to-have. Switch the no-warehouse-chosen branch from `scopeFilterSqlNullable('project','w')` to `scopeFilterSqlNullable('warehouse','w')`, and add a `userCan('warehouse', $warehouse_id)` guard when one is explicitly chosen. |
| `app/constant/reports/inventory_report.php` | Its warehouse dropdown is currently `SELECT ... FROM warehouses WHERE status='active'` — **zero scoping**, lists every warehouse to every user. Replace with the scoped helper. |
| `api/get_warehouse_stock_detail.php` | **No scope check of any kind today** (confirmed — validates the warehouse/project pair exists in the DB, never checks the session against it). Add `userCan('warehouse', $warehouse_id)`. Highest-priority report-side fix. |
| `api/get_product_warehouses.php` | Same — lists every warehouse's stock for a product with zero filtering. Add `scopeFilterSqlNullable('warehouse','w')`. |
| `warehouse_stock_view.php`, `warehouse_view.php` | Currently gated by `assertScopeForRecordHtml('warehouses','warehouse_id',$id)`, which resolves to a **project**-level check via the warehouse's `project_id` — not a direct per-warehouse check. Switch to `userCan('warehouse', $warehouse_id)` so a user narrowly granted one warehouse of a multi-warehouse project can't browse to another warehouse of that same project by URL. |
| `app/bms/stock/warehouses.php` (warehouse *management* list) | **Deliberately left on project scope**, not switched to warehouse scope. This page is about administering which warehouse records exist under which projects — a management/admin concern, not the operational "which warehouse can I transact in" concern the rest of this phase addresses. Noting the distinction explicitly so it's a documented decision, not a missed file. |

**6e. Dashboard (`app/dashboard.php`)**

| Widget | Location | Change |
|---|---|---|
| POS sales/revenue today | `get_business_stats()`, lines 298-309 | Currently **explicitly** unscoped ("POS is a shared point-of-sale terminal" comment) — every user sees company-wide POS revenue regardless of warehouse. Add `scopeFilterSqlNullable('warehouse','ps')`. Clearest dashboard-side gap in this whole phase. |
| Inventory value / low-stock count | `get_business_stats()`, lines 244-265 | Currently project-derived via `product_stocks` join. Switch to `scopeFilterSqlNullable('warehouse', <alias>)` for consistency with 6d's inventory-report fix. |
| Low-stock / negative-stock / expiring alerts | `get_system_alerts()`, lines 420-489 | Same switch, same reasoning. |
| Cashier "today's transactions" KPI | lines 837-850 | **No change** — already scoped to the logged-in user's own sales (`user_id`), which is a subset of "their warehouse" by construction. |

**6f. Tests** — `tests/test_warehouse_scope_cli.php`, mirroring the rigor of
the existing `tests/test_project_scope_cli.php`:
- An unassigned non-admin gets an empty warehouse scope → sees zero products/
  stock/sales/purchases anywhere in POS or reports.
- A user assigned exactly one warehouse (via 6b's UI, both the "project →
  its warehouse" path and the "external warehouse" path) sees only that
  warehouse's products/quantities in POS, only that warehouse's rows in
  sales/purchase/inventory reports, and only that warehouse's tile numbers on
  the dashboard.
- A "grant all warehouses" user sees everything, same as admin, without being
  admin.
- `process_sale.php` and `simple_products.php` reject an out-of-scope
  `warehouse_id` even if it's a valid warehouse in the database.
- `refreshScopeCache()` picks up a newly-saved assignment without requiring
  re-login.
- Reconciles every touched report tile to direct SQL, same convention as
  Phase 4's dashboard tests.

**6g. Cross-cutting** — same as every other phase in this plan (§4): CSRF on
the assignment-save POST, `logActivity`/`logAudit` on every scope change
(security-relevant), mobile card view on the assignment UI, dedicated branch
→ CLI test → PR into `develop`.

---

### Phase 7 — Ledger-integrity fix (void→GL reversal + receipt company-info bug)

**Status:** ✅ DONE · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

Follow-up scouting (2026-09-07, ahead of the "Advanced POS" push in §7 below)
found two real bugs, not just gaps:

1. `api/pos/void_sale.php` reversed stock and cash on void but **never reversed
   the GL entries** `postPosSale()` posted at sale time — so a voided sale's
   revenue/COGS stayed in the ledger-based Trial Balance/Balance Sheet forever,
   even though it was excluded from the operational P&L. Fixed by calling the
   existing generic `reverseAccrualEntry($pdo, 'pos_sale'|'pos_cogs', $sale_id, ...)`
   (already used by `reverseCreditNoteRestock()`) inside the same transaction as
   the void, before commit — best-effort, never blocks the void, logs a warning
   on failure exactly like `create_return.php` does for `postPosReturn()`.
2. `api/pos/print_receipt.php` hardcoded a fake `company_address`/`company_phone`/
   `company_tin` (BJP's own placeholder values) instead of reading each tenant's
   own Company Profile — every tenant's printed receipts showed the same fake
   details. Fixed to read `getSetting('company_physical_address'|'company_phone'|
   'company_tin'|'company_vrn', ...)`, matching the pattern already used by
   `petty_cash_print.php`/`supplier_payments.php`; blank fields are now omitted
   from the printed header instead of showing a placeholder.

Verified live: `tests/test_pos_phase7_void_gl_reversal_cli.php` (28 assertions) —
posts a synthetic sale via `postPosSale()`, reverses it via the same call
`void_sale.php` now makes, confirms both reversal entries balance and are
correctly tagged (`pos_sale_void`/`pos_cogs_void`), confirms idempotency (voiding
twice does not double-reverse), and confirms rollback leaves no trace.

---

## 7. "Advanced POS" professionalisation (post-scout, 2026-09-07)

Scope for the next tranche of work, ordered to build on what's already shipped
(§3 Phases 1–6) rather than restart it. Ties into the in-progress tenant
module-entitlement system (`core/feature_registry.php` already has a `'pos'`
feature key) — see Phase 13.

| Phase | What | Status |
|---|---|---|
| 7 | Ledger-integrity fix (void→GL reversal, receipt company-info bug) | ✅ DONE (above) |
| 8 | Register/Till model — activate the unused `pos_registers` schema (till selection, per-register receipt branding, populate `cash_register_shifts` totals at close) | ✅ DONE (below) |
| 9 | Z-Report / EOD reconciliation, built on Phase 8's populated shift totals | ✅ DONE (below) |
| 10 | Receipt printing improvements, email receipt, Select2 AJAX customer picker with quick-add — **re-scoped, see below** | ✅ DONE (below) |
| 11 | Loyalty points (real accrual/redemption) + multi-currency (base currency from `system_settings.currency`, replacing the hardcoded `TZS`) | ✅ DONE (below) |
| 12 | Offline resilience (local queue + sync-on-reconnect) | **Deferred — needs its own design discussion before implementation, per product owner (2026-09-07). Not scheduled in this tranche.** |
| 13 | Wire a `pos_advanced` feature-registry entry (`depends_on: ['pos']`) so a superadmin can gate the Phase 8-11 features per tenant company | ✅ DONE (below) |

---

### Phase 8 — Register/Till model

**Status:** ✅ DONE · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

Activated the `pos_registers` schema that had existed since day one with zero
call sites anywhere in the app:

- **Register selection at shift open.** `api/pos/open_shift.php` now requires an
  active register (defaults to register #1, "Main Counter", so an un-migrated
  client never breaks), rejects a register already staffed by another active
  shift, and stores `register_id` on `cash_register_shifts`. The Start Shift
  modal gained a Select2 register picker populated from a new
  `api/pos/get_registers.php`.
- **Register management.** New CRUD (`get_registers.php`/`save_register.php`/
  `toggle_register_status.php`, gated by `canEdit('pos_config_settings')`) with
  a UI added to the existing `pos_config_settings.php` settings page. A register
  is only ever deactivated, never deleted — past shifts and sales reference it.
- **Sale-level register denormalisation.** `pos_sales.register_id`/
  `register_name` (existed, always default/NULL) are now stamped at sale time
  from the cashier's active shift, mirroring how `customer_name` is already
  denormalised alongside `customer_id`. `print_receipt.php` joins
  `pos_registers` off the sale's own `register_id` to show per-register receipt
  header/footer overrides (falls back to the company-wide header when a
  register hasn't set its own) and the register name on the printed receipt.
- **Shift-close totals — the core of this phase.** `cash_register_shifts.
  total_sales/total_cash_sales/total_card_sales/total_mobile_sales/
  total_credit_sales/total_refunds/cash_in/cash_out` were defined on this table
  from day one but `close_shift.php` never wrote to them (permanently 0.00).
  Extracted the computation into a new, independently-tested
  `core/pos_shift_reporting.php::posShiftTenderTotals()` — built from
  `pos_sales` directly (not `cash_register_transactions`, which only ever logs
  the CASH leg of a sale and so cannot answer "how much card/mobile/credit did
  this shift do"). Excludes voided sales entirely; attributes a return to the
  shift the refund itself was processed in (not the original sale's shift,
  matching `create_return.php`'s own shift assignment).
- **Real bug found and fixed along the way:** the frontend's split-payment
  modal sends `payment_method: 'split'`, but `pos_sales.payment_method` is a DB
  enum that never included `'split'` (it has `'mixed'` for exactly this case).
  Under this server's non-strict `sql_mode`, MySQL was silently coercing the
  invalid enum value to `''` — confirmed live: one pre-existing sale already has
  `payment_method=''`, an unrecoverable tender type. Fixed by mapping
  `'split'` → the schema's own `'mixed'` value at insert time. The one
  historical `''` row was left untouched (deliberately — no confirmation it
  isn't real data; see `core/pos_shift_reporting.php::buildSplitPaymentLegs()`
  for the accompanying fix that also makes a split sale's per-tender breakdown
  (previously nowhere recorded) queryable via the new `payment_details` JSON
  column).
- **Also fixed in passing:** `open_shift.php`/`close_shift.php` were missing
  `csrf_check()` entirely — added (the frontend already sends the CSRF header
  globally via `header.php`'s `$.ajaxSetup`, so this needed no frontend change).

**Known limitation, called out rather than silently absorbed:** `bank_transfer`/
`voucher`/`loyalty_points` tenders have no dedicated bucket on this schema (only
cash/card/mobile/credit exist) — they still count in `total_sales`, just not
broken out individually. A genuinely bank-transfer-heavy tenant should get a
dedicated column rather than have it folded into an unrelated bucket.

Verified live: `tests/test_pos_phase8_registers_cli.php` (39 assertions) —
wiring checks across all touched files, `buildSplitPaymentLegs()` unit tests,
and a full `posShiftTenderTotals()` reconciliation against 7 synthetic
`pos_sales` rows (cash/card/mobile/credit/mixed/voided/returned) in one
transaction-isolated shift. Existing POS regression suites
(`test_pos_sale_posting_cli`, `test_pos_sale_backfill_cli`,
`test_pos_strictmode_nullable_cli`, `test_pos_credit_ar_cli`,
`test_pos_cleanup_cli`, `test_stock_movements_enum_safety_cli`) all still pass.

---

### Phase 9 — Z-Report / EOD reconciliation

**Status:** ✅ DONE · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

Built directly on Phase 8's now-populated shift totals — no schema work needed.

- **`app/bms/pos/zreport.php`** — a printable end-of-shift reconciliation report
  per shift: cash reconciliation (starting/expected/counted/difference), sales
  by tender (cash/card/mobile/credit/other, with gross vs net after refunds and
  a called-out void total), a **GL posting-health warning banner** (flags any
  completed sale in the shift with no posted ledger entry — `postPosSale()` is
  best-effort and never blocks a sale, so a chart-of-accounts misconfiguration
  can silently leave revenue off the Trial Balance), and a full transaction
  listing. Works for an **active** shift too (live totals recomputed on demand
  via `posShiftTenderTotals()`), not just a closed one — a supervisor can check
  an in-progress till without waiting for close.
- **`app/bms/pos/shift_history.php`** — the list a Z-Report needed to be findable
  from (none existed before this phase): every shift with its totals and cash
  difference, linking through to its Z-Report.
- **Access control:** a cashier may only view their own shift's Z-Report;
  `canEdit('pos')` (supervisor/admin) can view any shift — cash reconciliation
  is sensitive, not something every POS user should browse for others.
- Extracted `core/pos_shift_reporting.php::posShiftReportExtras()` (void
  summary, return count, GL-unposted count) alongside Phase 8's
  `posShiftTenderTotals()`, independently unit-tested rather than inlined in
  the report page.
- Routes registered (`pos/zreport`, `pos/shifts`) and wired into the POS
  Workspace header ("Shift History" button) and the close-shift success
  dialog ("View Z-Report" button, opens the just-closed shift's report).
- `core/feature_registry.php`'s `'pos'` feature `paths` updated to cover the
  two new pages + the new core file (the `paths` list is not yet enforced by
  an active bootstrap guard — confirmed via `featureForPath()` having no call
  sites outside its own test — but kept accurate for when it is).

Verified live: `tests/test_pos_phase9_zreport_cli.php` (24 assertions) —
wiring checks, and a live-DB reconciliation of `posShiftReportExtras()`
against a voided sale, a return, a completed sale with **no** GL posting, and
a completed sale **with** one (confirming the health check flags only the
genuinely unposted sale). All Phase 7/8 tests and pre-existing POS regression
suites re-run clean.

---

### Phase 10 — Customer picker, receipt printing, email receipt

**Status:** ✅ DONE (re-scoped) · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

**Scope was deliberately narrowed from the original "ESC/POS + drawer kick + email/SMS
receipt" roadmap wording, for two concrete, verified reasons — not a shortcut, a
platform-reality correction:**

1. **True ESC/POS raw printing and a software drawer-kick command are not
   achievable from a plain browser page.** A browser can only open the OS
   print dialog against HTML/CSS content — it cannot write raw bytes to a
   printer. The only way around that is either a native local print-bridge
   agent (extra software to install and maintain on every till, not requested)
   or WebUSB, which is Chrome-only **and requires a secure (HTTPS) context** —
   this deployment runs over plain HTTP on a LAN (WAMP), where WebUSB is
   flatly unavailable. Building either would have meant shipping something
   that either doesn't work in this tenant's actual environment or silently
   depends on infrastructure nobody asked for. Documented here instead of
   silently dropped.
2. **SMS receipts are not implemented because there is no working SMS gateway
   integration to build on.** Checked `api/test_sms_config.php` — its own
   comment reads "In a real system, you'd call the specific gateway API here.
   For now, we simulate." Building "Send Receipt via SMS" on top of a
   simulated backend would create the illusion of a working feature while
   sending nothing. Flagged as a real gap, not implemented.

**What professional-grade printing looks like within that real boundary
(and what was actually built):**
- **Configurable receipt paper width** (58mm/80mm) and an **"automatically
  print on sale complete"** setting, both on `pos_config_settings.php` —
  `print_receipt.php` now reads them instead of hardcoding 80mm and a
  commented-out auto-print line.
- **Honest cash-drawer messaging.** `openCashDrawer()` previously showed a fake
  "Cash drawer opened!" success toast that did nothing at all. Replaced with an
  accurate explanation: a browser cannot send a hardware open-drawer signal,
  but a drawer wired to a printer's kick port already opens automatically on
  every print — which the auto-print setting above now makes happen for free.
- **Email Receipt** — genuinely wired to the real SMTP-backed `sendEmail()`
  (`core/mailer.php`), not simulated. Fails with the real `mailer_last_error()`
  message (e.g. "SMTP is not configured") rather than a fake success.

**What was fully built as scoped:**
- **Select2 AJAX customer picker** (`api/pos/search_customers.php`, project-
  scoped per security.md §23) replacing the old plain `<select>` hard-limited
  to the first 50 active customers with no search at all.
- **Inline "+ New Customer" quick-add** — wired up the pre-existing but
  never-called `api/quick_add_customer.php` (added the `csrf_check()` it was
  missing) behind a small modal; the new customer is immediately selected.
- Fixed a restore-path bug this Select2 conversion would otherwise have
  introduced: an AJAX-mode Select2 has no static `<option>` list, so
  programmatically restoring a previously-picked customer (from
  `localStorage`, or from a held sale) needs the option created first —
  `setCustomerSelection()` handles this at all three call sites.
- Added `csrf_check()` to `pos_config_settings.php`'s POST handler (and a
  `_csrf` field to its form), which was missing entirely.

Verified live: `tests/test_pos_phase10_customer_receipt_cli.php` (32
assertions). All Phase 7-9 tests and pre-existing POS regression suites
re-run clean.

---

### Phase 11 — Loyalty points + currency-from-settings

**Status:** ✅ DONE · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

**Currency — the product owner's question answered.** Pulling the POS terminal's
currency from `system_settings.currency` (instead of a hardcoded `TZS`) is
exactly the professional baseline: this is what `getSetting('currency', 'TZS')`
already does elsewhere in the codebase (`pos_dashboard.php`, `zreport.php`,
`shift_history.php` already had it right; `pos.php`'s own `$currency` was
literally `= 'TZS';`, and the JS layer never used the variable at all — every
price/total/change/split-payment label was a hardcoded `'TZS '` string). Fixed
across `pos.php`, `pos_modals_new.php`, `pos_scripts_new.php` (new
`POS_CURRENCY` JS constant), `print_receipt.php`, and `customer_display.php` +
`api/pos_session.php` (the customer-facing screen polls a session bridge with
no server-settings access of its own — extended its JSON payload with a
`currency` field instead of adding a heavier bootstrap to that standalone
page). **This is a single-currency fix** (the right scope for the vast
majority of SME businesses — one operating currency, configurable). **Genuine
multi-currency** — accepting foreign-currency tender at checkout with a live
FX rate captured per sale — is a materially larger feature: it needs a real
rate source (a `currencies` table with maintained rates, or a live FX API),
per-sale rate capture, and GL conversion back to the base currency for
reporting. `pos_sales.currency`/`exchange_rate` columns already exist for it
but are unused. **Recommendation: scope this as its own future phase if the
business actually transacts in more than one currency** — building it
speculatively now would be exactly the kind of premature, undirected work this
plan tries to avoid.

**Loyalty points — built as a real, complete (if intentionally bounded)
feature**, not a placeholder:
- **Schema** (`migrations/tenant/2026_09_07_pos_loyalty_program.php`, applied
  to every tenant + `schema/tenant_schema_template.sql` for new ones):
  `customers.loyalty_points_balance` (fast denormalised read, mirrors
  `products.stock_quantity`) + `customer_loyalty_transactions` (the ledger of
  truth, mirrors `stock_movements` — every earn/redeem/reversal is an
  auditable row, never just a mutated number).
- **`core/pos_loyalty.php`** — `awardLoyaltyPoints()` (floor-rounded, never a
  customer's favour into more reward than earned; no-op for walk-ins, which
  can't accrue), `redeemLoyaltyPoints()` (row-locks the customer under `FOR
  UPDATE` so two tills can't both redeem past the true balance; always
  validates server-side, never trusts the client's idea of the balance),
  `reverseLoyaltyForSale()` (used by `void_sale.php` — a void is "as if the
  sale never happened" for loyalty too, exactly like it already is for
  stock/cash/GL).
- **Wired into `process_sale.php`**: redemption applied as an additional
  discount folded into the existing `discount_amount` (no new column needed);
  earning computed on the **post-redemption** total (no double-dipping points
  off a discount other points funded); both counts persisted onto the
  pre-existing but previously-always-zero
  `pos_sales.loyalty_points_earned/redeemed` columns.
- **POS terminal UI**: available balance shown once a registered customer is
  selected, a "Redeem N pts" input with a live discount preview, surfaced in
  the sale-completion message.
- **Settings** (`pos_config_settings.php`): enable toggle, earn rate ("1 pt
  per N currency spent"), redemption value ("1 pt = N currency off").
- **Deliberately NOT wired into partial returns** (`create_return.php`) —
  proportional point clawback on a partial return is a real edge case; a
  fragile guess under time pressure would be worse than a documented gap. A
  full void DOES reverse both earn and redeem in full (matching void's
  existing all-or-nothing semantics).
- **Known, accepted edge case**: if sale A's earned points are later spent via
  a *different* sale B's redemption, and THEN sale A is voided, the earn
  reversal clamps at a balance floor of 0 rather than pushing the customer
  negative — the business absorbs that small breakage rather than putting a
  customer in point-debt. A single sale being voided (the actual `void_sale.php`
  call pattern — one sale_id per void) is unaffected; this only bites the
  double-chained edge case, and reversing in the opposite order fully avoids
  it. Documented in `core/pos_loyalty.php`'s own comments, not hidden.
- **Real bug found and fixed while building this**: the split-payment modal
  sends `payment_method: 'split'`, but `pos_sales.payment_method` never had
  `'split'` in its enum (it has `'mixed'`) — under this server's non-strict
  `sql_mode`, MySQL silently coerced it to `''`. One historical sale already
  shows this corruption live; left untouched (no confirmation it's safe to
  edit real financial history) but the root cause is now fixed for every sale
  going forward.

Verified live: `tests/test_pos_phase11_loyalty_currency_cli.php` (36
assertions) — schema presence, wiring, and a full live-DB reconciliation of
award/redeem/reverse/idempotency using an explicit config override (so the
test doesn't depend on `helpers.php::get_setting()`'s process-wide settings
cache). All prior phase tests and pre-existing POS regression suites re-run
clean.

---

### Phase 13 — Wire `pos_advanced` into tenant module entitlement

**Status:** ✅ DONE · **Added:** 2026-09-07 · **Branch:** `feat/pos-professional-upgrade`

**The boundary decision (a real product call, made explicit rather than
guessed silently):** not everything built in Phases 8-11 is gated. Z-Report/
Shift History, email receipt, and the Select2 customer picker stayed in base
`pos` (`default: true`, every tenant gets them) — they're operational hygiene
every till needs, not a differentiated premium feature. **Multi-register/till
management and the customer loyalty points program** are what actually got
gated behind the new `pos_advanced` feature, because they're the two
genuinely upsell-shaped additions (a second till, a rewards program are both
things a business either needs or doesn't, unlike "can I see my own shift's
totals").

**What's real about this gate, not aspirational:**
- `core/feature_registry.php` — new `pos_advanced` entry, `default: false`,
  `depends_on: ['pos']`, `page_keys: ['pos_advanced']`. Grantable today through
  the existing superadmin module-request/grant UI — no new plumbing needed
  there.
- `migrations/tenant/2026_09_07_pos_advanced_permission.php` seeds the
  `pos_advanced` permissions row on every existing tenant (+
  `schema/tenant_seed_defaults.sql` for new ones).
- **UI**: `pos_config_settings.php` only renders the "Registers / Tills" and
  "Loyalty Program" sections when `canView('pos_advanced')` — a plan-locked
  tenant sees a clear "not included in your current plan" notice instead of a
  broken or silently-ignored form.
- **Settings save is also gated server-side**, not just hidden in the UI — a
  raw POST to `pos_config_settings.php` can't sneak `pos_loyalty_enabled=1`
  past a missing entitlement.
- **API-level enforcement**: `save_register.php`/`toggle_register_status.php`
  check `canView('pos_advanced')` before their existing CRUD permission check.
  `get_registers.php` (read-only listing) deliberately stays **un-gated** —
  every tenant, base tier included, still needs to see at least the one
  default register to open a shift at all; only *creating/editing/
  deactivating* registers is the premium action.
- **Runtime double-check, not just settings-page enforcement**:
  `core/pos_loyalty.php::loyaltySettings()` calls `tenantFeatureEnabled
  ('pos_advanced')` directly — so a superadmin *revoking* the entitlement
  immediately disables loyalty accrual/redemption even if
  `pos_loyalty_enabled` is still `'1'` from before the revoke, rather than
  requiring someone to remember to also flip that setting off.

Verified live: `tests/test_pos_phase13_entitlement_cli.php` (20 assertions) —
registry shape, permission seeding, wiring, and a live behavioural check that
forces the tenant's feature map to `pos_advanced=false` and confirms
`canView('pos_advanced')` is false **even for an admin session** (entitlement
is checked before the admin bypass — verified against the actual `canView()`
source, not assumed) and that `loyaltySettings()` correctly reports
`enabled=false` regardless of the raw setting. Fixed one pre-existing test
(`test_feature_registry_cli.php`'s hardcoded dependents-of-`warehouses` list)
that was stale the moment `pos_advanced` became a transitive dependent via
`pos` → `warehouses`. All Phase 7-11 tests and pre-existing POS regression
suites re-run clean; `test_feature_registry_cli.php` back to 106/106.

**This completes the planned tranche** (Phases 7-11, 13 — Phase 12 offline
resilience remains deliberately deferred per the product owner, 2026-09-07).

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

**Update 2026-07-17 — Phases 1, 2, 4, 5 are shipped; Phase 3 was split (see §3).
Phase 6 (Warehouse Access Control) is next**, and is the highest-priority item
in the whole plan at this point: `api/pos/process_sale.php` currently lets any
authenticated POS user post a sale against any warehouse's stock with zero
verification, and `api/get_warehouse_stock_detail.php` /
`api/get_product_warehouses.php` have no scope check of any kind. Recommended
build order within Phase 6: **6a → 6b → 6c** first (closes the live POS gap),
then **6d → 6e** (reports/dashboard), **6f** (tests) throughout, not bolted on
at the end.

---

## 8. Tier-3 — Advanced Retail Capabilities (UltimatePOS benchmark, 2026-09-08)

**Status:** APPROVED by product owner 2026-09-08, build **one phase at a time,
no rushing** — each phase gets its own confirmation before the next starts.
**Source:** a code-level audit of `C:\wamp64\www\UltimatePOS` (a mature
commercial Laravel POS product), comparing what it does at the counter to what
BMS ships today (§3 Phases 1-13, all done). Full method: read
`SellPosController.php`, `BusinessUtil::printerConfig()`, `SellingPriceGroup`,
the POS blade views and JS — not just filenames.

**Where BMS already leads and nothing here should regress:** GL-reversal on
void, ledger-tagged journal entries, per-warehouse ACL, row-locked loyalty
redemption, tenant feature-gating. Also confirmed by the same audit: UltimatePOS
is **also** fully online-only (no service worker/IndexedDB anywhere) — BMS's
Phase 12 offline deferral was the right call, not a shortcut. UltimatePOS's
"SMS receipt" is a `wa.me` WhatsApp deep-link, not a real gateway — folded into
Phase 22 below as a cheap add, not treated as a gap.

**Ground rule for every phase in this section (re-confirmed against the actual
schema before writing this plan, not assumed):** reuse what exists before
adding anything new.
- **Notification/email engine is fully built** — `core/notify.php`:
  `dispatchEvent($pdo, 'event_key', $ctx)`, `createNotification()`,
  `usersWithPermission()`, `resolveRecipients()` (permission + project-scope +
  per-user mute + admin-configured rules), `enqueueEmail()` → `notification_outbox`
  → `cron/process_notifications.php` worker, admin UI at
  `app/constant/settings/notification_rules.php` /
  `api/notifications/rules_api.php`. New event types are added by (1) a migration
  row in `notification_events` (event_key, page_key, required_verb, scope_aware)
  and (2) one `dispatchEvent()` call at the source action, **or**, for
  time-based checks (expiry, low stock), a new block in
  `cron/run_notification_checks.php` — exactly the existing pattern, see
  `notification_engine_plan.md`. **Every phase below that needs to alert
  someone uses this engine — no parallel notification path is built.**
- **Email delivery is real** (`core/mailer.php::sendEmail()`, PHPMailer,
  already used by Phase 10's Email Receipt) — reused as-is.
- **Dedupe-per-milestone pattern already exists and is proven**:
  `document_expiry_reminders(document_id, milestone, sent_at)` +
  `cron/check_document_expiry.php` (milestones `[30,14,7,1]` days,
  `INSERT IGNORE` dedupe, `resolveRecipients()`-driven audience). Phase 17
  below is the same pattern applied to product batches, not a new design.
- **`pos_advanced` feature gate already exists** (`core/feature_registry.php`,
  `depends_on: ['pos']`, wired into `pos_config_settings.php` and enforced
  both UI-side and API-side per Phase 13). The genuinely upsell-shaped new
  capabilities here (price tiers, batch/lot+expiry, unit conversion, network
  printer config) gate behind it, same boundary logic as Phase 13's own
  reasoning (a second till / a rewards program vs. "can I see my own shift's
  totals"). Loss-control and till hygiene (price/discount-override split, cash
  denomination counting) stay in base `pos` — every till needs them regardless
  of plan tier.
- **Company-prefixed sequential codes** (`core/code_generator.php::nextCode()`,
  `.claude/security.md` §18) are already more rigorous than what a "numbering
  scheme" gap would ask for — Phase 22 below is scoped to receipt **layout**
  variety only, numbering is not touched.

**Confirmed-empty starting points (verified by direct grep/read of
`schema/tenant_schema_template.sql`, not assumed) — each phase below states
exactly what has to be newly added:**
- No `secondary_unit`/`base_unit_multiplier`/unit-conversion columns anywhere.
- No `combo`/`is_combo` product type anywhere.
- `pos_registers.receipt_printer` is a plain `varchar(100)` label — no
  `ip_address`/`port`/`connection_type` columns.
- `cash_register_shifts` has one aggregate decimal per tender type — no
  denomination breakdown.
- `receipt_items` (the GRN line-item table) **already has** `batch_number` and
  `expiry_date` columns and `api/create_grn.php` **already writes them** on
  every goods receipt (confirmed reading `api/create_grn.php:144-181`) — but
  they are dead-end data: never copied into `products.expiry_date`, never
  given their own stock ledger, never consulted at sale time. `products` also
  already has its own single `expiry_date`/`expiry_days`/`email_alerts`
  columns (used today only by `dashboard.php`'s "expiring within 30 days"
  widget, one date per product, no batch concept) and `api/update_product_alerts.php`
  (writes `email_alerts` but nothing currently reads that flag to actually
  send anything). Phase 17 turns this existing-but-inert data into a real
  batch ledger instead of adding a parallel system.
- `customers.credit_limit` (and the equivalent column on `suppliers`) **already
  exists** and is captured on the customer form — but nothing at the point of
  sale ever reads it. Phase 19 is enforcement only, no new column.
- `products.wholesale_price` / `min_selling_price` / `selling_price` **already
  exist** — a two-price (retail/wholesale) foundation is there. Phase 14
  extends this into proper named, multi-tier price groups rather than
  replacing it.

---

### Phase 14 — Selling Price Tiers (Retail / Wholesale / Custom)

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned below. `core/pos_price_groups.php::resolveGroupPrices()`
extracted for independent testability (same pattern as Phase 16's
`pos_override_guard.php`); `simple_products.php` resolves per-product via a
`COALESCE(MAX(pgp.price), p.selling_price)` join (wrapped in `MAX()` to
satisfy `ONLY_FULL_GROUP_BY` — verified live against the real DB, not
assumed). Management UI `app/bms/pos/price_groups.php` (list + inline
per-product price grid), customer form gained a "Price Group" picker
(`customers.default_price_group_id`) that auto-applies at the POS terminal
when that customer is selected. `tests/test_pos_price_groups_cli.php`
(36 checks, transaction-rolled-back). Full POS + feature-registry regression
suite re-run clean; the same 2 pre-existing unrelated failures persist
(confirmed present on unmodified `develop`). Found and fixed in passing:
Phase 16's two new permission page_keys were missing from
`core/feature_registry.php`'s `pos` → `page_keys` list, which
`test_feature_registry_cli.php` correctly flagged as "ungated" — fixed here.

**Closes:** walk-in vs. bulk-buyer pricing, the single most common real-world
gap for a TZ shop counter. **Gate:** `pos_advanced`.

**What exists today:** `products.selling_price` (retail) and
`products.wholesale_price` (one wholesale price) — a flat two-price system,
no named tiers, no per-customer default, no cashier picker.

**Build:**
- New table `price_groups` (`price_group_id`, `name`, `is_default`, `status`) +
  `product_price_group_prices` (`product_id`, `price_group_id`, `price`) — a
  price group is a named list of per-product overrides, falling back to
  `products.selling_price` for any product without an override (same "sparse
  override" shape UltimatePOS uses, avoids forcing every product to be
  re-priced when a group is created). Seed two default groups on migration:
  "Retail" (`is_default=1`) pre-populated from `selling_price`, and
  "Wholesale" pre-populated from the existing `wholesale_price` column — so
  existing data becomes the first two groups, nothing is thrown away.
- `customers` gets `default_price_group_id` (nullable FK) — set on the
  customer form; selecting that customer in POS auto-applies their tier.
- POS terminal: a price-group Select2 next to the customer picker (defaults to
  "Retail" for walk-ins, switches automatically when a customer with a
  `default_price_group_id` is selected, cashier can still override before
  adding to cart — mirrors how Phase 11's loyalty balance is "shown, not
  forced"). `api/pos/simple_products.php` and `api/pos/process_sale.php` both
  take a `price_group_id` param and resolve each line's price from
  `product_price_group_prices` → fallback `products.selling_price`.
- Management UI: `app/bms/pos/price_groups.php` (list/create/edit groups +
  a per-product price grid), gated `canView/canEdit('pos_advanced')`.
- **Files:** `migrations/tenant/2026_09_08_pos_price_groups.php` +
  `schema/tenant_schema_template.sql`, `api/pos/simple_products.php`,
  `api/pos/process_sale.php`, `app/bms/pos/pos.php` (Select2), new
  `app/bms/pos/price_groups.php` + `api/pos/save_price_group.php` /
  `get_price_groups.php`, `app/bms/customer/customer_create.php` +
  `customer_edit.php` (default price group field).
- **Tests:** `tests/test_pos_price_groups_cli.php` — group CRUD, sparse
  override resolves to fallback correctly, POS line price matches the chosen
  group, customer auto-select wires the right group, reconciles totals to
  direct SQL.

---

### Phase 15 — Unit Conversion at the Register (packs/cartons ↔ pieces)

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned. `products.unit` confirmed (by reading `product_edit.php`)
to be free-text, not FK-linked to `product_units` — so the new selling-unit
labels are free-text too, for consistency, not a disconnected lookup system.
`core/pos_unit_conversion.php` (`resolveUnitConversion()`,
`convertToBaseUnit()`) resolves everything **server-side** in
`process_sale.php`: the client sends the raw entered quantity + a unit label,
never a multiplier or converted price directly — the same "never trust the
client's math" posture as Phases 16/14/17/18. A `unit_price_override` is
priced per selling unit (e.g. per carton) and converted back to a
per-base-unit price so `line_total = price × base_quantity` stays correct
throughout stock, FEFO, tax, and discount logic untouched from before this
phase. Management UI on `product_edit.php` ("Selling Units" grid); POS
quick-view modal gets a unit dropdown (only rendered when a product actually
has extra units) with a live price preview; cart shows a small unit badge.
**Real bug found and fixed while building this**: `pos_scripts_new.php`'s new
code called `safeOutput()`, but that function is a per-page **local** JS
convention in this codebase (never a global header.php helper — the exact
bug class `tests/test_pos_phase8_registers_cli.php` §3d already guards
against, from a prior incident) — and it turned out nothing on the live POS
terminal page defined it at all. Fixed by adding the standard local
definition to `pos_scripts_new.php`; caught by that pre-existing regression
guard before shipping, not after. `tests/test_pos_unit_conversion_cli.php`
(24 checks). Full POS regression re-run clean.

**Closes:** selling a carton of 12 or a ream of 500 sheets from stock held in
pieces — a routine stationery/general-shop need BMS's flat per-line POS can't
do today. **Gate:** base `pos` (till hygiene — see the gate-boundary note
added to Phase 17; unit conversion is an everyday counter need, not a
premium differentiator, so it wasn't restricted to `pos_advanced` as
originally sketched).

**What exists today:** `products.unit_of_measure` / `unit` — one unit, no
conversion. `product_units` table exists but is just a flat lookup list
(unit_id, unit_code, unit_name) with no conversion factor between two units of
the same product.

**Build:**
- New table `product_unit_conversions` (`product_id`, `sell_unit_id` FK
  `product_units`, `base_unit_multiplier` decimal — e.g. "Carton" = 12× the
  product's base stock unit, "Piece" = 1×). Base stock unit stays
  `products.unit_of_measure` (unchanged — `product_stocks.stock_quantity`
  keeps meaning base units, so nothing downstream breaks).
- POS line: a unit dropdown per product (defaults to base unit) populated from
  that product's conversions; changing it recomputes `quantity × multiplier`
  against available stock and `unit_price × multiplier` for the line total
  (or a separately-priced per-unit price, admin's choice per conversion row —
  store an optional `unit_price_override` alongside the multiplier for that).
  `api/pos/process_sale.php` converts the sold quantity to base units before
  writing `stock_movements`/`product_stocks`, so stock stays in one true unit
  regardless of what was picked at the till.
- Management UI: extend `app/bms/product/product_edit.php` with a "Selling
  Units" sub-table (add/remove unit + multiplier + optional price).
- **Files:** `migrations/tenant/2026_09_08_pos_unit_conversions.php` +
  schema, `app/bms/product/product_edit.php` + a new
  `api/save_product_unit.php`/`get_product_units.php`, `app/bms/pos/pos.php`
  + `pos_scripts_new.php` (unit dropdown, recompute logic),
  `api/pos/process_sale.php` (convert-to-base before stock write).
- **Tests:** `tests/test_pos_unit_conversion_cli.php` — multiplier math,
  stock decrements in true base units regardless of unit sold, price
  override vs. computed price, zero-conversion products unaffected
  (backward compatible).

---

### Phase 16 — Cashier Price/Discount-Override Permission Split

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned below, plus a real pre-existing gap found while building it:
`process_sale.php` trusted the client-submitted line price directly (only the
final/discounted price was checked against `min_selling_price`) — a forged
request could set the base price to anything. Fixed as part of this phase:
base price now always resolves server-side from `products.selling_price`
(`core/pos_override_guard.php::resolvePosLineBasePrice()`) unless a permitted
cashier explicitly used the new "Edit Price" line affordance.
`tests/test_pos_override_permissions_cli.php` (23 checks). Full POS
regression suite re-run clean.

**Closes:** a standard anti-fraud control — a cashier can sell but should not
always be able to change a line's price or discount. **Gate:** base `pos`
(loss-control hygiene, not a premium feature — same boundary logic Phase 13
used to keep Z-Report/receipt in base tier). **Lowest complexity in this
section — good first phase to build.**

**What exists today:** the four blanket `canView/canCreate/canEdit/canDelete('pos')`
gates only (§3 Phase 1d) — no finer-grained action inside a sale.

**Build:**
- Two new permission `page_key`s: `pos_price_override`, `pos_discount_override`
  (seeded via migration into `permissions`, assignable per role like any
  other page_key — no new permission *system*, just two more rows in the
  existing one).
- POS terminal: if the logged-in user lacks `canEdit('pos_price_override')`,
  the unit-price cell on each cart line becomes read-only (server-side
  `process_sale.php` re-validates: a submitted `unit_price` that doesn't match
  the resolved product/price-group price is rejected unless the user has the
  permission — never trust the client). Same pattern for
  `pos_discount_override` against `discount_amount`/`discount_rate`/
  `discount_percentage`.
- Admins bypass via `isAdmin()`, identical to every other `canX()` in the app.
- **Files:** `migrations/tenant/2026_09_08_pos_override_permissions.php`,
  `app/bms/pos/pos.php` (readonly attribute), `pos_scripts_new.php` (disable
  the input client-side too, for UX not security), `api/pos/process_sale.php`
  (server-side price/discount re-validation — the actual security boundary).
- **Tests:** `tests/test_pos_override_permissions_cli.php` — a user without
  the permission cannot post a sale with a manually-changed price/discount
  even via a raw API call with a forged `unit_price`; a user with it can;
  admin always can.

---

### Phase 17 — Batch/Lot Number + Expiry Tracking, end-to-end (GRN → stock → POS → alerts)

**Status:** ✅ DONE (17a/17b/17c/17d) · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped 17a-17d as planned below, with two adjustments made while building
against the real code (documented, not silently dropped):
- **The real stock-arrival point is `api/approve_grn.php` (on approval),
  not `api/create_grn.php`** — a GRN is `pending` at creation and its
  three-approval-workflow stock side-effects (`$updateStock=false` at
  create) only fire from `approve_grn.php`. `product_batches` rows are
  created there, alongside the existing `product_stocks`/`stock_movements`
  update, using `receipt_items.batch_number`/`.expiry_date`/`.unit_price`
  already captured at creation time.
- **`core/notify.php::resolveRecipients()` had NO warehouse-scope branch**
  (only project-scope) — the plan's claim that it could just reuse
  `scopeFilterSql('warehouse', ...)` was aspirational; that helper is
  session-bound to the *current* user and `resolveRecipients()` must check
  *candidate recipients* who aren't the current session. Added a real,
  reusable `warehouseIdsForUser(PDO $pdo, int $userId, bool $isAdmin)` to
  `core/warehouse_scope.php` (a literal per-user-id mirror of
  `loadUserScope()`'s warehouse derivation) and a matching warehouse-scope
  branch in `resolveRecipients()`, alongside the existing project-scope one.
  This is a genuine, reusable engine enhancement, not a one-off hack —
  future warehouse-scoped events get it for free.
- **UI batch-picker override** (cashier manually choosing a non-default
  batch) was deliberately NOT built this pass — server-side FEFO (always
  correct, always safe) ships; the manual-override affordance is a
  lower-value polish item deferred, not silently dropped.

Extracted `core/pos_batch_consumption.php` (`consumeFefoBatches()`,
`reverseFefoBatchConsumption()`) and `core/pos_price_groups.php`-style
testability. `tests/test_pos_batch_expiry_cli.php` (43 checks: multi-batch
FEFO split, insufficient-stock non-error, full/partial reversal restoring
the exact originating batch(es), `warehouseIdsForUser()` admin/grant-all/
specific-warehouse cases, `resolveRecipients()` warehouse narrowing incl. a
specific-user+email rule, milestone dedup on two consecutive cron runs).
Dashboard's "expiring" widget now shows real per-batch data for
batch-tracked products, unchanged product-level behaviour for the rest.
Full POS + GRN + notification-engine + feature-registry regression re-run
clean; pre-existing unrelated failures (`test_pos_color_settings_split_cli`,
`test_pos_dashboard_cli`, `test_notification_engine_cli` §11 — a stale
assertion about `save_invoice.php` from an earlier, unrelated
auto-approve refactor) confirmed present on unmodified `develop` too.

**Closes:** the one gap the product owner asked to be built out fully, not
minimally. **Gate, as actually shipped:** base `pos` / ungated — since the
UI batch-picker override (the one genuinely upsell-shaped piece) was
deferred, what shipped is automatic FEFO consumption + expiry alerting +
read-only batch visibility, which is operational integrity every tenant
needs (same boundary reasoning Phase 13 used to keep Z-Report/receipt in
base tier), not a premium differentiator. The expiry **alerting** itself
routes through the notification engine's own permission model (`canView`
on the relevant page_key + warehouse scope, same as every other event).
If the batch-picker override is built later, THAT specific affordance
should gate behind `pos_advanced`, matching the original intent below.

**What exists today (evidence, not assumption):** `receipt_items.batch_number`
/`.expiry_date` are captured at GRN (`api/create_grn.php:144-181`) but never
used again — no batch-level stock ledger, no FEFO, no alert. Separately,
`products.expiry_date`/`expiry_days`/`email_alerts` exist but represent **one**
expiry date for the whole product and are read only by `dashboard.php`'s
30-day widget; `email_alerts` is captured by `api/update_product_alerts.php`
but nothing sends anything off it today.

**Build — four parts, in this order (each independently testable):**

**17a. Real batch ledger.** New table `product_batches` (`batch_id`,
`product_id`, `warehouse_id`, `batch_number`, `expiry_date`,
`quantity_received`, `quantity_remaining`, `unit_cost`, `receipt_id` FK
`purchase_receipts`, `created_at`). `api/create_grn.php` inserts one row per
received line here (in addition to, not instead of, its existing
`receipt_items` write — `receipt_items` is the immutable GRN document line;
`product_batches` is the live, decrementing stock ledger derived from it) —
this is the fix for the "dead-end data" problem above.

**17b. FEFO consumption at sale.** `api/pos/process_sale.php`: when a product
has one or more rows in `product_batches` with `quantity_remaining > 0`,
consume oldest-expiry-first (First-Expired-First-Out) across as many batch
rows as the sold quantity needs, decrementing `quantity_remaining` per row and
recording which batch(es) a sale line drew from in a new
`pos_sale_item_batches` link table (`sale_item_id`, `batch_id`, `quantity`) —
mirrors how `create_return.php` already links returns back to original sale
items, same relational shape. A product with **no** batch rows (not tracked at
batch level) sells exactly as it does today — fully backward compatible, this
is additive.
- POS UI: when a product has multiple open batches, a small batch picker
  shows available batches with their expiry (oldest pre-selected, cashier can
  override — e.g. deliberately sell an older batch first is already the
  default, but a manager might need to move a specific batch).

**17c. Expiry alerting — reuses the notification engine and the exact
`document_expiry_reminders` dedupe pattern, not a new design:**
- New table `product_batch_expiry_reminders` (`batch_id`, `milestone`,
  `sent_at`), `UNIQUE(batch_id, milestone)`, `INSERT IGNORE` dedupe — copy of
  `document_expiry_reminders`.
- New block in `cron/run_notification_checks.php` (alongside the existing
  `invoice.overdue` check): scan `product_batches` where
  `quantity_remaining > 0` and `expiry_date` is within milestones `[30,14,7,1]`
  days (same milestone set as documents — consistent business language), fire
  `dispatchEvent($pdo, 'product.batch_expiring', [...])` for each newly-reached
  milestone, dedup via the new reminders table.
- New `notification_events` row: `event_key='product.batch_expiring'`,
  `page_key='products'`, `required_verb='view'`, `scope_aware` on warehouse
  (via the existing `scopeFilterSql('warehouse', ...)` helper from §3 Phase 6,
  so a warehouse-scoped user is only alerted about expiring stock in their own
  warehouse — reuses Phase 6's ACL, doesn't bypass it). Default routing:
  everyone with `canView('products')` in that warehouse's scope, admin-editable
  per §3's Phase 5 rules UI like every other event — **this is also where
  "email can be sent to a specific user" is satisfied**: the product owner (or
  any admin) opens Settings → Notification Rules → `product.batch_expiring` →
  Add Target → a specific **User** or **Role**, checks **Email**, and that
  person gets both the in-app bell and an email for every future expiry event,
  with **Preview recipients** to confirm exactly who before saving. No new UI
  is needed for "notify this specific person" — the engine already has it.
- Dashboard: extend the existing "expiring" widget (`dashboard.php` line
  ~581-588) to read from `product_batches` when a product has batch rows
  (falls back to the current `products.expiry_date` behavior otherwise) so the
  same widget shows real per-batch remaining-quantity + days-left instead of
  one date per product.

**17d. Management/visibility UI.** `app/bms/product/product_view.php` gets a
"Batches" tab (batch #, expiry, qty remaining, received date, source GRN link)
— read-only, no new write path beyond 17a/17b.

- **Files:** `migrations/tenant/2026_09_08_pos_product_batches.php` (+
  `product_batch_expiry_reminders`, `pos_sale_item_batches`) + schema,
  `api/create_grn.php`, `api/pos/process_sale.php`, `api/pos/create_return.php`
  /`void_sale.php` (restock **into the correct batch**, not just a generic
  quantity bump — matches how §3 Phase 1 already restocks on void/return),
  `app/bms/pos/pos.php` + `pos_scripts_new.php` (batch picker),
  `cron/run_notification_checks.php`, `app/dashboard.php`,
  `app/bms/product/product_view.php`.
- **Tests:** `tests/test_pos_batch_expiry_cli.php` — GRN writes a batch row;
  FEFO consumes oldest-expiry batch first across a multi-batch sale; void/
  return restocks the exact originating batch; milestone dedup (re-running the
  cron twice fires once); scope-aware routing (a warehouse-scoped user only
  sees their warehouse's expiring batches, mirroring
  `tests/test_warehouse_scope_cli.php`'s existing rigor); a specific-user email
  target actually enqueues to `notification_outbox` for that one user.

---

### Phase 18 — Purchase-to-Sell Cost Mapping (per-batch COGS)

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

`core/sales_posting.php::posSaleCogs()` (the function `postPosSale()` calls
for the ledger's COGS leg) now prefers, per sale line, Σ(quantity × the
batch(es) it drew from via Phase 17's `pos_sale_item_batches`) — real
purchase-linked cost, not an average — falling back to the pre-existing
`quantity × products.cost_price` for any line with no batch consumption
(not batch-tracked, or sold before this phase). `tests/test_pos_batch_cogs_cli.php`
(5 checks, including a fixture deliberately designed so batch-cost and
average-cost give clearly different numbers, proving the batch path is
actually used, not coincidentally matching). **Known, documented
divergence, not silently introduced:** the Income Statement's own
POS-COGS drill-down (`api/account/get_income_statement_detail.php`) still
computes average-cost only, inline across a report result set — making
*that* batch-aware too is a separate, larger change to a
financially-sensitive report, deliberately left for a future pass. POS
dashboard/Z-Report do not currently show any margin figure at all, so
there was nothing there to wire up.

**Closes:** accurate per-sale margin instead of a running average cost.
**Depends on Phase 17** (needs `product_batches`/`pos_sale_item_batches` to
exist — natural next phase, not standalone). **Gate:** `pos_advanced`.

**Build:** since Phase 17b already records which batch(es) a sale line drew
from and each `product_batches` row already carries its own `unit_cost` (from
the GRN it came from), COGS per sale line becomes
`Σ(quantity_from_batch × batch.unit_cost)` instead of the current single
average `products.cost_price`. Wire this into `core/pos_ledger.php`'s
`postPosSale()` COGS leg (§3 Phase 3-A/7's GL posting) so the Trial Balance's
COGS figure is batch-accurate, and into the POS dashboard/Z-Report margin
figures.
- **Files:** `core/pos_ledger.php` (or wherever `postPosSale()` computes COGS
  today — confirm exact file when this phase starts), `api/pos/get_dashboard.php`.
- **Tests:** `tests/test_pos_batch_cogs_cli.php` — COGS reconciles to
  Σ(batch quantity × batch cost) for a multi-batch sale, falls back to
  `products.cost_price` for non-batch-tracked products (no regression).

---

### Phase 19 — Customer Credit-Limit Enforcement at POS

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned, with one refinement: only a real credit **exposure**
(`balance_due > 0` after any deposit) is checked — a "credit" sale paid in
full via a deposit carries no actual risk and is never blocked, even for a
customer already at their limit. `core/pos_credit_limit.php`
(`customerOutstandingBalance()`, `assertPosCreditLimitPermitted()`) mirrors
the exact per-sale balance formula `api/pos/receive_payment.php` already
uses. A manager override (`canEdit('pos')`, re-checked server-side on
retry, never trusted from a client flag) is surfaced via a **dedicated
exception type** (`PosCreditLimitExceededException`) and a structured
`error_code`/`can_override` JSON field — deliberately not string-matching
the (translated) error message client-side, which would silently break
under Swahili. `api/pos/search_customers.php` now returns each customer's
credit limit + live outstanding balance, shown next to the customer picker
the moment they're selected — a cashier sees available credit before
attempting a sale, not only after being blocked. `tests/test_pos_credit_limit_cli.php`
(19 checks). Full POS regression re-run clean.

**Closes:** `customers.credit_limit` is captured today but never checked.
**Gate:** base `pos` (protects the business's own cash flow — not an upsell).

**Build:** in `api/pos/process_sale.php`'s existing credit-sale path (§3 Phase
3-A), before committing a `payment_method='credit'` sale, sum the customer's
current outstanding balance (same balance calculation `receive_payment.php`
already uses) and reject with a clear error if
`outstanding + this_sale_total > customers.credit_limit` (a `credit_limit` of
`0` means "no credit allowed" — consistent with the column's existing
`DEFAULT '0.00'`; a manager-level override to proceed anyway is a `canEdit('pos')`-gated
"Override limit" confirm, logged via `logAudit` since it's a deliberate policy
exception).
- **Files:** `api/pos/process_sale.php`, `app/bms/pos/pos.php` (surfaces the
  customer's available credit next to the balance/loyalty display already
  added in §3 Phase 10/11).
- **Tests:** `tests/test_pos_credit_limit_cli.php` — sale blocked at/over
  limit, allowed under limit, manager override logs an audit row, walk-in
  (no customer) unaffected.

---

### Phase 20 — Cash Denomination Counting (shift open/close)

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned. `core/pos_denominations.php` (`posDenominationList()`,
`validateDenominationBreakdown()`, `save/getDenominationBreakdown()`) — the
denomination list is admin-configurable (`system_settings.tzs_denominations`,
CSV, defaulted to current TZS notes/coins), never hardcoded. A collapsible
"Count by denomination (optional)" grid on both the Start Shift and End
Shift modals live-sums into the existing `openingCash`/`endingCash` field as
the cashier types counts — the single total stays authoritative either way,
the breakdown is optional supporting detail, never required. Server-side
validation rejects an unconfigured denomination value, a negative count, or
a breakdown that doesn't sum to the entered total. The Z-Report prints both
the open and close breakdowns when present. `tests/test_pos_denomination_cli.php`
(26 checks). Full POS regression re-run clean.

**Closes:** till-counting disputes — currently one lump `actual_cash` number.
**Gate:** base `pos` (till hygiene).

**Build:** new table `cash_denomination_counts` (`shift_id`,
`denomination_value`, `count`, `context` enum `'open'|'close'`) — TZS note/coin
values seeded from a settings-configurable list (`system_settings` key
`tzs_denominations`, default `10000,5000,2000,1000,500,200,100,50`, editable
so it isn't hardcoded). `open_shift.php`/`close_shift.php` accept a
denomination breakdown alongside the existing single total, store both (the
total stays authoritative for `starting_cash`/`actual_cash` — the breakdown is
supporting detail, not a second source of truth) and the Z-Report
(`app/bms/pos/zreport.php`, §3 Phase 9) prints the breakdown table.
- **Files:** `migrations/tenant/2026_09_08_pos_cash_denominations.php` +
  schema, `api/pos/open_shift.php`, `api/pos/close_shift.php`,
  `app/bms/pos/pos_modals_new.php` (denomination input grid),
  `app/bms/pos/zreport.php`.
- **Tests:** `tests/test_pos_denomination_cli.php` — breakdown sum must equal
  the entered total (validation), Z-Report prints the right rows, shift
  without a breakdown (older data) still renders fine.

---

### Phase 21 — Network (IP) Thermal-Printer Support (real ESC/POS + drawer-kick)

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned. `core/escpos_printer.php` (`buildEscPosReceipt()`,
`sendToNetworkPrinter()`) builds the raw ESC/POS byte stream (init, item
lines, totals, `GS V 0` full cut, `ESC p 0 25 250` drawer-kick — the exact
sequence that makes the drawer-kick claim real, not speculative: it rides
the same TCP socket as the print job, no separate hardware channel needed)
and sends it via a plain `fsockopen()`. `pos_registers` gained
`printer_connection_type` (default `'browser'` — every existing register's
behaviour is completely unchanged), `printer_ip_address`, `printer_port`.
`print_receipt.php` branches on the sale's register before rendering
anything: network mode attempts the socket send and shows a lightweight
confirmation page on success; any failure (unreachable IP, timeout) is
**fail-open** — it falls straight through to the existing browser
print-dialog page, so a misconfigured printer never blocks a cashier from
getting a receipt at all. Settings UI on `pos_config_settings.php` (per
register: connection-type picker, IP/port fields, a "Test Printer" button
hitting the new `api/pos/test_network_printer.php`). `tests/test_pos_network_printer_cli.php`
(34 checks, including exact byte-level assertions on the built receipt —
no real printer hardware needed to verify correctness). Full POS
regression re-run clean.

**Closes:** §3 Phase 10 correctly ruled out browser-only raw printing and
WebUSB (needs HTTPS, unavailable on this LAN/HTTP WAMP deployment) — but the
UltimatePOS audit found a **third path BMS hadn't evaluated**: a printer with
its own network interface is a plain TCP socket target, reachable directly
from PHP with no browser involvement at all. **Gate:** `pos_advanced`.

**Scope, precisely:** this only helps **network (Ethernet/WiFi) thermal
printers** — a real, common, and cheap class of hardware. USB-only printers
still need a local print-bridge agent (out of scope, unchanged conclusion from
Phase 10).

**Build:** extend `pos_registers` with `printer_connection_type` enum
(`'browser'` default — today's behavior unchanged — or `'network'`),
`printer_ip_address`, `printer_port` (default `9100`, the standard raw
ESC/POS port). New `core/escpos_printer.php`: builds an ESC/POS byte stream
(init, text, cut, and the drawer-kick command `\x1B\x70\x00\x19\xFA` riding
the same socket — this is what makes the drawer-kick claim real, not
speculative) and sends it via `fsockopen($ip, $port)`. `print_receipt.php`:
when the sale's register has `printer_connection_type='network'`, call this
instead of rendering the browser print dialog; falls back to the existing
browser path on any socket error (fail-open, never blocks a sale).
- **Files:** `migrations/tenant/2026_09_08_pos_network_printer.php` + schema,
  new `core/escpos_printer.php`, `api/pos/print_receipt.php`,
  `app/bms/pos/pos_config_settings.php` (printer connection fields per
  register, extending §3 Phase 8's register CRUD section).
- **Tests:** `tests/test_pos_network_printer_cli.php` — ESC/POS byte-stream
  construction (unit-testable without real hardware: assert the exact bytes
  for a sample receipt + the drawer-kick sequence), socket-failure fallback
  path, `connection_type='browser'` (the default) is fully unaffected.

---

### Phase 22 — Receipt/Invoice Layout Variety + WhatsApp Receipt Link

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned. `pos_registers.receipt_template` (`'classic'` default —
the pre-existing, unmodified layout — `'detailed'` adds per-line
discount/tax and the sale-level discount row, `'slim'` drops the
subtotal/tax breakdown down to totals only), whitelisted server-side both
on save (`save_register.php`, mirroring the exact lesson from Phase 8's
enum-coercion bug — never trust the DB enum alone) and on read
(`print_receipt.php`). The post-sale success dialog gained a third
("Share via WhatsApp") button alongside Print/Next Customer — builds the
receipt text from the cart **before** it's cleared for the next sale, then
opens a plain `wa.me` deep-link with it URL-encoded; no gateway, no new
dependency, confirmed working the same way in the UltimatePOS audit that
originally surfaced this gap. `tests/test_pos_receipt_templates_cli.php`
(29 checks). Full POS regression re-run clean.

**Closes:** cosmetic but real — one fixed layout today vs. selectable designs.
**Gate:** base `pos` (a small business shouldn't need the premium tier just to
pick a receipt look — cosmetic, not a capacity feature).

**Build:** 3-4 selectable receipt templates (not UltimatePOS's ~10 — scoped to
what's realistically distinct for an 58/80mm thermal receipt: "Classic",
"Detailed" [shows per-line tax/discount], "Slim" [totals only]) as
`print_receipt.php` render variants chosen via a `pos_registers.receipt_template`
column (per-register, extending Phase 8's per-register branding). Plus the
cheap, real WhatsApp deep-link found during the audit: a "Share via WhatsApp"
button on the post-sale receipt screen builds a `https://wa.me/<customer_phone>?text=<url-encoded receipt text>`
link — no gateway, no new dependency, works today.
- **Files:** `api/pos/print_receipt.php` (template branch), `pos_config_settings.php`
  (template picker per register), `pos_scripts_new.php` (WhatsApp share button).
- **Tests:** `tests/test_pos_receipt_templates_cli.php` — each template
  renders without error for a sample sale, WhatsApp link URL-encodes correctly
  (special characters, long receipts).

---

### Phase 23 — Combo/Bundle Products

**Status:** ✅ DONE · **Built:** 2026-09-08 · **Branch:** `feat/pos-tier3-advanced-retail`

Shipped as planned, with real reuse verified before trusting it: read
`api/get_service_components.php` first to confirm `product_assembly_components`
(`parent_product_id`/`component_product_id`/`qty_per_unit`, already used for
service cost-breakdowns and NIP material lists) really does already mean
"product X is made of N units of product Y" against `products.product_id` —
it does, so reusing it is genuinely safe: the new `products.is_combo` flag
is the only gate, existing service/NIP rows are completely untouched, and
combo behaviour only ever triggers on a product explicitly marked
`is_combo=1` through the new dedicated UI (never touching the existing
NIP/service pages or endpoints). `core/pos_combo_products.php`
(`checkComboAvailability()`, `consumeComboComponents()`,
`reverseComboComponents()`) — every combo line's component availability is
checked for the WHOLE cart before any write happens, so a short component
blocks the sale up front, never a partial failure. **Real pre-existing bug
found while building this, not introduced, not silently fixed**:
`void_sale.php`/`create_return.php` already recorded their own (non-combo)
stock reversals with `reference_type='pos_void'`/`'pos_return'`, neither of
which is in `stock_movements.reference_type`'s actual ENUM (`'purchase_order',
'sales_order','pos_sale','invoice','stock_adjustment','stock_transfer',
'return','production_order','manual'`) — silently coerced under this
server's non-strict `sql_mode`, the same bug class as Phase 8's `split`/
`mixed` finding. New combo code correctly uses `'return'`; the pre-existing
bug in the surrounding (already-shipped, already-tested) code is flagged
here for a separate fix, not bundled into this phase. `tests/test_pos_combo_products_cli.php`
(28 checks). Full POS regression re-run clean.

**Closes:** selling a fixed bundle (e.g. a "back-to-school pack") as one line
that decrements every component's stock. **Gate:** `pos_advanced`.

**Build:** reuses the existing `product_assembly_components` table (already in
the schema at line 5504 — built for BOM/assembly, structurally identical to
what a combo needs: `parent_product_id`, `component_product_id`, `qty_per_unit`)
rather than a new table. Add `products.is_combo` flag; `api/pos/process_sale.php`,
on selling a combo product, decrements each component's stock via the normal
`stock_movements` path (reference_type `'pos_sale'`) instead of the combo
product's own (nonexistent) stock. Void/return reverses all components
together (one combo line = one atomic unit for reversal, matching how a
regular sale line already reverses atomically).
- **Files:** `migrations/tenant/2026_09_08_pos_combo_products.php` (adds
  `is_combo`, reuses existing table), `app/bms/product/product_edit.php`
  (component picker, reuses whatever UI already manages assembly components
  if one exists — confirm at phase start), `api/pos/process_sale.php`,
  `api/pos/void_sale.php`/`create_return.php`.
- **Tests:** `tests/test_pos_combo_products_cli.php` — selling a combo
  decrements every component correctly, void reverses all components, a combo
  with a component that's itself out of stock is blocked before the sale
  posts (not a partial failure).

---

### Phase 24 — Weighing-Scale Barcode Support

**Status:** DEFERRED (confirmed, not built) · **Decided:** 2026-09-08

Per the recommendation below — no confirmed weight-sold inventory, and
blocked on §3 Phase 5's barcode-scan rebuild (not yet done) — this phase
was deliberately NOT built in this tranche, the same documented-deferral
pattern already used for Phase 12 (offline resilience) and Phase 11's
genuine multi-currency. **This completes the Tier-3 tranche: 10 of 11
phases shipped (16, 14, 17, 18, 15, 19, 20, 21, 22, 23), each on its own
commit with its own test suite, on branch `feat/pos-tier3-advanced-retail`.**
If the business later confirms it sells anything by weight, revisit this
phase — the design below stays valid.

**Closes:** lowest priority in this tranche — only matters if BMS's POS is
ever used for anything sold by weight (some general-shop goods; low relevance
for pure stationery). **Gate:** `pos_advanced`. **Recommendation: defer until
a concrete need is confirmed** — build only if the business actually stocks
weight-sold items; speculative build otherwise (same reasoning §3 Phase 11
used to defer genuine multi-currency).

**Build (when triggered):** the common embedded-weight barcode format is a
fixed-length numeric code with a prefix digit, product code, and a
weight/price segment. A JS decoder in `pos_scripts_new.php`'s existing barcode
scan handler (once barcode scanning itself is rebuilt — see §3 Phase 5's
flagged "clean re-add candidate", a **prerequisite** for this phase) parses
that format, looks up the product by its embedded code, and pre-fills quantity
(weight) instead of defaulting to 1 — no server changes needed beyond the
existing `process_sale.php` line-quantity handling.
- **Tests (when built):** `tests/test_pos_weighing_barcode_cli.php` — decode
  a representative sample of embedded-weight barcodes into
  {product_code, weight}, reject a malformed/checksum-failing one instead of
  guessing.

---

### Known follow-ups from this tranche (documented, not fixed here — separate work)

Found while building Phases 14-23, each already noted in its own phase
above, consolidated here so they aren't buried:

1. **`stock_movements.reference_type` enum mismatch** (found in Phase 23) —
   `void_sale.php` and `create_return.php` already logged their own
   (non-combo) stock reversals with `reference_type='pos_void'`/`'pos_return'`,
   neither of which is in the actual live ENUM
   (`purchase_order,sales_order,pos_sale,invoice,stock_adjustment,
   stock_transfer,return,production_order,manual`) — silently coerced under
   this server's non-strict `sql_mode`, the same bug class as §7 Phase 8's
   `split`/`mixed` finding. Pre-existing, predates this tranche; not fixed
   here since it's outside Phase 23's stated scope and touches
   already-shipped, already-tested code from an earlier phase.
2. **Income Statement POS-COGS drill-down not batch-aware** (Phase 18) —
   `api/account/get_income_statement_detail.php`'s own inline query still
   uses average `products.cost_price` only; only the ledger-posting path
   (`core/sales_posting.php::posSaleCogs()`) was made batch-aware. Making
   the report match is a separate, larger change to a financially-sensitive
   report.
3. **POS-side manual batch-picker UI deferred** (Phase 17) — server-side
   FEFO (always correct) ships; a cashier/manager manually overriding which
   batch a sale draws from is a lower-value polish item, not built.
4. **Phase 24 (weighing-scale barcode) deferred** — see above.

### Recommended build order

**Barcode scanning itself (§3 Phase 5's flagged, not-yet-built item) blocks
Phase 24 and is worth resurfacing as its own small phase before 24 — not
listed with its own number above because it was already scoped in §3, just
never scheduled.** Suggested sequence for this section, balancing "quick wins
first" against "the one the owner most wants done properly gets real
priority, not last":

1. **Phase 16** (permission split) — smallest, immediate loss-control value.
2. **Phase 14** (price tiers) — highest everyday relevance, builds on data
   that already exists.
3. **Phase 17** (batch/lot + expiry + notifications) — the owner's priority
   item; largest single phase, deliberately sequenced early rather than saved
   for last, per 17a→17b→17c→17d internally.
4. **Phase 18** (per-batch COGS) — immediately follows 17, same data.
5. **Phase 15** (unit conversion) — second-highest everyday relevance.
6. **Phase 19** (credit limit) — quick, protects cash flow.
7. **Phase 20** (cash denominations) — quick, till hygiene.
8. **Phase 21** (network printer) — real hardware payoff, moderate effort.
9. **Phase 22** (receipt layouts + WhatsApp link) — cosmetic polish.
10. **Phase 23** (combo products) — niche but reuses existing schema, cheap.
11. **Phase 24** (weighing scale) — deferred pending confirmed need, and
    blocked on barcode scanning being rebuilt first.

Each phase = its own branch off `develop`, its own `tests/test_pos_*_cli.php`
suite (transaction-wrapped, reconciled to direct SQL), its own PR into
`develop`, `changelog.md` updated at commit time — identical discipline to
every phase in §3 and §7. No phase starts without an explicit go-ahead on that
phase specifically, per the product owner's "no rushing" instruction.

---

## 9. Tier-4 — Multi-Vertical POS (Restaurant, Serials, Variants, Dashboard
Intelligence) — SalePro/fasteeypos benchmark, 2026-09-10, re-scoped 2026-09-11

**Status:** APPROVED by product owner 2026-09-10, **re-scoped 2026-09-11 to
POS-only** after an explicit instruction to stop at the POS module's own
boundary and not spread into adjacent-but-separate business functions or
touch any shared, cross-module surface more than strictly necessary. Build
**one phase at a time, no rushing** — same rule as Tier-3.

**Sources:** (1) a hands-on walkthrough of the SalePro demo
(`salepropos.com/demo`) — dashboard, POS terminal (Retail POS vs. Restaurant
POS chosen per warehouse), Restaurant module (Floors/Tables/Reservation/Menu
Type/Modifier Group/Kitchen/Kitchen Dashboard), Repair module, Add Product
form, Booking calendar; (2) a hands-on walkthrough of the fasteeypos.com demo
account — its two-tier icon-rail + contextual sub-menu navigation, and its
Business Overview dashboard (Sales Targets vs. Actual with achievement
bands, Top Performing Cashiers, Damage-in-Sales/Damage-in-Purchases KPIs,
Restaurant "Ingredients"/"Recipes" concept); (3) a full three-angle
code/schema audit of BMS itself (schema dump of every POS-adjacent table, a
full read of `api/pos/process_sale.php` end to end, and the project's own
conventions: `.claude/templates.md`, `.claude/security.md`, migration
mechanics, `core/project_scope.php`, `core/feature_registry.php`,
`core/notify.php`); (4) a direct read of `header.php`'s and `roots.php`'s
real, current POS navigation wiring (see Phase 30 below) to ground the
navigation redesign in the actual mechanism, not an assumed one.

**The gap this closes:** the product owner's businesses span pharmacy,
restaurant, pub, stationery, vehicle-spares, and supermarket — but BMS's POS
is one fixed terminal regardless of warehouse. SalePro solves this not with a
"pharmacy mode" or "vehicle-spares mode" but with (a) a **per-warehouse POS
mode switch** (Retail vs. Restaurant) and (b) a dedicated **Restaurant
module** (tables, kitchen routing, item modifiers) for restaurant/pub, while
(c) richer **product-level data** (variants, serials, warranty, promotional
pricing) is what lets the *generic* retail engine serve pharmacy/stationery/
supermarket/spares-counter-sales without any vertical-specific code at all.
This tier builds the BMS equivalent of those things — reusing existing BMS
infrastructure at every point below, not a parallel system — **and stops
there**: it does not create a new business module outside POS (see the
"Cut from this plan" note immediately below).

**Cut from this plan, 2026-09-11, with reasons (not silently dropped):**
- **Repair / Service-Job module — removed entirely, not deferred.** The
  2026-09-10 draft of this tier included a full ticketing workflow (its own
  `app/bms/repair/` nav module, its own permission set, billing that reaches
  into the shared `invoices`/Accounting tables) for vehicle-garage/
  electronics-repair businesses. On explicit re-scoping instruction
  ("focus only on POS... should not destroy or harm other modules at all"),
  this was cut: a job-ticket workflow is not a point-of-sale concern — it is
  a distinct business module (closer in shape to BMS's existing
  Project Management or HR-ticket-style modules) that happens to end in a
  bill. Building it as part of a "POS plan" would mean this plan's own
  discipline (branch/tests/PR *per phase*, contained to POS) stops applying
  the moment billing reaches into Accounting's tables — exactly the
  kind of blast-radius creep the re-scoping instruction was about. If the
  product owner wants this later, it deserves its own plan document,
  scouted and reviewed on its own terms, not smuggled into a POS tranche.
- **Booking/Appointment engine — cut down from a generic, reusable-anywhere
  calendar to a thin, POS-only reservation slot list.** The original design
  (`booking_type: general/service_appointment/table_reservation`,
  `resource_type: table/employee/none`) was deliberately generic so it could
  later serve non-POS use cases too — but "could be reused by other modules
  later" is itself a wider surface than "improve POS," and a generic engine
  is a bigger, more cross-cutting thing to get right than the one feature
  POS actually needs. Rebuilt inside Phase 30 below as `restaurant_
  reservations` — table bookings only, owned entirely by the Restaurant
  sub-module, no ambition to be a platform-wide scheduler.

**One naming correction made before designing anything:** the word
"workflow" is already a live BMS concept (`workflow_documents`/
`workflow_steps`/`workflow_signatures` — the document e-signature/
approval-chain engine used by tenders/POs/quotations/invoices/sales orders).
The new per-warehouse POS switch is named **`pos_mode`**, not "POS workflow",
to avoid colliding with that existing, unrelated feature.

**Ground rules for this whole tier (verified against real code, not
assumed):**
- **Every new table carries `warehouse_id`** and reuses the existing
  generic `scopeFilterSqlNullable('warehouse', alias)` / `userCan('warehouse',
  $id)` (`core/project_scope.php`) — the same ACL §3 Phase 6 already wired
  into POS/reports/dashboard. No new access-control mechanism anywhere in
  this tier.
- **Every new feature is a new `core/feature_registry.php` entry**,
  `default: false`, enforced UI-side **and** API-side, double-checked at
  runtime — identical shape to the existing `pos_advanced` entry. Invisible
  to every existing tenant until a superadmin grants it.
- **Every schema change ships in two matching halves**: a
  `migrations/tenant/YYYY_MM_DD_*.php` file (auto-applied to every existing
  tenant by `core/tenant_migration_runner.php` — one tenant's failure stops
  only that tenant) **and** the identical change in
  `schema/tenant_schema_template.sql` (+ `schema/tenant_seed_defaults.sql`
  for seeded rows), so a brand-new signup gets it from day one instead of
  relying on the migration backlog. Skipping the second half is exactly the
  "hanging logic" failure mode called out by the product owner — flagged
  explicitly per phase below so it can't be silently missed.
- **Every new column defaults to reproducing today's behaviour exactly.**
  `warehouses.pos_mode` defaults `'retail'` for every existing and new
  warehouse — the POS terminal renders byte-for-byte identical to today
  until an admin explicitly flips one warehouse. No workflow-picker modal
  appears anywhere until a warehouse actually opts in.
- **"Assigned staff" always reuses the existing `assigned_to` convention**
  (`crm_leads`, `loan_risk_factors` already use this exact column name/shape,
  FK to `users.user_id`) — never a new `waiter_id` column, confirmed there is
  no such precedent to fork from. A waiter picker filters by
  `employees.warehouse_id` — a real existing employee/user record, never a
  new "staff type" entity.
- **A shared file touched by every module (`header.php`, `roots.php`, any
  ENUM column another module also reads) is edited only additively, and
  only inside the lines that already belong to POS.** Phase 30 states
  exactly which lines of `header.php` it touches and proves, by test, that
  every other module's menu entry is byte-for-byte unchanged.
- **Every phase**: its own branch off `develop`, its own
  `tests/test_*_cli.php` (transaction-rolled-back, reconciled to direct SQL),
  its own PR into `develop`, its own `changelog.md` entry, and a full re-run
  of the entire existing `tests/test_pos_*_cli.php` suite (20+ files) before
  merge — proving a plain retail warehouse is completely unaffected. No phase
  starts without an explicit go-ahead on that phase specifically.

**Numbering note:** Phase 27 (Repair) and Phase 28 (the original generic
Booking engine) are kept as short historical stubs at their original slots
rather than reused for new content — same convention this document already
uses for Phase 24 (§8, deferred but not renumbered-over). The active,
buildable phases in this tranche are 25, 26, 29, 30, 31.

**Recommended build order** (lowest blast-radius first — data-only changes,
then a page-local dashboard, then the highest-touch phase that reorganizes
shared navigation and the POS terminal, then the most invasive schema change
last):

1. Phase 25 — Product professional fields
2. Phase 26 — Serial/IMEI-level stock
3. Phase 29 — POS Dashboard Intelligence (Sales Targets, Top Cashiers,
   Damage/Shrinkage)
4. Phase 30 — Restaurant Module + POS Navigation Reorganization (tables,
   kitchen, modifiers, recipes, table reservations, and the one-time
   regrouping of every POS menu entry into a single coherent, gated menu)
5. Phase 31 — Product Variants

---

### Phase 25 — Product professional fields (Warranty/Guarantee, barcode
symbology, promotional pricing)

**Status:** APPROVED, not yet built. **Closes:** the pharmacy/stationery/
supermarket/vehicle-spares-counter gaps that don't need a new module, just
richer product data — the same conclusion SalePro's own architecture reaches
(one generic Retail engine, richer product records, no vertical-specific
code).

**What exists today (confirmed by direct schema read):** `products` already
has `warranty_period` (int, **no unit** — days vs. months is ambiguous),
`serial_number` (a single free-text value on the product row, not a per-unit
table — Phase 26 below is what actually fixes this), `manufacturer`, `model`,
`barcode` (a single varchar, no symbology/format column). No `guarantee_*`
columns exist. No promotional/scheduled-price concept exists — Phase 14's
price groups are customer-tier pricing, not time-bound promotions.

**Build:**
- `products.warranty_unit ENUM('days','months','years')` (new, resolves the
  existing column's ambiguity) + `products.guarantee_period INT` +
  `products.guarantee_unit ENUM('days','months','years')` (new — a distinct
  concept from warranty, as seen in the SalePro product form).
- `products.barcode_symbology ENUM('CODE128','CODE39','UPC_A','UPC_E',
  'EAN_8','EAN_13') DEFAULT 'CODE128'` (new) — pure additive column read by
  the existing barcode-print page (`api/pos/... print barcode`); zero
  behaviour change until a product explicitly picks a different symbology.
- New table `product_promotions` (`promo_id, product_id, price, starts_at,
  ends_at, status ENUM('active','inactive'), created_by, created_at,
  updated_at`) — **extends the exact function Phase 14 already extracted**,
  `core/pos_price_groups.php::resolveGroupPrices()`, with one additional
  resolution layer checked *before* the price-group lookup: an active,
  in-window promo price wins, else fall through to the price-group price,
  else `products.selling_price` — same function, same call sites
  (`api/pos/simple_products.php`, `api/pos/process_sale.php`), not a new
  pricing engine.
- Receipt/invoice line rendering shows a "was / now" strike-through when a
  promo price applied (cosmetic, `print_receipt.php` only).
- **Files:** `migrations/tenant/2026_09_10_pos_product_professional_fields.php`
  (+ matching change in `schema/tenant_schema_template.sql`),
  `app/bms/product/product_edit.php` (new fields), `core/pos_price_groups.php`
  (`resolveGroupPrices()` promo layer), `api/pos/print_receipt.php`
  (was/now line).
- **Tests:** extend `tests/test_pos_price_groups_cli.php` with a promo-price
  section (active promo wins; expired/future promo falls through; reconciles
  to direct SQL) rather than a new file, since it's the same resolver.
- **Gate:** base `pos` (data quality, not an upsell — every tenant should be
  able to set a warranty/promo, same boundary logic as Phase 22's receipt
  templates).

---

### Phase 26 — Serial/IMEI-level stock tracking

**Status:** APPROVED, not yet built. **Depends on:** nothing (independent of
Phase 25, can build in parallel if ever desired — sequenced after 25 only for
"quick win first" pacing). **Closes:** selling a specific traceable unit (a
phone, a vehicle part, an appliance) instead of just a quantity — a real gap
for the vehicle-spares and electronics verticals.

**What exists today:** `products.track_inventory` (boolean, quantity-based
only). No per-unit identity table anywhere. Phase 17's `product_batches` is
the closest existing pattern (a decrementing quantity pool per batch) but a
serial is qty-always-1, not a pool.

**Build:**
- New table `product_serials` (`serial_id, product_id, warehouse_id,
  serial_number, status ENUM('in_stock','sold','returned','damaged'),
  sale_item_id, receipt_id, created_at, updated_at`) — **modeled directly on
  Phase 17's `product_batches`/`pos_sale_item_batches` shape**, generalized
  to single units instead of a decrementing pool. `UNIQUE(product_id,
  serial_number)`.
- `products.track_serials TINYINT(1) DEFAULT 0` (new) — a product with the
  flag off (the default, every existing product) behaves exactly as today,
  same backward-compatibility rule Phase 17 used for non-batch-tracked
  products.
- **Injection point, verified exact against a full read of
  `process_sale.php`**: immediately alongside the existing
  `consumeFefoBatches()` call in the per-line loop — a serial-tracked line
  carries the specific `serial_number` the cashier picked (POS UI: a small
  serial picker appears only when `track_serials=1`, mirroring Phase 17's
  batch-picker pattern), resolved and locked in the same transaction,
  written to a new `pos_sale_item_serials` child table
  (`id, sale_item_id, serial_id, created_at`) — **the identical child-table
  pattern** as `pos_sale_item_batches`, inserted right after the
  `pos_sale_items` row for that line is captured (the exact spot the code
  audit identified).
- Void/return flips the serial back to `in_stock`, mirroring Phase 17's
  batch-reversal logic exactly (`reverseFefoBatchConsumption()`'s sibling
  function for serials).
- **Bundled fix, relocated from the removed Repair phase (see Phase 27
  above):** the same migration file also `ALTER ... MODIFY`s
  `stock_movements.reference_type` to add the two values Phase 23's own
  audit found already missing and being silently coerced to `''` under this
  server's non-strict `sql_mode`: `'pos_void'` and `'pos_return'`. This is a
  fix to already-broken behaviour (confirmed present on unmodified
  `develop`), touched here only because this phase already needs to modify
  that same column's related consume/reverse code path — not a new risk
  introduced by this phase, and not a reason to touch the enum a second time
  later.
- GRN receiving: `api/create_grn.php`/`api/approve_grn.php` gain an optional
  per-line serial-entry grid (only shown for `track_serials=1` products),
  writing `product_serials` rows on approval — same "stock arrives on
  approval, not on creation" rule Phase 17 already established for batches.
- **Files:** `migrations/tenant/2026_09_10_pos_product_serials.php` (+
  schema template), `core/pos_serial_tracking.php` (new —
  `consumeSerial()`/`reverseSerial()`, independently testable like
  `pos_batch_consumption.php`), `api/pos/process_sale.php`,
  `api/pos/void_sale.php`/`create_return.php`, `api/create_grn.php`/
  `approve_grn.php`, `app/bms/product/product_edit.php` (`track_serials`
  toggle), `app/bms/pos/pos.php` + `pos_scripts_new.php` (serial picker).
- **Tests:** `tests/test_pos_serial_tracking_cli.php` — GRN writes serial
  rows; a sale locks the chosen serial to `sold`; void/return restores it;
  a non-serial-tracked product is completely unaffected (regression guard);
  a synthetic `pos_void`/`pos_return` stock-movement round-trip no longer
  silently coerces to `''` (the relocated enum fix); reconciles to direct
  SQL.
- **Gate:** `pos_advanced` (a genuinely upsell-shaped capacity feature, same
  boundary reasoning as Phase 18/21/23).

---

### Phase 27 — REMOVED, 2026-09-11: Repair / Service-Job module

This phase (job tickets for a vehicle garage/electronics-repair counter,
billed through the shared `invoices` table) was drafted 2026-09-10 and cut
the next day on explicit instruction to keep this plan inside the POS
module's own boundary. Reasoning kept here, not deleted, matching this
document's existing convention for deferred/removed items (see §8 Phase 24):
a job-ticket workflow is a distinct business module, not a point-of-sale
concern, and its billing step reached into Accounting's `invoices` table —
exactly the cross-module surface the re-scoping instruction ruled out. **One
piece of it is small and genuinely still worth doing on its own merits,
independent of Repair**, and has been relocated rather than lost: the
`stock_movements.reference_type` ENUM fix (adding the missing `'pos_void'`/
`'pos_return'` values Phase 23's own audit found being silently coerced to
`''`) is now bundled into Phase 26 below, since Phase 26 already touches the
same consume/reverse stock-movement code path. If a Repair/Service-Job
module is wanted later, it should be scouted and planned as its own
document — not re-inserted here.

---

### Phase 28 — MERGED, 2026-09-11: Booking / Appointment engine

The original generic, reusable-anywhere calendar (`booking_type: general/
service_appointment/table_reservation`, `resource_type: table/employee/
none`) was cut down on explicit re-scoping instruction — a platform-wide
scheduler is a bigger, more cross-cutting surface than the one feature POS
actually needs. Its one real use case, table reservations, is now built as
`restaurant_reservations` directly inside Phase 30 below, owned entirely by
the Restaurant sub-module. Kept here as a historical stub per this
document's numbering convention (see the note above §9 Phase 25).

---

### Phase 29 — POS Dashboard Intelligence (Sales Targets, Top Cashiers,
Damage/Shrinkage)

**Status:** APPROVED, not yet built. **Depends on:** nothing (independent of
every other phase in this tier — pure additions to the already-shipped,
already-tested `app/bms/pos/pos_dashboard.php` from §3 Phase 4). **Closes:**
three dashboard ideas found live on the fasteeypos.com benchmark that are
genuinely POS-scoped (unlike its Bank Accounts/Suppliers/Petty Cash tiles,
which belong to a cross-module "Home" dashboard and are deliberately
excluded from this plan) and cost close to nothing to add because BMS
already captures the underlying data.

**What exists today:** `app/bms/pos/pos_dashboard.php` (§3 Phase 4) already
has a "Best Selling Products" tile built the same way these three would be
— a scoped SQL aggregate rendered as a card, reconciled to direct SQL in
`tests/test_pos_dashboard_cli.php`. `stock_movements.movement_type` already
has `'damaged'`, `'expired'`, and `'theft'` values (confirmed in the live
ENUM) that are written today by the adjustment/GRN flows but **never
surfaced on any dashboard anywhere in BMS** — this is a pure reporting gap,
not a data gap.

**Build:**
1. **Damage/Shrinkage tile** — a read-only aggregate: `SELECT movement_type,
   SUM(quantity) FROM stock_movements WHERE movement_type IN
   ('damaged','expired','theft') AND ...` scoped by
   `scopeFilterSqlNullable('warehouse', alias)` and the dashboard's existing
   date-range filter. **Zero schema change** — this is a new query against
   data that already exists.
2. **Top Performing Cashiers** — `SELECT user_id, cashier_name,
   SUM(grand_total), COUNT(*) FROM pos_sales WHERE sale_status='completed'
   ...` grouped and ranked, same `sale_status` filter and warehouse/date
   scoping the existing "Best Selling Products" tile already applies. **Zero
   schema change.**
3. **Sales Targets vs. Actual**, with the same four achievement bands seen
   live on fasteeypos (Achieved ≥100%, On Track ≥75%, Needs Improvement
   ≥50%, Action Required <50%, computed at render time from
   `actual/target`) — the one genuinely new piece of data in this phase.
   New table `pos_sales_targets` (`target_id, warehouse_id INT NOT NULL
   DEFAULT 0, user_id INT NOT NULL DEFAULT 0, period_month DATE, target_amount
   DECIMAL(14,2), created_by, created_at, updated_at`,
   `UNIQUE(warehouse_id, user_id, period_month)`). **`0` is used instead of
   NULL for "all warehouses"/"all cashiers" deliberately** — MySQL treats
   NULL as distinct in a UNIQUE key, which would silently allow duplicate
   "company-wide" target rows for the same month; `0` as a sentinel (no real
   `warehouse_id`/`user_id` is ever `0`) avoids that pitfall cleanly, no
   application-level dedupe logic needed.

**Files:** `migrations/tenant/2026_09_11_pos_sales_targets.php` (+ schema
template — this phase's only schema change), `core/pos_dashboard_metrics.php`
(new — `damageShrinkageSummary()`, `topCashiers()`,
`salesTargetAchievement()`, independently testable, matching the existing
`core/pos_shift_reporting.php` extraction pattern), `app/bms/pos/
pos_dashboard.php` (three new tiles), `api/pos/get_dashboard.php` (extends
its existing response with the three new blocks, reusing the same scope/
date-range parameters it already accepts — no new endpoint), new
`api/pos/save_sales_target.php` (CSRF, `canEdit('pos_advanced')`).

**Tests:** extended into the existing `tests/test_pos_dashboard_cli.php`
(not a new file, since this is additive to an already-tested page, same
principle Phase 25 used for `resolveGroupPrices()`) — each new tile
reconciles to direct SQL; the achievement-band boundaries (exactly 100%,
75%, 50%) are asserted precisely at the edges, not just "somewhere in the
middle"; a fresh tenant with zero data renders every new tile's proper
empty-state (icon + message, matching the existing tiles' pattern) rather
than a blank div or a JS error; **a `pos`-only tenant (no `pos_advanced`)
never sees the Sales Targets tile or its "Set Targets" entry point anywhere
in the rendered HTML** — not hidden by CSS, genuinely absent — verified by
asserting the string is missing from the response, the same rule applied to
Phase 30's navigation work below.

**Gate:** Damage/Shrinkage and Top Cashiers are base `pos` (loss-control and
till-hygiene visibility every tenant needs, same boundary logic as Phase
16/19/20 — not an upsell). Sales Targets is `pos_advanced` (a
management-set goal with its own settings UI, matching the Phase 14/18
boundary for a genuine analytics capability).

---

### Phase 30 — Restaurant Module + POS Navigation Reorganization (Floors,
Tables, Kitchen, Modifiers, Recipes, Reservations)

**Status:** APPROVED, not yet built. **Depends on:** nothing structurally
(its reservation feature is now self-contained, not borrowed from a
separate Booking phase — see Phase 28's merge note above); sequenced after
25/26/29 for pacing only, so the lower-risk groundwork (feature-registry
gating, tenant-migration discipline, the `assigned_to`/warehouse-ACL
patterns) is already proven three times over before this phase touches the
shared POS terminal and `header.php`. **Closes:** the actual "different shop
type" mechanism for restaurant/pub, plus the POS-wide navigation cleanup
identified from the fasteeypos.com benchmark's two-tier icon-rail +
contextual-submenu pattern.

**Schema:**
- `warehouses.pos_mode ENUM('retail','restaurant','hybrid') DEFAULT
  'retail'` (new) — the one warehouse-level switch this whole tier is
  organized around. Default preserves current behaviour for every warehouse,
  everywhere, permanently, unless an admin explicitly changes it.
- `restaurant_floors` (`floor_id, warehouse_id, name, sort_order`),
  `restaurant_tables` (`table_id, warehouse_id, floor_id, table_number,
  seats, status ENUM('available','occupied','reserved','cleaning') DEFAULT
  'available'`).
- `kitchen_stations` (`station_id, warehouse_id, name`);
  `products.kitchen_station_id` (new, nullable FK) — routes a product to a
  station, matching the exact per-product "Kitchen" field confirmed live in
  the SalePro product form.
- `kitchen_tickets` (`ticket_id, hold_id, warehouse_id, station_id, status
  ENUM('queued','preparing','ready','served') DEFAULT 'queued', created_at,
  updated_at`), `kitchen_ticket_items` (`id, ticket_id, product_id,
  quantity, modifiers_summary, status`).
- `modifier_groups` (`group_id, name, selection_type ENUM('single',
  'multiple'), min_select, max_select, is_required TINYINT(1), status
  ENUM('active','inactive')`), `modifier_options` (`option_id, group_id,
  name, price_adjustment, status`), `product_modifier_groups` (link table,
  `product_id, group_id`) — modeled on Phase 14's "named group + linked
  options + linked products" shape, the same reuse-by-analogy Phase 14 itself
  used for price groups.
- Sale-time modifier choices land in `pos_sale_item_modifiers`
  (`id, sale_item_id, option_id, option_name, price_adjustment,
  created_at`) — **the identical child-table pattern** as
  `pos_sale_item_batches`/Phase 26's `pos_sale_item_serials`, inserted at the
  identical point in `process_sale.php` (right after the `pos_sale_items`
  insert captures `sale_item_id`).
- **Recipes — reuses Phase 23's combo mechanism outright, not a new
  concept.** The fasteeypos.com benchmark lists "Ingredients" and "Recipes"
  as distinct from menu items — i.e. a dish's stock should come from its
  ingredients, not a phantom "dish stock." BMS already has exactly this
  shape: `product_assembly_components` (`parent_product_id,
  component_product_id, qty_per_unit`), already reused once for Phase 23's
  combo products, and `is_combo`/`checkComboAvailability()`/
  `consumeComboComponents()`/`reverseComboComponents()` already implement
  "selling this product consumes its linked components' stock" exactly.
  **A recipe-based menu item is simply marked `is_combo=1` with its
  ingredients linked via `product_assembly_components`, identically to a
  retail combo** — zero new table, zero new code path in
  `process_sale.php`; only the product-edit UI's label changes to "Recipe
  (Ingredients)" when the product also has `kitchen_station_id` set, purely
  cosmetic.
- Service type / waiter / table — **reuses existing dormant columns instead
  of inventing new ones**: `pos_sales.sale_type` ENUM already includes
  `'delivery'` (confirmed unused by anything today) — add `'dine_in'`/
  `'take_away'` to that same ENUM rather than a new column.
  `pos_sales.delivery_address`/`delivery_fee`/`delivery_time` (confirmed
  existing, unused) are reused as-is for the Delivery service type. New:
  `pos_sales.table_id` (nullable FK) and `pos_sales.assigned_to` (the
  existing convention, reused for "waiter" — no new `waiter_id` column).
- `pos_held_sales` gains `warehouse_id` and `table_id` (both new, nullable —
  confirmed by direct read that this table currently has neither) — this is
  what turns the *existing* hold/park-sale mechanism into an addressable
  "open table order," not a new order-state system.
- **Reservations — self-contained, not borrowed from a generic engine (see
  Phase 28's merge note).** New `restaurant_reservations` (`id,
  table_id, warehouse_id, customer_id, reservation_time, party_size, status
  ENUM('booked','seated','completed','cancelled','no_show') DEFAULT
  'booked', notes, created_by, created_at, updated_at`) and
  `restaurant_reservation_reminders` (`id, reservation_id, milestone,
  sent_at`, `UNIQUE(reservation_id, milestone)`, `INSERT IGNORE` dedupe) —
  the same reminder-dedupe *pattern* Phase 17 already established
  (`document_expiry_reminders`), reused because `dispatchEvent()` is a
  shared **function call**, not a shared schema/UI surface, so reusing it
  does not reintroduce the cross-module surface the generic Booking engine
  was cut for.

**The mechanism, end to end — every state transition named, nothing left
implicit:**
1. Cashier opens POS on a warehouse with `pos_mode IN ('restaurant',
   'hybrid')` → a "Choose POS Mode" step appears (hybrid only — a pure
   `'restaurant'` warehouse goes straight to restaurant mode; a pure
   `'retail'` warehouse never shows this at all, zero UI change).
2. Selecting a table loads or creates that table's open order — this **is**
   `pos_held_sales`, now addressable by `table_id` instead of only by the
   holding user; `restaurant_tables.status` flips to `occupied` the moment
   an order is opened against it.
3. Adding a product with linked modifier groups opens the selection modal,
   enforcing each group's `min/max/required`; the chosen options are priced
   into the cart line client-side and persisted to `pos_sale_item_modifiers`
   only once the line is actually sold (step 6) — until then they live in
   the held sale's JSON blob, same as every other in-progress cart field.
4. **"Send to Kitchen"** writes/updates `kitchen_tickets`/
   `kitchen_ticket_items` from the held sale's current contents, grouped by
   each item's `kitchen_station_id`. This does **not** touch `pos_sales` —
   the bill isn't finalized, only the kitchen queue and the table's
   `occupied` status change.
5. Kitchen Dashboard (`app/bms/restaurant/kitchen_dashboard.php`) polls
   `kitchen_tickets` scoped by station + `scopeFilterSqlNullable('warehouse',
   ...)`; staff advance status queued→preparing→ready→served.
6. **"Close Bill / Pay" is the only point that calls the existing,
   unmodified `api/pos/process_sale.php`** — passing `table_id`/
   `sale_type`/`assigned_to` alongside the normal payload, so the sale
   inherits GL posting, receipt printing, loyalty, everything Phases 1-24
   already built, with zero changes to that endpoint's own logic beyond
   accepting the three new optional fields. On success: the held-sale row
   clears (existing behaviour, unchanged) and `restaurant_tables.status`
   flips back to `available`.

This keeps the entire Restaurant feature as additive branches around the
existing terminal and the existing hold-sale/finalize-sale pipeline — never
a second parallel sale-processing path. Recipes ride the same pipeline too:
step 6's `process_sale.php` call needs **zero new logic** for a recipe-based
dish, because Phase 23's existing `is_combo` branch already fires.

**New nav module** `app/bms/restaurant/` — `floors.php`, `tables.php`,
`reservations.php` (its own page over the new `restaurant_tables_
reservations` table — not a filtered view over a separate engine, see the
schema note above), `menu_type.php`, `modifier_group.php` (list + per-group
option manager + "Manage"/"Products" linking, matching the exact SalePro
screen shape: Name/Type/Min-Max/Required/Options/Linked Products/Status
columns), `kitchen.php` (station admin), `kitchen_dashboard.php` (the live
KDS view, auto-refresh poll same UX as SalePro's 5/10/15/30/60-second
selector). Standard page skeleton throughout.

**POS Navigation Reorganization — the second half of this phase, grounded in
a direct read of the real, current wiring, not an assumed one:**

*Current state, confirmed by reading `header.php` and `roots.php` directly:*
POS's existing pages are scattered across **two unrelated dropdowns** and
one **complete gap**. `header.php`'s "Sales" dropdown (lines ~902-908)
carries `POS` (`pos`), `POS Dashboard & Sales` (`pos/dashboard`), and, only
if `canView('pos_advanced')`, `Price Groups` (`pos/price-groups`).
Separately, `header.php`'s "System/Settings" dropdown (line ~1242) carries
`POS Settings` (`pos_config_settings`) — nowhere near the other POS links.
And `roots.php` already defines two real, working routes —
`pos/shifts` → `shift_history.php` and `pos/zreport` → `zreport.php` (§3
Phase 9) — that have **no link anywhere in `header.php` at all**; they are
reachable today only via in-page buttons inside `pos_dashboard.php`. This
is exactly the "not well linked" problem flagged from the fasteeypos.com
walkthrough, just discovered inside BMS's own POS instead of a competitor's.

*The fix, scoped to touch only POS's own lines:* a new `core/pos_nav.php`
(`posNavGroups(): array`) becomes the single source of truth for POS's menu
tree — an array of named groups, each entry carrying its route, its label,
its icon, and the `canView()` key that gates it:
- **Sell** — POS Terminal (`pos`).
- **Session & Tills** — Shift History (`pos/shifts`, *newly linked — closes
  the gap above*), Z-Report (`pos/zreport`, *newly linked*).
- **Dashboard & Reports** — POS Dashboard & Sales (`pos/dashboard`).
- **Catalog Setup** (group itself gated `canView('pos_advanced')`) — Price
  Groups (`pos/price-groups`), Product Variants (once Phase 31 ships).
- **Restaurant** (group itself gated `canView('restaurant_pos')`) — Floors,
  Tables, Reservations, Menu Type, Modifier Group, Kitchen, Kitchen
  Dashboard.

`header.php`'s existing `<?php if(canView('pos')): ?>` block (the one
already there today) is changed to loop over `posNavGroups()` and render
each group as a small Bootstrap `collapse` accordion **inside that same
`<li>`** — using element IDs uniquely prefixed `posNavGroup-*` so they
cannot collide with any other module's collapse/accordion IDs anywhere else
in the app. **No new global JS/CSS is added; the shared dropdown wrapper
`<li class="nav-item dropdown">` and Bootstrap's own dropdown behaviour,
used by every other module's menu, are untouched.** `POS Settings`
deliberately **stays** in the System/Settings dropdown — every module's own
settings page lives there by system-wide convention, and moving only POS's
would be an inconsistent one-off exception rather than a fix; a single
cross-link line is added to the top of `pos_config_settings.php` pointing
back to the POS menu instead, so nothing feels orphaned without breaking
the convention.

**Files:** `migrations/tenant/2026_09_10_pos_restaurant_module.php` (+ schema
template + seed defaults for a default floor/station on existing
warehouses — inert until `pos_mode` is changed), `app/bms/restaurant/*.php`
(new), `api/restaurant/*.php` (new — tables, reservations, kitchen tickets,
modifier groups), `app/bms/pos/pos.php` + `pos_modals_new.php` +
`pos_scripts_new.php` (mode picker, table picker, modifier modal,
Send-to-Kitchen action — all inside `if ($warehouse['pos_mode'] !==
'retail')` branches), `api/pos/process_sale.php` (accepts the three new
optional fields; no change to its existing logic), `api/pos/hold_sale.php`/
`get_held_sales.php` (table/warehouse addressing), `app/bms/product/
product_edit.php` (Kitchen station + Modifier Groups picker + Recipe
ingredients picker relabeling the existing combo-component UI, "Modifier
groups are managed from Restaurant > Modifier Group" cross-link matching the
SalePro UX note), new `core/pos_nav.php` (the menu-tree source of truth),
`header.php` (only the existing `canView('pos')` "Sales"-dropdown block is
restructured to loop over it; two new links added for Shift History/
Z-Report), `app/bms/pos/pos_config_settings.php` (the cross-link line back
to the POS menu).

**Tests:** `tests/test_restaurant_pos_cli.php` — table lifecycle
(available→occupied→available), kitchen ticket routing by station, modifier
min/max/required enforcement and pricing math, held-sale→finalized-sale
table linkage, a recipe-based dish decrements every linked ingredient's
stock via the unmodified Phase 23 combo path, reservation CRUD and reminder
milestone dedup, and a full regression re-run of every existing
`tests/test_pos_*_cli.php` suite proving a plain retail warehouse's POS
output is byte-for-byte unchanged. **New `tests/test_pos_nav_wiring_cli.php`**
for the navigation half specifically: a `pos`-only user sees Shift
History/Z-Report now present and correctly routed, while Catalog Setup and
Restaurant groups are **completely absent from the rendered HTML** (not
merely hidden) — and every dropdown belonging to every *other* module
renders byte-for-byte identical to a snapshot taken before this phase; a
`pos_advanced` user additionally sees Catalog Setup; a `restaurant_pos` user
additionally sees all seven Restaurant links; an admin sees everything.

**Gate:** new `restaurant_pos` feature-registry entry, `default: false`,
`depends_on: ['pos']`. The navigation reorganization itself is not
separately gated — it always renders — but each group inside it respects
the exact same per-feature gate it already used before this phase, per
`posNavGroups()` above.

---

### Phase 31 — Product Variants (size/color matrix)

**Status:** APPROVED, not yet built. **Deliberately last** — the one change
in this tier that touches the most existing surfaces, so it ships once every
other active phase's discipline (tenant-migration hygiene, feature-gating,
warehouse ACL reuse) has been proven three times over (Phases 25, 26, 29)
plus once more against the highest-touch phase (30).

**The reuse decision that makes this low-risk despite touching everything:**
a variant is **a normal row in `products`** with two new columns:
`parent_product_id` (nullable FK to `products.product_id`) and
`variant_attributes` (JSON, e.g. `{"size":"L","color":"Red"}`) — **not a
parallel variants table**. Because every downstream system already keys off
`product_id` (Phase 17 batches, Phase 23 combos, Phase 14 price groups,
per-warehouse stock, Phase 26 serials, GL posting), a variant automatically
works with all of them with **zero changes** to those systems — the child
row *is* a product as far as everything else in BMS is concerned.

**Build:**
- `products.parent_product_id` (new, nullable, indexed) +
  `products.variant_attributes` (new, JSON, nullable).
- `product_edit.php` gains an attribute-builder UI (define attribute types
  e.g. Size/Color, define values, generate the cartesian-product child rows
  in bulk with a shared base price/cost the admin can then adjust per
  variant).
- POS product grid: a parent with children renders as one tile
  ("2 Variants" badge, matching the SalePro UX seen live); clicking opens a
  small variant picker, then adds the chosen child's `product_id` to the
  cart exactly like any other product — no change to `process_sale.php` at
  all, since a variant *is* a normal `product_id` by the time it reaches
  that endpoint.
- **Files:** `migrations/tenant/2026_09_10_pos_product_variants.php` (+
  schema template), `app/bms/product/product_edit.php` (attribute builder),
  `app/bms/pos/pos_scripts_new.php` (variant tile grouping + picker modal),
  `api/pos/simple_products.php` (groups children under their parent for the
  grid response).
- **Tests:** `tests/test_product_variants_cli.php` — variant generation from
  an attribute matrix, POS line resolves to the correct child `product_id`,
  stock/batch/serial/combo/price-group all correctly scope to the child
  (not the parent), a non-variant product is completely unaffected.
- **Gate:** `pos_advanced`.
