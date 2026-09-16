# POS Credit Sales & Receivables — Implementation Plan (Simple POS only)

**Status:** APPROVED — implementing phase by phase.

**Decisions confirmed by user:**
1. Aging view = a **dedicated page** (not a pane inside `pos_dashboard.php`),
   reachable via a new hub card.
2. "Delete" on a credit sale row = **void** (reverses properly, keeps audit
   trail) — never a hard delete.
3. Reminder timing = **3 days before due date + on the due date + weekly
   while still overdue** (deduped so it never fires twice for the same
   threshold).
4. Credit customers are **not** a separate concept — integrate with the
   existing Customers module (list + detail page), not a parallel structure.

**Scope trigger:** every new piece of UI/logic in this plan is gated behind
`posSimpleModeEnabled()`. Advanced/full-ERP POS keeps its current credit-sale
behavior completely unchanged.

## What already exists (confirmed by code research — not being rebuilt)

- Credit as a payment method: `app/bms/pos/pos.php` payment-method radio group,
  `pos_scripts_new.php:1443` (blocks submit if credit + no customer selected).
- `api/pos/process_sale.php`: enforces customer required for credit (`:106`),
  enforces the customer's `credit_limit` via `core/pos_credit_limit.php`
  (`assertPosCreditLimitPermitted()`, with a manager override), computes
  `payment_status` (pending/partial/paid) and outstanding balance.
- `pos_sales` table: `customer_id`, `customer_name`, `customer_phone`,
  `payment_method` enum (includes `'credit'`), `payment_status` enum,
  `grand_total`. **No `due_date` column today — confirmed absent.**
- `pos_sale_payments` table: `payment_id, sale_id, amount, payment_method,
  reference, notes, received_by, created_at` — already exactly what a
  repayment-history "View Details" screen needs. No schema change needed here.
- `core/pos_credit_limit.php`: `customerOutstandingBalance()` already sums
  `grand_total − Σpayments` per customer across open credit sales — this is
  the core of the "who owes me" query.
- `api/pos/receive_payment.php`: already records a follow-up payment against
  a sale and updates its `payment_status`. The "Repay" action reuses this.
- **Credit customers are already real `customers` rows, end-to-end — no
  separate POS-customer concept exists:**
  - `process_sale.php:643-646` looks the credit customer up with
    `SELECT ... FROM customers WHERE customer_id = ?` — a credit sale cannot
    exist without a real `customers.customer_id`.
  - The POS customer picker (`pos_scripts_new.php:237`) is a Select2 against
    `api/pos/search_customers.php`, which queries the same `customers` table.
  - POS's inline "+ New Customer" quick-add (`pos_scripts_new.php:359-374` →
    `api/quick_add_customer.php:36`) does a plain `INSERT INTO customers`.
  - `app/bms/customer/customers.php:39-58` lists every `customers` row with
    no source/origin filter — a customer created during a POS sale shows up
    there automatically, today, with zero new work.
- `app/bms/customer/customer_details.php` (the customer view page) already
  has a working tab system to extend, not a new one to build:
  `<ul class="nav nav-pills ..." id="customerDetailTabs">` (`:930-957`),
  panes in `<div class="tab-content" id="customerDetailTabContent">`
  (`:958+`), each `<div class="tab-pane fade" id="pane-XXX">`. Existing tabs:
  Sales Orders, Quotations, Invoices, Payments, Deliveries, LPOs, **Credit
  Notes & Advances** (`#pane-creditnotes` — an accounting concept, refunds/
  adjustments; unrelated to POS shop-credit, but the new tab's label needs to
  read unambiguously so nobody confuses the two), System Info.
- Notification engine (`core/notify.php`, `dispatchEvent()`): recipient
  targeting (role/user/permission, email/in-app channel) is fully built and
  has its own admin settings UI (`app/constant/settings/notification_rules.php`).
  A new event just needs to be **registered** (migration, same pattern as
  `2026_07_23_hr_contract_autoclose_event.php`) and **triggered** from
  somewhere — nobody needs to build a new "who gets emailed" UI.

## Confirmed gaps (the actual new work)

1. No `due_date` on a credit sale at all.
2. No popup/requirement to capture a due date when credit is chosen.
3. No AR-aging view anywhere in the app (`grep -i aging` → zero matches).
4. No dashboard "Credit/Madeni" card (the one existing credit-limit widget on
   `dashboard.php` is invoice-based, not POS-based).
5. No dedicated "who owes me" page.
6. No "Madeni" tab on the customer detail page (per-customer credit history).
7. No scheduled due-date-approaching / overdue notification event.
8. No clear "paid" confirmation moment in the Repay flow (see Phase 2).

---

## Phase 0 — Schema foundation

- New tenant migration: `pos_sales.due_date DATE NULL` + index
  `idx_pos_sales_due_date`. Nullable, non-breaking — every existing row and
  every non-credit/Advanced-mode sale is completely unaffected.
- No other schema changes required (`pos_sale_payments` already sufficient
  for full repayment history and on-time/late computation — see Phase 2b).

## Phase 1 — POS.php: due-date capture (Simple POS only)

- When Simple POS is active **and** payment method = Credit **and** a
  customer is selected: show a small popup/step requiring **Due Date**
  (required, defaults to e.g. +30 days, editable) before the sale can be
  finalized.
- `process_sale.php`: accept and store `due_date` on the `pos_sales` row when
  provided; no behavior change when it's absent (Advanced mode, or any
  pre-existing caller).
- Advanced/full POS: **zero change** — no popup, `due_date` stays NULL
  exactly as it does today for every existing row.

## Phase 2 — Shared credit-aggregation helper (used by everything below)

One PHP helper (e.g. `core/pos_credit_aging.php`), so the dedicated page, the
customer-detail tab, and the dashboard card never compute this three
different ways:

- Per customer: total currently owed, oldest open due date, days
  elapsed/remaining/overdue.
- Per customer, lifetime counters (drives the customer-tab requirement
  below): **times borrowed** (count of credit sales), **times repaid on
  time** (sale fully settled — `payment_status = 'paid'` — and its last
  `pos_sale_payments.created_at` ≤ `pos_sales.due_date`), **times repaid
  late** (fully settled, last payment > due_date), **currently owed** (live
  outstanding balance across still-open sales).

### Phase 2a — Dedicated "Who Owes Me" page (Simple POS only)

- New page, reachable via a new hub card on `app/bms/pos/pos_dashboard.php`.
- List columns: Customer Name, Phone, Amount Owed, Sale Date, Due Date, Days
  Elapsed, Days Remaining / *Overdue by N days* (red), quick link to that
  customer's full profile (`customer_details.php`).
- Row actions (matching the app's standard gear-dropdown convention):
  - **View** — modal: full payment history for that customer's credit
    sale(s) (date + amount per `pos_sale_payments` row) and running balance.
  - **Repay** — modal to record a new partial/full payment (date, amount,
    method, reference) via the same path `receive_payment.php` already uses.
    On full settlement, shows a clear **"Paid in full"** confirmation
    (closing gap #8) and the row status flips visibly (e.g. green badge)
    instead of just silently updating a number.
  - **Edit** — safe fields only (due date, notes) — **not** amounts, to
    protect inventory/GL integrity already enforced at sale time.
  - **Delete** — implemented as a **void**, not a hard delete, reversing
    stock/ledger the same way other void flows in this codebase already do.

### Phase 2b — "Madeni" tab on `customer_details.php` (Simple POS only)

- New pill tab (`#pane-madeni`), following the exact existing pattern,
  clearly labelled to avoid any confusion with the existing "Credit Notes &
  Advances" tab (different concept — accounting adjustments, not shop debt).
- Shows, for **this one customer**: current amount owed; a small stat row —
  times borrowed / times repaid on time / times repaid late; and a table of
  their individual credit sales (date, amount, due date, status, days
  overdue if any) with the same View/Repay actions as Phase 2a.
- Since Phase 2a already links to `customer_details.php`, and this tab
  covers the reverse direction (customer → their credit history), the two
  together fully close the "should show up in customers.php, and the
  customer view should have a Madeni tab with borrow/repay-on-time/
  repay-late/currently-owed counts" requirement.

## Phase 3 — `dashboard.php`: "Credit" (Madeni) card

- New stat card, gated behind `posSimpleModeEnabled()`, showing total
  outstanding POS credit tenant-wide (same shared helper as Phase 2), linking
  through to the Phase 2a page.

## Phase 4 — Due-date reminder notifications

- Register `notification_events` row(s) (e.g. `pos_credit_due_soon`,
  `pos_credit_overdue`) via migration — same seeding pattern as existing
  scheduled-style events.
- A scheduled check (reusing this app's existing cron/background-check
  mechanism — same one the HR contract-autoclose reminder already uses)
  fires: **3 days before due date**, **on the due date**, and **weekly while
  still overdue** — each threshold deduped so it never re-fires for the same
  sale/threshold pair.
- Notification content: customer name, phone, amount owed, due date, days
  remaining/overdue.
- **Who receives it** is entirely governed by the existing
  `notification_rules` admin settings page — no new "grant" UI needed, just
  making sure the new event(s) show up there to be configured.
- Dispatch itself only fires for Simple-POS tenants.

## Phase 5 — Tests + live verification

- New CLI test(s) covering: due-date requirement/popup (source-level), the
  shared aggregation helper's math (borrowed/on-time/late/currently-owed
  counters) against manufactured data, Repay reduces balance correctly and
  flips to "Paid in full", the Madeni tab renders correctly on a real
  customer, notification events registered + dispatch correctly at each of
  the 3 thresholds, dashboard card gated correctly by mode.
- Live verification on the real (already Simple-POS-enabled) tenant, same
  rigor as every fix earlier this session — real HTTP, real data, cleaned up
  after.
- Regression re-run: `test_pos_quick_restock_cli.php`,
  `test_pos_simple_mode_cli.php`, and any existing POS credit/payment test
  suites, plus the customer_details.php page render (both with and without
  Simple POS) to confirm the new tab never appears otherwise.

## Phase 6 — Ship

- Branch-per-phase (or per logically-grouped pair of phases) off `develop`,
  PR each, following this session's established workflow.
  Suggested grouping: **Phase 0+1** (schema + POS capture), **Phase 2**
  (shared helper + both surfaces — the biggest single piece), **Phase 3+4**
  (dashboard card + notifications).
