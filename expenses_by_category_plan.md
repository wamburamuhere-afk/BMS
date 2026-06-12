# BMS — "Expenses by Category" (tree + roll-up) Implementation Plan

**Status:** DRAFT for approval · **Owner:** (dev) · **Created:** 2026-06-12
**Goal:** A professional, customer-attractive view of expenses arranged as
**Expense Type → Category → Sub-category**, where a parent's total **rolls up** all
spend beneath it — switchable between "collapsed to main Types" and "drill all the way
down to each expense." Mirrors the Chart-of-Accounts arrangement BMS already ships.
**Mockups approved:** `mockup_expenses/` (3 states).
**Reuses BMS's own pattern** — `ledgerRollupMap()` recursive CTE (`core/account_balance.php`)
and `chart_of_accounts.php` tabs/tree/roll-up. Nothing borrowed from WorkDo (verified: WorkDo
expense categories are flat, no roll-up — BMS is already ahead here).

---

## 1. Verified facts (scouted live 2026-06-12)

- `expense_categories` already has **`type_id` + `parent_id`** → a real self-nesting tree
  under each Type. Live depth = 3 (Type → Category → Sub-category), 28 categories, 5 Types.
- Spend attaches at the leaf. Today **nothing rolls up** (parents read 0). Real example:
  `Administrative → Staff Welfare → mange = 1,200`; `Fixed → Internet & Utilities = 531,264.09`;
  `Operating → Labor Wages = 456,789.00`; total expenses = 1,180,889.45.
- **Attribution is currently inconsistent**: of 50 expenses — 2 use `expenses.category_id`,
  17 use the `expense_category_map` (many-to-many), 18 carry `expenses.type_id`.
  **Owner decision (locked): one leaf category per expense** so Type totals reconcile to the
  true total (no double-counting).
- Project scope: `expenses` has `project_id` → every query MUST be project-scoped (§23).

## 2. The action menu is FROZEN (owner requirement)

The drill-down expense rows must show the **identical** gear (`bi-gear`) dropdown as the
current list (`expenses.php`), with the **exact same workflow gates**. Captured verbatim:

| Status | Items shown (in order) |
|---|---|
| any | **View Details**, **Print Voucher** |
| `pending` | *(+ if canEdit)* Edit Expense · **Mark as Reviewed** |
| `reviewed` | *(+ if canEdit)* Edit Expense · **Approve** · **Reject** |
| `approved` | *(+ if canEdit)* **Mark as Paid** ← *only here* |
| any | *(+ if canDelete)* **Delete** |

Workflow: created→`pending` → Reviewed → Approved → **Mark as Paid** (paid). Edit only while
`pending`/`reviewed`. This is reused **as-is** — same JS handlers (`editExpense`,
`updateStatus`, `confirmDelete`, `printVoucher`) and same APIs
(`update_expense_status.php`, `update_expense.php`, `delete_expense.php`). **No workflow change.**

## 3. Guardrails (every phase)

- **Additive, non-destructive.** New API + new page + new test + one idempotent migration.
  The live `expenses.php` list is left **fully working**; the only edit to it is a single
  **"By Category ▾"** link button (mockup-style) next to the existing buttons.
- **One source of truth for the action menu.** Extract the action-dropdown HTML + its JS
  handlers into a shared include (`app/includes/expense_action_menu.php`) that the new page
  uses. (Optional, separate follow-up: point `expenses.php` at the same include so the two
  can never drift — not required for this plan, keeps regression surface at zero.)
- Follows `.claude/templates.md`, `.claude/security.md` (CSRF, permission, soft-delete,
  logging, §23 project scope), `ui-constants.md`, idempotent migrations, runtime CLI test,
  branch → PR into `develop`.

---

## 4. Phases

> Each phase = its own commit, runtime-tested. Built on branch `feat/expenses-by-category`.

### Phase 0 — Leaf-category consolidation (data) ⬅ START HERE, pause after
Make every expense attribute to exactly **one leaf category** so roll-up reconciles.

- `migrations/2026_06_12_expense_leaf_category.php` (idempotent, criteria-based, no hard-coded ids):
  for each expense resolve its canonical leaf and write it to `expenses.category_id`:
  1. if it has `expense_category_map` rows → pick the **deepest** (most-specific leaf); if
     several at the same depth, the lowest id (deterministic) + log the ambiguity count.
  2. else keep existing `expenses.category_id` if it points to a real category.
  3. else derive from `type_id` (attach to that Type's "Uncategorised" leaf, created once per
     Type if missing — criteria-based).
- **Non-destructive:** `expense_category_map` is left intact (the new column is canonical; the
  map stays for history/audit). Old `expenses.type_id` untouched.
- **Verify (acceptance):** after running, `SUM(per-Type rolled-up) == SUM(all expenses)` to the
  cent; print a before/after reconciliation table. **Pause here for owner review** before any UI.

### Phase 1 — Roll-up API
- `api/account/get_expenses_by_category.php` (GET, auth, `canView('expenses')`, **§23 scoped**):
  - **tree mode:** returns Type → Category → Sub-category nodes, each with `own_spend`,
    `rollup_spend` (own + all descendants, recursive CTE in the style of `ledgerRollupMap()`),
    `expense_count`, `share_pct`. Period (`date_from/date_to`) + `status` filters.
  - **drill mode** (`?node_type=category&node_id=N` or `type_id`): returns every expense whose
    canonical leaf is **in that node's subtree** — the rows for the S/NO list (date, description,
    reference, paid-to, status, amount) + subtree subtotal. Reuses the `get_expenses.php`
    scoping/columns so it matches the main list exactly.
- Idempotent/read-only; balanced totals asserted by the test.

### Phase 2 — The page + shared action menu
- `app/includes/expense_action_menu.php` — the **frozen** gear dropdown (§2) as one reusable
  partial (PHP render + JS handlers identical to `expenses.php`).
- `app/constant/accounts/expenses_by_category.php` (per `ui-constants.md`):
  - Stat cards (Total / This Year / Types / Categories), **Type tabs**, period + status filters.
  - **Tree** (indented Type→Category→Sub-category) with parent rolled-up total + "own" beneath,
    **Collapse to Types / Expand All** switch, accent dots, share bars (collapsed view).
  - **Drill-down** card: **first column S/NO**, expense rows with the shared ⚙ action menu,
    subtree subtotal, breadcrumb to climb back up. Mobile cards per template.
  - Route + sidebar/menu entry; **one** new link button on `expenses.php` ("By Category ▾").
- `tests/test_expenses_by_category_cli.php` (runtime, rolled back / MyISAM-safe):
  - leaf `mange`=1,200 rolls up to Staff Welfare and Administrative;
  - **Σ Type totals == true total expenses** (single-leaf reconciliation);
  - period + status filters and subtree drill return correct sets;
  - non-admin project scope excludes out-of-scope expenses;
  - the action menu partial renders the exact status-gated items of §2.

### Phase 3 — Optional polish (separate sign-off)
- A small **donut/bar chart** of spend-by-Type above the collapsed table (customer appeal).
- "vs last period ▲▼%" per Type (needs a comparison range). Deferred unless requested.

## 5. Definition of done
- Idempotent migration; reconciliation proven (Σ Types == total).
- New API §23-scoped + permission-gated; action menu byte-for-byte the current workflow.
- Runtime CLI test green; existing expense tests stay green.
- `changelog.md` at commit; branch → PR into `develop`. Live list untouched bar one link.
