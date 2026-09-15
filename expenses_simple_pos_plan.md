# Simple POS — Expenses CRUD Simplification Plan

Status: DRAFT — awaiting your confirmation before any code changes.
Scope: **Expenses only** for this round. Products, Services, Customers follow later, one at a time, once this one is confirmed working with no bugs.

Gate: everything below only changes behavior when the tenant has **Simple POS mode ON** (superadmin's Tenant → Point of Sale → More → "Simple Mode" switch, `posSimpleModeEnabled()`). Normal (non-simple) tenants keep today's full form exactly as-is — nothing changes for them.

---

## 1. Your instructions, mapped 1:1 to what will change

| # | What you asked (paraphrased from your message) | What will happen |
|---|---|---|
| 1 | Remove "Expense Type" field | Hidden from the Add/Edit Expense form in Simple POS. |
| 2 | "Paid to" — remove Sub Contractor, keep Staff + Supplier | Sub Contractor option removed from the Paid-To type selector in Simple POS (it's project-linked, and Simple POS doesn't use projects). |
| 3 | Only show Staff/Supplier **if active** | Dropdown is built only from `status='active'` Staff and Suppliers, same as today. |
| 4 | If **none** are active at all → fresh manual field (role + name), rest of form stays | If a tenant has zero active Staff and zero active Suppliers, the Paid-To control renders directly as two free-text boxes ("Pay to whom, e.g. Bodaboda" + "Name") instead of a dropdown — no dead-end empty dropdown. |
| 5 | Even when active options exist, add a **"More"** option | Paid-To type dropdown = Supplier / Staff / **More**. Picking "More" swaps in the same two free-text boxes as #4. |
| 6 | Supplier as default | Paid-To type defaults to "Supplier" on a fresh form. |
| 7 | expenses_view should not show Category; but underlying data must still be filled (autofill), never shown blank | Category column/badges hidden from the list and detail pages in Simple POS. Behind the scenes every expense still gets a real `expense_type`/category tag automatically (a per-tenant auto-created "General" type) so no DB field is left null just because the UI stopped asking for it. |
| 8 | Account selection must not be in the UI; system should pick it; you can see where it posted once opened | No GL account picker anywhere in Simple POS. The system auto-resolves which expense account to debit (see §3). The **read-only** expense detail/voucher page keeps showing which account and which payment source it actually posted to — informational only, never a field you fill in. |
| 9 | Hide "Paid From" too | Hidden from the form. Auto-resolved to the tenant's Cash-on-Hand account (falls back to first active bank account if no cash account exists) — see §3. Same "you can see it later on the voucher" treatment as #8. |
| 10 | "Types & Categories" management should not appear in expense details | The "Manage types & categories" link and the inline "add new type/category" options disappear from the form and the detail page in Simple POS. |
| 11 | Check/add language translation for this section | Confirmed: the Expenses page currently has **zero** translation coverage (all strings hardcoded English) even though the codebase has a working `t()` / `lang/en.php` / `lang/sw.php` system used elsewhere. Every string touched by this change will be wrapped in `t()` with an English + Swahili translation added. (Full-page retrofit of the *untouched* strings is out of scope for this round — only what this change adds/modifies gets translated, so scope stays bounded.) |
| 12 | No bugs, everything saved correctly | Covered by §5 (testing) below before this is considered done. |
| 13 | Do Products/Services/Customers next, one at a time | Explicitly deferred — not touched in this round. |
| 14 | "Double entry is not allowed" | **Read this as: the double-entry *picker UI* is not allowed to appear — not that double-entry posting itself is switched off.** The ledger rule in `.claude/reporting-source.md` is absolute: every financial figure must come from real, balanced `journal_entries`/`journal_entry_items` rows, always. So Simple POS expenses still post a full, balanced double-entry (Dr expense / Cr accrual, then Dr accrual / Cr cash on payment) exactly like today — the only change is that a human never picks the accounts; the system picks them automatically. **Please confirm this reading is what you meant** — if you actually intended something else by "double entry is not allowed," tell me before I proceed, since getting this wrong would corrupt real financial reports. |

---

## 2. Confirmed decisions (from the questions you already answered)

- **Default expense account**: a new canonical leaf account, **`6-1900 Miscellaneous Expenses`**, added to the chart of accounts (auto-created per tenant if missing). All Simple POS expenses debit this account by default. An admin can override it later via one system setting, same resolver pattern already used for every other control account (`core/gl_accounts.php`) — no UI needed for that override right now, it's just future-proofing the plumbing.
- **Default "Paid From"**: the tenant's Cash-on-Hand account (falls back to first active bank account if none exists).
- **Manual "More" payee**: stored as two plain free-text columns directly on the `expenses` row (`payee_manual_role`, `payee_manual_name`) — no hidden Supplier records get created behind your back.

---

## 3. Technical plan (for your awareness — this is the "how")

**A. Schema (one new tenant migration)**
- `expenses`: add nullable `payee_manual_role`, `payee_manual_name`; allow `paid_to_type = 'other'`.
- Chart of accounts: seed `6-1900 Miscellaneous Expenses` (new tenants get it by default; existing tenants get it backfilled if missing).

**B. `core/gl_accounts.php`** — two new resolver functions, following the existing pattern used by every other control account (setting override → canonical code → category fallback):
- `miscExpenseAccountId($pdo)` → resolves to 6-1900.
- `defaultCashAccountId($pdo)` → resolves to Cash-on-Hand.

**C. Backend save/update** (`api/account/add_expense.php`, `api/update_expense.php`)
- In Simple POS: auto-fill `ex_type_id` (auto-created tenant-level "General" type), `expense_account_id` → `miscExpenseAccountId()`, `bank_account_id` → `defaultCashAccountId()`.
- Accept `paid_to_type='other'` with `payee_manual_role`/`payee_manual_name` in place of a Staff/Supplier id.
- The actual ledger posting code (`core/expense_posting.php` accrual, `postOutflow()` settlement) is **not modified** — same real double-entry as today, just fed auto-resolved accounts instead of user-picked ones.

**D. Add/Edit Expense modal** (`app/constant/accounts/expenses.php` + its inline JS)
- Simple POS branch: hide Expense Type + category cascade + "Manage types & categories" link + "Paid From". Paid-To = Supplier (default) / Staff / More, with the zero-active-payees fallback from §1.4.
- Every string this branch touches gets `t()` + `en`/`sw` entries.

**E. List & detail pages**
- `includes/tables/expenses_table.php`: hide the Category column in Simple POS.
- `expense_details.php`: hide type/category badges and "Manage types" affordances in Simple POS; keep the Financial Impact panel (account + payment source actually posted to) visible, since that's your "can see where it posted" requirement.

**F. Testing** (before this is called done)
- An end-to-end CLI test that creates a Simple-POS expense through the real save path (both the "More" manual-payee path and the normal Supplier/Staff path), asserts every DB field that autofill promises is actually filled (no nulls), and runs `assertLedgerBalanced()` to prove the ledger still reconciles. Modeled on the existing `tests/test_pos_simple_mode_cli.php`.
- Manual run-through in the real form (not just tests) before reporting this finished, per this project's own testing standard.

---

## 4. Explicitly out of scope this round
- Products CRUD, Services CRUD, Customers — next, one at a time, after Expenses is confirmed solid.
- Any change to the non-Simple-POS (normal) Expenses experience.
- A UI to let an admin override the default Misc-Expenses/Cash accounts (the plumbing supports it; no screen is being built for it now).

---

**Please check this against what you asked for — especially item 14 above (double-entry) — and tell me if anything needs to change before I start.**
