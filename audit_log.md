# BMS — Audit / Activity Log Standard

**Purpose:** make the Activity Log (`app/activity_log.php`, table `activity_logs`) an
audit-grade record an auditor can read at a glance: *who* did *what* (which kind of
action, on which record), *when*, from *where* (IP), and — for sessions — *how long*
they stayed. Everything below is the single source of truth for how activities must be
logged and displayed.

---

## 1. The SIX core audit activities (the only ones the filter exposes)

Every meaningful user action is one of these six. They are the canonical "verbs".

| # | Activity | Meaning | Canonical verb (Type column) |
|---|----------|---------|------------------------------|
| 1 | **View**    | Read / open / look at a record or page | `View` |
| 2 | **Create**  | Add a brand-new record | `Create` |
| 3 | **Edit**    | Change an existing record | `Edit` |
| 4 | **Delete**  | Remove / void a record | `Delete` |
| 5 | **Review**  | Move a record to *reviewed* (workflow check) | `Review` |
| 6 | **Approve** | Move a record to *approved* (workflow sign-off) | `Approve` |

> Session events **Login** / **Logout** are tracked separately (see §5) and are **not**
> part of the six — they are not record actions.

---

## 2. The TYPE column — short and smart

The Type column is always **`<Verb> <entity>`** — present-tense canonical verb + the
entity (singular/plural as natural), nothing else. Short, scannable, consistent.

| Good (use this) |
|---|
| `Delete invoice` |
| `View customers` |
| `Edit asset` |
| `Review quotation` |
| `Approve sales order` |
| `Create purchase order` |

**Never** show raw/internal strings in Type (`page_view`, `update_journal_mappings`,
`Deleted role`, `Recorded payment`). The page normalises legacy verbs to the canonical
form (see §4) — but new code must log cleanly so no normalisation is needed.

---

## 3. The DESCRIPTION column — starts with the action, then the specifics

The Description **begins with the past-tense action and the entity, then the identifying
detail** (a name and/or id). Deep enough to be unambiguous, not bloated.

| Activity | Description format | Example |
|---|---|---|
| **View**    | `viewed <entity> page` *(or the specific record)* | `viewed customers page` |
| **Create**  | `created <entity> <name> (id …)` | `created invoice INV-2026-0007 (id 7)` |
| **Edit**    | `edited <entity> <name> (id …)` | `edited asset "Toyota Hilux" (id 42)` |
| **Delete**  | `deleted <entity> with id …` | `deleted invoice with id 7` |
| **Review**  | `reviewed <entity> with id …` | `reviewed sales return with id 18` |
| **Approve** | `approved <entity> with id …` | `approved purchase order with id 31` |

Rules:
1. **Start with the verb** (`viewed…`, `deleted…`) so the entry reads as a sentence and
   the filter (which matches the start of action OR description) always catches it.
2. **Always include the id** for create/edit/delete/review/approve. Include a human
   **name/number** too when there is one (`INV-2026-0007`, asset name) — the Activity Log
   auto-extracts an `ID …` / code into the **Reference** column.
3. Keep it one line. No internal jargon, no table names.

---

## 4. How to LOG (the one call to use going forward)

Use `logActivity()` with the canonical verb at the **start** of both fields:

```php
// logActivity($pdo, $user_id, $action, $description)
logActivity($pdo, $_SESSION['user_id'], 'Delete invoice',
    "deleted invoice INV-2026-0007 with id $invoice_id");

logActivity($pdo, $_SESSION['user_id'], 'View customers',
    'viewed customers page');

logActivity($pdo, $_SESSION['user_id'], 'Approve purchase order',
    "approved purchase order PO-2026-0031 with id $po_id");
```

- **action** = the Type (`<Verb> <entity>`).
- **description** = the past-tense detail (starts with `viewed/created/edited/deleted/reviewed/approved`).
- `who` (user_id), `when` (created_at) and the IP/user-agent are filled automatically.

### Legacy-verb normalisation (display only — do not rely on it for new code)
The Activity Log maps old/inconsistent verbs onto the six so existing rows still filter
and bucket correctly. Reference mapping:

| Canonical | Legacy verbs it absorbs |
|---|---|
| **View**    | `View`, `Viewed`, `page_view` |
| **Create**  | `Create`, `Created`, `Add`, `Added`, `Recorded` |
| **Edit**    | `Edit`, `Edited`, `Update`, `Updated`, `update_*`, `Changed` |
| **Delete**  | `Delete`, `Deleted`, `Remove`, `Removed`, `Void`, `Voided` |
| **Review**  | `Review`, `Reviewed` |
| **Approve** | `Approve`, `Approved` |

New code should emit the canonical verb directly so this table can eventually be retired.

---

## 5. Sessions — "time in system" (separate from the six)

- **Login** writes a `Login` activity event and opens a `user_sessions` row (`login_at`).
- **Logout** closes the row (`logout_at`, `duration_seconds`) and writes a `Logout` event:
  *"Logged out — session lasted 2h 15m"*.
- The Activity Log shows, **when filtered by one user**, a *Time in System* panel:
  total time, # sessions (open badge for unclosed), avg/session, last login, and a
  recent-sessions table (login, logout, duration, how it ended, IP).
- Sessions with no logout (browser closed / timed out) are shown honestly as **open** —
  never a fabricated end time.

---

## 6. Summary cards (top of the page)

The card counts (Created / Viewed / Edited / Deleted today, etc.) must use the **same
canonical mapping** as the filter (§4) so the numbers equal what the filter returns —
no separate, drifting logic. A card and its matching filter must always agree.

---

## 7. Roll-out plan (one action type at a time)

Standardise existing logging to this spec, file by file, in this order:
**Delete → Edit → View → Create → Review → Approve.** For each: confirm the action is
real, then ensure it calls `logActivity()` in the §2/§3 format.

_Done: Delete-role (`user_roles.php`) logs `Deleted role "<name>" (ID …)`._

---

## 8. The 3-part mechanism (simple, repeatable — follow this for EVERY delete)

A delete is "fully covered" only when all three parts are true. Nothing is done
silently; the output is always visible on `activity_log.php`.

### PART 1 — `user_roles.php` = WHO may delete (the gate)
- Every deletable thing has a **page-key** with a `delete` permission row in the
  `permissions` table, manageable on `user_roles.php`.
- The delete handler must call **`canDelete('<page_key>')`** before deleting.
- **If the page-key/permission does not exist → ADD it** (seed it so it appears on
  `user_roles.php` and an admin can grant/revoke "who deletes what").
- This is the ONLY place we add anything. We never add a delete button/endpoint
  that wasn't already there.

### PART 2 — the delete handler = REPORT on delete (never silent)
A "delete handler" is **anywhere a real delete happens**, in either form:
- an **API endpoint**: `api/**/delete_*.php`, OR
- an **in-page handler**: a `function delete*()` / inline `DELETE FROM …` /
  `UPDATE … SET status='deleted'` inside an `app/**` page.

For each, **without changing the delete behaviour**:
1. Capture the entity **name/number BEFORE** deleting.
2. After the delete succeeds, call exactly:
   ```php
   logActivity($pdo, $_SESSION['user_id'], 'Delete <entity>',
       "deleted <entity> \"<name>\" with id <id>");
   ```
   (keep any existing `logAudit` call too).
- **Do NOT add a delete where none exists.** If a file/page has no delete, skip it.

### PART 3 — `activity_log.php` = the OUTPUT (already done)
- Reads `activity_logs`, renders **Type** = `Delete <entity>` and **Description** =
  the sentence, shows who/when/IP, and the **Delete** filter catches it.
- Nothing to change per delete — Part 2's `logActivity` makes it appear here.

### How EVERY delete is discovered (so none is missed)
1. `api/**/delete_*.php` (the endpoint deletes).
2. `grep` across `app/**` for in-page deletes:
   `DELETE FROM`, `status = 'deleted'`, `function delete`.
3. A delete is DONE when: it has a `canDelete()` gate (Part 1) **and** a
   `logActivity('Delete <entity>', …)` on success (Part 2).

### Verification per file
- Trigger the real delete (or simulate its path) → confirm one new
  `activity_logs` row with action `Delete <entity>` and a `deleted … with id …`
  description → confirm it shows under the **Delete** filter on `activity_log.php`.
