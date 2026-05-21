# E-Signature Modernisation — Implementation Plan

**Status:** ✅ Complete — implemented, tested (141 + 26 tests pass), ready for PR.
**Branch:** `feature/esignature-modernization` (based on `develop`)
**Goal:** Make the document e-signature feature legally defensible (ESIGN/UETA-aligned)
and production-ready, while keeping the wizard simple for mid-tier clients.
**Scope:** Single-party / automated signing only — no multi-party workflow.

---

## Background

The current wizard (`select_document_add_esignature.php`) pastes a signature **image**
onto a PDF. That is the weakest tier of electronic signature: the signed file is an
ordinary PDF anyone can re-edit, with no proof it is unchanged. The three improvements
below make every signed document **provably signed** without adding any user friction.

The signing UX (4-step wizard) stays identical. All modernisation is automatic.

---

## Improvement 1 — Tamper-evident integrity + Certificate of Completion

- [x] Compute **SHA-256** of the PDF **before** signing and of the final signed file
      **after** signing. Hashes are computed **server-side** (authoritative) in
      `save_signed_pdf.php` — never trust the client.
- [x] Append a **Certificate of Completion** page to the signed PDF (client-side via
      `pdf-lib`, no PHP PDF library needed). The page shows: signer name + email,
      signing date/time, document name, original-document SHA-256, signing reference,
      and the consent statement.
- [x] New endpoint `api/document/verify_signed_document.php` — re-hashes the stored
      signed file and compares it to `hash_after`. Returns *Verified* / *Tampered*.
- [x] "Verify" button on the wizard finish screen + in the e-signatures History tab.

## Improvement 2 — Server-verified intent + audit trail + API hardening

- [x] Server **verifies consent** is present (`consent_text` + acceptance). Rejects the
      sign request if intent evidence is missing.
- [x] Store an audit trail on `document_signatures`: `user_agent`, `consent_text`,
      `consent_accepted_at`, `event_log` (JSON: viewed → consent → signed), the hashes,
      and a `signing_reference`.
- [x] Harden `save_signed_pdf.php`: add `canCreate('documents')` permission check;
      stop leaking exception text to the client (generic message + `error_log`);
      ensure `uploads/documents/.htaccess` exists.
- [x] Remove dead `uint8ToBase64()` helper from the wizard JS.

## Improvement 3 — `upload_signature.php` hardening (§19)

- [x] Whitelist by **extension** (`png`, `jpg`, `jpeg`).
- [x] Whitelist by **real MIME** (`finfo` magic bytes) — stop trusting `$_FILES['type']`.
- [x] Enforce **size limit** (2 MB, matches the modal text).
- [x] **Non-guessable filename** (`bin2hex(random_bytes(16))`), `mkdir(0755)` not `0777`.
- [x] Write `uploads/signatures/.htaccess` to block script execution.
- [x] Add `logActivity()` on success.
- [x] Fix the upload modal `accept` attribute in `e_signatures.php` (drop `.gif` —
      pdf-lib can only embed PNG/JPG).

---

## Files

### New
- [x] `migrations/2026_05_21_esignature_audit_columns.php` — add audit/integrity columns
- [x] `api/document/verify_signed_document.php` — integrity verification endpoint
- [x] `tests/test_esignature_integrity_cli.php` — CLI test suite for the new work

### Modified
- [x] `api/document/save_signed_pdf.php` — hashing, audit fields, hardening
- [x] `api/document/upload_signature.php` — §19 file-upload hardening
- [x] `app/constant/document/select_document_add_esignature.php` — cert page, hashes,
      consent capture, event log, remove dead code, Verify button
- [x] `app/constant/document/e_signatures.php` — upload modal `accept` fix
- [x] `tests/test_esignatures_wizard_cli.php` — extend for the new behaviour
- [x] `changelog.md` — log all changes

### Scope note — non-PDF documents
This deliverable modernises the **PDF signing path** (the main use case) end-to-end.
Word/Excel/image documents currently get a silently-misleading "signature" (the
downloaded file is the unchanged original). That misleading path is replaced with an
**honest message**: only PDF documents can be signed with a verifiable certificate.
Converting image documents into a signed PDF is a clearly-scoped **follow-up**, not
part of this branch.

---

## Database — columns added to `document_signatures`

| Column | Type | Purpose |
|---|---|---|
| `hash_algorithm` | VARCHAR(20) | e.g. `sha256` |
| `hash_before` | VARCHAR(64) | SHA-256 of the original document |
| `hash_after` | VARCHAR(64) | SHA-256 of the final signed file |
| `signing_reference` | VARCHAR(64) | Unique human-facing reference on the certificate |
| `signed_document_id` | INT | Links to the new signed `documents` row (for Verify) |
| `user_agent` | VARCHAR(255) | Signer's browser/device |
| `consent_text` | TEXT | Exact consent statement the signer accepted |
| `consent_accepted_at` | TIMESTAMP | When consent was accepted |
| `event_log` | TEXT | JSON: ordered viewed → consent → signed events |

Migration is idempotent (`SHOW COLUMNS` guard before each `ADD COLUMN`).

---

## Test & ship

- [x] Run the migration locally (`php migrations/2026_05_21_esignature_audit_columns.php`).
- [x] `php tests/test_esignatures_wizard_cli.php` — all pass.
- [x] `php tests/test_esignature_integrity_cli.php` — all pass.
- [x] `php -l` on every modified/new PHP file.
- [x] Update `changelog.md`.
- [x] Commit and push to `feature/esignature-modernization`.

> Only e-signature files are committed. Unrelated working-tree items (`scratch/*`,
> `response.txt`, `view`, `.claude/settings.local.json`, `docs/templates/*`) are left
> untouched and never staged.
