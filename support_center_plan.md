# Help & Support Center — Plan

**Status:** APPROVED 2026-10-24 · Phase 1 in progress (branch `feat/support-center`)
**Goal:** Give every tenant one simple, always-available place to (1) learn how to do anything in BMS on their own and (2) reach BJP when they are stuck — and give BJP one inbox to answer every tenant.
**Prototype:** https://claude.ai/artifact/9dEjyHYHZZPGPMxEh6uHxP (built on the verified menus below)

---

## 0. Verified real system (2026-10-24 — live demo shop + header.php; nothing assumed)

| Item | Smart POS (`bms_shop`, verified live) | Smart BMS (`bms_full`, from header.php) |
|---|---|---|
| Signup | Instant. Modules pos + warehouses only; Simple Mode ON + locked; Shop wording ON; supplier access ON | Held for superadmin approval; plan default modules; Simple Mode OFF; Warehouse wording |
| Desktop menu (admin) | My Business ▾ · Expenses · **Sell** · **Shop** ▾ (Shops, Receive Stock) · Reports ▾ (short list) · Subscription · Settings ▾ (Admin, Selling Settings) · EN · user (My Profile, Help, Logout) | Core · Finance · CRM · Sales (… POS …) · Inventory (… Warehouses) · Procurement · Operations · Projects · Comms ("Ask BMS" AI) · Docs · Reports · Subscription · Settings — each only if its module is on |
| Phone | Top bar **EN + ☰** only; fixed bottom bar Dashboard · Products · (Sell) · Expenses · Reports; ☰ side menu: My Business, Subscription, Settings, My Account (Help) | Standard collapsing ☰ menu |
| Menu engine | `bmsNavMenu()`: 0 granted links = hidden, 1 = direct link, 2+ = dropdown | same |
| Permissions | `canView()` checks **module entitlement first** (`tenantModuleAllowsPage`), then role | same |
| Notifications | **No bell in the tenant header** (Notifications page lives under Comms, absent in Smart POS) | same header |
| AI | `ai_assistant` OFF at signup | "Ask BMS" under Comms when configured |
| Default roles | Admin, Managing Director, Staff, Director, CFO, Accountant, Credit Manager, Secretary (no "Cashier") | same |
| Help today | User menu › Help → static `help.php`; offers invoicing/Finance help to shops without them; Email/Call buttons were fake (fixed in Phase 1) | same page |

Consequences built into the decisions below: ? placement per product (D1), reply signal = dot on ? + on-screen message + email, never a bell (D10), articles filtered by a single `canView(page)` per tagged page (D8c/D8e), article steps use `{nav:page_key}` placeholders resolved from the live menu so paths read "Shop › Shops" vs "Inventory › Warehouses" (D8e).

---

## 1. Research summary (why this shape)

| Source | What they do | What we take |
|---|---|---|
| **Xero** | One `?` icon top-right → side panel. Shows articles for the page you are on, search, read the article inside the panel while working. "Contact support" sits at the bottom of the article. | `?` → panel, page-aware articles, read in-panel, contact only after self-help |
| **QuickBooks Online** | `?` Help → Assistant / Search tab → "Contact Us" → chat or callback | Same funnel: help first, contact second |
| **WorkDo (workdo.local)** | Helpdesk tickets go to the platform superadmin; categories with colours; chat-style replies with attachments + **internal notes**; email + in-app templates for "New ticket" / "New reply" | Platform-owned tickets, internal notes, attachments, both-direction notifications |
| **LMS (demo.lms)** | 7-item Support menu (KB, Guides, Videos, FAQs, Tickets, Contact, Status) — but every table lives in the **tenant DB**, no notification to BJP, each tenant writes its own KB | Good grouping idea; **avoid** the tenant-DB storage, the 7 menu items, and per-tenant content |
| Industry guidance | Put help where users get stuck; use page context; mine failed searches to decide what to write next | Page tagging + search log |

---

## 2. Decisions

- **D1 — One entry point.** A single `?` button in the header (desktop: next to the EN pill in both products; Smart POS phone: in the top bar beside EN and ☰ — the 5-item bottom bar is not touched; Smart BMS phone: beside the ☰ toggler; never hidden inside a menu). No dropdown — it opens the Help panel directly. Shows a red dot when a request is waiting on the user's reply.
- **D2 — Help panel (drawer).** Desktop: 420px panel slides in from the right, the page stays usable behind it. Phone: full-screen sheet with a back arrow and a sticky "Contact BJP" button.
  Panel order: *Help for this page* → *Search* → *Popular* → footer *My requests · Contact BJP*.
- **D3 — One full page: "Help & Support"** (`getUrl('help')`, replaces the current static `help.php`). Three tabs: **Help articles · My requests · Contact BJP**. Reached from the panel footer and the existing "Help" item in the user menu.
- **D4 — One content type: Article.** Optional video link, optional "FAQ" flag, category, tagged to one or more page/module keys, English + Swahili body. Replaces the LMS's separate KB / Guides / Videos / FAQs.
- **D5 — Content is written once by BJP** in the superadmin and shown read-only to every tenant instantly (no deploy, no per-tenant copies).
- **D6 — Requests (tickets) live in the control DB**, same pattern as `core/module_requests.php`, so BJP sees all tenants in one inbox.
- **D7 — Contact only after self-help.** Every article ends with 👍/👎 and "Still need help? Contact BJP". The request form pre-fills the subject from the search/article and auto-captures: page URL, module, tenant, plan, user, role, browser, app version.
- **D8 — Who sees what (tenant side).** Every user sees **their own** requests. Tenant admins (`isAdmin()`) also see **all of their company's** requests. Support is always on — not a toggleable module, not plan-restricted, reachable when the tenant is expired/suspended.
- **D8a — Tiered routing (help ladder).** 1) Articles → 2) Ask AI (optional) → 3) **Contact support — staff** (routed to their own company admin, ref `HLP-YYYY-NNNN`, stays in the tenant DB) → 4) **Contact support — admin** (routed to BJP, ref `SUP-YYYY-NNNN`, control DB). Only admins, or roles the admin grants "Contact BJP", write to BJP. Admin can **Escalate to BJP** a staff request with one click (thread copied, staff can follow it). Staff never see BJP phone/WhatsApp.
- **D8a-1 — One neutral label.** Every user sees the same **"Contact support"** button; routing (staff → company admin, admin → BJP) is automatic and never printed on screen. Staff never see the words "admin" or "BJP" in the request flow.
- **D8e — Product-aware help (Smart BMS vs Smart POS).** Reuse the signals BMS already has, never a hard-coded edition check:
  1. **Modules on** — `tenantFeatureEnabled()` (Smart POS = `SMART_POS_MODULES`). Articles are tagged with the module(s)/page keys they need; an Invoices/Quotations/Payroll article never shows in Smart POS.
  2. **Wording** — `core/terminology.php` (`isShopLabel()` / `wLabel()`). Article text uses placeholders (`{warehouse}`, `{warehouses}`, …) resolved by the same rule → "Shop/Duka" in Smart POS, "Warehouse/Ghala" in Smart BMS. One article, never two copies.
  3. **Simple Mode** — `posSimpleModeEnabled()`: an article may carry an alternative shorter step list for Simple POS screens.
  4. **Role** — `canView()` (D8c). Note `canView()` already applies (1) before the role, so one `canView(page)` per tagged page covers both.
  - Article steps reference menus as `{nav:page_key}`; the renderer resolves the real path from the same menu definitions the header uses (e.g. `{nav:warehouses}` → "Shop › Shops" or "Inventory › Warehouses"; `{nav:pos}` → "Sell" or "Sales › POS").
  - Search synonyms (warehouse ↔ shop ↔ store ↔ ghala ↔ duka, invoice ↔ bill, refund ↔ return…) so either word finds the right article, with a one-line note ("In Smart POS, warehouses are called shops").
  - A topic whose module is off answers plainly ("Invoices and quotations aren't part of Smart POS — sales are recorded at the POS"); admins also see "compare plans in Subscription"; staff never see upgrade talk.
  - The AI prompt receives the product name, enabled modules and the resolved terminology, so answers match the user's screens.
- **D8b — Permission key `support_requests`** (Users › Roles): view own (always), create (Ask my admin, default on), view/reply all company (admin), contact BJP (admin; grantable), escalate (with contact BJP). Billing & subscription topics: admin only.
- **D8c — Role-filtered help.** An article shows only if the user can `canView` at least one of its page tags (untagged = general, visible to all). Applies to panel, search, Popular, category counts, "Take me there", and the AI.
- **D8d — AI.** Help works fully without AI. When enabled, "Ask AI" answers how-to from role-filtered articles and simple figures from permission-checked data functions. **Permission filtering happens in BMS code before the AI is called**; restricted topics are refused without calling the AI. Fix found gap: current `core/ai_help.php` / `core/ai_insights.php` check project scope but not page permissions. **AI config moves to Superadmin** (provider, model, encrypted key, plans that include AI, monthly cap per tenant, optional tenant-own-key) — same pattern as the platform email relay; questions are still answered inside each tenant. No domain hard-coded anywhere; links use the tenant's own host.
- **D9 — BJP owns support settings** in Superadmin → Support → Settings: support email, phone, WhatsApp, office hours, SLA targets, who receives new requests, auto-reply text. Tenants cannot edit these.
- **D10 — Notifications, both directions, in-app + email.**
  - Tenant → BJP: new request / tenant reply → superadmin inbox badge + email to support team (platform SMTP).
  - BJP → Tenant: reply / status change → red dot on the ? button + on-screen message on the user's next page load (recorded via `dispatchEvent()` for the Notifications page where it exists) + email **from "BJP Support"** via the platform relay; links point to the **tenant's own domain**.
  - v1: tenants reply inside BMS (email button links to the request); reply-by-email parsing is a later option.
  - Tenant admins can choose, in their Notification Rules, whether they are copied on company requests. They cannot change BJP's sender or contacts.
- **D11 — Status flow:** `open → in_progress → waiting_on_you → resolved → closed`.
  Tenant reply on `waiting_on_you` → back to `in_progress`; tenant reply on `resolved` → reopens; `resolved` auto-closes after 5 days with no reply; tenant may rate (1–5 + comment) on resolve.
- **D12 — Priority + SLA (first response):** urgent 2h · high 4h · normal 1 business day · low 2 business days (editable in settings). Overdue → superadmin alert + email via existing background jobs.
- **D13 — Reference number:** `SUP-YYYY-NNNN` (UI standard §UI-6).
- **D14 — Notices.** BJP posts maintenance/incident notices (all tenants, a plan, or one tenant, with start/end time) → thin banner at the top of every tenant page, dismissible per user. Replaces the LMS "System Status" page.
- **D15 — Search log.** Every search (and zero-result searches especially) is logged per tenant so BJP knows which article to write next.
- **D16 — Colours follow `.claude/ui-constants.md`:** blue primary, blue-scale status badges, `bg-primary` modal/panel headers, Bootstrap Icons, SweetAlert2.

---

## 3. Data model (control DB)

| Table | Key columns |
|---|---|
| `support_categories` | id, name_en, name_sw, icon, sort, is_active |
| `support_articles` | id, category_id, title_en, title_sw, body_en, body_sw, video_url, is_faq, page_keys (JSON), status (draft/published), views, helpful_yes, helpful_no, updated_by, timestamps |
| `support_tickets` | id, ref_no (SUP-YYYY-NNNN), tenant_id, tenant_user_id, user_name, user_email, user_role, subject, category, priority, status, page_url, module_key, plan_code, browser, assigned_to, first_response_at, resolved_at, closed_at, rating, rating_comment, last_activity_at, timestamps |
| `support_ticket_messages` | id, ticket_id, author_type (tenant/bjp/system), author_id, author_name, body, is_internal, created_at |
| `support_attachments` | id, message_id, path, original_name, mime, size |
| `support_canned_replies` | id, title, body, created_by |
| `support_notices` | id, title, body, level (info/maintenance/incident), target (all/plan/tenant), target_value, starts_at, ends_at |
| `support_search_log` | id, tenant_id, query, results_count, created_at |
| platform settings keys | support_email, support_phone, support_whatsapp, support_hours, support_sla_*, support_recipients, support_autoreply |

---

## 4. Phases

1. **Foundation** — control-DB tables (idempotent migration), `core/support.php` helpers, Superadmin → Support → Settings, fix the broken Email/Call buttons in `help.php` to read from settings. *Test:* tables created twice safely; settings save/read.
2. **Articles** ✅ (2026-10-24) — superadmin Support › Articles / Categories / Settings tabs; article editor (EN/SW, Simple Mode steps, video link allow-list, FAQ flag, page tags, draft → published → archived, live preview as Smart POS/BMS × admin/staff). `core/help_nav.php` mirrors the header menu (collapse rules, Simple Mode, Shop wording) so `{nav:page}` resolves to the real path; a drift test fails if header.php gains an unmirrored menu page. Body format is escaped-first plain text (no HTML). **Seed changed:** the user guide is an overview of full BMS, not how-to steps, and does not fit Smart POS — so instead 8 categories + 16 starter how-to articles written against verified button labels are inserted once **as drafts** for BJP to review and publish. *Test:* `tests/test_support_center_phase2_cli.php` (153 checks).
3. **Help panel** ✅ (2026-10-24) — `includes/help_panel.php`: ? button (desktop beside EN; Smart POS phone in the top bar; Smart BMS phone beside ☰) opening a Bootstrap offcanvas (full width on phones) with Help for this page, Popular, search (synonyms + "this company calls them shops" note), in-panel article reader, Take me there, 👍/👎. `api/support_help.php` (POST + CSRF, signed-in only). Help & Support page rebuilt (search, topics, FAQ, article view; admin contact card kept). **Found + fixed:** `help` was granted only to Managing Director and Secretary, so other roles were refused — `canView('help')` is now true for any signed-in user (module check still first) and the switch is hidden from the roles matrix (tenant migration + seed). **Visibility rule tightened:** an article shows only if one of its pages is reachable from the reader's own menu (not merely permitted), so every "Open …" step is clickable. Counters (views, votes, search log) de-duplicated per session. *Test:* `tests/test_support_center_phase3_cli.php` (93 checks). **Not yet browser-checked:** the worktree is not served by local Apache; verify visually after merge. **Reported, not changed:** `my_settings` (My Profile) has the same grant gap — the user menu link shows for everyone but most roles are refused.
4. **Requests — tenant side** — request form in panel (auto-context, attachment ≤5MB, jpg/png/pdf), "Help & Support" page with 3 tabs, My requests list + thread, reply, reopen, rate. Own vs company scope. *Test:* staff sees own only; admin sees company; cross-tenant access denied.
5. **Support inbox — BJP side** — inbox (filter by tenant/status/priority/assignee, SLA badges), ticket view (thread, internal notes, canned replies, assign, status, tenant context sidebar), dashboard counters. *Test:* internal notes never reach tenant APIs.
6. **Notifications + SLA** — new `dispatchEvent` events (`support_reply`, `support_status_changed`), superadmin email + inbox badge, tenant email from BJP with tenant-domain links, overdue reminders + auto-close in background jobs. *Test:* each event fires once; links use tenant host.
7. **Notices + AI + finish** — notice banner, AI assistant searches Articles instead of the markdown guide, login-page "Need help?" link, expired/suspended access, full end-to-end test (tenant creates request → BJP replies → tenant notified → tenant replies → resolve → rate).

---

## 5. Gap checklist

- [ ] Locked-out user: "Need help?" on login page → public contact card (no ticket without login)
- [ ] Expired/suspended tenant can still open Help & Support
- [ ] Support excluded from module entitlement and plan restrictions
- [ ] Staff = own requests; admin = company requests; never another tenant's
- [ ] Internal notes never returned by any tenant endpoint
- [ ] Attachment type/size validation, stored outside webroot, served through an auth-checked endpoint
- [ ] All output escaped; CSRF on every write; activity log on every write
- [ ] English + Swahili via `t()`; article body per language with English fallback
- [ ] Mobile: `?` always visible; panel = full-screen sheet; requests as cards
- [ ] Emails from BJP; links to tenant's own domain
- [ ] Zero-result searches visible to BJP
- [ ] Changelog entry at commit time; tests per phase + one real end-to-end
