# Payment Section Upgrade Plan — "Receive Payment v2"

**Goal (owner's words):** *"At the end I need to know how much revenue I had on the account."*

Three questions the system must answer at any moment, all from the same books:

| Question | Where the answer lives |
|---|---|
| How much revenue did I earn? | Sales Revenue account → Income Statement |
| How much money came in, and into *which* account? | Each bank/cash account's ledger + running balance |
| Who still owes me? | Accounts Receivable balance |

Benchmark: WorkDo's Account module (`packages/workdo/Account`) — analysed 2026-06-10.
Status: **PLAN — awaiting phase-by-phase sign-off. No code yet.**

---

## A. Audit facts (verified 2026-06-10, local DB + code)

### Money-in paths today
1. **Single-invoice**: `app/bms/invoice/payment_create.php` → `api/account/record_payment.php`.
   Writes `payments` row (incl. `received_into_account_id`, WHT fields), updates invoice
   paid/balance/status, saves attachments, fires gated `autoPostEvent('payment_received')`.
   **Does NOT write a bank-register line.**
2. **Multi-invoice receipt**: `app/constant/accounts/receive_payment.php` → `api/account/save_receipt.php`.
   Same as above **plus** `payment_allocations` rows **plus** a bank-register deposit via
   `recordBankTransaction()` (core/bank_register.php).
3. **Invoice approval**: `api/account/approve_invoice.php` fires `postOutputVat()` (credits Output
   VAT Payable by `tax_amount` via direct balance delta, idempotent on `invoices.output_vat_posted`)
   and gated `autoPostEvent('invoice_approved', grand_total)`.

### The gate is closed
- `journal_mappings`: `invoice_approved` and `payment_received` both `is_active=0`, both accounts NULL.
- `journal_entries` with `entity_type='payment'`: **0 rows**. Payments never reach the ledger,
  bank/cash COA balances never move, revenue never appears in accounts.

### Infrastructure that already exists (do not rebuild)
- `postLedgerEntry()` (core/ledger_post.php): balanced **multi-line** journal entries (≥2 lines),
  joins caller's open transaction, validates balance to 0.01.
- `autoPostEvent()` (core/auto_post_hook.php): mapping lookup, kill-switch, idempotency on
  `(entity_type, entity_id, status='posted')` — but **2-line only, fixed Dr/Cr from mapping**.
- `recordBankTransaction()` (core/bank_register.php): running `balance_after`, idempotency on
  `(account, reference, type)`, `matching_status` ready for reconciliation.
- `cashBankAccounts()` (core/payment_source.php): leaf active asset accounts with
  `cash_flow_category='cash'` — the "Received Into" dropdown already returns **real COA accounts**,
  so no WorkDo-style `gl_account_id` link table is needed.
- Sales-side WHT (core/wht.php "Plan B"): captured on `payments.wht_rate_id/wht_base/wht_amount/wht_posted`,
  position-summed; **deliberately does not move account balances today**.
- Pages: `general_ledger.php`, `ledger_report.php`, `journals.php`, `journal_mappings.php` (admin UI).
- `payments.status` column exists (`completed`/`pending` already flow through record_payment.php).
- `payments.payment_method` enum: `cash, bank_transfer, check, mobile_money, credit_card, credit`.

### WorkDo reference findings (what they do that we don't)
- Customer Payments is a first-class module: list page (filters: customer, status, bank account,
  date range, payment-number search), create modal, voucher view.
- Create form: customer → auto-loads outstanding invoices + credit notes; "Add" pre-fills allocation
  with invoice balance, editable but capped; total auto-computed; submit disabled until ≥1 allocation;
  payment date cannot be in the future.
- **Lifecycle: `pending` → `cleared`** (separate permission). Books move only on clear:
  journal (Dr bank GL / Cr A/R), bank transaction with running balance, invoice balances.
  Delete allowed only while pending.
- Weaknesses we will NOT copy: clear/post sequence not wrapped in a DB transaction; hardcoded
  account codes ('1100'/'2000'); non-atomic denormalized balance updates.

---

## B. Design decisions (locked unless owner objects)

1. **Debit follows the form.** `payment_received` posting debits the **selected
   `received_into_account_id`**, not a fixed mapped account. The `journal_mappings.payment_received`
   row remains the **kill-switch** and supplies the **credit (A/R) account** + fallback debit when no
   account was chosen. Generic `autoPostEvent()` stays untouched for other events; payments use a new
   dedicated helper that calls `postLedgerEntry()` directly (same idempotency check pattern).
2. **WHT joins the entry.** When sales-side WHT is captured:
   `Dr Received-Into (net cash) + Dr WHT Receivable (wht) / Cr A/R (gross)`.
   The `wht_posted` column stamp stays (it feeds the WHT position report).
3. **Revenue posts at approval, VAT-aware (3 lines).**
   `Dr A/R (grand_total) / Cr Sales Revenue (subtotal) / Cr Output VAT Payable (tax_amount)`.
   `postOutputVat()` keeps stamping `output_vat_posted` (VAT-return report feeds from it) but its
   **balance delta must not double-count** once the journal line carries VAT — Phase 1 resolves this
   with a regression test (decision: skip the delta when a posted `invoice_approved` entry exists).
4. **One bookkeeping path.** Both money-in APIs converge on one shared helper
   (`core/receipt_post.php` — name TBD) that does: payments row → allocations → invoice updates →
   journal entry → bank-register deposit, **all inside one transaction** (better than WorkDo).
5. **Lifecycle reuses `payments.status`.** `pending` = recorded, books untouched (cheque/transfer not
   cleared). `completed` = books posted. New **"Mark Cleared"** action gated by `canPost('invoices')`
   (workflow-verb permission per `.claude/templates.md` §11.1). Delete/void of a completed payment is
   a reversal, not a row delete; pending payments may be deleted outright (WorkDo rule).
6. **Overpayment is blocked** (save disabled while unallocated > 0) until Phase 5 introduces customer
   advances. No silent credit balances.
7. **Backfill targets the live DB**: criteria-based + idempotent migration (no hard-coded ids),
   per deploy rules in CLAUDE.md.

---

## C. Phases (each = own branch off `develop` + PR + CLI test, per workflow)

### Phase 1 — The books move correctly (backend only) ⬅ START HERE
**Files:** new `core/receipt_post.php`; edits to `api/account/record_payment.php`,
`api/account/save_receipt.php`, `core/vat.php` (double-count guard), seed/activation of the two
`journal_mappings` rows (admin configures account ids in the existing UI).
- Shared posting helper per decisions 1–4; `record_payment.php` gains the missing
  `recordBankTransaction()` deposit line.
- `approve_invoice.php` switches to the 3-line VAT-aware entry (decision 3).
- Idempotency: re-approving / re-posting never double-posts (reuse existing entity check).
- **Test:** CLI test in `tests/` mirroring `test_phase4_supplier_payment_cli.php` — asserts entry
  shape (2/3/4 lines), balance, idempotency, bank-register row, and order
  `beginTransaction < post < commit`.
- **Acceptance:** record a payment on a test invoice → journal entry visible in General Ledger;
  CRDB/Cash COA account shows the debit; A/R credited; bank statement shows the deposit;
  Income Statement shows the invoice's net revenue after approval.

### Phase 2 — Receive Payment v2 (the form)
**Files:** upgrade `app/constant/accounts/receive_payment.php` (+ its API);
`app/bms/invoice/payment_create.php` becomes a redirect → `receive_payment?invoice=N`
(customer pre-locked, that invoice pre-allocated).
- Auto-allocate oldest-first button; per-line caps; unallocated counter (blocks save, decision 6).
- WHT selector with computed WHT + net-cash preview.
- **Live double-entry preview card** (Dr/Cr lines exactly as they will post) — our edge over WorkDo.
- Status radio: Completed (post now) vs Pending (cheque/transfer uncleared — no posting).
- Payment date `max=today`; attachments (existing handler); Select2 AJAX customer per UI standard.
- UI per `.claude/ui-constants.md` — load before building.

### Phase 3 — Payments Received register + receipt voucher
**Files:** new `app/constant/accounts/payments_received.php` (list) + `receipt_print.php` (voucher);
new "Mark Cleared" / "Reverse" APIs.
- List: stat cards (Today / This Month / Pending Clearing / Total), filters (customer, method,
  received-into account, status, date range), DataTable + mobile cards per templates.
- Receipt voucher (RCP-…): printable official receipt; "Save & Print" hook from the form.
- Mark Cleared (canPost) posts the books for pending payments; Reverse (canVoid) posts a reversal
  entry + removes the register line (`reverseBankTransaction()` exists).

### Phase 4 — Backfill history (live DB migration)
- Idempotent migration: post all `approved/partial/paid` invoices (3-line) and all `completed`
  payments (per decision 1–2) that have **no** posted journal entry; write missing bank-register
  deposits. Criteria-based; safe to rerun; logged counts.
- After this, year-to-date revenue and account balances are complete — not just from go-live day.

### Phase 5 — Later (separate sign-off)
- Customer advances / overpayment as a liability account + apply-to-invoice flow.
- Credit-note application inside the receipt (WorkDo parity) — requires credit-notes module first.
- Bank reconciliation screen (matching_status already in place).

---

## D. Open items for the owner
1. Which COA accounts: **Sales Revenue**, **Accounts Receivable**, **Output VAT Payable** — confirm
   the account ids/codes to configure in Journal Mappings (admin UI) before Phase 1 goes live.
2. Confirm `canPost('invoices')` vs a new `payments` page_key for the clearing permission
   (Phase 3 list page needs a page_key either way; default proposal: new key `payments`).
