# Zoom Video-Conferencing Integration — Plan

> **How this file is used:** written before implementation starts, per project convention
> (see `employee.md`, `pos_upgrade_plan.md`, `company_code_prefix_plan.md` for the same
> pattern). Updated as phases complete; not a static spec.

## 1. Objective

Add real Zoom meeting capability **inside the existing `meetings.php` module** — not a
parallel standalone page — using **Zoom Server-to-Server OAuth**, the mechanism Zoom
itself recommends for a single-company internal system (confirmed against how WorkDo/ERPGo
implements the same feature, and against Zoom's own auth-method guidance: JWT is dead
since 2023, OAuth Authorization Code is for multi-tenant marketplace apps, Server-to-Server
OAuth is for exactly this case — one company, one Zoom account, staff scheduling meetings
without each connecting a personal Zoom login).

**Explicitly out of scope for v1** (matches WorkDo's own scope, not a corner cut):
recurring meetings, Zoom webhooks (auto-detecting start/end). Flagged as v2 stretch only.

## 2. What already exists today (verified by reading the code, not assumed)

| Piece | File | Notes |
|---|---|---|
| Meetings list/create page | `app/bms/pos/meetings.php` | Its own comment: "Minimal: schedule + attendees + minutes + status. No rooms/recurrence/video." — the gap this plan closes. |
| Meetings CRUD API | `api/manage_meeting.php` | Single endpoint, `action` param: add/update/mark_attendance/complete/cancel/delete. Notifies attendees via `core/notify.php` already. |
| Meetings read API | `api/get_meetings.php` | List (+stats) and single-record+attendees. `scope-audit: skip` — meetings are company-wide by design, no `project_id`. |
| Schema | `migrations/2026_07_04_hr_talent_foundation.php` | `meetings` (meeting_id, title, agenda, meeting_date, start_time, end_time, venue, minutes, status enum, created_by/updated_by) + `meeting_attendees` (meeting_id, employee_id, attended). |
| Secret-at-rest encryption | `core/crypto.php` | `encryptSecret()`/`decryptSecret()` — AES-256-GCM, per-environment app secret in `includes/ai_app_secret.php` (gitignored). Generic despite the filename — reuse as-is for the Zoom Client Secret, no new crypto needed. |
| Settings page template to mirror | `app/constant/settings/ai_settings.php` + `api/ai/save_ai_settings.php` + `api/ai/test_ai_config.php` | Exact pattern to copy: enable toggle, encrypted-secret field that never redisplays ("leave blank to keep"), Save + Test Connection buttons, `system_settings` key/value upsert with `setting_group`. |
| Settings storage | `system_settings` table | Key/value, no schema change needed — `INSERT ... ON DUPLICATE KEY UPDATE` via `setting_key/setting_value/setting_group/is_public`. Read via `getSetting($key, $default)` (cached per-request). |
| External HTTP call convention | `core/ai_service.php` (`_aiHttp()`) | cURL, `includes/cacert.pem` CA bundle (Windows/WAMP quirk — curl.cainfo often unset/wrong), 45s timeout, structured `{ok, error}` / `{ok, text}` return shape. Mirror this shape in `core/zoom_service.php`. |

## 3. Phased plan

**Status (2026-07-24):** Phases 1–6 built and tested on branch
`feat/zoom-meetings-integration` ([PR #1536](https://github.com/wamburamuhere-afk/BMS/pull/1536)
into `develop`) — 89 new CLI assertions against a mocked Zoom HTTP layer, no regressions in
the existing meetings/trips/HR-talent suites. Phase 7 (live verification) is blocked on a
real Zoom Server-to-Server app (Phase 0.1) and Phase 8 (final PR/merge) follows once Phase 7
is done.


### Phase 0 — Decisions (no code)
- 0.1 Real Zoom Server-to-Server app (Account ID/Client ID/Client Secret) — **gates Phase 7 only**, not Phases 1–6.
- 0.2 Zoom "host" = the email of the BMS user selected as host; must be a real user in the connected Zoom account. Resolved naturally (no separate mapping table) — if invalid, Zoom's API rejects it and Phase 4.3's graceful-degradation path surfaces that clearly.
- 0.3 No recurrence, no webhooks in v1 (confirmed above).
- 0.4 **Hard requirement:** editing a Zoom meeting's time or cancelling it must call Zoom's Update/Delete API too, not just update the local row — otherwise the Zoom-side meeting silently drifts out of sync with what BMS shows.

### Phase 1 — Settings & Credential Storage ✅ DONE
- Admin-only "Zoom Integration" panel (new file, mirroring `ai_settings.php` exactly): Account ID / Client ID / Client Secret (encrypted, never redisplayed) / Enable toggle / Test Connection.
- `system_settings` rows: `zoom_enabled`, `zoom_account_id`, `zoom_client_id`, `zoom_client_secret_enc` — no schema migration, same upsert pattern as `save_ai_settings.php`.
- Gate: `php -l` clean; encrypted round-trip test; non-admin blocked (403); Test Connection correctly fails on fake credentials before it ever needs to succeed.

### Phase 2 — Core Service Layer (`core/zoom_service.php`) ✅ DONE
- `zoomGetAccessToken()` — S2S OAuth token, cached with expiry (~1hr), auto-refreshes.
- `zoomCreateMeeting($data)` / `zoomUpdateMeeting($zoomId, $data)` / `zoomDeleteMeeting($zoomId)`.
- Uniform `{success, message, data}` return shape from every function — a Zoom failure is data, never an uncaught exception.
- Gate: CLI test with **mocked HTTP responses** (no real Zoom account required) — token caching behaves, request payload built correctly, error path shape is uniform.

### Phase 3 — Data Model ✅ DONE
- Additive migration to `meetings` (no new table): `meeting_type` enum('in_person','zoom') default 'in_person', `host_user_id`, `zoom_meeting_id`, `zoom_join_url`, `zoom_start_url`, `zoom_password`, `zoom_host_video`, `zoom_participant_video`, `zoom_waiting_room`, `zoom_auto_recording`, `zoom_sync_status` enum('pending','synced','failed').
- `venue` untouched, used only for `meeting_type='in_person'`.
- Gate: idempotent (`SHOW COLUMNS` guard per column), existing rows unaffected.

### Phase 4 — Backend (`api/manage_meeting.php` extended) ✅ DONE
- `add`/`update`: when `meeting_type='zoom'`, call Phase 2 after the local write.
- `cancel`: call `zoomDeleteMeeting()` first (Phase 0.4).
- **4.3 Graceful degradation:** Zoom API failure never blocks the local save — `zoom_sync_status='failed'`, clear message, Retry action. Never silent.
- CSRF/permission/`logActivity`+`logAudit` on every Zoom-touching action (external system + real calendars — worth the audit trail), same `meetings` page_key as today (unchanged).
- Gate: CLI test against Phase 2's mocks — success path populates fields; failure path still saves locally with `failed` status and a working Retry.

### Phase 5 — UI (`meetings.php` extended, not replaced) ✅ DONE
- In-Person/Zoom switch in the existing modal; Zoom mode swaps Venue for Host/Password/video-setting toggles.
- List + View modal show Join URL to attendees; **Start URL only to the host/creator** (Zoom's own convention — it carries host privileges).
- Visible "Zoom sync failed — Retry" state.
- Zoom option disabled (with tooltip → Settings) when the integration isn't enabled.
- Gate: full manual run-through (create in-person — regression check; create Zoom against the mock; view/edit/cancel; disabled state).

### Phase 6 — Notifications ✅ DONE
- Reuse `core/notify.php`'s `dispatchEvent()` (same engine behind HR contract-expiry alerts) — attendees get the join link on create/reschedule.
- New `notification_events` seed row via migration, same seeding pattern used twice already this week.
- Gate: CLI test — fires once per attendee on create, not duplicated on a no-op re-save (existing dedupe mechanism).
- **Implementation note:** `dispatchEvent()`'s `resolveRecipients()` targets "everyone with
  a given page-key permission," not an arbitrary attendee list — using it as written would
  have broadcast the Zoom join link to every user who can view Meetings, not just the
  invited attendees. Kept the existing attendee-targeted `notifyMeetingAttendees()` (already
  correct) and instead registered `hr_meeting` into `notification_events` so it gets the
  same per-user mute-preference check `dispatchEvent()` would have applied, without the
  broadcast side effect.

### Phase 7 — Live Verification (gated on 0.1 — real Zoom credentials) ⏳ PENDING
- Real Test Connection success; create/edit/cancel a real meeting; confirm the Zoom side actually reflects each change (not just the BMS row); confirm Phase 4.3's failure path against a real broken-credential case, not just the mock.
- **Cannot be simulated — the only phase that needs your live Zoom account.**

### Phase 8 — Docs, Changelog, Release ⏳ PENDING
- Changelog entry; dedicated branch off `main`; PR; CI green; merge — same one-change-per-branch discipline as every other item this week.

## 4. Execution note

Phases 1–6 run back-to-back with their own test gate shown at each step, no re-asking in
between. Phase 7 pauses for real Zoom credentials if not yet available when reached —
everything through Phase 6 is still fully built and mock-tested regardless.
