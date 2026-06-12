# Account Code & Parent-Hierarchy — Roadmap (`account_code.md`)

> **Goal:** Account codes follow the MYOB-style `D-WXYZ` hierarchy so every code
> reflects where the account sits under its parent, the create/edit flow is flexible
> (an account can be a top-level parent OR a child), and every parent/selector shows
> the **number first** (`1-1000 — Current Assets`).
>
> **Hard rule:** ADDITIVE / non-breaking. Postings reference `account_id` (never the
> code), so re-coding is data-safe — but must be deliberate. One feature branch off
> `develop` → PR into `develop`.
>
> **Created:** 2026-06-08 · **Branch:** `feat/account-code-hierarchy`

---

## 0. What already exists (do NOT rebuild)

- **Hierarchical generator** — `api/account/get_next_account_code.php` already produces
  `D-WXYZ`: parent `1-1000` → `1-1100` → `1-1110`/`1-1120`…; no parent → class root
  `D-0000` or next group `D-W000`. Class digits: Asset 1, Liability 2, Equity 3,
  Income 4, Cost of Sales 5, Expense 6, Finance Cost 8. Codes are guaranteed unique.
- **Parent picker shows number-first** — `chart_of_accounts.php` renders each option as
  `account_code - account_name`; Select2-enabled; rebuilt to **same-class** parents only.
- **Flexible create** — empty parent = top-level/parent account; chosen parent = child;
  any account can later be a parent. Self-parent + cycle + same-class guards enforced.

➡️ Requirements "code matches parent", "flexible parent/child", and "number-first in the
parent dropdown" are **already met**.

## 0.1 Known limits of the scheme (accepted)

- Max **9 siblings per parent** (digits 1–9 at each slot).
- Max **~5 levels** deep (`D` + 4 digits).
- Fine for an SMB chart; documented here so deep branches are designed within it.

---

## Gaps this roadmap closes

| # | Gap | Phase |
|---|-----|-------|
| 1 | Code does **not** update when an account is moved to a new parent (code drifts from the tree) | **3** (core) |
| 2 | The **Category** selector is code-less / confusing vs the parent tree | **2** (decision — OPEN) |
| 3 | Code is **read-only** — no controlled manual override | 4 |
| 4 | Legacy **malformed codes** (e.g. `WAMBURA_28` / CRDB) don't fit the scheme | 5 |

---

## PHASE 1 — Lock & document the scheme ✅ (this file)

- [x] Digit map, `D-WXYZ`, depth/sibling limits written down here.
- [x] Confirm the generator's digit map matches the seeded roots (`4-0000 Income`,
      `6-0000 Expenses`) — it does.

## PHASE 2 — Parent picker = the single hierarchical selector  ⏳ DECISION OPEN

- [ ] **2.A** Guarantee every parent option renders `code — name`, same-class scoped
      (already mostly there; verify + lock with a test).
- [ ] **2.B** **DECISION (user):** retire the code-less *Category* field in favour of the
      parent tree **(recommended)**, OR keep *Category* as an optional tag.
      *Current default while undecided: **KEEP** (non-destructive).* 

## PHASE 3 — Re-derive the code when the parent changes  ◀ START HERE

**File:** `app/constant/accounts/chart_of_accounts.php` (Edit-form JS)

- [ ] **3.A** In **Edit** mode, when the user changes the parent, offer (SweetAlert
      confirm) to **renumber** the account to match the new parent via
      `generateAccountCode()`.
- [ ] **3.B** Guard against the prompt firing during programmatic population
      (`editAccount()` sets the parent with `.trigger('change')`) — suppress flag.
- [ ] **3.C** Skip for **system accounts** (code field locked) and when in Add mode
      (Add already auto-generates).
- [ ] **3.D** No server change needed — the regenerated code is in the submitted field;
      `save_account.php` keeps its duplicate-code guard.

**Check:** open a non-system account → change parent → confirm prompt → code updates to
the new parent's branch; opening the edit form does NOT prompt; system account never
prompts.

## PHASE 4 — Optional manual override (validated)

**File:** `chart_of_accounts.php` + `save_account.php`

- [ ] **4.A** "✏️ edit code" toggle to unlock the code field on demand.
- [ ] **4.B** Validate server-side: format `^[1-9]-\d{4}$`, **class digit matches the
      parent's class**, and uniqueness.

## PHASE 5 — Legacy normalization migration

**File:** `migrations/2026_06_08_normalize_account_codes.php` (new, idempotent)

- [ ] **5.A** Find accounts whose code doesn't match `^[1-9]-\d{4}$` (e.g. CRDB).
- [ ] **5.B** Re-code + re-parent them into the right branch (CRDB → under `Cash On Hand`).
      Balances untouched; reversible.

## PHASE 6 — Tests + changelog

- [ ] Extend `tests/test_account_code_and_ui_cli.php`: re-parent → re-code, override
      validation, legacy normalization.
- [ ] Append each phase to `changelog.md`.

---

## DONE =
- Phases 3–4 implemented; Phase 2 decision made; Phase 5 migration run; tests green.
