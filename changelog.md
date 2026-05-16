# BMS Changelog

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
