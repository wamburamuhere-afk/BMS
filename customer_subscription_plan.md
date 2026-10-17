# Plan: Service Price Terms + BMS Customer Subscriptions

**Status:** ALL PHASES (0–8) BUILT AND TESTED, 2026-10-16. Approved by the owner on 2026-10-15. Awaiting PR review + the operator steps below.
**Branch:** `feat/customer-subscription` (worktree `../bms-subscription`), PR into `develop`
**Source:** Boss's suggestions plus the owner's corrections (conversation of 2026-10-15)

---

## 0. What we are building

1. **Price terms on services.** This is optional. A service is registered exactly as today. If price terms are filled in (e.g. 1 Month = 10,000, 3 Months = 25,000), the selling price becomes "choose a term" instead of one fixed price. Nothing is hardcoded: the user names each term and sets its length, price and description.
2. **Quotation / Sales Order / Invoice:** when a line uses a service that has terms, the user picks a term and the price comes from it.
3. **Service Details** shows the price terms. The material-components table is **hidden** when the service has no components.
4. **Customer Visits → real customer.** Marking a visit as "Joined" also captures the client's **subdomain**. A "Make real customer" action adds them to Customers and links them to their tenant. Visits are never turned into customers automatically.
5. **Payment bridge:** when a BJP invoice is fully paid, superadmin extends the linked tenant's access **automatically**, by the term on the invoice line.
6. **"My Subscription" inside the customer's tenant:** shows their remaining time, lets them choose a term (a quotation is created already approved), and lists their quotations, invoices and contracts.
7. **Contracts after payment**, signed electronically.
8. **Most Profitable Products report.**

## 1. Rules every phase keeps

| # | Rule |
|---|---|
| R1 | The Quotation workflow (pending → reviewed → approved) and the Invoice workflow **do not change**. They are role-based; nothing is hardcoded. We only add term selection on lines. |
| R2 | Simple POS, Shop Mode, the simple customer form, the short service form (`$simpleServiceForm`) and the default data a new company gets at registration **do not change**. |
| R3 | Platform-vendor features (subscription flag, subdomain on "joined", payment bridge, request processing) work **only on `bms.bjptechnologies.co.tz`** (root, resolver status `none`), the same rule `register.php` follows. Everywhere else they are a no-op. |
| R4 | No tenant ever writes to BJP's database. Writes to BJP's DB happen in BJP's own context. |
| R5 | The tenant is identified by the server (`bmsCurrentTenantId()`), **never** by a form field or URL. |
| R6 | One invoice extends access **once** (UNIQUE key), only from real payments, counted from the end of the current period. |
| R7 | `products.selling_price` is never left empty. A service with terms stores its default term's price there, so POS, reports and lists that read `selling_price` keep working. |
| R8 | UI follows `.claude/ui-constants.md`: white background with blue as primary; DataTable starting with S/NO on desktop and card view on phones; one gear+caret Actions menu; Select2; SweetAlert2; Bootstrap Icons. |
| R9 | Each phase (or two small phases together) is tested until there is no bug or gap, then committed. At the end the whole set is re-run, then pushed and a PR opened into `develop`. |

## 2. Vendor context

`core/vendor_context.php` → `isPlatformVendorContext()` is true only when multi-tenancy is on, no tenant is resolved, and the request host is exactly `TENANT_BASE_DOMAIN` (or `www.` of it). CLI workers opt in with `BMS_VENDOR_CONTEXT=1`. Installs without multi-tenancy return false.

---

## PHASE 0: Foundations and safety ✅ DONE

1. ✅ `core/vendor_context.php` + tests.
2. ✅ Control DB (`scripts/setup_control_db.php`, idempotent, run by an operator): `tenants.vendor_customer_id` (+ index); `tenant_payments.source` / `source_ref` + `UNIQUE(source, source_ref)`.
3. ✅ `applySubscriptionPayment()` in `core/tenant_lifecycle_policy.php` is the single payment path. `actions/superadmin_record_payment.php` uses it. This fixes two bugs:
   - a trial tenant who paid stayed `trial` and was suspended when the trial ended → now becomes `active`;
   - paying early restarted the period from today and lost the remaining days → now counts from the later of the chosen date and the current end date (trial days left are kept).
   The Record Payment modal's preview uses the same rule.
4. ✅ **Migration Health badge fix.** `tenantMigrationHealth()` counted every `failed` row ever logged, so a migration that failed once (`2026_10_13_sales_documents_money_and_line_discount.php`, a concurrent-deploy duplicate-column race, fixed by `6fe14fbb`) showed "1 failed" forever, even after it succeeded on a re-run. It now judges each migration by its **latest** event, as `dashboard.php` already did. A resolved failure is shown as a grey note.
5. Tests: `tests/test_tenant_migration_health_cli.php` (11), `tests/test_subscription_payment_cli.php` (33).

**Operator steps (owner):** run `php scripts/setup_control_db.php` on bms and demo. Until then, manual payments keep working and only automatic invoice payments are refused, with a clear message.

---

## PHASE 1: Service price terms ✅ DONE

**Database** (`migrations/tenant/` + `_legacy_db` mirror):
```
service_price_terms
  term_id, product_id, term_label VARCHAR(100), duration_value INT,
  duration_unit ENUM('day','week','month','year'), price DECIMAL(15,2),
  description TEXT NULL, sort_order INT, is_default TINYINT(1),
  status ENUM('active','inactive','deleted'), created_by/at, updated_by/at
vendor_subscription_services (product_id PK, created_by, created_at)   -- vendor only
```
No new columns are added to `products`.

**Services page (full form only, Add + Edit):** a new optional section, "Price by term (optional)", with rows of Term name · Length (number + unit) · Price · Description, plus add, remove, and a default marker. When at least one term exists, the Selling Price field shows "Depends on term" (read-only, holding the default term's price, per R7). A "This service is a system subscription (extends a tenant's access)" checkbox is shown only in vendor context; when it is ticked, every term must use months or years. The short Simple POS form is unchanged.

**APIs:** `create_nip_product.php` / `update_nip_product.php` accept `price_terms` (JSON) inside the same transaction. A term already used on a document becomes `inactive` rather than deleted. A request without `price_terms` leaves existing terms untouched. New endpoint `api/account/get_service_price_terms.php`; `get_products.php` adds `has_price_terms`.

**Lists and details:** the Services list shows "From 10,000 · 4 terms". `service_view.php` gets a "Price by term" card, and the components table renders only when the service actually has components.

## PHASE 2: Term on Quotation / Sales Order / Invoice lines ✅ DONE

The same nullable columns are added to `quotation_items`, `sales_order_items` and `invoice_items`: `price_term_id`, `price_term_label`, `term_duration_value`, `term_duration_unit`. They must be added to **all three together**, because `convert_quote_to_order.php` copies every quotation-line column into `sales_order_items`. Values are stored as a snapshot, filled by the server from the DB and never trusted from the client.

Forms (`quotation_form.php`, sales order create/edit, invoice create/edit): a Term select appears for a service with terms; choosing one fills `unit_price` and appends "(Term: 6 Months)" to the line description, so existing prints show it without template changes. Editing reloads the stored term. An invoice raised from a sales order takes the term from the order line. `save_quotation.php`, `save_sales_order.php` and `save_invoice.php` store and validate the term (it must belong to that product and be active). Statuses and workflows are untouched (R1). Older and mobile clients that send no term keep working.

## PHASE 3: Customer Visits → real customer + tenant link ✅ DONE

`field_visits` gets `tenant_id`, `tenant_subdomain`, `customer_id`. In vendor context, "Mark as joined" opens a modal with an optional subdomain field, checked against the control DB. For a joined visit with a tenant, "Make real customer" either reuses the customer already linked to that tenant or offers a phone/email match, or else creates a customer through the normal path (`nextCode('CUST')`), prefilled from the visit and the tenant's registration. It then sets `field_visits.customer_id` and `tenants.vendor_customer_id`. Superadmin's tenant view gets a "BJP customer" panel to link or unlink self-registered tenants. Outside vendor context, "Mark as joined" is exactly as today.

## PHASE 4: Payment bridge ✅ DONE

`record_payment.php`, `save_receipt.php` and `apply_customer_advance.php` call `vendorOnInvoiceSettled()` after commit. `update_invoice_status.php` (marking paid without money) does **not**. `void_payment.php` raises an alert only and never shortens access. Rules:
- vendor context only;
- `balance_due` must be 0;
- months = Σ quantity × term length (a year counts as 12) over subscription-service lines;
- a subscription line without a term → alert;
- tenant = `vendor_customer_id` match (none or several → alert);
- then `applySubscriptionPayment(..., 'vendor_invoice', invoice_number)`;
- if the control DB is down, the event goes to an outbox and a cron retries it.

Superadmin shows the payment source and the open alerts.

## PHASE 5: "My Subscription" inside the customer's tenant ✅ DONE

Admin-only page on tenant subdomains:
- **Status:** read from the control DB, for this tenant only.
- **Pay / extend:** the subscription services and their terms (read through a read-only MySQL user on BJP's DB). Choosing one writes a `subscription_requests` row in the control DB, then an HMAC-signed call to the root host creates an **approved** quotation in BJP's books, with a cron as safety net. Only one open request per tenant at a time.
- **Documents:** that customer's quotations and invoices; the PDF opens through a short-lived signed link to the existing print page.
- **Contracts:** added in Phase 6.

## PHASE 6: Contracts after payment ✅ DONE

`customer_contracts` in BJP's DB, built from templates via `document_merge.php`. A draft is created when a subscription invoice is paid. A BJP signer signs (role-based), then the existing `request_external_signature.php` flow sends it to the customer. Signed PDFs are locked and their hash recorded. The contracts also appear in My Subscription.

## PHASE 7: Most Profitable Products ✅ DONE

Extend `product_analysis` with cost, profit and margin, sorted by profit. Cost uses the same basis as `postInvoiceCOGS` / POS COGS. A reconciliation line compares the totals against `glProfitLoss`. Project and warehouse scope apply (§23), and the report is also shown in the Simple POS report list.

## PHASE 8: Final end-to-end and regression ✅ DONE

1. The full story on demo: visit → joined + subdomain → customer → My Subscription request → approved quote → invoice → payment → +months → contract → signed.
2. Shop-tenant regression: Simple POS and Shop Mode unchanged.
3. Tenant isolation suites pass.
4. Changelog, docs and memory updated.

## Decisions taken (change any you disagree with)

| # | Decision |
|---|---|
| D1 | ~~Linked by superadmin~~ → **changed:** linked from BJP's side (Customer Details → Platform company), because the superadmin panel never opens BJP's own database. Superadmin shows who pays (stored label) and can remove a link. |
| D2 | After a self-service quotation is auto-approved, **BJP staff create the invoice** through the normal flow. |
| D3 | ~~Read-only MySQL user~~ → **changed:** a signed server-to-server gateway on the platform root (HMAC with a key derived from `TENANT_CRED_KEY`); no extra database user. Documents open as a read-only customer copy via a 30-minute signed link. |
| D4 | The superadmin payment bugs are fixed first (done in Phase 0). |
| D5 | Web forms require a term for a service that has terms; APIs still accept none. |
| D6 | A voided payment raises an alert only; access is never shortened automatically. |

## Operator steps before go-live (owner)

1. On **bms** and **demo**: `php scripts/setup_control_db.php` (adds `tenants.vendor_customer_id/_label`, `tenant_payments.source/source_ref`, `vendor_bridge_alerts`). Until then manual payments keep working; automatic ones wait.
2. Schedule `https://bms.bjptechnologies.co.tz/api/cron/vendor_bridge_retry.php` (Bearer `CRON_SECRET`, e.g. every 15 minutes) — a safety net; payments normally apply at once.
3. In BJP's own system: create the subscription service(s) with price terms, tick "BMS system subscription", choose a contract template (Documents → Templates, using the `{{customer_name}}`, `{{period}}`, `{{period_start}}`, `{{period_end}}`, `{{amount}}` … tokens).
4. Link existing paying companies to their customers (Customer Visits → joined + web address → Make real customer, or Customer Details → Platform company → Link).

## Test suites (all wired into CI; CI runs the database-free part)

`test_tenant_migration_health_cli` (11) · `test_subscription_payment_cli` (33) · `test_service_price_terms_cli` (47) · `test_document_line_terms_cli` (31) · `test_customer_visit_link_cli` (39) · `test_vendor_bridge_cli` (33) · `test_my_subscription_cli` (36) · `test_subscription_contracts_cli` (28) · `test_most_profitable_products_cli` (16) · `test_customer_subscription_e2e_cli` (18) — **292 checks, 0 failures**, no data left behind.

**Note (merged with main 2026-10-16):** invoices now go pending → reviewed → approved (role-based); a BJP invoice must be approved before the customer's payment can be recorded. The bridge is unchanged — it still acts only on real money received.
