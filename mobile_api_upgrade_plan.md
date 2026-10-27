# Smart POS Mobile API Upgrade Plan (Flutter)

**Status:** DRAFT, waiting for approval · **Created:** 2026-10-27
**Goal:** the Flutter app can do everything Smart POS does on the web, superadmin can see app activity, and the shared API doc is current again.
**API doc:** https://claude.ai/artifact/Ca1TnGk31veQuYnND4Xjka (same link, republished; it is v27, dated 2026-10-06).

---

## How every phase is done

1. **Re-scout** the phase's files before coding (the code may have moved since this plan).
2. **Build** in a worktree on one branch, `feat/mobile-api-upgrade`, off `develop`.
3. **Test for real.** Each phase gets a CLI test **and** real HTTP calls with a Bearer token against a throwaway test company: success, refusal, wrong role, wrong shop, old app build (fields missing). Mail is captured, never sent.
4. **Gap check.** Compare the result with the web screen it mirrors. Fix gaps before moving on.
5. **Commit locally**, log `changelog.md`, and update the phase's section in the API doc draft.
6. **Next phase** only after the user OKs this one.
7. At the end: push once, open a PR into `develop`, then republish the API doc as **v28** after merge.

**Rules for all phases:**
- Additive and backwards compatible. Old app builds keep working; new fields are optional.
- Same permission, shop scope and project scope as the web.
- The mobile endpoint reuses the web's logic (`mobileRun()` / core functions). Never a second copy.

---

## Phase 1 — Superadmin sees app activity ("Last Active")

**Gap:** `tenants.last_active_at` is set only by web login (`actions/login.php:61`). Companies that use only the app show **"Never"**.

- `api/mobile/login.php`: set `tenants.last_active_at` on app login.
- `core/mobile_auth.php` (`mobileBearerAuth`): also set it on app use, at most once every 15 minutes per company. App activity then counts, not only logins.
- Tenant view → Users (`tenantUserDirectory()`): show each user's last login **with its source** (Web / App + device name from `mobile_tokens`), and the last app use (`mobile_tokens.last_used_at`).
- **Test:** an app login and an app call each update Last Active; the throttle holds; web login is unchanged; the Users list shows App/Web.

## Phase 2 — Superadmin notifications for company activity

**Gap:** the superadmin bell only knows trial/subscription expiry and Smart BMS requests (`insertSaNotification` allowed types).

New bell types, deduplicated per company per type, via web **or** app:
- `first_login`: the owner signed in for the first time
- `first_product`: first product added
- `first_sale`: first sale made
- `inactive`: no activity for 7 / 14 / 30 days, from a daily check in the existing background job

Bell only (no email), so nothing slows the user down.

- **Test:** each event fires once, from web and from app. The inactive check fires at 7/14/30 and not twice. Existing types are unchanged.

## Phase 3 — Record Old Sale in the app

**Gap:** the server already accepts old sales (`process_sale.php`: `old_sale`, `sold_at`; `old_sale_context.php`), but the doc and `me.php` don't show them.

- `me.php`: add `permissions.pos_past_sales` and `pos_settings.old_sale_max_days` (the POS Settings limit, default 30).
- Check that `old_sale_context.php` and `process_sale.php` (old sale) work with a Bearer token and return proper HTTP codes (403 / 422).
- `get_sales.php` already returns `is_past_entry`. Document it with the badge rule.
- `void_sale.php`: voiding an old sale reverses it in that sale's own shift. Test over HTTP.
- **Test:** an old sale lands on the right day and shift, gets a receipt numbered on that day, and appears in the Z-report. Without the permission → 403. A future date or one beyond N days → 422. Void works.

## Phase 4 — Plans at signup and the shop limit

**Gap:** the app can't offer plans, and doesn't know about the plan's shop limit.

- Document `GET /api/platform/public_offers.php` (plans + prices) and `register.php`'s `plan_key` / `plan_term`.
- `register_status.php` (ready): add the chosen plan to the response.
- `me.php`: add `limits.shops` = `{used, max}` (plan + extra shops bought).
- `warehouses/create` and `update`: already return `422 code: shop_limit_reached`. Test and document it.
- **Test:** register with a plan → the company is on that plan. A bad plan → 422. Shop limit reached → 422 with the code.

## Phase 5 — Subscription in the app

**Gap:** the web has a Subscription page (`api/my_subscription.php`). The app has nothing, and `me.php` has no plan or expiry information.

- `me.php`: add `subscription` = `{plan_name, status: trial|active|grace|expired, ends_at, days_left}` for **every** user, so the app can show a banner. Read-only.
- New `api/mobile/subscription/{status, catalog, documents, request, contracts, contract_sign}`. Each wraps `api/my_subscription.php` through `mobileRun()`. Admin only, same as the web.
- `mobile/notifications/list`: add the 7 / 3 / 1 / 0-day trial and plan reminders (`core/subscription_reminders.php` steps).
- **Test:** each status (trial, active, grace, expired). Non-admin → 403 on actions but still sees the banner. A request creates a pending quotation at BJP (test transport, no real platform call). Reminders appear on the right days.

## Phase 6 — Language follows organization mode

**Gap:** an admin can force one language for everyone (`language_mode = organization`), but `me.php` returns the user's own choice, and `profile/language` saves it as if it applied.

- `me.php`: `language` = the **effective** language; add `language_mode` (`user` | `organization`) and `language_locked`.
- `profile/language`: in organization mode → 409 "Language is set for the whole company".
- **Test:** both modes. Server messages already follow the effective language (`mobileBearerAuth`); check they agree with `me.php`.

## Phase 7 — Product, stock, expense and POS parity

| Item | Change |
|---|---|
| Selling below buying price | Product create/update and `quick_restock` return a non-blocking `warnings[]` (same rule as `assets/js/bms-price-check.js`), so the app can ask "Is this correct?" |
| `products/delete` | Document the new rule: refused only while a shop holds stock; history is kept |
| `expenses/create` | Document `warehouse_id` (the shop's profit). Check the default when it's missing |
| POS product list | Subscription services are hidden; `process_sale` refuses them with 422. Document it |
| Dashboard chart | Document `fill_gaps=1` and the new "Sales, Expenses & Net profit" chart fields |
| Shop wording | Error messages say shop/sell in shop mode. Document: the app shows `message` and never matches text |
| Receipt quantity | Quantities print as sold (1, 1.5). Note it for app receipts |

- **Test:** each row over HTTP; warnings appear and never block.

## Phase 8 — Help & Support in the app

**Gap:** the web Help & Support Center (`api/support_help.php`, `api/support_requests.php`, `api/support_attachment.php`) has no app API.

- New `api/mobile/support/{articles, article, requests, request_create, request_reply, attachment}`. Each wraps the web endpoints via `mobileRun()`, with the same staff → admin → BJP flow and the same notices.
- **Test:** read articles in the company's own words; send a request; reply; attachment limits; staff see only their own requests.

## Phase 9 — "Ask Smart POS" (AI) in the app

**Gap:** the floating AI chat on the web (`api/ai/ask.php`) has no app API.

- `me.php`: add `ai.enabled` (module allowed + key available + user under their limit).
- New `api/mobile/ai/ask`: wraps `api/ai/ask.php`, with the same role and shop limits, caps and "talk to a person" handoff.
- **Test:** off → 404/403; on → answer; cap reached → clear message; answers limited to the user's shops.

## Phase 10 — API doc v28 + full regression

- Republish the doc at the **same link** as v28. Each phase's section goes in, with a changelog entry per phase (before → after) and a "web changes the app should mirror" list (one When field for old sales, folding Sell bar, Actions sheet on phones, new dashboard chart, signup steps).
- Run every mobile test suite together, plus a full HTTP walk-through on a fresh test company, as admin and as a cashier.
- Push with the user's OK, open the PR into `develop`, then verify live on the demo company after deploy.

---

## Decisions (2026-10-27, user: "as is in web based")

The app does what the web does, everywhere:
1. **Superadmin events (Phase 2):** all four (first login, first product, first sale, inactive 7/14/30 days), bell only.
2. **Subscription (Phase 5):** everything the web Subscription page does (status, plans, request, documents, contracts).
3. **AI (Phase 9):** yes, exactly where the web allows it.
4. **Order:** as written, 1 → 10.

## Progress

| Phase | Status |
|---|---|
| 1 Last Active | ✅ done 2026-10-27 — 18 checks (tests/test_mobile_last_active_cli.php) |
| 2 Superadmin bell | ✅ done 2026-10-27 — 31 checks (tests/test_superadmin_activity_alerts_cli.php) |
| 3 Old sale | — |
| 4 Plans + shop limit | — |
| 5 Subscription | — |
| 6 Language mode | — |
| 7 Parity | — |
| 8 Help & Support | — |
| 9 AI chat | — |
| 10 Doc v28 + regression | — |
