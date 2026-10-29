# Customer Visits — repeated follow-ups, View details, reminders

Approved by the product owner 2026-10-28. Extends `customer_visits_ux_plan.md` (3.1 gave
each visit ONE follow-up date). Same access rule as the rest of the module
(`core/field_reports.php`): staff see and change only their own visits; admins see
everyone's (another staff member's day only once submitted) and may change them.

## Decisions

| # | Decision |
|---|---|
| D1 | A visit can be followed up **any number of times**. Each follow-up is a row in `field_visit_follow_ups`: due date, purpose, status (pending / done / cancelled), how it was done (visit / call / WhatsApp / SMS / other), what happened, done by/at, the new visit it produced, created by/at. |
| D2 | **One pending follow-up per visit at a time.** Marking it done can set the next date in the same step, so the chain is unlimited and every step is kept. |
| D3 | `field_visits.follow_up_date / follow_up_done_at / follow_up_done_by` stay, as a **mirror of the visit's current follow-up** (pending one, else the last done one), written only by `frSyncVisitFollowUp()`. Reports, print and Excel keep reading them unchanged. |
| D4 | The form's "Follow up on" field drives the pending follow-up: new date → add (or move the pending one); cleared → cancel the pending one; same date → nothing (a done follow-up stays done). |
| D5 | **New visit** from a follow-up closes it: method "visit", outcome "New visit recorded", linked to the new visit — in the same transaction as the new visit. |
| D6 | **View details** (`field_reports/details?id=`) — first item in the row menu, first button on the phone card. Shows every field of the row plus record info, the follow-up timeline for **this customer** (all visits with the same phone, within the viewer's scope), and the customer's other visits. Owner or admin (submitted-day rule) only. |
| D7 | Reminders: event `field_visit.follow_up_due`, **2 days before, 1 day before, on the day, and once the day after** if still not done; then it stops. Once per stage per follow-up (a rescheduled date starts fresh). Not for joined clients or deleted visits. |
| D8 | Notification Rules gains the target **"The person who recorded it"** (`record_owner`): resolved per record at send time, so each staff member gets only their own customers. Seeded rules: record owner (in-app + email) and each admin role (in-app + email). Editable on Notification Rules. |
| D9 | Privacy for this event: non-admin recipients are always limited to that visit's own staff (and whoever set the follow-up), whatever rules say. An admin gets it only when the admin may open that visit (own, or the day was submitted). |
| D10 | Overdue after the "day after" email: shown only in the page's red "To follow up" list. |

## Phases

1. **Schema** — `field_visit_follow_ups` in `fieldReportsEnsureSchema()`; one-time backfill of existing follow-up dates (keeps done/not done); event + seeded rules; tenant migration + legacy-DB mirror; tenant template rebuilt.
2. **Backend** — `core/field_visit_followups.php` (add / reschedule / cancel / complete / reopen / sync / customer timeline / due list); `api/field_reports/followup_save.php` (add, reschedule, cancel), `followup_done.php` (complete with method, outcome, optional next date; old `visit_id` + `done=1|0` calls keep working), `detail.php`; `save.php` (D4, D5); `delete.php` cancels pending follow-ups.
3. **UI** — details page; "View details" first in the menu + phone card; shared follow-up modals (mark done / add / reschedule) used by both pages; due list "Followed up" opens the mark-done form; list page opens Edit from `?edit=`.
4. **Reminders** — `record_owner` target (resolveRecipients, rules API, rules page, preview); privacy ctx (D9); daily check block in `cron/run_notification_checks.php` (D7).
5. **Re-scout + tests** — every changed part + an end-to-end run: visit → follow-up → done + next → 2/1/0/-1 reminders to the right people (outbox rows only, nothing sent); existing suites stay green; browser check.
