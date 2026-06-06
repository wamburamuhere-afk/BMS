# Chart of Accounts — Professional Upgrade Plan (`account.md`)

> **Goal:** Make the Chart of Accounts page behave like a professional accounting
> chart (MYOB-style) — **type tabs at the top**, an **indented account-within-account
> tree** where parent accounts roll up the value of their children, a **redesigned
> Add/Edit form**, and a **click-row → slide-in View** panel.
>
> **Hard rule:** This is **ADDITIVE ONLY**. Nothing in the existing accounting
> chain (reports, journals, payments, payroll, petty cash, bank) may break. Every
> account dropdown across the Finance section must keep communicating with the
> single `accounts` master table.
>
> **Reference image:** `scratch/WhatsApp Image 2026-06-05 at 03.39.42.jpeg`
> **Created:** 2026-06-06
> **Branch:** create a fresh feature branch off `develop` before Phase 1.

---

## 0. The Picture (what we are building)

The reference image (MYOB) shows:

1. **Tab bar across the top** — `All Accounts | Asset | Liability | Equity | Income | Cost of Sales | Expense | Other Income | Other Expense`. Clicking a tab instantly filters the list to that type.
2. **Indented tree** — group headers in **bold** (e.g. `1-0000 Assets`), children indented beneath (`1-1000 Current Assets` → `1-1100 Cash On Hand` → `1-1110 Cheque Account`).
3. **Parent roll-up balance** — a group header's balance = sum of all its children.
4. **A "Linked / system" marker** (✓) — accounts wired to system functions can't be deleted.
5. **Click a row** (the `⇒` arrow) → see that account's full detail.

### Tab → BMS category mapping (NO database ENUM change)

| Image tab        | BMS `account_types.category` | Note                         |
|------------------|------------------------------|------------------------------|
| All Accounts     | *(no filter)*                |                              |
| Asset            | `asset`                      |                              |
| Liability        | `liability`                  |                              |
| Equity           | `equity`                     |                              |
| Income           | `revenue`                    | UI label only                |
| Cost of Sales    | `cogs`                       |                              |
| Expense          | `expense`                    |                              |
| Finance Cost     | `finance_cost`               | BMS equivalent of "Other Expense" |
| *(Other Income)* | —                            | no BMS equivalent → omit tab |

The category ENUM is `'asset','liability','equity','revenue','expense','cogs','finance_cost'`.
**Every financial report groups on this column. We must NEVER touch it.**

---

## 1. Impact Map (what is connected — verified)

### 1.1 Files we WILL modify or create (the whole job)

| # | File | Action |
|---|------|--------|
| 1 | `migrations/2026_06_06_accounts_tree_columns.php` | **NEW** — add 3 nullable columns + backfill |
| 2 | `api/account/get_chart_of_accounts.php` | add `category` filter + 4 new SELECT columns |
| 3 | `api/account/get_account.php` | add 4 new columns to response |
| 4 | `api/account/save_account.php` | `is_system` guard + save `level`/`normal_balance` |
| 5 | `api/account/delete_account.php` | `is_system` guard (block delete) |
| 6 | `api/account/get_account_detail.php` | **NEW** — detail panel feed (sub-accounts + txns + calc balance) |
| 7 | `app/constant/accounts/chart_of_accounts.php` | tabs + tree + form redesign + view offcanvas |
| 8 | `core/payment_source.php` | **ADD** `expenseAccounts()`, `incomeAccounts()`, `allActiveAccounts()` |
| 9 | `app/constant/accounts/revenue.php` | use `incomeAccounts()` |
| 10 | `app/constant/accounts/bank_transfers.php` | use `expenseAccounts()` |
| 11 | `app/constant/accounts/recurring.php` | use `expenseAccounts()` |
| 12 | `app/constant/accounts/expenses.php` | filter bar → use `expenseAccounts()` |

### 1.2 Files that READ accounts but we do NOT touch (must stay working — test only)

- **Reports (group on `account_types.category`, untouched):** `get_trial_balance.php`,
  `get_balance_sheet.php`, `get_income_statement.php`, `get_cash_flow.php`,
  `get_general_ledger.php`, `close_period.php`,
  `app/constant/reports/*` (trial_balance, balance_sheet, cash_flow, ledger_report).
- **Payment engine (only does `UPDATE accounts SET current_balance`):**
  `core/payment_source.php` functions `postOutflow / postInflow / applyAccountBalanceDelta /
  reverseOutflow / reverseInflow / postPayrollAccrual / postPayrollPayment / postSdlAccrual`.
- **Account dropdown consumers already correct (use `cashBankAccounts()`):**
  `receive_payment.php`, `bank_statement.php`, `expenses.php` (Paid-From), `recurring.php` (bank), `payment_vouchers.php` (uses categories, not accounts), `petty_cash.php` (uses categories).
- **Shared CRUD also used by `bank_accounts.php`:** `save_account.php`, `get_account.php`
  (so the `is_system` lock will also protect bank accounts — correct behaviour).
- **Existing full-page ledger view:** `account_details.php` (kept; still reachable from the
  "View Details" dropdown link). The new offcanvas is a *quick* view, not a replacement.

### 1.3 The Finance-wide account communication (the "no hanging" requirement)

There is **one master list**: the `accounts` table (shown in full by Chart of Accounts).
Every Finance dropdown pulls a filtered slice of it. Today there are **4 retrieval patterns**,
and 3 of them are inconsistent. We standardise to canonical helpers:

| Slice needed         | Canonical helper (target)      | Filter                                                     |
|----------------------|--------------------------------|------------------------------------------------------------|
| Cash / bank accounts | `cashBankAccounts()` *(exists)*| `status='active' AND account_type='asset' AND cash_flow_category='cash'` |
| Expense accounts     | `expenseAccounts()` **(new)**  | `status='active' AND at.category IN ('expense','finance_cost')` |
| Income accounts      | `incomeAccounts()` **(new)**   | `status='active' AND at.category='revenue'`                |
| All accounts (journal)| `allActiveAccounts()` **(new)**| `status='active'` (+ AJAX `search_accounts.php` stays)     |

**Why this matters:** `revenue.php`, `bank_transfers.php`, `recurring.php`, and the
`expenses.php` filter currently query the *denormalized* `accounts.account_type` column with
raw strings (`'income'`, `'expense'`) or `type_name LIKE '%expense%'`. That can drift from the
canonical `account_types.category`, so e.g. a **Finance Cost** account silently never appears
in an Expense dropdown. The new helpers JOIN `account_types` and filter on `category`, the same
source of truth the reports use → every page agrees, nothing hangs.

---

## 2. The Three New Columns (foundation)

Added to `accounts`, **all nullable / defaulted** → zero break on existing INSERTs:

| Column           | Type                          | Meaning                                   |
|------------------|-------------------------------|-------------------------------------------|
| `level`          | `INT NULL`                    | Tree depth: 1 = top, 2 = child, 3 = grandchild |
| `is_system`      | `TINYINT(1) NOT NULL DEFAULT 0` | 1 = wired to a system function; lock edit/delete |
| `normal_balance` | `ENUM('debit','credit') NULL` | Per-account natural side (override-capable) |

`is_system = 1` is auto-set for any account referenced by:
- a `system_settings` key ending `_account_id` (petty cash, AP, payroll, SDL, VAT, WHT, etc.)
- the `journal_mappings` table (auto-posting debit/credit accounts)

---

# IMPLEMENTATION PHASES

> Each sub-phase is **atomic**: one clear edit, then a one-line check. Do them in order.
> After every phase, run the listed check before moving on. Commit at the end of each phase.

---

## PHASE 1 — Migration (database foundation)

**File:** `migrations/2026_06_06_accounts_tree_columns.php` (new, idempotent, no transaction around DDL)

- [x] **1.A** Guard: `SHOW TABLES LIKE 'accounts'`; exit cleanly if absent.
- [x] **1.B** Add `level INT NULL` if `SHOW COLUMNS ... LIKE 'level'` returns nothing.
- [x] **1.C** Add `is_system TINYINT(1) NOT NULL DEFAULT 0` (guarded).
- [x] **1.D** Add `normal_balance ENUM('debit','credit') NULL` (guarded).
- [x] **1.E** Data hygiene: detach self-referencing parents — `UPDATE accounts SET parent_account_id=NULL WHERE parent_account_id=account_id`. Runs before the level calc. (Repaired #6 `Electricity`/`NMB`.)
- [x] **1.F** Backfill `level` — reset-and-recompute: `level=NULL`, then roots (no parent / self-ref / dangling) = 1, then fill children depth-by-depth assigning only NULL rows (idempotent + cycle-safe), then any remaining NULL → 1.
- [x] **1.G** *(folded into 1.F loop — child = parent.level + 1)*.
- [x] **1.H** Backfill `normal_balance` from type: `... SET a.normal_balance = t.normal_side WHERE a.normal_balance IS NULL`.
- [x] **1.I** Flag system accounts from settings (guarded): accounts whose id is a numeric `*_account_id` setting value. Matched 14.
- [x] **1.J** Flag system accounts from `journal_mappings` (guarded): union of `debit_account_id` + `credit_account_id`.
- [x] **1.K** Echo a summary count of rows touched.

**✅ Phase 1 check — DONE:** Migration ran twice with identical output (idempotent); CLI gate `tests/test_accounts_tree_columns_cli.php` passes **26/0**. Committed on `feat/chart-of-accounts-tree`.
> Note: the original 1.E/1.F (naive level backfill) ran away on a self-referencing row; replaced with the reset-and-recompute above + the 1.E data-hygiene repair. Lesson logged in the testing notes.

---

## PHASE 2 — Backend API (read side)

### 2.1 `get_chart_of_accounts.php` — feed the tree + tabs
- [x] **2.1.A** Add `$category = $_GET['category'] ?? '';` to the param block.
- [x] **2.1.B** In `$baseQuery`, add `AND at.category = :category` when `$category` is non-empty.
- [x] **2.1.C** Bind `:category` in all three places (count, filtered, data) — mirror the existing `:account_type` binding exactly.
- [x] **2.1.D** Add to the SELECT: `at.category`, `a.level`, `a.is_system`, `a.normal_balance` (`a.parent_account_id` already present).
- [x] **2.1.E** Leave existing `account_type`, `status`, `search` filters untouched.

**✅ check — DONE:** category filter narrows to the chosen category and every returned row carries the four new columns (verified live).

### 2.2 `get_account.php` — feed the Edit form
- [x] **2.2.A** Add `a.level`, `a.is_system`, `a.normal_balance`, `at.category` to the SELECT.
- [x] **2.2.B** No other change.

**✅ check — DONE.**

### 2.3 `get_account_detail.php` — NEW, feeds the View offcanvas
- [x] **2.3.A** Standard header: roots, `Content-Type: application/json`, `isAuthenticated()`, `canView('chart_of_accounts')`.
- [x] **2.3.B** Read `$account_id` (int); error if missing/invalid.
- [x] **2.3.C** Query 1 — account core (join `account_types` for `display_name`, `category`, `normal_side`; join self for parent code/name).
- [x] **2.3.D** Query 2 — direct children (`WHERE parent_account_id = ? AND account_id <> ?`).
- [x] **2.3.E** Query 3 — last 50 posted journal lines.
- [x] **2.3.F** Query 4 — calculated balance from ALL posted lines, on the account's natural side; returns opening / stored / calculated + `in_sync`.
- [x] **2.3.G** Return `{ success, account, children, transactions, balances }`.

**✅ check — DONE:** all 4 queries + the calculated-balance formula run against a real account; `calculated_balance` is finite and `in_sync` computes. CLI gate `tests/test_accounts_api_phase2_cli.php` passes **33/0**.

---

## PHASE 3 — Backend API (write side, guards)

### 3.1 `save_account.php` — protect system accounts, persist new fields
- [x] **3.1.A** On UPDATE, `$orig` now fetches `is_system` (+ code/name). If `is_system==1` AND `!isAdmin()` AND a protected field (code/name/type) actually changed → throw "system account … protected".
- [x] **3.1.B** Compute `level`: `parent.level + 1` when a parent is chosen, else `1`. Added to UPDATE + INSERT.
- [x] **3.1.C** Accept optional `normal_balance` from POST; if empty/invalid, derive from the type's `normal_side`. Added to UPDATE + INSERT.
- [x] **3.1.D** `is_system` is never read from POST (migration/admin-tool only).
- [x] **3.1.E** Existing Phase-0.5 type-change-with-journal-lines guard left intact.
- [x] **3.1.F** *(bonus over WorkDo)* parent validation: reject self-parent, non-existent parent, and any parent that would create a **cycle** (ancestry walk).

**✅ check — DONE:** real INSERT + UPDATE carrying `level`/`normal_balance` succeed against the schema (transaction rolled back); system-lock, self-parent and cycle guard conditions all verified.

### 3.2 `delete_account.php` — block system deletes
- [x] **3.2.A** SELECT now includes `is_system`.
- [x] **3.2.B** If `is_system==1` → throw "This is a system account and cannot be deleted." (for everyone, incl. admins) **before** the other checks.
- [x] **3.2.C** Existing "has transactions" + "has sub-accounts" guards untouched.

**✅ check — DONE:** system account (#120) hits the block; non-system (#2) passes it. CLI gate `tests/test_accounts_save_delete_phase3_cli.php` **25/0**.

---

## PHASE 4 — Core helpers (the Finance communication layer)

**File:** `core/payment_source.php` (append; wrap each in `if (!function_exists(...))`)

- [x] **4.A** `expenseAccounts(PDO $pdo)` — JOIN `account_types`, `WHERE a.status='active' AND at.category IN ('expense','finance_cost')`.
- [x] **4.B** `incomeAccounts(PDO $pdo)` — `WHERE at.category='revenue'`.
- [x] **4.C** `allActiveAccounts(PDO $pdo)` — every active account + `type_name`, `category`, `level`, `is_system`.
- [x] **4.D** `cashBankAccounts()` left exactly as-is.

**✅ check — DONE:** id-sets match the canonical category SQL exactly; no wrong-category/inactive rows leak; a (rolled-back) `finance_cost` account proves it lands in `expenseAccounts()` not `incomeAccounts()`. CLI gate `tests/test_account_helpers_phase4_cli.php` **18/0**.

---

## PHASE 5 — Finance pages: standardise dropdown sources

> Pure swap: replace a raw query with a helper call. Same variable name → markup unchanged.

- [x] **5.A** `revenue.php` → `$income_accounts = incomeAccounts($pdo);`.
- [x] **5.B** `bank_transfers.php` → `$expense_accounts = expenseAccounts($pdo);` (charge-account dropdown).
- [x] **5.C** `recurring.php` → `expenseAccounts($pdo)`.
- [x] **5.D** `expenses.php` filter bar → `expenseAccounts($pdo)`.
- [x] **5.E** All four already require `core/payment_source.php` (each uses `cashBankAccounts()` too).

**✅ check — DONE:** wiring gate `tests/test_finance_dropdowns_phase5_cli.php` **16/0** (each calls the helper, old query gone, require present). Regression: existing posting suites `test_revenue_posting` / `test_expense_posting` / `test_bank_transfer` / `test_recurring` all still pass (39/35/35/26, 0 fail) — posting logic untouched. Live finance_cost-in-dropdown shows once such an account exists (proven via Phase 4's rolled-back insert).

---

## PHASE 6 — Chart of Accounts UI: Tab bar

**File:** `chart_of_accounts.php`

- [x] **6.A** `nav nav-tabs.coa-tabs` above the table: All / Asset / Liability / Equity / Income / Cost of Sales / Expense / Finance Cost, each with `data-category` (`""`, `asset`, `liability`, `equity`, `revenue`, `cogs`, `expense`, `finance_cost`). Horizontal-scrolls on mobile; hidden in print.
- [x] **6.B** `let currentCategory = ''` declared before the table init (avoids TDZ on first AJAX load).
- [x] **6.C** Tab click → toggle `.active`, set `currentCategory`, `table.draw()`.
- [x] **6.D** `ajax.data` sends `d.category = currentCategory`.
- [x] **6.E** Old `#accountTypeFilter` dropdown removed (tabs replace it); status + search kept.

**✅ check — DONE (wiring):** CLI gate `tests/test_coa_tabs_phase6_cli.php` **16/0**. ⏳ Browser smoke (T4–T5) still owed once UI ships.

---

## PHASE 7 — Chart of Accounts UI: Tree / indentation / system lock

**File:** `chart_of_accounts.php` (DataTable column renderers)

- [ ] **7.A** Add `level`, `is_system`, `normal_balance`, `parent_account_id` to the DataTable `columns` data (hidden where not displayed).
- [ ] **7.B** In the `account_name` renderer, prefix `padding-left` by depth: `style="padding-left:${((row.level||1)-1)*22}px"`.
- [ ] **7.C** Bold rows that are parents (i.e. `row.level==1`, or any row whose `account_id` appears as another row's `parent_account_id`). Simplest: bold when `level==1`.
- [ ] **7.D** Add a **normal_balance** badge column: Debit = blue pill, Credit = green pill (matches MYOB colour logic).
- [ ] **7.E** Add a small **lock icon** (`bi-lock-fill`) in the name cell when `row.is_system==1`.
- [ ] **7.F** In the Actions renderer, when `row.is_system==1`: hide **Edit** and **Delete** items, keep **View Details** only.

**✅ check:** children render indented under parents; system accounts show a lock and no edit/delete; debit/credit pills show correct colour.

> **Note on roll-up balances:** true recursive roll-up (parent shows sum of children) is
> presentational. Phase 7 keeps each row's own stored balance. **Roll-up is deferred to
> Phase 10 (optional)** so the core ship is safe; do not block on it.

---

## PHASE 8 — Chart of Accounts UI: Add / Edit form redesign

**File:** `chart_of_accounts.php` (`#accountModal` + JS)

- [ ] **8.A** Make **Parent Account** a permanently visible Select2 (remove the "This is a sub-account" checkbox + `toggleParentAccountField` show/hide). Empty = top-level.
- [ ] **8.B** Add **Normal Balance** radio (`Debit` / `Credit`).
- [ ] **8.C** JS: on **Account Type** change, fetch/lookup that type's `normal_side` and preselect the matching radio (user may override).
- [ ] **8.D** JS `editAccount()`: also populate `normal_balance` radio and the parent select; show a **level badge** ("Level 2") derived from the chosen parent.
- [ ] **8.E** Edit + system lock: when the loaded account has `is_system==1`, disable `account_code`, `account_name`, `account_type` inputs and show an amber banner "System account — code, name & type are protected." (mirrors the server guard in 3.1.A).
- [ ] **8.F** Submit handler unchanged (still posts to `save_account.php`); just ensure the new fields (`parent_account_id`, `normal_balance`) are inside the form.

**✅ check:** add a sub-account by picking a parent (no checkbox); type change flips the normal-balance radio; editing a system account locks the three fields.

---

## PHASE 9 — Chart of Accounts UI: View offcanvas (click row)

**File:** `chart_of_accounts.php` (`#accountViewOffcanvas` + JS)

- [ ] **9.A** Add a Bootstrap `offcanvas` (right side) with 4 tabs: **Details · Sub-Accounts · Transactions · Balance Check**.
- [ ] **9.B** Row click (on the name cell / a `⇒` button) → `openAccountView(account_id)` → `GET get_account_detail.php`.
- [ ] **9.C** **Details tab:** code, name, type, category, level badge, parent (as a link that re-opens the parent), normal-balance pill, description, status, system flag.
- [ ] **9.D** **Sub-Accounts tab:** list children (code, name, balance) + an "Add sub-account" button that opens the Add modal with this account preselected as parent.
- [ ] **9.E** **Transactions tab:** the 50 journal lines (date, journal #, description, debit, credit).
- [ ] **9.F** **Balance Check tab:** show Opening vs Stored (`current_balance`) vs Calculated; if Stored ≠ Calculated show an amber "balance may be out of sync" note (the WorkDo-style reconciliation cue).
- [ ] **9.G** Keep the existing **"View Details"** dropdown link to the full `account_details.php` page — offcanvas is the quick look, the page is the deep ledger.

**✅ check:** clicking a row slides in the panel; all 4 tabs populate; parent link navigates; calculated balance matches stored on a clean account.

---

## PHASE 10 — (Optional, non-blocking) Parent roll-up balances

- [ ] **10.A** In `get_account_detail.php` Sub-Accounts query, also return each child's own posted balance.
- [ ] **10.B** In the tree (Phase 7), compute a group header's displayed balance as the sum of its descendants client-side after the table draws.
- [ ] **10.C** Show roll-up only on `level==1`/`level==2` headers; leaf rows show their own balance.

**✅ check:** an Assets header shows the total of all asset children. (Ship Phases 1–9 first; this is polish.)

---

# TESTING MASTER CHECKLIST

> Run after Phase 9 (and re-run the relevant block after any later change).

### Migration & data
- [ ] T1 — `runner.php` runs twice cleanly (idempotent).
- [ ] T2 — system accounts flagged: every `*_account_id` from `system_settings` + every `journal_mappings` account has `is_system=1`.
- [ ] T3 — `level` correct (top=1, child=2); `normal_balance` set for all rows.

### Chart of Accounts page
- [ ] T4 — each tab filters correctly; "All Accounts" shows everything.
- [ ] T5 — search works within a tab.
- [ ] T6 — tree indentation reflects parent/child.
- [ ] T7 — debit/credit pills correct colour.
- [ ] T8 — add account (top-level) → level 1, normal_balance saved.
- [ ] T9 — add sub-account (pick parent) → level 2, parent set.
- [ ] T10 — edit normal account → all fields editable, saves.
- [ ] T11 — edit **system** account → code/name/type locked (UI + server both refuse).
- [ ] T12 — delete **system** account → blocked with system message.
- [ ] T13 — delete account **with transactions** → blocked (existing guard).
- [ ] T14 — delete account **with sub-accounts** → blocked (existing guard).
- [ ] T15 — view offcanvas: all 4 tabs render; parent link works; calc balance shows.

### Finance dropdown communication (no hanging)
- [ ] T16 — new **cash/bank** account → appears immediately in: expenses Paid-From, receive_payment, bank_statement filter, bank_transfers from/to, recurring bank.
- [ ] T17 — new **expense** account → appears in: expenses filter, bank_transfers charge, recurring expense.
- [ ] T18 — new **finance_cost** account → now ALSO appears in expense dropdowns (the fix).
- [ ] T19 — new **revenue** account → appears in revenue income dropdown.
- [ ] T20 — set an account `status=inactive` → disappears from ALL Finance dropdowns.
- [ ] T21 — saving an expense / revenue / bank transfer / payment voucher still posts to the ledger and moves balances (postOutflow/postInflow unaffected).

### Reports must be byte-for-byte unaffected
- [ ] T22 — Trial Balance unchanged.
- [ ] T23 — Balance Sheet unchanged.
- [ ] T24 — Income Statement (P&L) unchanged.
- [ ] T25 — Cash Flow unchanged.
- [ ] T26 — General Ledger unchanged.
- [ ] T27 — `account_details.php` full ledger page still loads via "View Details".

### Bank accounts page (shares save/get APIs)
- [ ] T28 — add/edit a normal bank account still works.
- [ ] T29 — a bank account flagged `is_system` is protected from rename/delete (expected).
- [ ] T30 — `search_accounts.php` (journal debit/credit, expense pickers) still returns results.

---

# ROLLBACK NOTES

- The migration is purely additive (3 nullable columns). If anything misbehaves, the columns can be ignored by older code paths with no effect; a down-migration would simply `DROP COLUMN level, is_system, normal_balance` (write only if needed).
- Phases 6–9 are confined to `chart_of_accounts.php`; reverting that one file restores the old page without affecting data.
- Phase 5 swaps are one-line each; reverting restores the prior raw queries.

# CHANGE LOG DISCIPLINE

At each commit, append to `changelog.md`: date, file(s), and a one-line description of the sub-phases included. One feature branch off `develop`; PR into `develop` (never `main`).

# DONE = 

- All of Phases 1–9 implemented and committed.
- Testing master checklist T1–T30 green.
- Phase 10 optional — schedule separately if wanted.
