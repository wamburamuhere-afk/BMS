# BMS Smart Notification & Workflow Engine — Implementation Plan & Tracker

**Goal:** an internal, role-aware notification engine. Each business event is routed by email
**and** the dashboard to the *specific people responsible for that area* — verified against their
actual permissions (and project scope) — configured by admins per event. Email-only now; built so
WhatsApp/SMS drop in later with zero rework. Optional AI layer for digests/prioritization/anomalies.

**Constraints (from owner):** internal staff only (NO customer login); email channel only for now
(WhatsApp/SMS later); never dump all notifications to one mailbox — route by role/specific user and
**only to users who actually have access to that area** (e.g. HR events → HR only).

---

## Architecture
```
[Source action / Scheduler] → dispatchEvent(eventKey, ctx)
   → notification_rules (admin: event → role/user → channels)
   → resolveRecipients = rule targets ∩ usersWithPermission(page_key,verb) ∩ project-scope
   → channels: [In-App] [Email]  (+ future WhatsApp/SMS)
   → outbox (queue, dedupe) → log (audit) → dashboard "requires your attention" + bell + email
   ↑ optional AI: digest, prioritize, anomaly events, draft text
```
**Guarantees:** decoupled • channel-abstracted • permission+scope verified (no leaks) •
kill-switch + idempotent • fully logged • backward-compatible.

---

## VERIFIED INVENTORY (scout complete — facts, not assumptions)

### EXISTS → reuse
- `notifications` table: `notification_id, user_id, title, message, type ENUM('loan','payment','system','report','alert'), priority ENUM('low','medium','high'), is_read, loan_id, customer_id, document_id, action_url, created_at, read_at, project_id`
- Notification UI/APIs: `notification_center.php`, `notification_settings.php`, `api/get_notifications.php`, `mark_notification_read.php`, `delete_notification.php`, `notification_bulk_actions.php`, `save_notification_preferences.php`
- Per-user prefs: `users.notification_preferences` (TEXT/JSON)
- **RBAC routing pattern**: `cron/check_document_expiry.php` resolves recipients = admins + users whose role has `can_view` on a permission `page_key` (the exact rule we want)
- Permission model: `users(role_id,is_admin,is_active,email,department_id)` → `roles(role_id,is_admin)` → `role_permissions(can_view/create/edit/delete/review/approve)` → `permissions(page_key,module_name)`
- Dedupe pattern: `document_expiry_reminders(document_id,milestone,sent_at)` + `INSERT IGNORE`
- Scheduler: `cron/` dir + **header.php throttle** (each job ≤1×/day via `get_setting('X_last_run')`), also `php cron/X.php`. No OS-cron dependency.
- Settings: `system_settings(setting_key,setting_value,setting_group)` + `get_setting()/save_setting()`
- Existing settings keys: `smtp_host, smtp_port, from_email, company_email, enable_email_notifications, enable_sms_notifications, notification_*`
- `email_templates(template_name,template_type,subject,content,is_active)`
- Dashboard `dashboard.php` already computes: low/negative stock, overdue, expiring, doc_expiring, cash_shift_open, bank_recon_overdue, leave_pending, payroll_due, quote_expiring, tender_deadline, grn_pending, credit_over, approvals

### MISSING → build
- **Real email sending** — `test_email_config.php` is a SIMULATION; no PHPMailer/composer/vendor (only `includes/cacert.pem`)
- Central `sendEmail()` and `createNotification()` helpers
- `dispatchEvent()` bus, `usersWithPermission()` reusable resolver
- Admin "event → role/user → channel" config (only global toggles exist)
- Outbox/queue + delivery log
- Missing settings keys: `smtp_username, smtp_password, smtp_encryption, from_name, notif_master_enabled`

### DB changes needed
1. `notifications`: add `event_key VARCHAR(80) NULL`, `category VARCHAR(40) NULL` (+ indexes) — don't fight the `type` ENUM.
2. New: `notification_events`, `notification_rules`, `notification_outbox`, `notification_log`, `notification_reminders`.
3. `system_settings`: add `smtp_username, smtp_password, smtp_encryption, from_name, notif_master_enabled`.
4. `permissions`: add page_keys for notification admin + per-module alert audiences as needed.

---

## PHASES

- [ ] **Phase 0 — Lock specs**: freeze Event Catalog (13 dashboard events + status events) + DB change list.
- [x] **Phase 1 — Mailer foundation (DONE)**
  - [x] 1.1 PHPMailer v6.9.1 vendored into `includes/PHPMailer/` (Exception/PHPMailer/SMTP — no composer)
  - [x] 1.2 `core/mailer.php` → `sendEmail($to,$subject,$html,$opts)` + `mailer_last_error()`; SMTP from settings (per-call override supported); TLS via `includes/cacert.pem`; fail-silent + log when unconfigured
  - [x] 1.3 Settings already exist in `system_settings.php` (host/port/username/password/encryption/from_email/from_name); `api/test_email_config.php` now **actually sends** (simulation removed), with POSTed-config override + saved-password fallback
  - [x] 1.4 Branded HTML wrapper `bms_email_wrap()` (inline-CSS, asset-free)
  - [x] 1.5 Tested: lint clean (5 files); 9/9 runtime (PHPMailer loads/instantiates, wrapper renders, fail-silent returns clear error). NOTE: real end-to-end send needs SMTP username/password saved in Settings > Email (this dev DB has none) — verify via the Test Email button.
- [x] **Phase 2 — Core helpers (DONE)**
  - [x] Migration `migrations/2026_06_28_notification_engine_foundation.php`: `notifications.event_key/category` + tables `notification_events` (13 seeded), `notification_dedupe`, `notification_log` + `notif_master_enabled` setting
  - [x] `core/notify.php`: `usersWithPermission()`, `createNotification()`, `notifClaimDedupe()`, `notifLog()`, `resolveRecipients()` (permission-only for now), `dispatchEvent()` (in-app + audit, fail-safe)
  - [x] Tested 6/6: RBAC resolve (4 users), dispatch creates in-app + log, idempotent re-dispatch (0 created), unknown event safe-skips. Migration idempotent.
- [x] **Phase 3 — Recipient resolution (DONE)**
  - [x] `usersWithPermission()` now returns an `is_admin` flag per user
  - [x] `resolveRecipients()` adds **project-scope filtering** (scope-aware event + `project_id` → admins + `user_projects` members only) and **per-user mute** (`notifUserMuted()` honoring `notifications_enabled`/`muted_events`/`muted_categories`, backward-compatible)
  - [x] Tested 12/12: mute logic (5), is_admin flag, no-scope == all, scope filter actually drops non-assigned non-admins (4→1), mute excludes a real user (4→3) with prefs backed-up/restored
  - [ ] `previewRecipients()` for the admin UI → deferred to Phase 5 (where it's consumed)
- [x] **Phase 4 — Channels & delivery (DONE)**
  - [x] Migration `2026_06_28_notification_outbox.php` — `notification_outbox` queue (status/attempts/dedupe/scheduled_for)
  - [x] `dispatchEvent()` now ALSO enqueues email per recipient (gated by `enable_email_notifications`), with an "Open in BMS" action button; separate in-app vs email dedupe
  - [x] `enqueueEmail()` (dedupe via unique key) + `processNotificationOutbox()` worker (retry/backoff, give-up at max_attempts, logged) + `cron/process_notifications.php` runner
  - [x] Email link uses configurable `app_url` (cron-safe), falls back to `buildUrl()` in web
  - [x] Tested 7/7: dispatch enqueued 4 emails, dedupe (1st/2nd), worker processed queue + requeued on SMTP failure (attempts=1, error captured), setting restored
  - [ ] Digest batching (group many items into one email) → deferred (immediate per-event for v1; revisit with Phase 9 AI digest)
  - [ ] WhatsApp/SMS channels → deferred (worker has the `channel` switch ready)
- [x] **Phase 5 — Routing rules + Admin UI (DONE)**
  - [x] 5a Engine: migration `2026_06_28_notification_rules.php` (`notification_rules` + `notification_rules` page_key); `resolveRecipients()` applies rules (target = permission|role|user) and sets per-recipient channels; `dispatchEvent()` uses per-recipient channels; `previewRecipients()` added; fixed a `$base`/URL-base variable collision in the dispatch loop
  - [x] 5a Tested 12/12: rule narrowing (role/user/permission), **safety: rule to no-access user → nobody**, per-rule channels, cross-entity no-collision regression, idempotent, preview (saved + override rules)
  - [x] 5b Admin page `app/constant/settings/notification_rules.php` (accordion by module; per-event rule chips; Add Target modal with role/user Select2; event on/off; global master/email switches) + `api/notifications/rules_api.php` (list/save/delete/toggle_event/set_global/preview/test_send); route in `roots.php` + menu link in `header.php`; UI standard applied
  - [x] 5b Tested: lint clean (API+page+roots+header); save→preview→delete data-flow 5/5 (user-rule narrows preview to 1, delete restores all)
- [x] **Phase 6 — Scheduler (DONE)**
  - [x] `cron/run_notification_checks.php` — time-based checks (invoice.overdue implemented; extensible per-check) emitting via `dispatchEvent`, deduped once/day per record
  - [x] `header.php` wiring: run checks once/day + drain the email outbox (throttled ~2 min, fail-silent); both also runnable via server cron
  - [x] Tested 3/3: scanned 5 overdue invoices → 11 in-app (per-invoice scope filtering proven), 2nd run idempotent (0 new), cleaned up
- [~] **Phase 7 — Emit at source actions (mechanism done; representative emits wired)**
  - [x] Pattern established: one fail-safe `dispatchEvent()` after the successful write, behind the kill-switch
  - [x] `save_purchase_order.php` (create) → `po.needs_approval`; `save_invoice.php` (create) → `invoice.needs_review`. Lint clean; both events resolve recipients (4) + dispatch.
  - [ ] Remaining emit points (same one-liner): GRN approved, quotation/SO submitted, returns/notes pending, voucher needs-approval, expense needs-review, low-stock — add per endpoint as desired
- [x] **Phase 8 — Dashboard + bell unification (DONE)**
  - [x] Bell already unified — engine writes to the `notifications` table that `api/get_notifications.php` reads per user
  - [x] `dashboard.php` "System requires your attention" now also surfaces the engine's per-user unread **action** notifications (via `get_system_alerts`), excluding event types the inline alerts already compute (no double-count); title+message render branches added
  - [x] Tested 2/2: lint clean; engine query includes action items, excludes `invoice.overdue` (dedup)
- [ ] **Phase 9 — AI smart layer (optional, after core)**: digest, priority scoring, anomaly events, drafted text; setting + fallback
- [ ] **Phase 10 — Hardening, tests, rollout**: no-leak/integration/idempotency/perf/security; master switch default-off; changelog; staged rollout

---

## Decisions (resolved by scout)
- Scheduler = existing header.php throttle + `cron/` (no OS-cron dependency).
- Mailer = vendor PHPMailer if obtainable, else self-contained SMTP client in `includes/` (same `sendEmail()` signature either way).
- Notifications table extended via `event_key`/`category` columns (not ENUM surgery).
- Routing generalizes the proven `check_document_expiry.php` RBAC query.
- AI layer deferred to Phase 9 (after the core engine is solid).
