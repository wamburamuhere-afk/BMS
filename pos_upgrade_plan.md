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
