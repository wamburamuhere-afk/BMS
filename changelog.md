# BMS Changelog

## 2026-05-22 (update 50)

### Fix: Quotation flow — correct redirections, decouple from PO
Quotations and Sales Orders share the same table and pages (distinguished by the
`is_quote` flag). Several navigation paths ignored the quotation context, and a
quotation must not carry a customer PO reference — it is the first document
issued to the customer, before they raise their own PO.
- `app/bms/sales/sales_order_create.php`: after saving a quotation, redirect to
  the Quotations list instead of the Sales Orders list; "PO No" field hidden
  when `is_quote` (a quotation has no PO reference).
- `app/bms/sales/sales_order_edit.php`: "Back to Orders" button goes to the
  Quotations list for a quote; `$is_quote` now read from the saved record
  instead of the URL parameter; "PO No" field hidden for quotations.
- `app/bms/sales/sales_order_view.php`: "Back to List" goes to Quotations for a
  quote; Print uses the `print_quotation` route for a quote; "Create Invoice"
  and the two invalid-ID error redirects use `getUrl()` instead of bare URLs.
- `app/bms/sales/quotations/quotations.php`: Convert-to-Order success redirect
  uses `getUrl('sales_orders')` instead of a bare `sales_orders.php` URL.
All navigation changes are conditional on `is_quote`; the Sales Order flow is
unchanged.

---

## 2026-05-21 (update 49)

### Fix: Sub-Contractor Details — Received Invoices and Recent Payments tabs inactive
- `app/bms/operations/sub_contractor_details.php`: removed duplicate `const CSRF_TOKEN` declaration from the inline `<script>` block — `header.php` already declares it globally. The duplicate caused a `SyntaxError: Identifier 'CSRF_TOKEN' has already been declared` that silently aborted the entire script block, leaving `switchScTab()` and all DataTable initializations undefined. Clicking "Received Invoices" or "Recent Payments" therefore did nothing; only "Projects Involved" appeared to work because its pane is visible by default (no `d-none`).
- `tests/test_sc_details_cli.php` (new): 22-test static CLI suite — catches the duplicate-const anti-pattern, checks all three pane IDs and their initial visibility, verifies tab button `onclick` wiring, confirms `switchScTab()` is defined, checks all DataTable IDs, and verifies AJAX URL uses `buildUrl()`.
- `.github/workflows/php-lint.yml`: added Sub-Contractor Details test suite step.

---

## 2026-05-21 (update 48)

### Delivery Notes — split into Record (inbound) vs Create (outbound)
Branch `feature/dn-record-vs-create`. Per management feedback, Delivery Notes now
distinguish **recording** a DN received FROM a supplier/sub-contractor from
**creating** a DN sent TO one.
- `migrations/2026_05_21_dn_record_vs_create.php` (new): idempotently adds
  `dn_type` (inbound/outbound), `party_type` (supplier/subcontractor) and
  `subcontractor_id` columns to `deliveries`; ensures the `delivery_attachments`
  table and the `uploads/deliveries/` folder (+ `.htaccess` execution guard).
- `app/bms/grn/dn_create.php`: rewritten as the inbound **Record DN** form —
  hand-typed supplier DN number first, a Supplier/Sub-Contractor dropdown, the
  specific party chosen from all active suppliers OR sub-contractors (no longer
  gated by Purchase Orders), warehouse filtered by the selected project, and a
  required multi-attachment section (each attachment has a name + file, with an
  "Add Attachment" button).
- `app/bms/grn/dn_outbound.php` (new): the outbound **Create DN** form — DN
  number auto-generated, Supplier/Sub-Contractor dropdown, no attachment.
- `api/dn_attachment_helper.php` (new): `dn_collect_attachment_pairs()` +
  `dn_save_attachments()` — named multi-file uploads stored under
  `uploads/deliveries/` with §19 five-check security + document-library registration.
- `api/create_dn.php` / `api/update_dn.php`: rewritten to handle both directions —
  `dn_type`, `party_type`, supplier/sub-contractor validation, manual DN number
  for inbound, named attachments.
- `api/delete_dn_attachment.php` (new): removes a single DN attachment.
- `api/get_delivery_notes_list.php`: returns `dn_type`/`party_type`, joins
  `sub_contractors`, and supplies per-direction tab counts.
- `app/bms/grn/delivery_notes.php`: two action buttons (Record DN / Create DN),
  separate Inbound/Outbound tabs each showing only that direction, a Type column,
  and direction-aware edit links.
- `app/bms/grn/dn_view.php`: rewritten — shows direction, party (supplier or
  sub-contractor) and the supplier's DN attachments.
- `api/account/print_delivery_note.php`: resolves sub-contractor parties and is
  direction-aware; the print layout/format is identical for inbound and outbound.
- `roots.php`: added the `dn_outbound` route.
- `tests/test_dn_cli.php`: rewritten — 80-test suite covering the Record/Create
  split, manual number, named multi-attachments, sub-contractor selection and
  separate list tabs.

---

## 2026-05-21 (update 45)

### E-Signature Modernisation — tamper-evident integrity, audit trail & upload hardening
Branch `feature/esignature-modernization` (off `develop`). Makes the document
e-signature feature legally defensible (ESIGN/UETA-aligned) and production-ready,
with no change to the 4-step wizard UX. Single-party / automated signing only.
- `migrations/2026_05_21_esignature_audit_columns.php` (new): idempotently adds
  `hash_algorithm`, `hash_before`, `hash_after`, `signing_reference`,
  `signed_document_id`, `user_agent`, `consent_text`, `consent_accepted_at`,
  `event_log` columns + `idx_signed_document_id` index to `document_signatures`.
- `api/document/save_signed_pdf.php`: computes SHA-256 of the original and the
  signed file **server-side** (authoritative — client hashes are never trusted);
  rejects a sign request with no `consent_text` (intent evidence); persists the
  consent text, user-agent, signing reference and an ordered JSON `event_log`
  (viewed → consent → signed); adds the missing `canCreate('documents')`
  permission check; stops leaking exception text to the client (generic message
  + `error_log`); drops a script-blocking `.htaccess` into `uploads/documents/`.
- `api/document/verify_signed_document.php` (new): re-hashes the stored signed
  file and compares it to `hash_after` with `hash_equals()` — returns
  Verified / Tampered / unverifiable.
- `api/document/upload_signature.php`: §19 hardening — extension whitelist,
  real-MIME (`finfo` magic-byte) check, 2 MB size limit, non-guessable
  `random_bytes` filename, `mkdir(0755)` not `0777`, protective `.htaccess`,
  `logActivity()` on success; no longer trusts `$_FILES['type']`.
- `app/constant/document/select_document_add_esignature.php`: appends a
  **Certificate of Completion** page to every signed PDF (pure pdf-lib — signer
  name/email, date, signing reference, original-document SHA-256, consent text);
  computes a client-side SHA-256 for the certificate; captures the consent
  timestamp and document-viewed time; sends `consent_text` / `consent_accepted_at`
  / `viewed_at` / `signing_reference`; adds an integrity **Verify** button on the
  finish step; removes the dead `uint8ToBase64()` helper; replaces the misleading
  silent non-PDF "signature" with an honest PDF-only message; builds signature
  image URLs via `APP_URL` so they resolve on sub-directory installs.
- `app/constant/document/e_signatures.php`: upload modal `accept` drops `.gif`
  (pdf-lib can only embed PNG/JPG).
- `tests/test_esignatures_wizard_cli.php`: extended to 141 tests — new sections
  for integrity/audit hardening, the verify endpoint, upload hardening and the
  certificate/consent wiring.
- `tests/test_esignature_integrity_cli.php` (new): 26-test DB-backed suite —
  verifies the audit columns, the SHA-256 tamper-evidence round-trip, migration
  idempotency and the integrity endpoints.
- `todo.md` (new): implementation plan for the modernisation.

---

## 2026-05-21 (update 47)

### Fix: Sub-Contractor Details — Received Invoices and Recent Payments tabs inactive
- `app/bms/operations/sub_contractor_details.php`: removed duplicate `const CSRF_TOKEN` declaration from the inline `<script>` block — `header.php` already declares it globally. The duplicate caused a `SyntaxError: Identifier 'CSRF_TOKEN' has already been declared` that silently aborted the entire script block, leaving `switchScTab()` and all DataTable initializations undefined. Clicking "Received Invoices" or "Recent Payments" therefore did nothing; only "Projects Involved" appeared to work because its pane is visible by default (no `d-none`).
- `tests/test_sc_details_cli.php` (new): 22-test static CLI suite — verifies file/syntax, catches the duplicate-const anti-pattern, checks all three pane IDs and their initial visibility, verifies tab button `onclick` wiring, confirms `switchScTab()` is defined and removes `d-none`, checks all DataTable IDs, and verifies AJAX URL uses `buildUrl()`.
- `.github/workflows/php-lint.yml`: added Sub-Contractor Details test suite step.

---

## 2026-05-21 (update 46)

### Document Signing Wizard — PDF embedding Phases 2 & 3
- `api/document/save_signed_pdf.php` (new): accepts file upload `signed_pdf_file` + `original_document_id`, `signature_id`, `signature_position`; validates MIME via `finfo` (must be `application/pdf`); max 40 MB; verifies original doc + user's signature; saves to `uploads/documents/`; INSERTs `documents` record named "Original (Signed)"; INSERTs/UPDATEs `document_signatures`; `logActivity` + `logAudit`; returns `{ success, new_document_id }`
- `app/constant/document/select_document_add_esignature.php` (Phase 3): rewrote `processFinalSign()` as `async`; added `embedSignatureIntoPdf()` — fetches original PDF, loads with `PDFLib.PDFDocument.load()`, fetches signature image, embeds PNG/JPG, converts canvas coordinates to PDF points (posX/posY ÷ 1.5, Y-axis flipped), draws image on target page, serialises to Blob, POSTs to `save_signed_pdf.php`, wires download button to new signed document ID; added `recordSignatureOnly()` fallback for non-PDF documents; added `uint8ToBase64()` helper safe for large files
- `tests/test_esignatures_wizard_cli.php` (Phase 4): expanded to 100 tests across 12 sections; new sections cover pdf-lib asset existence + size, PDF embedding logic (PDFLib.PDFDocument.load, embedPng/Jpg, drawImage, coordinate conversion, Blob upload, new_document_id wiring), and save_signed_pdf.php security (auth, CSRF, finfo MIME, move_uploaded_file, no base64, logAudit)

---

## 2026-05-21 (update 45)

### Document Signing Wizard — PDF embedding Phase 1: add pdf-lib.js
- `assets/js/pdf-lib.min.js` (new): pdf-lib v1.17.1 downloaded from jsDelivr (~513 KB); used in Phase 3 to burn signature image into PDF client-side
- `app/constant/document/select_document_add_esignature.php`: added `<script src>` tag for pdf-lib.min.js (after pdf.min.js, before inline script block)

---

## 2026-05-21 (update 44)

### Fix: Document Signing Wizard — Phases 1 & 2
- `api/get_documents.php`: replaced empty stub with real server-side DataTable query; SELECTs from `documents` LEFT JOIN `document_categories`; returns `id`, `document_name`, `file_path`, `file_size`, `file_type`, `uploaded_at`, `category_name`; honours `draw`/`start`/`length`/`search[value]`; filters `status != 'deleted'`; auth check on `$_SESSION['user_id']`
- `app/constant/document/select_document_add_esignature.php`: fixed 6 hardcoded absolute paths → `buildUrl()` / `APP_URL`: `/api/get_documents.php`, `/ajax/get_user_signatures_list.php`, two `/documents/library?action=download` references (PDF load + download button), `/ajax/apply_signature.php`, `/ajax/quick_upload_document.php`
- `app/constant/document/select_document_add_esignature.php` (Phase 3): fixed `selectSignature()` — `event.currentTarget` undefined in onclick; now passes `this` from onclick and accepts `el` parameter; fixed `changeStep()` — going backward no longer leaves step indicators stuck in `completed` state; fixed `updateButtons()` — `#btnBack` was never un-hidden after step 4, breaking error-recovery flow back to step 3
- `app/constant/document/select_document_add_esignature.php` (Phase 4): fixed `setPresetPosition()` — now actually repositions the draggable element using parent/element dimensions; bottom_left/bottom_center/bottom_right all compute correct translate(x, y) values and update posX/posY; removed misleading Swal toast (visual feedback is immediate)
- `app/constant/document/select_document_add_esignature.php` (Phase 5): corrected 3 API paths pointing to non-existent `ajax/` stubs — `ajax/get_user_signatures_list.php` → `api/document/get_user_signatures_list.php`; `ajax/apply_signature.php` → `api/document/apply_signature.php`; `ajax/quick_upload_document.php` → `api/document/quick_upload_document.php`; corrected 2 download URLs from `APP_URL + 'documents/library?...'` (wrong route) → `buildUrl("document_library")?action=download&document_id=` (matches existing codebase pattern)
- `api/get_documents.php`: fixed SQLSTATE[42S22] — `d.status` column does not exist on `documents` table; changed `WHERE d.status != 'deleted'` → `WHERE 1=1`; changed total-records count to remove same invalid filter
- `tests/test_esignatures_wizard_cli.php` (new): 59-test CLI suite covering file existence, PHP syntax, SQL correctness (no d.status), no hardcoded paths, correct buildUrl() paths, JS bug fixes, step navigation, setPresetPosition, and backend API integrity
- `.github/workflows/php-lint.yml`: added E-Signatures Wizard test suite step

---

## 2026-05-21 (update 43)

### Delivery Note — remove attachment feature + auto-generate DN number

**Attachment removal (all surfaces)**
- `app/bms/grn/dn_create.php`: removed Attachments & Documents card HTML; removed `$dn_attachments` DB query + variable; removed `handleFileSelect()`, `addAttachmentRow()`, `removeAttachmentRow()` JS functions; removed all attachment FormData blocks from `submitDN()`
- `app/bms/grn/dn_view.php`: removed Documents & Attachments card; removed `delivery_attachments` query + auto-create table block; removed `$attachments` variable
- `api/create_dn.php`: removed attachment upload block (INSERT into delivery_attachments)
- `api/update_dn.php`: removed all three attachment sub-sections (delete, replace, add new)

**DN Number auto-generation**
- `app/bms/grn/dn_create.php`: removed manual `dn_number` input field; in edit mode shows auto-generated `delivery_number` as read-only with "Auto-generated — cannot be changed" label; removed `formData.append('dn_number', ...)` from JS
- `api/create_dn.php`: removed `$dn_number_input`; INSERT now stores `null` in `dn_number` column; `delivery_number` auto-generation unchanged
- `api/update_dn.php`: removed `$dn_number_input`; removed `dn_number=?` from UPDATE query

**Test suite + CI gate**
- `tests/test_dn_cli.php` (new): 60-test CLI suite covering file existence, PHP syntax, DN number removal, auto-generation, attachment removal across all 5 files, and core logic still intact
- `.github/workflows/php-lint.yml`: added Delivery Note test suite step
- Pre-push hook already uses `test_*_cli.php` glob — picks up new suite automatically

---

## 2026-05-21 (update 42)

### Fix: missing `project_progress_report_attachments` table (live server error)
- `migrations/2026_05_21_create_project_progress_report_attachments.php` (new): creates table for storing file attachments on progress reports — columns: report_id, attachment_name, file_path, file_size, file_ext, created_at; fixes SQLSTATE[42S02] error on Projects → Project Details → Reports → Reporting when uploading attachment

---

## 2026-05-21 (update 41)

### E-Signatures — Full bug-fix, real API implementations, CSRF protection, test suite

**Phase 1 — Fixed broken API URL paths in `e_signatures.php`**
- `app/constant/document/e_signatures.php`: upload URL corrected to `api/document/upload_signature.php`; delete URL corrected to `api/document/delete_signature.php`; quick-upload URL corrected to `${APP_URL}/api/document/quick_upload_document.php`; loadDocuments URL changed from relative to `${APP_URL}/api/get_documents.php`

**Phase 2 — Created missing API endpoints**
- `api/document/apply_signature.php` (new): validates document + signature ownership; updates pending record to signed or inserts new signed record; records IP address; logs activity
- `api/document/get_user_signatures_list.php` (new): returns JSON array of current user's active signatures for select dropdowns
- `migrations/2026_05_21_create_document_signatures.php` (new): creates `document_signatures` table (document_id, signature_id, requested_by, signed_by, signature_position, ip_address, status, due_date, signed_at)

**Phase 3 — Fixed remaining /ajax/ path references**
- `app/constant/document/e_signatures.php`: `get_user_signatures_list` calls updated to `api/document/`; both `apply_signature` references updated to `api/document/`

**Phase 4 — Fixed JS logic bugs**
- `app/constant/document/e_signatures.php`: removed `const canvas` redeclaration in draw form submit (shadowed outer `let canvas`); fixed `event.currentTarget` in `selectSignatureForDoc` and `finalSelectSignature` — both now accept `el` param passed via `this` in onclick; DataTable `signaturesTable` sort fixed from `[[2,'desc']]` (Type) to `[[3,'desc']]` (Created At); View Full Size URL now strips leading slash from `file_path` to prevent double-slash

**Phase 5 — CSRF protection**
- `header.php`: added `const CSRF_TOKEN` JS global and `$.ajaxSetup` to send `X-CSRF-Token` header on every jQuery AJAX call automatically
- `api/document/upload_signature.php`: added `csrf_check()`
- `api/document/delete_signature.php`: added `csrf_check()`
- `api/document/apply_signature.php`: `csrf_check()` included on creation
- `ajax/save_drawn_signature.php`: replaced stub with real implementation (base64 PNG decode, file save, DB insert, activity log); added `csrf_check()`

**DataTable stubs replaced with real implementations**
- `api/get_user_signatures.php`: full server-side DataTable query from `user_signatures` (was empty stub)
- `api/get_pending_signatures.php`: full server-side query from `document_signatures` JOIN `documents` JOIN `users` (was empty stub)
- `api/get_signature_history.php`: full server-side query from `document_signatures` JOIN `documents` (was empty stub)

**Select button UX fix**
- `app/constant/document/e_signatures.php`: `selectSignature()` now highlights the selected row green and injects a checkmark icon — previously showed only a toast with no visual indicator

**Test suite**
- `tests/test_esignatures_cli.php` (new): 56-test CLI suite (no DB required); checks file existence, PHP syntax, API URL correctness, JS logic, CSRF presence, auth guards, stub detection, migration integrity; exits 1 on failure — used by pre-push hook and CI
- `scratch/test_esignatures_full.php` (new): browser-based integration test; checks DB tables, saved records, file-on-disk presence, all API HTTP responses
- `.github/workflows/php-lint.yml`: added e-signatures CLI test step
- `.github/workflows/deploy.yml`: added e-signatures files to critical files check + CLI test step

## 2026-05-20 (update 40)

### Document Library — Issue/Expire dates + expiry notifications
- `migrations/2026_05_21_document_expiry_tracking.php` (new): adds `documents.issue_date` + `documents.expire_date`, adds `notifications.document_id`, creates `document_expiry_reminders` dedup table, inserts the `document_expiry_alerts` RBAC permission (Documents module) — all idempotent
- `cron/check_document_expiry.php` (new): expiry notification engine. Scans documents within 30 days of expiry; fires one notification at the 30/14/7/1-day milestones (deduped via `document_expiry_reminders`); recipients are RBAC-driven (Admins + any role with VIEW on `document_expiry_alerts`); runnable via CLI/cron or auto-included by `header.php`
- `app/constant/document/document_library.php`: upload modal gains Issue Date + Expire Date inputs; client-side validation (expire > issue); table gains an Expiry column with status badge; card view gains expiry badge; filter bar gains an Expiry Status filter; statistics row gains an "Expiring Soon" card; added `getExpiryBadge()` JS helper; updated `handleDocumentUploadLocal()` INSERT for consistency
- `api/document/upload_document.php`: saves `issue_date` + `expire_date`; rejects expire ≤ issue
- `api/document/get_documents.php`: added `expiry_status` filter (expiring/expired/active/none) and `expiring_soon` stat
- `app/dashboard.php` (additive only): new "Document Expiry" notification group; `get_system_alerts()` now also reads the user's unread document-expiry notifications; the "System requires your attention → View Details" panel lists expiring documents
- `header.php`: once-per-day guarded trigger that runs the expiry engine
- `tests/test_document_expiry_cli.php` (new): 50-check static test suite gating the feature (files, syntax, migration integrity, upload capture, engine logic, display, additive dashboard changes, header trigger)
- `.git/hooks/pre-push` (local): rewritten to run every `tests/test_*_cli.php` suite — any failure blocks `git push`
- `.github/workflows/php-lint.yml`: added a step running the document-expiry test suite on every push/PR

## 2026-05-20 (update 39)

### Project View — Supplier Payments Actions column + status workflow
- `migrations/2026_05_20_supplier_payment_workflow_status.php`: adds 'reviewed' and 'approved' to supplier_payments.status ENUM (idempotent)
- `api/suppliers/add_project_payment.php`: changed initial status from 'completed' to 'pending' so new payments enter the workflow
- `api/suppliers/get_project_payment.php` (new): GET single payment with PO + recorded_by join
- `api/suppliers/update_project_payment.php` (new): edit pending payment; reverses old paid_amount, applies new; CSRF + canEdit guard
- `api/suppliers/delete_project_payment.php` (new): soft-delete (status=cancelled) with PO reversal; blocks approved payments; CSRF + canDelete guard
- `api/suppliers/change_payment_status.php` (new): workflow transitions pending→reviewed (canReview) and reviewed→approved (canApprove); CSRF guard
- `app/bms/operations/project_view.php`:
  - `renderSupplierProjectPayments()`: added Actions column with gear dropdown — View Details, Edit (pending only), Mark Reviewed (pending), Approve (reviewed), Delete (not approved)
  - Added `viewSuppPayment()`, `editSuppPayment()`, `saveSuppPaymentEdit()`, `deleteSuppPayment()`, `changeSuppPayStatus()` JS functions
  - Added `#suppEditPaymentModal` (Edit modal with PO dropdown pre-selected)

## 2026-05-20 (update 38)

### Project View — Payments tab for Supplier mode (full implementation)
- `api/suppliers/get_project_payments.php` (new): GET endpoint; `action=list` returns payments joined via `supplier_payments → purchase_orders` filtered by `project_id + supplier_id`; `action=get_pos` returns POs for this supplier+project for the payment modal dropdown
- `api/suppliers/add_project_payment.php` (new): POST endpoint; verifies PO belongs to the project and supplier before inserting into `supplier_payments`; updates PO `paid_amount` and `payment_status`; full auth + CSRF + permission checks + `logActivity()`
- `app/bms/operations/project_view.php`:
  - Payments tab pane: supplier branch now has "Record Payment" button + `#suppAddPaymentModal` with PO dropdown (shows outstanding balance per PO), Date, Amount, Currency, Method, Reference, Notes
  - `openSuppPaymentModal()` — opens modal, loads POs via `get_pos` action
  - `saveSuppPayment()` — validates inputs, posts to new API, reloads table on success
  - `#suppPayPO` on-change handler — shows outstanding balance and auto-sets currency when PO selected
  - `loadSupplierProjectPayments()` + `renderSupplierProjectPayments()` — fetch and render payments table
  - Single `shown.bs.tab` listener routes to correct load function based on `supplierMode`; removed duplicate old listener that caused the error

## 2026-05-20 (update 37)

### Project View — Received Invoices tab in Sales section
- `api/received_invoices.php`: added `project_id` filter to `action=list` handler (one extra WHERE clause, same pattern as existing supplier_id/status filters)
- `app/bms/operations/project_view.php`:
  - Added "Received Invoices" menu item to both SC-mode and full-mode Sales dropdowns (targets new `#proj-received-invoices` tab pane)
  - Added `#proj-received-invoices` tab pane: when opened via Supplier Details → View Project (`?supplier_id=X`), the tab heading shows the supplier name and the API call is filtered by both `project_id` AND `supplier_id` — so only that supplier's invoices for the project are shown, not all suppliers'
  - Added `loadProjectReceivedInvoices()` — fetches from API with `project_id`; conditionally adds `supplier_id` when `$supplier_mode` is true; lazy-loads on first tab activation
  - Added `renderProjectReceivedInvoices(rows)` — renders table with columns: Invoice Ref, Supplier, Type, Date Raised, Date Recorded, PO Number, Amount, Status
  - Added `safeOutput()` JS utility function (was missing from this file; used by the new render function)

## 2026-05-20 (update 36)

### Supplier Details — Received Invoices table blank despite badge showing count
- `app/bms/Suppliers/supplier_details.php`: added `safeOutput()` JS function definition — it was used in 3 places (DataTable render for `invoice_ref`, `po_number`, and inside `riActions()`) but never defined in this file; JavaScript threw `ReferenceError: safeOutput is not defined` on every DataTable draw, leaving the table empty even though the API was returning data correctly

## 2026-05-20 (update 35)

### Received Invoices — PO cumulative cap + PO vs Invoice report
- `app/bms/invoice/received_invoices.php`: PO Reference field moved above Amount and Attachment (per boss requirement); live "PO Summary" panel shows PO Total / Previously Invoiced / Remaining Capacity / After This Invoice when a PO is selected; client-side cap guard blocks submit if amount + previous invoices would exceed PO total; warning message tells user to return invoice to supplier
- `helpers.php`: new `ri_check_po_cap($pdo, $po_id, $new_amount, $exclude_id)` — verifies that the running total of invoices on a PO does not exceed `grand_total`; excludes deleted invoices and (when editing) the current invoice itself
- `api/received_invoices.php`: `create` and `update` actions now call `ri_check_po_cap` server-side (defense in depth); new `action=po_summary` GET endpoint returns `{ grand_total, invoiced_total, remaining, invoice_count, project_id, project_name }` for the live panel
- `app/bms/invoice/received_invoices.php`: Project field auto-fills when PO is selected (per boss request: "ukichagua PO, automatically Project name itokee tuu"); injects option into Select2 if not already present; user can still manually override after auto-fill
- `app/bms/invoice/po_invoice_report.php`: new report page — DataTable of all POs with Supplier, PO Date, PO Total, Invoiced, Remaining, % Billed (progress bar), Status (Open / Partial / Fully Billed / Over-billed); filters by supplier, status, date range; stat cards; CSV export; mobile cards
- `api/po_invoice_report.php`: new aggregated feed (LEFT JOIN + GROUP BY on supplier_invoices)
- `roots.php`: route registered for `po_invoice_report`
- `header.php`: menu link "PO vs Invoice Report" added under Sales & Purchases (visible to anyone with `received_invoices` view permission)

## 2026-05-20 (update 34)

### Customer LPO — fix "Server error." on delete and status change
- `app/bms/customer/customer_details.php`: added `const CSRF_TOKEN = '<?= csrf_token() ?>'` at the top of the JS block; passed `_csrf: CSRF_TOKEN` in `deleteLpo()` `$.post` call and `changeLpoStatus()` `$.post` call — both APIs call `csrf_check()` which returned HTTP 419 when token was missing, causing jQuery `.fail()` to show "Server error."

## 2026-05-20 (update 33)

### RFQ — multi-file attachment support (create + edit + view)
- `migrations/2026_05_20_add_rfq_attachment.php`: creates `uploads/procurement/rfq/` directory with `.htaccess` execution guard (intermediate migration — superseded by next)
- `migrations/2026_05_20_rfq_multi_attachments.php`: creates `rfq_attachments` table (`attachment_id`, `rfq_id`, `attachment_name`, `file_path`, `original_name`, `file_size`, `uploaded_by`, `uploaded_at`); drops the single `attachment` column from `rfq` table
- `api/create_rfq.php`: rewritten — CSRF check; handles `attachment_file[]` + `attachment_name[]` arrays; 5-check security per file (extension whitelist, finfo MIME, 10 MB limit, `random_bytes` filename, `.htaccess` folder); inserts each file into `rfq_attachments`; `registerFileInLibrary()` called per file
- `api/update_rfq.php`: fully rewritten from duplicate-of-create into proper UPDATE; draft-only guard; replaces `rfq_items`; appends new files to `rfq_attachments` (existing attachments kept)
- `api/delete_rfq_attachment.php`: new — removes one attachment row from `rfq_attachments` + physical file; draft-only guard; CSRF protected
- `app/bms/purchase/rfq_create.php`: CSRF token added; Attachments card placed below RFQ Items — each row has Attachment Name input + file input + trash button; "Add Attachment" button appends rows dynamically; edit mode shows saved attachments with View + AJAX remove (Swal confirm)
- `app/bms/purchase/rfq_view.php`: queries `rfq_attachments`; Attachments card rendered below Authorization Trail — list-group with name, original filename, Download button; count badge; print-safe filename fallback

## 2026-05-20 (update 32)

### Customer LPO — line items + multi-file attachments
- `migrations/2026_05_20_create_lpo_items.php`: creates `customer_lpo_items` table (item_id, lpo_id, sort_order, product_name, quantity, unit_price, tax_rate, total)
- `migrations/2026_05_20_create_lpo_attachments.php`: creates `customer_lpo_attachments` table (attachment_id, lpo_id, file_path, original_name, file_size, created_by)
- `api/customer/get_lpo.php`: returns `items[]` and `attachments[]` arrays with download_url
- `api/customer/add_lpo.php`: saves line items (recalculates amount from totals); saves multiple attachments to `uploads/finance/customer_lpos/`
- `api/customer/update_lpo.php`: replaces line items on update; appends new attachments; fixed status validation to include pending/reviewed/approved
- `api/customer/delete_lpo_attachment.php`: new — removes single attachment record + file
- `app/bms/customer/customer_details.php`: Add/Edit modals upgraded to modal-xl; items table (S/NO, Product, Qty, Unit Price, Tax%, Total) with add/remove rows and live grand total; row-based attachment section (name field + file/existing-link per row) with Add Attachment button and per-row trash; View Details modal shows items table and attachments list; all colors white/blue only (no yellow/teal); table headers white with border; delete icons use bi-trash; JS helpers: `lpoAddRow`, `lpoCalcRow`, `lpoRemoveRow`, `lpoUpdateGrandTotal`, `lpoAddAttachRow`, `lpoRemoveAttachRow`, `lpoRenumberAttach`; `lpoEsc()` global XSS helper
- `.github/workflows/deploy.yml`: added 3 new files to CI critical-file check

## 2026-05-20 (update 31)

### Customer details — section tabs
- `app/bms/customer/customer_details.php`: wrapped Sales Order History, Invoice & Payment History, Purchase Orders (LPO), and System Information in Bootstrap pill tabs; tabs render in one scrollable row; active tab is blue; LPO tab button is PHP-conditional (hidden if no LPOs and no create permission); DataTable columns.adjust() called on tab show to fix hidden-pane rendering

## 2026-05-20 (update 30)

### Customer LPO — UI polish and bug fixes
- `app/bms/customer/customer_details.php`:
  - Removed "Document" column from LPO desktop table (th + td); documents now accessible only via View Details modal
  - Fixed `safeOutput is not defined` JS error in `viewLpo()` — added local `esc()` escape helper; replaced all `safeOutput()` calls within the function
  - Changed `statusColors.pending` in JS from `warning text-dark` to `primary` (blue badge)
  - PHP `$lpo_badges`: `pending` and `partially_fulfilled` changed from `bg-warning text-dark` to `bg-primary`; `fulfilled` changed to `bg-success`
  - View LPO modal header: `bg-info text-dark` → `bg-white border-bottom`; Edit and Review buttons: `btn-warning`/`btn-info` → `btn-primary`
  - Edit LPO modal header: `bg-warning text-dark` → `bg-primary text-white`; close button → `btn-close-white`; Update button: `btn-warning` → `btn-primary`
  - Edit LPO modal: LPO Number visible input replaced with `<input type="hidden">`; Status `<select>` replaced with `<input type="hidden">` — status managed via workflow only; info banner added matching Add LPO modal
  - Mobile cards: always-show footer; View Details button (eye icon) added as first button; document download button removed
  - DataTable `columnDefs` targets updated from `[0, 6, 7]` to `[0, -1]` after Document column removal

## 2026-05-20 (update 29)

### Customer LPO — View Details, status workflow, auto-generated number
- `migrations/2026_05_20_lpo_status_workflow.php`: alter status ENUM to add `pending`, `reviewed`, `approved`; default changed to `pending`
- `api/customer/change_lpo_status.php`: new — POST endpoint enforcing `pending→reviewed→approved` workflow
- `api/customer/add_lpo.php`: auto-generate LPO number (`LPO-YYYY-NNNNN`); status always set to `pending` on create
- `api/customer/get_lpo.php`: join customer name (`customer_display_name`); add `document_url` to response
- `app/bms/customer/customer_details.php`:
  - Gear dropdown gains "View Details" as first item
  - View LPO modal: full details, Print button, Edit shortcut, Mark Reviewed / Approve workflow buttons (shown based on current status)
  - Add LPO modal: removed LPO Number input (auto-generated) and Status select (always starts pending)
  - Edit LPO modal: status select now includes pending/reviewed/approved options
  - Status badges updated for all new statuses
- `.github/workflows/deploy.yml`: added 2 new files to CI critical-file check

## 2026-05-20 (update 28)

### Customer LPO (Purchase Order) Feature — full implementation
- `migrations/2026_05_20_create_customer_lpos.php`: creates `customer_lpos` table (`lpo_id`, `lpo_number`, `customer_id`, `issue_date`, `expiry_date`, `amount`, `currency`, `description`, `status` ENUM, `document_path`, `notes`, `created_by`, timestamps)
- `api/customer/add_lpo.php`: POST — validate + save new LPO; optional document upload (PDF/DOC/Image, 10MB max, magic-byte checked) to `uploads/finance/customer_lpos/`
- `api/customer/update_lpo.php`: POST — validate + update LPO; replaces document if new file uploaded
- `api/customer/delete_lpo.php`: POST — soft-delete (`status = 'deleted'`)
- `api/customer/get_lpo.php`: GET — fetch single LPO for edit modal
- `api/customer/get_lpos_list.php`: GET — fetch all LPOs for a customer (not used directly; available for future AJAX use)
- `app/bms/customer/customer_details.php`: inserted Purchase Orders (LPO) section between Invoices and System Information cards; stat cards (total, open, other, total amount); desktop DataTable; mobile card view; Add LPO modal; Edit LPO modal; delete with SweetAlert2 confirm; all gated by `canCreate/Edit/Delete('customers')`
- `.github/workflows/deploy.yml`: added `uploads/finance/customer_lpos` to `mkdir -p` on all 4 servers; added 6 new files to CI critical-file check

## 2026-05-19 (update 27)

### Project View — Fix Procurements dropdown appearing open on tab URL activation
- `app/bms/operations/project_view.php`:
  - Root cause: Bootstrap 5 Tab's internal `_toggleDropDown` sets `active` class and `aria-expanded="true"` on the parent `.dropdown-toggle` (Procurements button) whenever a tab inside a dropdown-menu is activated programmatically — making the dropdown appear open/pressed.
  - Fix: added `closeAllDropdowns()` helper that strips `show` class and resets `aria-expanded="false"` on all dropdown toggles and menus; called via `setTimeout(0)` immediately after `Tab.show()` so the tab pane shows correctly before dropdowns are reset.
  - Also added `pageshow` listener to call `closeAllDropdowns` when the page is restored from browser bfcache (back button), preventing a frozen-open dropdown state.

## 2026-05-19 (update 26)

### Project View — Sub-Contractor "View Details" opens full SC page with project context
- `app/bms/operations/project_view.php`:
  - Added `'sub-contractors': 'proj-sc-tab'` to the `?tab=` URL activation map so returning from SC details restores the SC tab.
  - "View Details" dropdown item changed from `onclick="projScView()"` modal to a direct link `sub_contractors/view?id=X&from=project&project_id=Y`.
- `app/bms/operations/sub_contractor_details.php`:
  - Reads `from` and `project_id` query params; fetches project name when `from=project`.
  - Breadcrumb: when from project shows `Dashboard > Projects > [Project Name] > Sub-Contractors > [SC Name]`; otherwise unchanged.
  - Desktop back button and mobile "Back to List" item both use `$back_url` — returns to `project_view?id=X&tab=sub-contractors` when from project, or `sub_contractors` list otherwise.
  - Core SC list flow completely untouched.

## 2026-05-19 (update 24)

### Admin — Remove Collections & Guarantors from Roles & Permissions
- `migrations/2026_05_19_remove_collections_guarantors_permissions.php`: removes 8 permission rows (`module_name IN ('Collections','Guarantors')`) and 60 `role_permissions` assignments that referenced them. Loan/microfinance module does not exist in this system; these were ghost entries with no backing pages.

## 2026-05-19 (update 23)

### Expenses — DB-driven "Applies to Projects" flag on Expense Types
- `migrations/2026_05_19_expense_type_show_project.php`: adds `show_project TINYINT(1) NOT NULL DEFAULT 1` to `expense_types`; sets `show_project = 0` for types named administrative / fixed / operating (case-insensitive).
- `api/finance/get_expense_schema.php`: includes `show_project` in SELECT so the JS schema always carries the flag.
- `api/finance/manage_expense_schema.php`: `add_type` now saves `show_project` param; new `toggle_show_project` action flips the flag and returns new value.
- `app/constant/accounts/expenses.php`:
  - Removed hardcoded `NON_PROJECT_TYPES` name-string check; replaced with `typeData.show_project == 0` — works on any server regardless of DB type-name spelling.
  - Add-type form in manage modal: "Applies to Projects" toggle switch (checked by default); `addManageType()` sends value as `show_project`.
  - Type list items: green badge when `show_project = 1`, grey "Off" badge when `0`.
  - Breadcrumb bar: project-toggle button next to Delete; colour/icon reflects current flag; `toggleTypeShowProject()` confirms via Swal before posting.

## 2026-05-19 (update 22)

### Sub-Contractor View — Tab buttons for Projects / Invoices / Payments
- `app/bms/operations/sub_contractor_details.php`:
  - Replaced three always-visible section rows with a tab button row (3 `btn-primary`/`btn-outline-primary` buttons in one row).
  - Each button reveals its own pane (`#pane-projects`, `#pane-invoices`, `#pane-payments`); others hide — one pane visible at a time.
  - Desktop: table view; Mobile (< 768 px): card view — applied for all three panes.
  - Added `switchScTab(tab)`: toggles pane visibility, updates active button, adjusts DataTable columns.
  - Added `applyProjectsView()` / `renderProjectCards()` for Projects pane card view.
  - Added `applyPaymentsView()` / `renderPaymentCards()` for Payments pane card view.
  - Updated `scProjectsTable` and `scPaymentsTable` DataTable inits with `drawCallback` to populate card views.
  - Unified resize listener routes to the correct `applyXView()` based on active tab.
  - Removed stale `#scPOTable` DataTable init (no matching HTML element).
- `scratch/test_sc_view_tabs.php`: new test — verifies all 6 pane/button IDs, switchScTab() function, card view divs, and DB data access for all 3 tabs; run with `?supplier_id=N`.

## 2026-05-19 (update 21)

### Project View — Sub-Contractors tab parity with external SC page
- `api/get_sub_contractors_list.php`: added `search` (LIKE name/code) and `exclude_project_id` params (excludes already-assigned SCs via `sub_contractor_projects`); returns `results[]` array in Select2 AJAX format alongside `data[]`.
- `app/bms/operations/project_view.php`:
  - **Assign Existing modal**: replaced text input + `<select size="6">` listbox with a single `<select id="assignScSelect2">` initialized as Select2 with AJAX search on `shown.bs.modal`; `openAssignExistingScModal()` now uses Select2 AJAX (no pre-load, no client-side filtering); removed `renderAssignScOptions()` and `#assignScSearch` input listener.
  - **Export button**: added to toolbar (`projScExport()` triggers DataTable Excel button); DataTable init updated to `dom: 'Brtip'` with `excelHtml5` button (cols 0–6, excludes actions).
  - **View Orders + View Payments**: added to each row's action dropdown, linking to `purchase_orders?supplier=ID` and `suppliers/payments?id=ID`.
  - **Country + City filters**: added two new filter inputs; filter section layout changed to `col-md-3` × 4; hidden `Location` column (col 7) added to DataTable (`visible: false, searchable: true`) holding `city, country` text for column-search; `projScApplyFilters()` and `projScClearFilters()` updated accordingly.
  - Address cell now renders city + country inline for visual display.

## 2026-05-19 (update 20)

### Project View — Edit Expense modal parity with Add Expense modal
- `app/bms/operations/project_view.php`:
  - **Category cascade preselection in edit**: replaced dead checkbox code (`#edit_cat_${id}`) with `preSelectCascade(categoryId, isEdit)` — traverses `expenseSchema` tree to find path from root to saved category, then selects each cascade level in sequence (80ms between levels).
  - **Budget info display in edit modal**: added `id="edit_ex_budget_id"`, `onchange="editExOnBudgetChange()"`, and budget info alert block (Allocated / Spent / Remaining badge) to edit modal's Budget Selection container — mirrors add modal.
  - **`editExOnBudgetChange()` function**: new function (mirrors `exOnBudgetChange`) targeting `#edit_ex_budget_id` / `#edit_ex_budget_info_cont` / `#edit_ex_amount`; also auto-fills amount from remaining balance if amount is empty.
  - **Budget preselect triggers info**: `editExpenseInline()` calls `editExOnBudgetChange(e.budget_id)` via `setTimeout` when opening with a linked budget.
  - **Fixed API URL**: `#expenseActionForm submit` was posting to hardcoded `/api/account/update_expense.php`; replaced with `buildUrl('api/account/update_expense.php')`.

## 2026-05-19 (update 19)

### Project View — Expenses edit modal "Sub Contractor" double-line fix
- `app/bms/operations/project_view.php`:
  - In `#expenseActionModal shown.bs.modal` handler, added explicit Select2 initialization for `#edit_ex_paid_to_type` with `minimumResultsForSearch: Infinity` (disables search box for the 4-option list).
  - Select2 renders the selected value in a styled single-line container, eliminating the browser-native `<select>` text wrapping that caused "Sub Contractor" to appear on two lines in the edit modal.
  - Guard: `!hasClass('select2-hidden-accessible')` prevents double-initialization on repeated modal opens.

## 2026-05-19 (update 18)

### Expenses — Staff payroll linking (Paid To → Employee)
- `migrations/2026_05_19_add_payroll_id_to_expenses.php` *(new)*: idempotent migration adding `payroll_id INT NULL` to `expenses` after `invoice_id`.
- `api/account/get_employee_payrolls.php` *(new)*: returns approved + unpaid payrolls for a given employee (`status='approved' AND payment_status!='paid'`); accepts optional `current_payroll_id` so edit mode includes the already-linked payroll in the list.
- `app/constant/accounts/expenses.php`:
  - Added `#payroll_id_block` dropdown (Select2) after `#invoice_id_block`; visible only when `paid_to_type = 'staff'`.
  - `#paid_to_id_select` change handler extended: `staff` type calls `get_employee_payrolls` API and populates the payroll dropdown; supplier/sub_contractor path unchanged.
  - Selecting a payroll auto-fills the Amount field (`net_salary`).
  - Added `resetPayrollBlock()` function; called on payee-type change and form reset.
  - Added `_pendingPayrollId` module variable for async preselection in edit mode.
  - Edit populate block sets `_pendingPayrollId = data.payroll_id` before triggering the payee chain.
- `api/account/add_expense.php`: saves `payroll_id`; marks linked payroll `payment_status = 'paid'` + `payment_date = CURDATE()` inside the DB transaction.
- `api/account/update_expense.php`: saves `payroll_id`; marks new payroll paid; reverts old payroll to `payment_status = 'approved'` if the link is removed or changed.

## 2026-05-19 (update 17)

### Expenses — DataTable Invalid JSON / Ajax error fix
- `api/account/get_expenses.php`:
  - Added `ob_start()` at top to buffer stray PHP warnings/notices from includes.
  - Wrapped all DB operations in `try/catch (PDOException|Exception)` — unhandled exceptions no longer output HTML; catch returns valid DataTables-format JSON with HTTP 500.
  - Added `ob_clean()` before every `echo json_encode(...)` to discard any buffered non-JSON output.
  - Fixed `SQLSTATE[HY093]: Invalid parameter number` crash: `array_unique()`/`array_filter()` preserve non-sequential keys; added `array_values()` on `$toFetch` and `$typeIds` before passing to PDO `execute()`.
  - Added `sub_contractor` CASE branch in `paid_to_name` subquery (was falling through to `ELSE e.vendor` returning null).
- `app/constant/accounts/expenses.php`:
  - Fixed DataTable AJAX URL: was hardcoded as `/api/get_expenses.php` (missing `/bms/` base path); replaced with `<?= buildUrl('api/get_expenses.php') ?>`.

## 2026-05-19 (update 16)

### Supplier View — Received Invoices table fix + Create PO / Add Payment buttons
- `app/bms/Suppliers/supplier_details.php`:
  - Restored full Received Invoices pane with `#riTable` DataTable (was incorrectly removed in a prior edit).
  - Fixed `loadReceivedInvoices()`: removed `type: 'supplier'` filter so all `invoice_type` values for the supplier are returned; fixes table showing 0 rows while DB count showed 5.
  - Fixed `riActions()`: attachment link now uses `APP_URL` JS constant directly instead of wrong PHP interpolation.
  - Added `+ Create PO` button in Purchase Orders card header (gated on `canCreate('purchase_orders')`), linking to `purchase_order_create?supplier_id=`.
  - Added `+ Add Payment` button in Payments card header, linking to `suppliers/payments?id=&create=1`.

### Expenses — Invoice linking (Paid To → Supplier/Sub-contractor)
- `migrations/2026_05_19_add_invoice_id_to_expenses.php` *(new)*: idempotent migration adding `invoice_id INT NULL` column to `expenses` after `paid_to_id`.
- `api/account/get_payee_invoices.php` *(new)*: returns approved invoices for a given payee (`payee_type`, `payee_id`); used by the expense form invoice dropdown.
- `api/account/add_expense.php`: added `invoice_id` to INSERT.
- `api/account/update_expense.php`: added `invoice_id` to UPDATE SET.
- `app/constant/accounts/expenses.php`:
  - Added `#invoice_id_block` dropdown (Select2) after Paid To payee, loads approved invoices via AJAX when a supplier/sub-contractor is selected.
  - Selecting an invoice auto-fills the Amount field.
  - Amount and Project fields moved to appear after the full Paid To section.
  - Edit form pre-populates linked invoice using `_pendingInvoiceId` async pattern.
  - `resetInvoiceBlock()` clears invoice dropdown on payee-type change; form reset also clears it.

## 2026-05-19 (update 15)

### Supplier View — Invoice ref auto-fill + Received Invoices full-width table
- `app/bms/Suppliers/supplier_details.php`:
  - Invoice Reference No. now auto-generates on modal open (add mode) via `generateRiRef()` calling `get_next_ref` API; refresh button shows for add, hides for edit.
  - Fixed `hidden.bs.modal` reset: was still using old green (`#198754`) inline CSS — now uses Bootstrap classes (`bg-primary`/`bg-warning`) matching the header's class-based approach.
  - Fixed `riEditRow()`: now removes `bg-primary` and adds `bg-warning text-dark`; removes `btn-primary` not `btn-success`.
  - `#riTable` given `style="width:100%"` + `autoWidth:false` so DataTable renders full-width matching the other tables in the pane.

## 2026-05-19 (update 14)

### Supplier View — 4-section pill tabs layout
- `app/bms/Suppliers/supplier_details.php`:
  - Replaced stacked rows (Projects Involved, Received Invoices, Purchase Orders, Payments) with a single row of 4 Bootstrap pill tab buttons.
  - Active tab is blue (Bootstrap nav-pills default). Clicking any button instantly shows only that section.
  - Purchase Orders and Payments each promoted from col-md-6 to full-width col-12 inside their own pane.
  - Added `shown.bs.tab` handler to call `columns.adjust()` on DataTables when switching to hidden panes (fixes column-width rendering).
  - Supplier info section (Basic Info, Contact, Address, Bank, Description) untouched.

## 2026-05-19 (update 13)

### Received Invoices — Actions column: gear dropdown UI
- `app/bms/invoice/received_invoices.php`:
  - Replaced individual action buttons (eye/paperclip/pencil/trash) with a single gear+caret dropdown (`btn-outline-primary dropdown-toggle`, `bi-gear`) matching the project-wide pattern.
  - Dropdown items: View, View/Download Attachment, Edit (if can_edit), Delete (if can_delete, with divider).
  - Applied to both the desktop DataTable (`actionButtons()`) and the mobile card footer (`renderCards()`).
  - No logic changes — UI restructure only.

## 2026-05-19 (update 12)

### Received Invoices — Fix blank table (safeOutput not defined)
- `app/bms/invoice/received_invoices.php`:
  - Root cause of blank DataTable: `safeOutput()` is not a global function — it is defined per-page in this project. DataTables called it during the first row render, threw `ReferenceError: safeOutput is not defined`, crashed the draw callback silently, and rendered nothing.
  - Fix: added `safeOutput()` and `CSRF_TOKEN` definitions at the top of the page `<script>` block.

## 2026-05-19 (update 11)

### Admin flag + loadInvoices error visibility
- `migrations/2026_05_19_roles_is_admin_flag.php` (NEW):
  - Adds `is_admin TINYINT(1)` column to `roles` table
  - Sets `is_admin = 1` for role_id=1 (Admin)
  - Any role can now be flagged as admin — not hardcoded to role_id=1
- `core/permissions.php`:
  - `isAdmin()` now reads `$_SESSION['is_admin']` (set by header.php each page load) instead of hardcoding `role_id = 1`
  - Fallback DB query if session flag is missing
- `header.php`:
  - Role query now fetches `r.is_admin` and stores it as `$_SESSION['is_admin']`
- `app/bms/invoice/received_invoices.php`:
  - `loadInvoices()` now has a `.fail()` handler — shows HTTP status + raw response in `#list-message` div above the table instead of silently dropping failures

## 2026-05-19 (update 10)

### Received Invoices — Fix permissions for non-admin roles
- `migrations/2026_05_19_received_invoices_permissions.php`:
  - Bug fix: migration was only inserting into `permissions` table but never into `role_permissions`
  - `canView/canCreate/...` reads `$_SESSION['permissions']` loaded at login — without `role_permissions` rows, non-admin users got 403 from the API and `$.getJSON` silently dropped the response, leaving the table blank
  - Now assigns full CRUD+review+approve to roles 1,2,5,6,7 (Admin, MD, Director, CFO, Accountant) and view+create to all other roles
  - **Note:** existing logged-in non-admin users must log out and back in for the new permissions to load into their session

## 2026-05-19 (update 9)

### Receive Invoice — View Details
- `app/bms/invoice/received_invoices.php`:
  - Feature: added Eye (👁) view button to both desktop table and mobile card action rows
  - Feature: View modal shows full invoice details — type badge, party name, amount, dates, PO/project, SC basis fields, recorded-by, created-at, notes, attachment link
  - Feature: "Edit" shortcut button in view modal footer (gated by `canEdit`)
  - JS: `viewRow(id)`, `viewToEdit()` functions; spinner shown while loading
- `api/received_invoices.php`:
  - `get` action: added `recorded_by_name` join to `users` table

## 2026-05-19 (update 8)

### Receive Invoice — List refresh + auto-generated reference
- `app/bms/invoice/received_invoices.php`:
  - Bug fix: success handler now calls `modal.hide()` + `loadInvoices()` immediately, then fires SweetAlert — eliminates Bootstrap 5 + SweetAlert2 timing issue where `getInstance()` returned null and `loadInvoices()` never ran
  - Bug fix: added `shown.bs.modal` handler that loads the party list (suppliers/SCs) and generates the invoice reference on every new-invoice modal open — supplier dropdown was empty on first open (no `change` event fires for the default radio selection)
  - Feature: Invoice Reference No. now auto-generated as `INV-YYYY-NNNN` when modal opens; refresh button (↻) lets user regenerate; field is still editable for overrides
- `api/received_invoices.php`:
  - Added `get_next_ref` GET action — returns next `INV-YYYY-NNNN` reference based on `MAX()` of existing refs for the current year

## 2026-05-19 (update 7)

### Receive Invoice — Fixes & Enhancements
- `app/bms/invoice/received_invoices.php`:
  - Bug fix: moved `initDataTable()` before `loadInvoices()` in `$(document).ready` — eliminates race condition where `riTable` was null when AJAX callback fired, causing list to appear empty after first create
  - Bug fix: added `setTypeMode('supplier')` on page init to correctly set field visibility on first modal open
  - Feature: project selection now shown for ALL invoice types (supplier + SC); for supplier it is optional, for SC it is required
  - Feature: `setTypeMode()` updated to toggle project label and `required` attr by type
  - Feature: supplier `#f-supplier change` handler now calls `loadProjects(sid, 'supplier')` alongside `loadPOs`
  - Feature: `loadProjects()` now accepts `type` param and passes it to API
  - Feature: `editRow()` now loads projects for supplier type too (pre-fills saved value)
  - UI: stat cards now have `background-color: #d1e7dd` green background
  - UI: table header changed from `table-dark` to `bg-white` (white background)
  - UI: first column header changed from `#` to `S/No`
- `api/received_invoices.php`:
  - `get_projects` action: added `type` param — `type=supplier` queries `supplier_projects` join table, `type=sub_contractor` (default) queries `sub_contractor_projects`
- `app/bms/Suppliers/supplier_details.php`:
  - Added `Project (optional)` field to RI record/edit modal
  - `loadRiProjects(selectedId)` function added — loads this supplier's projects via `get_projects?type=supplier`
  - Modal `shown.bs.modal`: initialises project Select2 and loads options
  - Modal `hidden.bs.modal`: destroys and resets project select
  - `riEditRow()`: pre-fills project field when editing existing invoice

---

## 2026-05-19 (update 6)

### Phase 5 — Receive Invoice: Sub-Contractor Details Integration + Bug Fixes
- `app/bms/operations/sub_contractor_details.php` (modified):
  - **Bug fix** — PO query: added `AND supplier_id = ?` so only this SC's POs are fetched (was returning all suppliers' POs in the same projects)
  - **Bug fix** — Payments: switched from `supplier_payments` to `sc_payments` table; query now uses correct columns (`id AS payment_id`, no PO join)
  - **Bug fix** — `$milestones_count`: replaced hardcoded `0` with `SELECT COUNT(*) FROM project_milestones WHERE project_id IN (...)`
  - **Bug fix** — `$paid_amount`: replaced hardcoded `0` with `SELECT COALESCE(SUM(amount), 0) FROM sc_payments WHERE supplier_id = ?`
  - Added `$received_invoices_count` query using `supplier_invoices` table
  - Desktop action bar: added "Record Invoice" button (green, `bi-receipt`), gated by `canCreate('suppliers')`
  - Mobile actions dropdown: added "Record Invoice" item with same gate
  - New "Received Invoices" section with AJAX DataTable inserted before Related Tables — columns: S/No, Invoice Ref, Date Raised, Date Recorded, Project, Basis, Amount, Status, Actions (3: View/Download, Edit, Delete)
  - DataTable loaded via `api/received_invoices.php?action=list&supplier_id=X` on page ready
  - Record/Edit Invoice modal added (type=sub_contractor locked, supplier_id locked): fields — Invoice Ref, Project (Select2, pre-loaded from assigned projects), Invoice Basis (Select2: IPC/Milestone/Scope/Final), Basis Ref, Date Raised, Date Recorded, Amount, Attachment, Notes
  - Mobile card view for Received Invoices section with `applyRiScView()` + resize listener

---

## 2026-05-19 (update 5)

### Phase 4 — Receive Invoice: Supplier Details Integration
- `app/bms/Suppliers/supplier_details.php` (modified):
  - PHP: added received invoices COUNT query at top (`$received_invoices_count`)
  - Desktop action bar: added "Record Invoice" button (green, `bi-inbox`), gated by `canCreate('received_invoices')`
  - Mobile actions dropdown: added "Record Invoice" item with same gate
  - New "Received Invoices" section (card + DataTable) inserted before Purchase Orders row — columns: S/NO, Invoice Ref, Date Raised, Date Recorded, PO Reference, Amount, Status, Actions (3: View/Download, Edit, Delete)
  - DataTable loaded via AJAX `api/received_invoices.php?action=list&type=supplier&supplier_id=X` on page ready
  - Badge count (`#ri-count-badge`) updated live from AJAX response
  - Record/Edit Invoice modal added (supplier type + supplier_id locked, no type toggle): fields — Invoice Ref, PO (Select2 cascaded from API), Date Raised, Date Recorded, Amount, Attachment, Notes
  - Edit: loads row via `action=get`, pre-fills modal fields including Select2 PO
  - Delete: SweetAlert2 confirm → `action=delete` → reloads DataTable
  - View/Download: `window.open` on attachment path, "No Attachment" Swal if empty

---

## 2026-05-19 (update 4)

### Phase 3 — Receive Invoice: Main List Page + Entry Points
- `app/bms/invoice/received_invoices.php` (new, 601 lines): full list page — 4 stat cards (total, total amount, by supplier count, by SC count); filter bar (type/status/date range); DataTable with 10 columns; Add/Edit modal with radio toggle switching between supplier fields (PO cascade) and SC fields (project + basis + basis ref cascade); mobile card view (§5); SweetAlert2 confirms (§6); Select2 on all dropdowns (§4); CSRF; spinner on submit
- `roots.php` (modified): added `received_invoices` and `received_invoices.php` route keys mapping to new page
- `app/bms/invoice/invoices.php` (modified): added "Received Invoices" button beside New Invoice, gated by `canView('received_invoices')`
- `header.php` (modified): added "Received Invoices" nav link under Finance > Sales & Purchases and under Sales dropdown, both gated by `canView('received_invoices')`

---

## 2026-05-19 (update 3)

### Phase 2 — Receive Invoice: API Layer
- `api/received_invoices.php` (new): single-file CRUD API with 8 actions
  - GET `list` — all received invoices, filterable by type/supplier_id/status; JOINs suppliers, sub_contractors, purchase_orders, projects, users
  - GET `get` — single invoice by id
  - GET `get_suppliers` — supplier list for Select2 (id/text pairs)
  - GET `get_sub_contractors` — SC list for Select2
  - GET `get_pos` — POs for a given supplier_id (cascades from who selection)
  - GET `get_projects` — projects for a given SC supplier_id (via sub_contractor_projects join)
  - POST `create` — inserts new row; validates type, required fields, amount > 0; handles attachment upload (5-check security: ext + MIME + size + safe name + .htaccess)
  - POST `update` — edits existing row, replaces attachment if new file provided
  - POST `delete` — soft delete (status = 'deleted')
  - All state-changing actions: CSRF check → permission gate → validate → PDO → logActivity → JSON

---

## 2026-05-19 (update 2)

### Phase 1 — Receive Invoice: Database Foundation
- `migrations/2026_05_19_supplier_invoices.php` (new): creates `supplier_invoices` table — columns: invoice_type (supplier/sub_contractor), supplier_id, invoice_ref, date_raised, date_recorded, po_id (null), project_id (null), sc_invoice_basis (IPC/Milestone/Scope/Final), sc_basis_ref, amount, attachment, status (draft/submitted/approved/paid/deleted), notes, recorded_by, timestamps. Indexes on supplier_id, po_id, project_id, type, status.
- `migrations/2026_05_19_received_invoices_permissions.php` (new): inserts `received_invoices` permission row into `permissions` table (module = Finance). Uses INSERT IGNORE pattern via SELECT guard — idempotent.

---

## 2026-05-19 (update 1)

### CLAUDE.md — Selective loading to reduce context and speed up responses
- `CLAUDE.md`: removed 4 heavy @imports (`migrations`, `templates`, `security`, `strategy`); kept only `dev-standards.md` (~94 lines) and `process.md` (~110 lines) as always-loaded — saves ~640 lines per session
- Added trigger-word comments in CLAUDE.md: `#migrate`, `#newpage`, `#secure`, `#plan` for on-demand loading
- `migrations/CLAUDE.md` (new): auto-loads `.claude/migrations.md` only when editing migration files
- `api/CLAUDE.md` (new): auto-loads `.claude/security.md` only when editing API files
- `app/CLAUDE.md` (new): auto-loads `.claude/templates.md` only when editing app pages

---

## 2026-05-18 (update 11)

### app/bms/operations/project_view.php — Supplier mode: Purchase Orders tab + supplier filter
- **Removed Scope tab from supplier mode**: in restricted_mode, Scope dropdown now only shows for SC mode; supplier mode gets no Scope tab (not relevant to suppliers)
- **Added Purchase Orders tab for supplier mode**: first tab in restricted_mode is now "Purchase Orders" (active by default) when `$supplier_mode` is true
- **`#purchases` tab-pane**: made `show active` conditionally when `$supplier_mode` is true; `scope-original` pane made active only for SC restricted mode (not supplier mode) to avoid tab conflict
- **Filtered POs by supplier**: `renderPurchasesFull()` now filters purchase orders to only those belonging to `viewSupplierId` when `supplierMode` is true
- **`createPurchaseOrder()`**: appends `&supplier=${viewSupplierId}` to the URL when in supplier mode so the new PO is pre-filled with the correct supplier

---

## 2026-05-18 (update 10)

### app/bms/Suppliers/supplier_payments.php — Print footer CSS + printSlip fix
- Added `<?php require ROOT_DIR . '/includes/print_footer_css.php'; ?>` after the `<style>` block so the standard footer renders correctly on print (fixed position at bottom, correct typography, 14mm body padding)
- Removed stale `slip_print_date` JS reference from `printSlip()` — the date is now rendered by PHP via `print_footer_html.php`; function simplified to `window.print()`

---

## 2026-05-18 (update 9)

### CLAUDE.md — Split into modular files
- `CLAUDE.md` reduced from ~1580 lines to 20 lines (project overview + general rules + @imports only)
- Created `.claude/migrations.md` — migration system rules
- Created `.claude/dev-standards.md` — §1–§7 development standards
- Created `.claude/templates.md` — §8–§17 page/API templates, URL routing, permissions, soft delete, logging, XSS, icons, AJAX pattern, stats cards
- Created `.claude/security.md` — §18–§22 constants, file upload security, auth/session, CSRF, RBAC
- Created `.claude/strategy.md` — §23–§25 do-not-add list, features roadmap, production gaps
- Created `.claude/process.md` — §26–§29 UI anti-patterns, PDO reference, page-touch walkthrough, button test cases

---

## 2026-05-18 (update 8)

### Print Footer — Standardised across all 10 standalone print pages
- Created `includes/print_footer_css.php`: shared CSS for `.print-footer` (12px font, `position:fixed; bottom:0`, `padding-bottom:14mm` in `@media print` to prevent footer overlapping body content)
- Created `includes/print_footer_html.php`: shared HTML with `Printed by <strong>Name</strong> — <strong>Role</strong> on <date>` and BJP Technologies brand line; uses session fallbacks; respects pre-set variables from calling file
- Removed all internally-defined `.print-footer` CSS blocks, `$printed_by / $printed_role / $printed_at / $copy_year` vars from all 10 files
- Added Bank Details block (Bank Transfer / Mobile Money / Cheque from `system_settings`) to:
  - `app/bms/sales/print_sales_order.php`
  - `app/bms/invoice/invoice_print.php`
  - `app/constant/accounts/payment_voucher_print.php`
- Files fully migrated to external footer (footer only, no bank details):
  - `api/account/print_rfq.php`
  - `api/account/print_delivery_note.php`
  - `api/account/print_purchase_order.php`
  - `app/bms/grn/grn_print.php`
  - `app/bms/purchase/print_purchase_return.php`
  - `app/bms/sales/sales_returns/print_sales_return.php`
  - `app/bms/operations/print_ipc.php`

## 2026-05-18 (update 7)

### Print Quotation — Bank Details block beside totals
- `app/bms/sales/print_sales_order.php`:
  - Fetches bank/payment settings from `system_settings` at render time (keys: `bank_name`, `account_name`, `account_number`, `swift_code`, `mpesa_paybill`, `mpesa_account_no`, `check_payable_to` — same keys saved by `payment_settings.php`)
  - Replaced `float:right` totals layout with a flex row: Bank Details block on the left, amounts (Subtotal / Tax / Shipping / Grand Total) on the right
  - Bank Details block shows Bank Transfer section, Mobile Money section, and Cheque section — each only rendered when that group has data
  - If no bank details configured at all, block is hidden and totals remain right-aligned
  - Bank Details block styled to match existing `.box` convention (grey background, blue left border, same typography)

## 2026-05-18 (update 6)

### Tenders — tender_edit.php §2 parity fix
- `app/bms/tenders/tender_edit.php`:
  - Phase 3 heading renamed from "TENDER PERTICIPATION FEE" → "Tender Entrance Fee" with explanatory subtitle (matches create form)
  - POST handler now reads `entrance_fee_tzs`/`entrance_fee_usd` from `$_POST` (was `tender_amount_tzs`/`tender_amount_usd`)
  - UPDATE SQL now writes to `entrance_fee_tzs`/`entrance_fee_usd` columns (was `tender_amount_tzs`/`tender_amount_usd`)
  - Form input names/IDs changed to `entrance_fee_tzs`/`entrance_fee_usd`; pre-fill now reads `$tender['entrance_fee_tzs']`/`$tender['entrance_fee_usd']`
  - Card headers updated: "Tender Amount & Submission Document" → "Entrance Fee" (Tshs and USD sections)
  - Input labels updated: "Tender Amount (Tshs/USD)" → "Entrance Fee (Tshs/USD)"
  - JS `required` binding updated to `#entrance_fee_tzs`/`#entrance_fee_usd`
  - Added `csrf_check()` call at top of POST handler
  - Added `<input type="hidden" name="_csrf">` token to wizard form
  - Upload handler now applies all 5 §19 security checks (extension whitelist, finfo MIME check, 20 MB limit, `bin2hex(random_bytes(16))` filename, `mkdir(0755)`)

## 2026-05-18 (update 5)

### Tenders — §28 walkthrough fixes (CSRF + upload security)
- `helpers.php`: added `csrf_token()` and `csrf_check()` helpers (§21 — required globally)
- `app/bms/tenders/tender_create.php`:
  - Added `csrf_check()` call at top of POST handler
  - Added `<input type="hidden" name="_csrf">` token to wizard form
  - Upload handler now applies all 5 §19 checks: extension whitelist (pdf/doc/docx/xls/xlsx/jpg/png), `finfo` MIME-byte validation, 20 MB size limit, `bin2hex(random_bytes(16))` safe filename, `mkdir(0755)` (was 0777)
- `uploads/tenders/.htaccess` (new): blocks PHP/script execution in the upload folder

## 2026-05-18 (update 4)

### Tenders — separate Entrance Fee from Tender Sum (Contract Sum)
- `migrations/2026_05_18_add_entrance_fee_columns.php` (new): adds `entrance_fee_tzs` and `entrance_fee_usd` columns to `tenders`; back-populates them from `tender_amount_tzs`/`tender_amount_usd` for records still in PENDING/APPROVED/INVITATION status
- `app/bms/tenders/tender_create.php`:
  - Phase 3 POST handler now saves to `entrance_fee_tzs`/`entrance_fee_usd` (new columns) instead of `tender_amount_tzs`/`tender_amount_usd`
  - Phase 3 heading renamed from "Tender Participation Fee" → "Tender Entrance Fee" with explanatory sub-text clarifying this is the document-purchase fee, not the bid amount
  - Input labels updated to "Entrance Fee (Tshs/USD)"
- `app/bms/tenders/tender_view.php`:
  - Added dedicated **Entrance Fee** row (reads `entrance_fee_tzs`/`entrance_fee_usd`; shows "Not recorded" if absent)
  - **Tender Sum (Contract Sum)** row now reads `tender_amount_tzs`/`tender_amount_usd` (set by Financial Submission); shows "Not yet submitted — set during Financial Submission" when null (pre-submission tenders)

## 2026-05-18 (update 3)

### CLAUDE.md — Workflow-status permissions + page-touch walkthrough
- `CLAUDE.md`:
  - **§1 (Button Testing)** — added the **page-audit rule**: before editing any frontend page, read §1–§27 and fix any rule violations as part of the same task; list fixes in the commit message
  - **§11.1 Workflow-Status Permissions (NEW)** — full catalogue of permission verbs beyond CRUD (submit / review / approve / post / void / reject / publish / cancel / reopen / export / print) with: helper names, typical role allowed, page-level pattern, status-button rendering pattern, and a complete API endpoint pattern that enforces allowed transitions + permission per verb + audit log. Mandates segregation of duties (creator ≠ approver) and immutability after posting
  - **§28 Chronological Page-Touch Walkthrough (NEW)** — 28-step ordered checklist from "create feature branch" → "commit & push", each step linked to the relevant section. Steps marked N/A in commit message when not applicable
  - **§29 Per-Button Test Cases (NEW)** — concise manual-test list per button type (View / Add / Edit / Delete / Status / Search / Export / Print / Modal close)
  - **Summary section (NEW)** — high-level table of contents grouping all 29 sections into: Bootstrap & Deploy / Dev Standards / New Page Reference / Constants & Security / Strategic Direction / Operations & Quality / Process

## 2026-05-18 (update 2)

### CLAUDE.md — Production concerns & forbidden UI patterns
- `CLAUDE.md`: Added 2 new sections (§25 & §26) covering operational gaps and UI anti-patterns:
  - **§25 Operational Gaps to Close** — 7 production-grade items currently missing: CSP/security headers (full `.htaccess` snippet), rate limiting (MySQL-backed `rateLimitCheck()` helper, recommended limits), automated DB backups (cron + retention + off-site + restore test), error monitoring (custom error_log table or Sentry), staging environment + rollback strategy, `/health.php` endpoint for uptime monitors, log rotation (logrotate config + DB-log pruning)
  - **§26 Forbidden UI Patterns** — 13 UI/UX anti-patterns banned across the system: auto-playing media, modal-in-modal, auto-refresh wiping input, dashboard carousels, horizontal mobile scroll, hover-only buttons, <3s flash messages, >7 top-nav items, long forms without "Save & Continue", files >1000 lines, functions >100 lines, nesting >4 levels deep, `!important` in CSS
  - **§27 PDO Quick Reference** — renumbered from previous §25

## 2026-05-18 (update 1)

### CLAUDE.md — Security, constants, roadmap & "do not add" list
- `CLAUDE.md`: Added 8 new reference sections (§18–§25) covering audit findings:
  - **§18 Constant Conventions** — entity code prefixes (CUST-, SUP-, PRD-…), TZS/Tanzania defaults, full helper-function catalogue
  - **§19 File Upload Security (CRITICAL)** — five mandatory checks (ext + MIME magic-bytes + size + safe filename + .htaccess); gatekeeper download pattern for sensitive docs. Documented that current logo/document uploads fail this bar
  - **§20 Authentication & Session Security** — `session_regenerate_id()` after login, cookie HttpOnly/Secure/SameSite flags, failed-attempt tracking + 15-min lockout, password reset rules
  - **§21 CSRF Protection** — `csrf_token()`/`csrf_check()` helpers, hidden field in every form, jQuery `ajaxSetup` header
  - **§22 Access Control Depth (RBAC)** — extended verb set (approve/review/export/print/post/void), full role matrix (Admin/Manager/Accountant/Sales/Procurement/Storekeeper/HR/Auditor/Field Officer), row-level scope, 2FA for elevated roles
  - **§23 What NOT to Add** — hard "do not add" list (no frameworks, no ORMs, no SPA, no build step, no TS, no microservices, no GraphQL, no extra CSS/icon/chart libraries…) to keep the raw-PHP setup productive
  - **§24 Trending Features Roadmap** — 5 phases prioritised for 2026 + Tanzania: security hardening → TRA EFD / M-Pesa / WhatsApp / Swahili / SMS → barcode + 2FA + PWA + REST API + webhooks → dashboards + OCR + AI assist + predictive reorder → dark mode + e-signature + timelines
  - **§25 PDO Query Patterns** — renumbered from previous §18

## 2026-05-17 (update 16)

### CLAUDE.md — Documented all common codebase patterns
- `CLAUDE.md`: Added 11 new reference sections (§8–§18) covering every pattern used across the project:
  - **§8 New Page Template** — full PHP skeleton with auth, permissions, DataTable, modals, mobile cards, AJAX
  - **§9 New API Endpoint Template** — 6-step structure (auth → permission → method → validate → logic → log)
  - **§10 URL & Routing Rules** — `getUrl()` vs `buildUrl()`, never hardcode paths
  - **§11 Permission System** — `canView/canCreate/canEdit/canDelete('page_key')` usage and admin bypass
  - **§12 Soft Delete** — always `UPDATE … SET status = 'deleted'`, never `DELETE FROM`
  - **§13 Activity Logging** — `logActivity()` required on every write; `logActivityAction()` in JS
  - **§14 Safe Output / XSS** — `safe_output()` in PHP, `safeOutput()` in JS for all rendered values
  - **§15 Icon Library** — Bootstrap Icons (`bi bi-*`) only; no Font Awesome on new pages
  - **§16 AJAX Submit Button Pattern** — disable + spinner during request, restore in `complete:`
  - **§17 Statistics Cards Pattern** — 2×4 grid above every list table with colour conventions
  - **§18 PDO Query Patterns** — quick reference for fetch/insert/update/soft-delete/transaction/column-check

## 2026-05-17 (update 15)

### Locations — fix "headers already sent" on delete + SweetAlert2 alerts + CLAUDE.md standards
- `app/bms/stock/locations.php`:
  - **Fix "Cannot modify header information — headers already sent"**: Moved entire `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block (add, update, delete handlers) to BEFORE `includeHeader()`. Root cause: `includeHeader()` was called on line 9 before the POST handlers, so HTML output from `header.php:77` was already sent before `header("Location: ...")` ran on delete/add/update.
  - **SweetAlert2 for success/error**: Replaced Bootstrap alert divs with inline `<script>` blocks that call `Swal.fire()` on `DOMContentLoaded` — users now see a SweetAlert2 popup after add, edit, or delete operations.
  - **Select2**: Added `select2-static` class and explicit `select2()` init to filter dropdowns (warehouse, status) and all three modal dropdowns (warehouse, type, status). Modal selects use `dropdownParent: $('#locationModal')` and are re-initialized on `shown.bs.modal` to prevent dropdown clipping.
  - **Mobile card view**: Added `d-md-none` card section below the table — each card shows name, code, warehouse, type, item count, qty, and action buttons in a single `flex-wrap:nowrap` row with `flex:1;min-width:0` so buttons never wrap regardless of count. Table is wrapped in `d-none d-md-block` so it's desktop-only.
  - **Sticky navbar on mobile**: Added `@media (max-width:767px)` CSS rule making `.navbar` sticky (`position:sticky; top:0; z-index:1020`).
- `migrations/2026_05_17_locations_status_deleted.php`: Added `'deleted'` to `locations.status` ENUM. The soft-delete handler (`UPDATE locations SET status='deleted'`) was silently failing because `'deleted'` was not a valid ENUM value — MySQL converted it to `''` without error.

## 2026-05-17 (update 14)

### Deploy — create all upload directories on all servers
- `.github/workflows/deploy.yml`: Replaced per-directory mkdir lines with a single `mkdir -p` call covering all 29 upload paths used across the codebase, followed by `chmod -R 777 uploads/`. Fixes `move_uploaded_file(): No such file or directory` for delivery notes, products, and any other module whose upload folder was missing. Root cause: PHP www-data cannot create directories; deploy script runs as privileged user.

## 2026-05-17 (update 13)

### Deploy — create uploads/products on all servers
- `.github/workflows/deploy.yml`: Added `mkdir -p uploads/products && chmod 777 uploads/products` to all four server lines. Fixes `move_uploaded_file(): Failed to open stream: No such file or directory` when uploading a product image — the directory was missing on the server and PHP's www-data user cannot create it.

## 2026-05-17 (update 12)

### Purchase Orders — delete fix + mobile card button row
- `api/delete_purchase_order.php`: Changed `$_POST['id']` to `$_POST['order_id'] ?? $_POST['id']` — fixes "Purchase order ID is required" error. `purchase_orders.php` sends `order_id` but the local API was reading `id`.
- `app/bms/purchase/purchase_orders.php`:
  - **Mobile card buttons**: Changed from `flex-wrap` (wrapping) to `flex-wrap:nowrap` with `flex:1;min-width:0;padding:3px 4px;font-size:0.72rem` on each button — all action buttons now stay in a single non-wrapping row, matching the DN page pattern.
  - **Icon-only on mobile**: Removed text labels from card action buttons (View, Approve, Edit); icon-only with `title` attributes for accessibility.
  - **View toggle hidden on mobile**: Added `d-none d-md-flex` to the table/card toggle button group (mobile always shows card view).

## 2026-05-17 (update 11)

### Products — product_edit.php / update_product.php form parity with add modal
- `app/bms/product/product_edit.php`:
  - **Removed Product Type selector**: Removed Inventory/Service radio card block (no equivalent in add modal). Hidden `is_service` and `track_inventory` inputs preserved with current values.
  - **Removed `onProductTypeChange()` JS**: No longer needed; removed its call in `$(document).ready`.
  - **Simplified tracking section**: Removed redundant Tracking badge column from Inventory tab row.
  - **Pricing colors matched**: Selling price TZS → `bg-success text-white`; Wholesale → `bg-info text-white` input-group; Min Selling Price → visible input with `bg-danger text-white` TZS span.
  - **Removed duplicate is_taxable**: Cleaned up second checkbox and empty comment div from Advanced tab.
  - **Added editable Current Stock section**: Shows and accepts stock quantities per warehouse in the Inventory tab (same layout as Opening Stock in add modal). Inputs have `name="stock[warehouse_id]"`.
- `api/update_product.php`:
  - **Added stock adjustment handling**: Reads `$_POST['stock']` array, compares each warehouse quantity with current value, creates `adjustment_in`/`adjustment_out` stock movements for any changes, and upserts `product_stocks`.

## 2026-05-17 (update 10)

### Finance > Expenses — table column polish
- `app/constant/accounts/expenses.php`:
  - Removed "Account" and "Created By" columns from the expenses table.
  - Renamed "Category Path" header to "Category"; column now shows only the leaf segment (last sub-category) as plain text — no badge/rectangle.
  - "Project" column also changed from a badge to plain text.
  - Mobile card view updated to match: leaf category shown as plain text, no badge.
  - Amount column shows "Day: X" subtitle when multiple expenses share the same category on the same date.
- `api/account/get_expenses.php`:
  - Column sort mapping updated.
  - Category path (`Type › Category › Sub`) built server-side; leaf extracted client-side for display.
  - Daily category total computed per date+category and returned as `daily_category_total`.

## 2026-05-17 (update 9)

### Products — bug fixes and CLAUDE.md standards applied
- `app/bms/product/products.php`:
  - **Bug fix**: After adding a product, redirect to `?sort_by=created_at&sort_order=DESC` so the new product always appears first. Previously `location.reload()` kept alphabetical sort, hiding the new product on page 2+.
  - **Bug fix**: Dimension field names in add modal corrected: `dim_l/dim_w/dim_h` → `dim_length/dim_width/dim_height` to match what `create_product.php` reads. Dimensions were silently not saving.
  - **Select2**: Added `select2-static` to filter selects (category, brand, supplier) and modal selects (category, tax, brand, supplier).
  - **Mobile**: View toggle button group set to `d-none d-md-flex` (hidden on mobile, desktop only).
  - **Mobile sticky navbar**: Added `position:sticky; top:0; z-index:1020` CSS for `.navbar` at mobile breakpoint.
- `app/bms/product/product_edit.php`:
  - **Bug fix**: Added `name="dim_length"`, `name="dim_width"`, `name="dim_height"` to dimension inputs. Without these, `update_product.php` received no dimension data and cleared dimensions on every save.
  - **Select2**: Added `select2-static` to category, tax, brand, and supplier selects.

## 2026-05-17 (update 8)

### Purchase Orders — fix "Invalid Order ID" on delete online
- `app/bms/purchase/purchase_orders.php` — Fixed `cancelOrder()` AJAX call sending `{ id: id }` while `api/account/delete_purchase_order.php` expects `order_id`. Changed to `{ order_id: id }`. Locally WAMP served the physical `api/delete_purchase_order.php` (which reads `id`); online the router maps to `api/account/delete_purchase_order.php` (which reads `order_id`), causing the mismatch.

## 2026-05-17 (update 7)

### Migration — purchase_receipt_attachments table
- `migrations/2026_05_17_purchase_receipt_attachments.php` — Creates `purchase_receipt_attachments` table on all servers. Fixes fatal PDOException on `grn_view.php` on servers where the table was never created (it was previously only created lazily inside `create_grn.php`).

## 2026-05-17 (update 6)

### Deploy — create uploads/documents on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/documents && chmod 777 uploads/documents` to all four server deploy lines. Fixes `Warning: mkdir(): Permission denied` in `upload_document.php` — the directory was missing so PHP couldn't create it under the `www-data` user.

## 2026-05-17 (update 5)

### Deploy — create uploads/document_library on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/document_library && chmod 777 uploads/document_library` to all four server deploy lines. Fixes `mkdir(): Permission denied` error when uploading documents via the document library.

## 2026-05-17 (update 4)

### Document Library — CLAUDE.md standards applied
- `app/constant/document/document_library.php` — Added mobile card view with toggle (`d-none d-md-flex`); `drawCallback` renders cards from AJAX row data. Select2 (`select2-static`) on `#categoryFilter` (filter) and `#category_id` (upload modal). Updated `clearFilters()` to trigger Select2 reset. Sticky navbar CSS. `@media print` hides card grid.

## 2026-05-17 (update 3)

### Edit Customer — sticky navbar CSS
- `app/bms/customer/edit_customer.php` — Added sticky navbar CSS to existing `@media (max-width: 768px)` block. No other changes needed (no tables, no plain selects, already uses SweetAlert2).

## 2026-05-17 (update 2)

### Customer Details — CLAUDE.md standards applied
- `app/bms/customer/customer_details.php` — Added DataTable to Sales Orders table (`#customerOrdersTable`) and Invoices table (`#customerInvoicesTable`). Added mobile card view (toggle hidden on mobile with `d-none d-md-flex`; `drawCallback` renders card grids). Added Select2 (`select2-static`) to `#edit_category_id` and `#edit_project_id` in edit modal; init on `shown.bs.modal`. Added sticky navbar CSS (`@media max-width 767px`). Added `@media print` rule to hide card grids. Moved DataTable/view JS to unconditional script block; edit form handler and `editCustomer()` remain in conditional (`$can_edit_customers`) block.

## 2026-05-17 (update 1)

### Customers — CLAUDE.md standards applied
- `app/bms/customer/customers.php` — Mobile-enforced card view (`col-12`, icon-only footer buttons, `flex-nowrap`). Select2 on `#categoryFilter`, `#category_id`, `#project_id` (add + edit modals). View toggle hidden on mobile (`d-none d-md-flex`). Sticky navbar CSS. Resize handler.

## 2026-05-16 (update 13)

### Expenses — Cascade drill-down category selection (single select)
- `app/constant/accounts/expenses.php` — Replaced multi-select checkbox category block with a cascading single-select dropdown. Selecting an expense type shows a "Select Category" dropdown; if the chosen category has sub-categories, a "Select Sub-category" dropdown appears below it automatically. Only the deepest selected category is saved per expense. Edit modal restores the full cascade path for the stored category. Added `renderCascadeDropdown()`, `populateCascadeForCategory()`, and cascade-change handler. Removed `toggleAllCategories()` and the inline quick-add category input.
- `api/account/add_expense.php` — Changed from `category_ids[]` (array) to single `category_id` integer.
- `api/account/update_expense.php` — Same change; syncs single category on update.

## 2026-05-16 (update 12)

### Purchase Order Details — Attachments as dedicated visible card
- `app/bms/purchase/purchase_order_details.php` — Moved `#attachmentsSection` out of the Notes card where it was buried and hard to find. Now renders as its own card (paperclip icon header "Documents & Attachments") below the Notes card in the right panel. Hidden on print (`d-print-none`). Shows automatically when attachments exist.

## 2026-05-16 (update 11)

### Deploy — ensure uploads/purchase_orders is writable on all servers
- `.github/workflows/deploy.yml` — Added `mkdir -p uploads/purchase_orders && chmod 775 uploads/purchase_orders` after each server's migration step. Runs as the SSH deploy user (who owns the directory) so chmod succeeds — unlike the PHP migration which ran as www-data and couldn't change permissions.

## 2026-05-16 (update 10)

### Hotfix — PO attachment upload permission denied on live server
- `migrations/2026_05_16_fix_uploads_permissions.php` — Sets `uploads/purchase_orders/` to `0775` (fallback `0777`) so the web server process can write uploaded files. Previous migration created the directory but left it with restrictive permissions.
- `api/account/save_purchase_order.php` — `move_uploaded_file()` now uses `@` to suppress the PHP warning (which was corrupting the JSON response and causing "System Error" in the browser), and throws a proper Exception instead so the error is shown as a clear SweetAlert message.

## 2026-05-16 (update 9)

### Hotfix — DN redirect wrong + PO attachment crash on live servers
- `app/bms/grn/dn_create.php` — `$return_url` now returns to `purchase_order_details?id={po_id}` when opened via `?po_id=`. Previously it always went to `project_view` (if PO had a project) or `delivery_notes`, ignoring the PO origin.
- `migrations/2026_05_16_purchase_order_attachments.php` — Creates `purchase_order_attachments` table on live servers where it was missing (table existed only in local DB, no migration existed). Also creates `uploads/purchase_orders/` directory if absent. The missing table caused a fatal DB error when attaching documents to a PO, corrupting the JSON response and triggering the "System Error" in the browser.

## 2026-05-16 (update 8)

### Purchase Orders — Hide "Add Delivery Note" when delivery is complete
- `app/bms/purchase/purchase_orders.php` — "Add Delivery Note" gear dropdown item now only shows when `status === 'approved'` AND `delivery_status !== 'complete'`. Removed `ordered` and `partially_received` from the condition. A completed PO no longer offers the option.

## 2026-05-16 (update 7)

### Purchase Order — PARTIAL/COMPLETE delivery status in list and details

- `api/account/get_purchase_orders.php` — Added `delivery_status` subquery: returns `'partial'` if at least one delivery exists but not all PO items are fully covered, `'complete'` if all items are fully delivered, `NULL` if no deliveries.
- `app/bms/purchase/purchase_orders.php` — Status column (table + mobile cards) now shows `PARTIAL` (yellow) or `COMPLETE` (green) when `delivery_status` is set, falling back to the raw PO status otherwise.
- `app/bms/purchase/purchase_order_details.php` — Top `#orderStatus` badge overrides to PARTIAL/COMPLETE when the PO is approved and deliveries exist. Per-delivery-note status badge removed from the DN panel below.

## 2026-05-16 (update 6)

### Purchase Order Details — Delivery Notes panel below PO (screen only)
- `app/bms/purchase/purchase_order_details.php` — Added PHP queries to load all non-cancelled delivery notes linked to the PO, their items, and the PO ordered quantities. Renders a `d-print-none` section below the PO body showing: each DN as a card (number, date, received-by, status badge); items table with qty delivered, PO qty, unit, condition icon, and a progress bar showing cumulative coverage %. Overall delivery status badge (PARTIAL / COMPLETE) calculated by comparing total delivered vs PO ordered quantities per product. Print button/preview completely untouched.

## 2026-05-16 (update 5)

### Warehouse Delete — cascade delete with informative confirmation alert
- `ajax_delete_warehouse.php` — Redesigned with two-step flow: first call returns counts (products, stock qty, locations); JS shows SweetAlert listing exactly what will be removed; second call with `confirmed=1` cascade-deletes `product_stocks`, `stock_movements`, `locations`, then soft-deletes the warehouse. Removed all blocking guards (stock check, location check).
- `app/bms/stock/warehouses.php` — `deleteWarehouse()` JS function updated to call AJAX twice: fetch counts first, show detailed warning SweetAlert, then confirm-delete. POST handler also updated to cascade-delete without blocking. Permission check updated to use `canDelete()` helper instead of Admin-only check.
- `migrations/2026_05_16_warehouse_status_deleted.php` — Adds `'deleted'` value to `warehouses.status` enum (was `active/inactive` only — caused Data truncated error on soft-delete). Idempotent.

## 2026-05-16 (update 4)

### Hotfix — Fatal crash on Add Delivery Note for approved Purchase Orders
- `migrations/2026_05_16_deliveries_purchase_order_id.php` — Adds `purchase_order_id INT NULL` to the `deliveries` table. Column existed in the local database but was never captured in a migration, so live servers were missing it. This caused `SQLSTATE[42S22]: Unknown column 'd.purchase_order_id'` in `app/bms/grn/dn_create.php` line 73 whenever a user clicked "Add Delivery Note" on an approved Purchase Order. Idempotent.

## 2026-05-16 (update 3)

### Finance > Expenses — Unlimited Category Hierarchy

- `app/constant/accounts/expenses.php` — `addExpenseModal` and `quickManageTypeModal` now only close via explicit X/Cancel buttons (hide.bs.modal intercepted with flag). Expense type change handler uses `flattenCategoryTree()` to render nested categories as indented checkboxes. Manage modal right panel redesigned: breadcrumb drill-down navigation (`activeManageCatPath` stack), gear dropdown per category row (Add Sub-category, Edit/Rename via SweetAlert input, Delete with cascade warning). New helpers: `flattenCategoryTree`, `findCatInTree`, `getCategoriesAtCurrentLevel`, `drillDownCategory`, `navigateManageBreadcrumb`, `renderManageBreadcrumb`, `renameManageCategory`. Updated `addManageCategory` passes `parent_id`; `editManageCategory` re-renders at current level; `deleteManageCategory` shows SweetAlert confirm with sub-category count warning.
- `api/finance/get_expense_schema.php` — Returns nested category tree via recursive `buildCategoryTree()`; SHOW COLUMNS guard falls back to flat query on un-migrated servers.
- `api/finance/manage_expense_schema.php` — `add_category` action accepts `parent_id`; SHOW COLUMNS guard used so INSERT works on both migrated and un-migrated servers.
- `migrations/2026_05_15_expense_category_hierarchy.php` — Adds `parent_id INT NULL` to `expense_categories`; adds self-referential FK `fk_expense_cat_parent` with ON DELETE CASCADE. Idempotent.

## 2026-05-16 (update 1)

### Hotfix \xe2\x80\x94 migration deploy failures
- `migrations/2026_05_13_expense_schema.php` \xe2\x80\x94 Guard `expenses` table existence before ALTER; remove `AFTER expense_account_id` (column may not exist on all servers).
- `migrations/2026_05_13_expense_schema_fix.php` \xe2\x80\x94 Same guards applied.
- `migrations/2026_05_13_expense_schema_final.php` \xe2\x80\x94 Same guards applied.
- `migrations/2026_05_14_sub_contractor_projects.php` \xe2\x80\x94 Guard `project_id` column existence in `sub_contractors` before INSERT IGNORE SELECT.

## 2026-05-16 (update 2)

### UX: Delete option now always visible in Warehouses List actions dropdown
- `app/bms/stock/warehouses.php` \xe2\x80\x94 Removed the `$warehouse['status'] != 'active'` condition that was hiding the Delete button for active warehouses. The button now appears for any user with delete permission regardless of warehouse status. Backend (`ajax_delete_warehouse.php`) already enforces safety \xe2\x80\x94 it blocks deletion if the warehouse has existing stock or locations.

## 2026-05-15 (update 6)

### SC mode test fixes — all assertions corrected
- `scratch/test_sc_mode_full.php` — Fixed 3 broken C-group assertions: C6 (`!\$sc_mode` was un-escaped, interpolating to empty string), C17 (multi-condition OR simplified to single clean strpos), C18 (cross-section regex replaced with SC-section extraction + substring checks). All tests now expected to pass.
- `scratch/test_assign_sc_project.php` — Auth guard updated to auto-set session from DB (same pattern as other scratch tests).
- `scratch/test_sc_details_full.php` — Rebuilt with auto-session, cleaner API-direct test approach.
- `app/bms/operations/project_view.php` — SC context banner text shortened (removed redundant tab list from banner text).

## 2026-05-15 (update 5)

### Sub-Contractor Project View — SC mode with filtered tabs

- `app/bms/operations/sub_contractor_details.php` — "View Project" links (name + gear dropdown) now include `&sc_id={supplier_id}` so project_view opens in SC mode.
- `app/bms/operations/project_view.php` — Detects `?sc_id=` param; when set, shows SC mode: only Scope, Sales (IPC+Invoices), Inventory, Inspections, Reports, Payments tabs. SC context banner added below header. Back button returns to sub-contractor. Overview tab replaced by Scope (Original) as default active pane. `scId`/`scMode` JS vars injected. `loadReportingData` and save-report FormData pass `sc_id` when in SC mode. New `#sc-payments` tab pane + SC Add Payment modal.
- `api/sc/get_payments.php` — Returns `sc_payments` rows for a given supplier_id + project_id.
- `api/sc/add_payment.php` — Inserts into `sc_payments` (dedicated SC payments table, separate from `supplier_payments`).
- `api/sc/delete_payment.php` — Deletes a row from `sc_payments`.
- `api/operations/get_progress_reports.php` — Accepts optional `sc_id`; filters reports to that SC when provided.
- `api/operations/save_progress_report.php` — Accepts optional `sc_id`; stores it on insert so SC reports are tagged. Upsert key includes `sc_id`.
- `migrations/2026_05_15_sc_project_context.php` — Adds `sc_id` column to `project_progress_reports`; creates `sc_payments` table with `supplier_id`, `project_id`, `receipt_number`, etc.

## 2026-05-15 (update 4)

### Inspection View — Attachments as DataTable with gear dropdown
- `app/bms/operations/inspection_view.php` — Attachments section converted to DataTable with gear+triangle dropdown per row: View Online (new tab), Download, Delete. overflow:visible on container so dropdown doesn't clip.
- `api/operations/delete_inspection_attachment.php` — New API: deletes attachment record from DB and physical file from disk.

## 2026-05-15 (update 3)

### Inspection — Dynamic named attachments in Add & Edit modals
- `app/bms/operations/project_view.php` — Replaced single file input with dynamic rows (name + file + remove) in both Add and Edit modals. Blue "Add Attachment" button below list. Shared `inspAddAttachRow()` JS helper. Reset clears attachment list on save.
- `api/operations/save_inspection.php` — Stores `display_name` from `attach_name[]` POST field alongside each uploaded file.
- `api/operations/get_inspection.php` — Returns `display_name` in attachments array.
- `app/bms/operations/inspection_view.php` — Shows `display_name` (falls back to original filename) in attachments table.
- `migrations/2026_05_15_inspection_attachment_name.php` — Adds `display_name` column to `inspection_attachments`.

## 2026-05-15 (update 2)

### Inspection Modal — Add Inspector button position, Edit multiple inspectors, View Details full page
- `app/bms/operations/project_view.php` — "Add Inspector" button moved below inspector rows, blue color. Edit Inspection modal: replaced single inspector fields with multiple inspectors (same add/remove pattern as Add modal). `inspEdit()` loads inspectors from DB. `inspUpdate()` uses FormData. `inspView()` opens new page instead of modal.
- `app/bms/operations/inspection_view.php` — New standalone full-screen page showing all inspection details (fields, inspectors table, attachments table) with Print and Back to Project buttons.
- `api/operations/get_inspection.php` — Now returns `inspectors` and `attachments` arrays alongside inspection data.
- `api/operations/save_inspection.php` — Update path now replaces inspection_inspectors rows when insp_name[] provided.
- `roots.php` — Added `inspection_view` route.

## 2026-05-15

### Add Inspection Modal — Recursive Milestones, Multiple Inspectors, Attachments
- `app/bms/operations/project_view.php` — Milestone query updated to top-level only (`parent_id IS NULL`); includes `scope` column. Add Inspection modal rebuilt: recursive sub-milestone cascade (AJAX), scope/inspected-scope display at deepest level, multiple inspectors (add/remove rows), file attachment input. `inspSave()` switched to FormData for file upload support; new JS helpers `inspOnMilestoneChange()` and `inspAddInspectorRow()` added.
- `api/operations/save_inspection.php` — Updated to store `sub_milestone_id`, `inspected_scope`; saves all inspectors to `inspection_inspectors` table; handles multi-file uploads to `uploads/inspections/{id}/`.
- `api/operations/get_sub_milestones.php` — New API: returns child milestones for a given `parent_id`.
- `migrations/2026_05_15_inspection_extras.php` — Adds `sub_milestone_id` + `inspected_scope` columns to `project_inspections`; creates `inspection_inspectors` and `inspection_attachments` tables with FK cascade; creates `uploads/inspections/` directory.
- `scratch/test_inspection_modal.php` — 18-test regression suite covering schema, milestone query, sub-milestone query, DB insert with new fields, cascade delete.
