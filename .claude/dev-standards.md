# BMS — Development Standards (apply to every file touched)

These rules apply automatically whenever any file is modified. No need to repeat them per task.

## §1. Button Testing After Every Modification
After modifying any file, verify **every interactive element** in that file still works:
- Every button (Add, Edit, Delete, Save, Cancel, Status change, Export, Print, etc.)
- Every form submission
- Every modal trigger
- Do not leave any button untested — even buttons outside the directly modified section must be confirmed working.

**Page audit rule (mandatory when touching any frontend page):**
Before editing, **read all CLAUDE.md sections and run the touched page through them in order**. If the page violates any rule (missing CSRF token, plain `<select>` instead of Select2, no mobile card view, status button without permission gate, etc.), **fix it as part of the same task** — even if the user only asked for a small change. Leave the page cleaner than you found it. List each fix in the commit message.

## §2. Add Form ↔ Edit Form Parity
Whenever a field is added to or removed from a registration / "Add New" form, apply the **exact same change** to the corresponding Edit form in the same file (or linked edit file). Both forms must always stay in sync — same fields, same validation, same order.

## §3. DataTable Enforcement
Before modifying any file, check whether it contains an HTML `<table>`. If it does:
- Confirm the table is already initialised as a **DataTable** (search, sort, pagination).
- If it is not, convert it to a DataTable as part of the task.
- Use the project's standard DataTable initialisation pattern (Bootstrap 5 styling, responsive extension).

## §4. Searchable Dropdowns (Select2)
Every `<select>` that is populated from the database must use **Select2** so the user can search by typing — never a plain `<select>`.

**Standard pattern (static Select2 — pre-loaded options):**
- Add the `select2-static` CSS class to the `<select>` element.
- PHP pre-loads the options into `<option>` tags at render time.
- `initSelect2()` picks up every `.select2-static` element and wraps it with:
```js
$(el).select2({
    theme: 'bootstrap-5',
    dropdownParent: isInsideModal ? $('#theModal') : null,
    placeholder: 'Select...',
    allowClear: true,
    width: '100%'
});
```
- Use this pattern even for very small lists (2–5 items). Select2's built-in filtering handles the search.
- For dynamically populated selects (options change at runtime, e.g. a payee list that depends on a prior selection), destroy the old Select2 instance before repopulating, then re-init after appending the new options.
- When touching any file, audit all dropdowns in that file and upgrade any plain `<select>` backed by database data to Select2.

## §5. Responsive Layout — Mobile Card View & Sticky Header
Apply to every file touched:

**Mobile view (`max-width: 767px`):**
- All tables must render as **card view by default** — no toggle to switch back to table view on mobile.
- The **table/card view toggle** must be **hidden on mobile** (`d-none d-md-flex` on the toggle button group). Mobile always shows card view; the user must not see a switch-to-table option.
- Each card must show all row data in a compact, well-aligned layout sized for small screens.
- Action buttons (Edit, Delete, View, Status, etc.) must appear **below each card in a single non-wrapping row — no matter how many buttons there are**. Never use `flex-wrap` or `overflow-auto`. Use `display:flex; flex-wrap:nowrap` with `flex:1; min-width:0` on each button so they share the available width equally and shrink to fit — no scrolling, no wrapping. Use **icon-only buttons** (no text labels) on mobile cards to keep them compact. Apply small padding (`padding: 3px 4px`) and small font-size (`0.72rem`) on card buttons.
- Action button row background must be **white** (consistent with existing card styling across the project).
- All non-button text/labels within a card must be small (`font-size: 0.8rem` or `small` tag) and well-aligned.
- The **page header / navbar must stick to the top** (`position: sticky; top: 0; z-index: 1020`) in mobile view, matching behaviour already implemented on other pages.

**Desktop / web view (`min-width: 768px`):**
- Default display is **table view**.
- A toggle button to switch to card view is allowed but optional — **only visible on desktop**.
- All existing desktop styling and functionality must remain unchanged.

## §6. SweetAlert2 for All Alerts & Confirmations
**Never use native browser dialogs** — `alert()`, `confirm()`, or `prompt()` are forbidden everywhere in the project. They produce "dev.bms.local says…" pop-ups that look unprofessional and break the UI.

**Always use SweetAlert2 (`Swal.fire`)** for:
- Confirmation dialogs before destructive actions (delete, status change, etc.)
- Success / error feedback after async operations
- Any informational alert shown to the user

**Standard patterns:**
```js
// Confirmation before delete
Swal.fire({
    title: 'Delete?', text: 'This action cannot be undone.', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
}).then(r => { if (r.isConfirmed) { /* do the delete */ } });

// Loading while async runs
Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

// Success after async
Swal.fire({ icon: 'success', title: 'Done!', text: res.message, confirmButtonColor: '#198754' });

// Error
Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
```

When touching any file, **audit all `alert()` / `confirm()` calls** and replace them with the patterns above.

## §7. Branch & Commit Workflow
- Never commit directly to `main` or `develop`.
- For every task, create a **new dedicated feature branch** named `feature/<short-description>` (e.g. `feature/add-supplier-notes`).
- Commit all changes for that task to the feature branch.
- Push the branch and leave it for the user to open a PR into `develop`, then `main`.
