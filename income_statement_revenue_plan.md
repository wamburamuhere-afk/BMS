# income_statement_revenue_plan.md — Income Statement: the INCOME side (Revenue + Other Income)

**Purpose:** make the Income Statement's income side **true to IFRS / IFRS for SMEs** (which
Tanzania's **NBAA adopted without modification**), so that "Revenue" shows only the company's
**ordinary sales**, non-ordinary income/gains sit in **Other Income**, and purchase-side items
(supplier refunds) leave the income side entirely — and so that revenue is **complete** (every
earned invoice recognised).

**Non-negotiable principles** (so the same change is correct on LOCAL *and* ONLINE, for **any**
customer, **any** data volume):

1. **Define by account ROLE / CATEGORY — never by account id, name, or amount.** Use resolvers
   and `account_types.category`, not constants. (No "id 241", no "829M".)
2. **Detect by STATUS + missing-posting CRITERIA — never by counting specific records.**
3. **Reuse the existing idempotent posting/recognition engine** so re-runs are no-ops.
4. **Remediate history PER-RECORD with dated contra journal entries — never blanket re-tag / void.**
   (Online holds real client data; only reversible, auditable corrections are allowed.)
5. **Verify with the balance guardrail** after every change: Σ Dr = Σ Cr and Assets = Liab + Equity,
   and TB / BS / IS still reconcile.
6. **WIRE END-TO-END, AND PROVE IT WITH DATA — never ship a link/section whose source is empty.**
   A new section, line, total, or **View (drill-down)** icon is only "done" when it actually
   **renders real records from a real source at runtime** — verified by executing the path, not by
   lint/static green. If a section can have no source yet, it must be **hidden when empty**, never
   shown as a dead link. (See "WIRING & VISIBILITY" below — this is mandatory for every area.)

---

## The standard (why these are the rules, not opinions)

- **Revenue = "income arising in the course of an entity's ordinary activities"** — sales of goods,
  rendering of services, construction contracts, and others' use of the entity's assets (interest,
  royalties, dividends, rent). [IFRS 15 / IFRS for SMEs §23]
- **Gains ≠ revenue.** Income from incidental activities — e.g. **disposal of surplus/fixed assets** —
  is income but **cannot be classified as revenue**; it is *Other Income / Gains*.
- **A supplier credit / refund / purchase return is a PURCHASE-side item.** It is recorded
  `Dr Accounts Payable / Cr Purchase Returns` (reduces purchases / COGS / inventory) — **never revenue**.
  TRA agrees in direction: a supplier debit note adjusts **input** VAT (purchase side), not output VAT.
- **Tanzania = IFRS.** NBAA adopted IFRS and IFRS for SMEs **without modification** (effective 2004 /
  2010, Technical Pronouncement No. 3), so the IFRS rules above are the local rules.

Sources: IFRS (IAS 18/15 revenue), Grant Thornton (revenue vs gains), Planergy / CPDbox (supplier
credit = reduce purchases, not revenue), IFRS.org Tanzania jurisdiction + IFAC NBAA, TRA VAT.

---

## The root cause (one structural fact explains the whole mess)

The chart has **exactly one credit-normal Income-Statement category: `revenue`.** There is **no
`other_income` / `gain` category.** So `glProfitLoss` has nowhere to put non-sales income, and
**every credit-normal account collapses into Revenue** — real sales *plus* supplier credits *plus*
disposal gains *plus* interest income. Meanwhile the UI already draws an **"OTHER INCOME" section**
that the engine can never fill (it is dead/empty by construction).

Everything below follows from fixing that one structural gap + two consequences of it.

---

## WIRING & VISIBILITY — mandatory acceptance for EVERY area

> The rule: **if a number shows on screen, its source must exist and return that number; if a View
> icon shows, clicking it must list the real contributing records.** No section, total, or drill may
> point at an empty/absent source. This is checked by **running the real path** (render the page,
> call the endpoint, click-equivalent the drill), never by lint alone.

**How the income side is wired today (so we extend it, not break it):**
- Each P&L line carries a **drill descriptor** `{ source:'journal', account_id:N }`. The **View** icon
  calls `get_income_statement_detail.php`, which lists that account's posted journal records for the period.
- This is **account-generic** — any account with posted activity drills correctly — *provided* two things
  are handled (both are currently gaps the plan must fix):

**Drill gap 1 — sign is hard-coded to `revenue`.** In `get_income_statement_detail.php` the `journal`
case signs the amount as credit−debit **only when `category === 'revenue'`**, else debit−credit. A new
**`other_income`** category (Area A) is also **credit-normal**, so without a fix its View rows would show
**inverted (negative) amounts**. → Area A MUST change this to: *any credit-normal IS category*
(`revenue` OR `other_income`) signs credit−debit. (Resolve by the account's `normal_side`/category,
not by listing categories by hand where avoidable.)

**Drill gap 2 — the journal drill is gated `admin-only + no project filter`.** Today
`if ($project_id !== null || !$is_admin || !$account_id) break;` returns **zero rows** for a non-admin,
or whenever a project filter is active — on **every** line (because every line now uses
`source='journal'`). So "click View → see them" silently fails for those users. → The plan must make the
journal drill **return the same scoped records the report itself used** (apply the report's project/scope
filter to the drill query) so View works for exactly the rows that built the line, for every viewer who
can see the line. (Title should also read "Contributing ledger entries", not "Manual journal entries".)

**Per-area acceptance (all must pass at runtime, with real data):**
1. Every line the area introduces **carries a working `drill` descriptor**, and clicking **View** returns
   **the real records that sum to the line's amount** (the drill total == the line amount).
2. Every **section/subtotal** the area introduces is **fed from the engine** (a real GL bucket), not a
   static placeholder; **hidden when its source is empty**, shown only when it has data.
3. The page is **rendered** (not just linted) and the endpoint **executed**; numbers on screen equal
   numbers from the source; the drill modal lists rows (S/NO, Reference, Date, Party, Status, Amount).
4. Empty/edge state verified: a period with no data shows the section hidden or a clean "no records",
   never a broken/dead View.

---

## THREE INDEPENDENT AREAS (walk one at a time; each is shippable alone)

### AREA A — Separate Revenue from Other Income / Gains  (structural foundation)

**Rule:** ordinary sales → **Revenue**; non-ordinary income/gains → **Other Income**. Two distinct
credit-normal IS buckets, per IFRS.

**Gap today:** only `revenue` exists as a credit-normal IS category; the UI's Other-Income section is dead.

**Logic of the fix (criteria-based, additive):**
1. **Add an `other_income` (a.k.a. gains / non-operating income) account-type category** — credit-normal,
   statement = IS. Idempotent migration; additive only (clones the `revenue` type's structural columns,
   like the earlier `cogs` / `finance_cost` types were added). No account is moved by id.
2. **Engine:** `core/financial_reports.php::glProfitLoss()` emits a new `other_income` bucket alongside
   `revenue` (same derivation, just a second credit-normal category). Net profit = revenue + other_income
   − cogs − expense − finance_cost.
3. **Endpoint:** `get_income_statement.php` maps the new bucket to the **already-built (but empty)
   OTHER INCOME** section + Total Other Income subtotal. JSON contract additive; the dead section comes alive.
4. **Re-point accounts by ROLE** (not id): accounts whose role is **gain on disposal / interest income /
   sundry non-operating income** get `category = other_income` via a **criteria-based, idempotent**
   migration (resolve by sub-type / canonical code / the disposal-gain resolver — never a hard-coded id).
   Going forward, the disposal-gain resolver already points at the right account; this only re-classifies
   the *type* so it lands in Other Income instead of Revenue.

5. **WIRE THE VIEW (mandatory):** fix the two drill gaps above so the new OTHER INCOME lines have a
   **working View** — `get_income_statement_detail.php` signs `other_income` as credit-normal (no inverted
   amounts) and returns the scoped contributing records. The OTHER INCOME subtotal must be **fed from the
   engine bucket** and **hidden when empty**, never a dead placeholder.

**Safeguard:** purely a classification + presentation change (no journal posting moves), so BS/TB are
untouched and the IS net profit is unchanged — only the *split* between Revenue and Other Income changes.

**Test (runtime, real data):** `glProfitLoss` returns both buckets; an account tagged `other_income`
appears under OTHER INCOME (not REVENUE); net profit unchanged; TB/BS still reconcile; **the page renders
with the OTHER INCOME section populated; clicking View on an Other-Income line returns real records whose
total == the line amount (correct positive sign); a non-admin / project-filtered view also sees those
records; an empty period hides the section.**

---

### AREA B — Remove supplier credits / refunds from the income side  (purchase-side cleanup)

**Rule:** a supplier refund reduces the **cost of purchases** (or settles AP). It is **never** income.

**Gap today:** supplier-credit-type accounts are tagged `category = revenue`, and the debit-note-refund
posting **credits an income account**. Both are wrong by IFRS + TRA.

**Logic of the fix — two layers, both criteria-based + idempotent:**
1. **Forward (the real cause):** change the **resolver** the debit-note-refund posting uses so its credit
   leg lands on a **purchase-side account** (purchase returns / COGS-contra / AP — resolved by setting →
   canonical code → category, never a hard-coded id). The cash leg (Dr Bank into the chosen account) is
   already correct and stays. Result: every future refund on every server posts correctly.
2. **History (remediation):** for refunds already posted to an income account, **re-classify per record
   with a dated contra journal entry** — `Dr [income account previously credited] / Cr [purchase-side
   account]` for that record's amount, dated to the original, idempotent on a remediation key, run inside
   the balance guardrail. **No blanket re-tag, no void of client data.** Detection is criteria-based:
   "posted entries whose credit account is the supplier-credit income account and whose source is a
   debit-note refund," not a list of ids.

   *(Simpler alternative for choosing the bucket only: re-classify the supplier-credit account's TYPE out
   of `revenue`. That removes it from the Revenue section but leaves it on the income side — acceptable as
   a stop-gap, but the resolver + contra approach is the correct one because it puts the money on the
   purchase side where IFRS/TRA require.)*

**Safeguard:** every correction is a balanced, dated, reversible journal; guardrail re-checked after.

**Wire the View:** after re-homing, the refund's records must still be reachable — the View on the
purchase-side line (wherever the amount now lands) lists the refund records; the Revenue section no longer
shows the supplier-credit line at all (its source is gone from revenue).

**Test (runtime, real data):** the refund resolver returns a purchase-side account (not income); a new
refund posts `Dr Bank / Cr [purchase-side]`; a remediation contra moves a historical refund off income;
Revenue total drops by exactly the reclassified amount while the ledger stays balanced; **the page renders
with the supplier-credit line absent from Revenue, and View on the purchase-side line shows the moved records.**

---

### AREA C — Revenue completeness (recognise every earned-but-unposted invoice)

**Rule:** revenue is recognised in the GL when an invoice reaches its **recognition status**
(approved onward). An invoice in that status with **no posted revenue entry** is simply un-recognised.

**Gap today:** invoices that reached recognition **before the recognition routine existed** carry no GL
revenue, so genuine sales are missing from the IS (correctly-classified but **incomplete**).

**Logic of the fix (criteria-based + idempotent, reuse the engine):**
1. **Detect** every invoice whose status ≥ recognition threshold **AND** that has **no posted revenue
   entry under its idempotency key** — **excluding** invoices recognised elsewhere (POS-sourced,
   IPC-deferred) so nothing double-counts. Pure criteria; no record lists.
2. **Recognise** each by running the **existing `postInvoiceRevenue` routine** (already idempotent on the
   invoice key, posts the standard `Dr AR / Cr Sales [/ Cr Output VAT]`, stamps the recognition flag).
   Dated to the invoice's **own recognition date**, not "today".
3. Re-runnable safely on local and online — it only fills genuine gaps; already-posted invoices are skipped.

**Safeguard:** runs inside the balance guardrail; after it, TB / BS / IS reconcile and the all-time net
profit still equals retained earnings.

**Wire the View:** each newly-recognised invoice's revenue must be **drillable** — clicking View on the
Sales/Service line now lists those invoices among the contributing records (because they finally have a
posted journal entry the drill reads).

**Test (runtime, real data):** identify a recognised-but-unposted invoice → backfill posts exactly one
balanced revenue entry → re-running is a no-op → Revenue rises by the recognised (net-of-VAT) amount →
ledger balanced → POS/IPC invoices skipped → **the page renders with the higher Revenue, and View on the
Sales line lists the newly-recognised invoice(s) with the right amount.**

---

## Recommended order (each independently shippable, with its own branch + test + PR)

1. **Area A** first — it builds the *home* (Other Income bucket) and revives the dead UI section, so
   later moves have somewhere correct to land. Lowest risk (classification/presentation; no posting moves).
2. **Area B** next — re-home supplier credits to the purchase side (resolver forward + per-record contra
   remediation). This is the biggest single distortion remover on Revenue.
3. **Area C** last — completeness backfill, so Revenue is finally both *correctly classified* and *complete*.

*(A and C are independent of each other; B is independent of both. Order is by clarity, not hard dependency —
the user may walk them in any order.)*

---

## Cross-cutting guarantees (apply to every area)

- **Dataset-agnostic:** resolvers + category criteria + status criteria only — runs identically on local,
  online, and for any customer.
- **Idempotent:** every migration and every posting/remediation is safe to re-run (keyed; re-runs no-op).
- **Auditable:** history is corrected by **dated contra entries**, never blanket re-tag / void.
- **Self-verifying:** each area ships a CLI test that proves the behaviour AND that TB/BS/IS still reconcile
  (Σ Dr = Σ Cr, Assets = Liab + Equity, all-time net profit == retained earnings).
- **Additive contract:** JSON shape + UI sections preserved/extended, never broken.
- **Wired & visible (no dead links):** every section/total/View introduced is fed from a real source and
  proven at runtime to render real records (drill total == line amount); empty sources are hidden, never
  shown as dead links. A phase is not "done" until "click View → I see the real records" is demonstrated.

---

## Progress tracker

| Area | What | Status |
|------|------|--------|
| A | Other-Income/Gains category + engine bucket + revive UI section + re-class non-operating income by role | ✅ DONE — migration adds `other_income` (credit-normal IS) + re-points 8-xxxx branch & disposal-gain account by role; glProfitLoss/glBalanceSheet/glCashFlow handle it; endpoint feeds the (already-built) OTHER INCOME section; drill signs by `normal_side` + uses report scope. test_income_statement_other_income_cli 18/18; View returns real records (total==line). |
| B | Supplier credits → purchase side (resolver forward + per-record dated contra remediation) | ✅ DONE — migration re-points the supplier-credits account `revenue`→`cogs` (cost reduction) + re-parents it under Cost of Sales (5-0000); forward, pay_debit_note.php credits Accounts Payable (nets the OUT-8 AP debit), not income. Revenue no longer carries supplier credits. |
| C | Recognise every earned-but-unposted invoice (idempotent backfill via postInvoiceRevenue) | ✅ DONE — backfill migration recognises every recognised-status invoice with no posted revenue (reuses idempotent postInvoiceRevenue + postInvoiceCOGS, dated to the invoice, IPC/POS-excluded). 13 invoices backfilled; 0 left; ledger balanced. test_income_statement_revenue_truth_cli 14/14. |

> **Out of scope here:** COGS/expense side, Other Income from sources not yet posting (handled by money.md),
> and any change to the BS/Cash Flow (income side only). The money.md money-event work is the upstream
> dependency that already feeds the GL these reports read.
