# BMS — Financial Reporting Data Source (READ BEFORE BUILDING ANY REPORT)

**Every financial report reads ONE source: the canonical double-entry ledger.**
Never read the legacy `transactions` / `books_transactions` tables, `accounts.current_balance`,
or raw source documents (invoices, payments, vouchers…) to compute a financial figure. Those are
either superseded mirrors or operational records — they do **not** reconcile and must never feed a
statement.

## The one ledger = two tables + the chart

| Table | Role |
|---|---|
| `journal_entries` | **Header** — one row per posted event (date, status, source link). |
| `journal_entry_items` | **Lines** — the actual `Dr`/`Cr` legs (`type`, `amount`, `account_id`). The Dr/Cr truth. |
| `accounts` | Names/types/hierarchy each line rolls up into (Assets, Liabilities, Equity, Income, Expense, COGS…). |

One header → many lines (multi-leg entries are normal). The **lines table holds the amounts** a
Trial Balance sums; the header holds **date + status + source**.

## The mandatory rules for any report query

1. **`status = 'posted'` ONLY.** Ignore `draft`, `void`, `reversed`.
2. **Sum from `journal_entry_items`**, joined to `journal_entries` for date/status and to `accounts`
   for classification.
3. **Date filter is on `journal_entries.entry_date`** (the source document's date, not `created_at`).
4. **Project scope:** apply the standard `scopeFilterSqlNullable('project', 'je')` for non-admins
   (see `.claude/security.md` §23).
5. A figure's **sign/normal side** comes from `accounts.normal_balance` / `account_type`.

### Canonical join (copy this shape)
```sql
SELECT a.account_code, a.account_name, jei.type, SUM(jei.amount) AS amount
FROM journal_entry_items jei
JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
JOIN accounts        a  ON a.account_id = jei.account_id
WHERE je.entry_date <= :as_of            -- or BETWEEN :from AND :to
GROUP BY a.account_id, jei.type;
```

## Use the existing report engine — don't re-invent it

All four statutory statements are already built in **`core/financial_reports.php`** and read this
ledger. Call these, don't hand-roll SQL:

| Report | Function |
|---|---|
| Trial Balance | `glTrialBalance($pdo, $asOf, $projectId, $includeOpening, $scopeSql)` |
| Income Statement (P&L) | `glProfitLoss($pdo, $from, $to, $projectId, $scopeSql)` |
| Balance Sheet | `glBalanceSheet($pdo, $asOf, $projectId, $includeOpening, $scopeSql)` |
| Cash Flow | `glCashFlow($pdo, $from, $to, $projectId, $scopeSql)` |
| Guardrail | `assertLedgerBalanced($pdo, $asOf)` — asserts Σ Dr = Σ Cr; run after posting/important reports. |

## Tracing an event back to its source

- `journal_entries.entity_type` + `entity_id` = the source document (e.g. `'supplier_invoice'` + Bill id).
- `journal_entries.parent_entity_type` + `parent_entity_id` = the parent that groups related entries
  (e.g. every part-payment → its parent Bill). *(Being wired in — may be NULL on older rows.)*
- `reverses_entry_id` = the original entry a void/reversal cancels.

**Bottom line:** report = `journal_entries` (posted) ⨝ `journal_entry_items` ⨝ `accounts`. Nothing else.
