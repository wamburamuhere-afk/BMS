# Accounts & Ledger — Master Unification Plan (`accounts_ledger_master_plan.md`)

> **One-sentence target:** Every account (bank, petty cash, or any) is a single
> Chart-of-Accounts record; every money movement posts one balanced entry to **one**
> ledger; and that single balance + transaction history is shown identically in the
> Bank/Petty-Cash screens, the Chart of Accounts, and every financial report.
>
> **Hard rules:**
> 1. **Additive / non-breaking** — no existing posting or report may break mid-flight.
> 2. **One source of truth** — balances derive from / reconcile to the posted ledger.
> 3. **No money moves without a balanced, posted ledger entry** (the choke-point).
> 4. **Idempotent + reversible** — every migration re-runnable; every posting voidable.
> 5. One feature branch per phase off `develop` → PR into `develop` (never `main`).
>    Log each commit in `changelog.md`. Tests (CLI) green before each PR.
>
> **Created:** 2026-06-08 · **Supersedes/absorbs:** `account_code.md` (Phase 3 done).

---

## 0. Root cause (why anything is wrong)

BMS has **two parallel ledgers** from two build phases, and they don't talk:

```
 MONEY ENGINE (real activity)                 REPORTS + CHART VIEWS (what users read)
 payment_source.php → recordGlobalTransaction  →  journal_entry_items / journal_entries
        → transactions (181) + books_transactions (98)        (2 rows — nearly empty)
        → accounts.current_balance (real)
                         ✗ NO BRIDGE ✗
```

- **Balances** (`accounts.current_balance`) are real (directly updated). ✅
- **Transaction lists + all reports** read `journal_entry_items` (2 rows) → effectively empty. ❌
- **Petty Cash** is a third silo (`petty_cash_transactions`), only partly synced. ❌

Everything below flows from fixing this split.

## 0.1 Requirements being satisfied (traceability)

| # | Requirement | Phase(s) |
|---|---|---|
| R1 | Bank/petty account = one Chart-of-Accounts record | A, C, D |
| R2 | Same amount everywhere | A, B, C |
| R3 | Code auto-generated from parent hierarchy | D, G (Phase 3 ✅) |
| R4 | Flexible parent/child, number-first picker | D (✅ on chart) |
| R5 | Transactions visible in both bank view + chart | **A** |
| R6 | Parent rolls up sub-account amounts | E |
| R7 | Every flow posts to the ledger | **A**, C |
| + | Security (auth/CSRF), COGS/Gross-Profit, category cleanup | H, F |

---

## PHASE 0 — Safety net & baseline (do FIRST, no behaviour change)

**Files:** `migrations/` (read-only diag), `tests/`

- [ ] **0.A** Full DB backup of the target before any write-phase deploys.
- [ ] **0.B** Reconciliation snapshot script: for every account, compare
      `current_balance` vs ledger-derived balance vs (legacy) `books_transactions`
      derived balance. Capture the *current* drift so we can prove we reduced it.
- [ ] **0.C** Inventory table (this doc's map) committed for reference.

**Check:** snapshot runs read-only and prints per-account drift; no data changed.

---

## PHASE A — Unify the ledger (the keystone) — closes R5, R7

**Decision (resolved):** the **canonical ledger = `journal_entries` / `journal_entry_items`**,
because every report + the Chart-of-Accounts views already read it. We bring the money
engine TO it (not the reverse).

**Files:** `core/payment_source.php`, `core/ledger_post.php`, `core/auto_post_hook.php`,
`api/helpers/transaction_helper.php`, `app/constant/reports/consolidated_expenses.php`,
new backfill migration, tests.

- [ ] **A1** *(done — see map)* Inventory every writer/reader.
- [ ] **A2 Post to canonical:** make `postOutflow` / `postInflow` (and the payroll/SDL/WHT
      accrual posters) ALSO write a balanced entry to `journal_entries` via
      `postLedgerEntry()` — same Dr/Cr legs they already pass to `recordGlobalTransaction`.
      Keep `current_balance` update. (Add alongside; do not remove the old write yet.)
- [ ] **A3 Reversal/void parity:** `reverseOutflow` / `reverseInflow` / void paths post the
      mirror canonical entry (or mark the journal entry `void`), so reversals show too.
- [ ] **A4 Backfill history:** idempotent migration converts the existing `books_transactions`
      lines (98) into posted `journal_entries`/`journal_entry_items`, keyed so re-runs don't
      double-post (dedupe on a stable source key, e.g. `transaction_id`).
- [ ] **A5 Repoint the lone legacy reader:** `consolidated_expenses.php` → read canonical
      `journal_entry_items` (so nothing still depends on `books_transactions`).
- [ ] **A6 Idempotency guard:** posting is keyed by (entity_type, entity_id, event) so a flow
      can never double-post (reuse `auto_post_hook`'s existing already-posted check).

**Check:** after backfill, **Trial Balance balances (ΣDr = ΣCr)**; the Chart-of-Accounts
"Transactions" tab shows CRDB's real lines; P&L/Balance Sheet reflect real activity;
`current_balance` ≈ ledger-derived balance for every account (drift ≈ 0 vs Phase 0 snapshot).

---

## PHASE B — Balances become ledger-true — closes R2 (integrity)

**Files:** new "rebuild balances" maintenance action, `chart_of_accounts.php`,
`api/account/get_chart_of_accounts.php`.

- [ ] **B1** "Rebuild balances from posted ledger" admin action: recompute every
      `accounts.current_balance` = opening + Σ(posted journal lines on natural side).
- [ ] **B2** Surface `in_sync` on the chart list (a small drift badge), not only in the
      offcanvas, so any future drift is visible.

**Check:** after rebuild, all accounts `in_sync`; badge clear.

---

## PHASE C — Unify Petty Cash onto the ledger — closes R1/R2/R7 for petty cash

**Files:** `app/constant/accounts/petty_cash.php`, `api/petty_cash/save_transaction.php`,
`api/petty_cash/get_transactions.php`, backfill migration.

- [ ] **C1** Top-up/Deposit = a **posted transfer**: select a **source bank/cash account**
      (`cashBankAccounts()`), post Dr Petty Cash / Cr source (via the Phase-A canonical
      poster). Source bank drops, Petty Cash rises — both in the chart.
- [ ] **C2** Petty Cash page balance reads the **chart** Petty Cash account
      (`pettyCashAccountId()` → `accounts.current_balance`), not `SUM(petty_cash_transactions)`.
- [ ] **C3** Backfill: reconcile historical `petty_cash_transactions` so the chart Petty
      Cash balance matches reality (post catch-up entries or an opening adjustment).

**Check:** top-up reduces the chosen bank AND raises Petty Cash in the chart; Petty Cash
page balance == chart Petty Cash balance == ledger sum.

---

## PHASE D — Bank Accounts form parity — closes R3/R4 for bank accts, stops new mess

**Files:** `app/constant/accounts/bank_accounts.php`, (reuse) `get_next_account_code.php`,
`api/account/save_account.php`.

- [ ] **D1** Replace the free-typed code box with the **auto-generated readonly code** +
      regenerate button (reuse `get_next_account_code.php`).
- [ ] **D2** Add a **parent picker defaulting to `1-1100 Cash On Hand`** (cash class).
- [ ] **D3** Auto-set `cash_flow_category = 'cash'` on save so the account appears in payment
      dropdowns (closes the "shows on Bank page but not when paying" mismatch).
- [ ] **D4** Server validation in `save_account.php`: bank/cash creation must be leaf-under-cash,
      code format `^[1-9]-\d{4}$`.

**Check:** a new bank account gets a `1-11xx` code under Cash On Hand, is `cash`-tagged,
and immediately shows in expense/POS/transfer dropdowns AND under its parent's roll-up.

---

## PHASE E — Account cleanup, structure & roll-up — closes R6

**Files:** new data-migration(s), `api/account/save_account.php`.

- [ ] **E1 De-duplicate:** merge the duplicate CRDB accounts (`BANK-001`, `WAMBURA_28`,
      `ghash_281`, `MASHAKA`) into one canonical CRDB — move postings + balance to the keeper,
      retire the rest (`status='deleted'`), behind a reviewed, reversible migration.
- [ ] **E2 Re-parent:** place the real cash/bank accounts under `1-1100 Cash On Hand`;
      fix `Electricity`→`NMB` naming.
- [ ] **E3 Legacy code normalization:** re-code non-conforming accounts to `D-WXYZ`
      (idempotent migration; postings reference `account_id`, so safe).
- [ ] **E4 Parent = header rule:** block a parent from holding its own direct balance
      (prevents roll-up double-count); leaf-only posting already enforced by the dropdown
      helpers — add the same guard server-side.

**Check:** one CRDB; cash accounts nested under Cash On Hand; roll-up totals correct
(parent = Σ children, no double count); no `D-WXYZ`-nonconforming codes remain.

---

## PHASE F — Chart of Accounts completeness — Categories + COGS/Gross Profit

**Files:** `chart_of_accounts.php`, `account_categories` cleanup migration, new
`account_types` seed migration.

- [ ] **F1 Category data fix (KEEP — it's used by petty cash + payment vouchers):** repair the
      2 dangling `account_type_id` links, re-parent "bank" sensibly, standardise names, add
      "Assets".
- [ ] **F2 Wire the dead sidebar:** clicking a category filters the accounts table (close the
      dead-control gap), or remove the click affordance if undesired.
- [ ] **F3 COGS + Finance Cost:** seed `account_types` rows for `cogs` and `finance_cost`
      (+ a couple of accounts) so those tabs populate and the **Income Statement shows Gross
      Profit**. Verify P&L grouping.

**Check:** category sidebar filters; Cost-of-Sales & Finance-Cost tabs non-empty; P&L shows
Revenue − COGS = Gross Profit.

---

## PHASE G — Account code finish (from `account_code.md`)

- [x] **G0** Phase 3 — renumber code on re-parent. **DONE** (committed `feat/account-code-hierarchy`).
- [ ] **G1** Optional manual code override (toggle) with server validation: format
      `^[1-9]-\d{4}$`, class digit matches parent, uniqueness.
- [ ] **G2** = Phase **E3** (legacy normalization) — single migration, no duplication.

---

## PHASE H — Security hardening (fold in while touching these files)

**Files:** `api/account/get_chart_of_accounts.php`, `save_account.php`, `save_category.php`,
`delete_account.php`, `delete_account_category.php`, `chart_of_accounts.php`.

- [ ] **H1** `get_chart_of_accounts.php`: add `isAuthenticated()` + `canView('chart_of_accounts')`;
      remove wildcard `Access-Control-Allow-Origin: *`.
- [ ] **H2** Add `csrf_check()` to the COA write APIs and send the token from the page's
      `fetch()` calls (`X-CSRF-Token` header) — meets §21.

**Check:** unauthenticated/permission-less calls are rejected; writes require a valid CSRF token.

---

## PHASE I — Tests, verification & rollout

- [ ] CLI test per phase (extend existing suites): A (ledger backfill + TB balances),
      B (rebuild balances), C (petty cash transfer + balance source), D (bank form parity),
      E (de-dupe/re-parent/roll-up), F (categories/COGS), H (auth/CSRF).
- [ ] Regression: existing posting suites (revenue/expense/transfer/payroll/WHT) stay green.
- [ ] Browser smoke: create bank acct → appears in chart with code under Cash On Hand;
      post a payment → shows in chart Transactions tab; petty-cash top-up moves both balances;
      Trial Balance balances.
- [ ] Each phase: `changelog.md` entry, PR into `develop`.

---

## Sequencing & dependencies

```
Phase 0 (safety)              ──┐
Phase A (one ledger)  ◀ keystone │ everything visible depends on A
Phase B (balances true)  ← A     │
Phase C (petty cash)     ← A     │
Phase D (bank form)   ── independent (can run first; stops new mess)
Phase E (cleanup)        ← D,A   │
Phase F (cats/COGS)   ── independent
Phase G1 (override)   ── independent
Phase H (security)    ── independent (fold into A/D/F touches)
Phase I (tests)          throughout
```

**Recommended order:** **0 → A → B → C → E → D/F/G1/H interleaved → I.**
(Phase D can jump earlier if you want to immediately stop new malformed accounts.)

## Risk & rollback

- **A is the sensitive one** (touches money posting + backfill). Mitigations: post to canonical
  *alongside* the existing write first (dual-write), backfill is idempotent + keyed, verify
  TB balances before repointing readers, keep `books_transactions` until A6 proves parity.
- **E (de-dupe/re-code)** changes real account rows: reviewed before/after diff, reversible
  migration, balances moved not deleted.
- Reverting any single phase's branch restores prior behaviour; migrations are additive/keyed.

## Open decisions — RESOLVED (recommendations baked in)

| Decision | Resolution |
|---|---|
| Canonical ledger | **`journal_entries`** (all readers already use it) |
| Category field | **KEEP + clean** (used by petty cash & vouchers; don't retire) |
| Bank form | **Upgrade to parity** (auto-code + parent + cash tag) |
| Petty cash top-up | **Posted transfer** from a source bank account |
| Manual code override | **Offered, validated** (Phase G1) |

## DONE =
- Phases 0–I complete; Trial Balance balances; chart Transactions tab + reports reflect real
  activity; bank & petty cash show one ledger balance; codes hierarchical; accounts de-duped &
  parented; security gaps closed; all CLI suites green.
