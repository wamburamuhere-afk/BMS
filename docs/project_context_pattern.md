# Project-Context Navigation Pattern

## Problem

Every procurement module (RFQ, PO, GRN, DN, etc.) has two entry points:
1. **External list** — e.g. `/rfq`, `/purchase_orders` — all records, any project.
2. **Project detail page** — Project → Procurements → sub-tab — scoped to one project.

When a user opens a form from within a project, they expect:
- Back button → back to that project tab (not the external list).
- Save → back to that project tab.
- Supplier dropdown → only suppliers of that project.
- Warehouse dropdown → only warehouses of that project.
- Project field → auto-filled and locked (read-only).

## The `return_url` Parameter

Every form/view page that can be opened from either context reads an optional
`return_url` GET parameter. Only **relative paths** are accepted (open-redirect guard).

### How to read it (PHP, top of page)
```php
$_raw_return  = $_GET['return_url'] ?? '';
$return_url   = (!empty($_raw_return) && $_raw_return[0] === '/') ? $_raw_return : '';
$back_url     = $return_url ?: getUrl('module_list_page');   // fallback = external list
$from_project = !empty($return_url) && strpos($return_url, 'project_view') !== false;
```

### How to use it in the form
| Element | External context | Project context |
|---|---|---|
| Back button | `getUrl('module_list_page')` | `$back_url` ("Back to Project") |
| Cancel link | `getUrl('module_list_page')` | `$back_url` |
| Post-save JS redirect | external list | `$back_url` |
| Breadcrumb parent | "Module List" | "Project [Name]" → `$back_url` |

```php
// Back button
<?php if ($from_project): ?>
<a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-outline-primary">
    <i class="bi bi-arrow-left"></i> Back to Project
</a>
<?php else: ?>
<a href="<?= getUrl('module_list_page') ?>" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Back to List
</a>
<?php endif; ?>
```

```js
// Post-save redirect (JS)
}).then(() => {
    window.location.href = '<?= htmlspecialchars($back_url) ?>';
});
```

## Narrowing Dropdowns to Project

When `$project_id > 0`, override the role-scoped supplier/warehouse lists with
project-specific queries **before** rendering the form:

```php
if ($project_id > 0) {
    // Suppliers
    $stmt = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers
                           WHERE status='active' AND project_id = ? ORDER BY supplier_name");
    $stmt->execute([$project_id]);
    $proj_sup = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($proj_sup)) $suppliers = $proj_sup;

    // Warehouses
    $stmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id
                           FROM warehouses WHERE status='active' AND project_id = ?
                           ORDER BY warehouse_name");
    $stmt->execute([$project_id]);
    $proj_wh = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($proj_wh)) $warehouses = $proj_wh;

    // Lock project dropdown to this one entry
    $stmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

## Locking the Project Field

When in project context (create mode), replace the project `<select>` with a
hidden input + read-only display so the user cannot change it:

```php
<?php if ($project_id > 0 && !$is_edit): ?>
<input type="hidden" name="project_id" id="project_id" value="<?= $project_id ?>">
<input type="text" class="form-control bg-light text-muted"
       value="<?= htmlspecialchars($projects[0]['project_name'] ?? '') ?>" readonly tabindex="-1">
<div class="form-text"><i class="bi bi-lock-fill me-1"></i>Locked to this project</div>
<?php else: ?>
<select class="form-select select2-static" id="project_id" name="project_id">
    ...
</select>
<?php endif; ?>
```

## How to Pass `return_url` from the Project Detail Page

In `project_view.php`, any link that opens a module form in "project context" must
include both `project=ID` and `return_url=PROJECT_TAB_URL`:

```js
// Inside a JS render function
const retUrl  = encodeURIComponent('<?= getUrl('project_view') ?>?id=<?= $project_id ?>&tab=rfq');
const poRetUrl = encodeURIComponent('<?= getUrl('project_view') ?>?id=<?= $project_id ?>&tab=procurement');

// RFQ action links
`<a href="<?= getUrl('rfq_create') ?>?project=<?= $project_id ?>&return_url=${retUrl}">Create RFQ</a>`
`<a href="<?= getUrl('rfq_view') ?>?id=${r.rfq_id}&return_url=${retUrl}">View</a>`
`<a href="<?= getUrl('rfq_create') ?>?edit=${r.rfq_id}&project=<?= $project_id ?>&return_url=${retUrl}">Edit</a>`

// Create PO from approved RFQ
`<a href="<?= getUrl('purchase_order_create') ?>?supplier=${r.supplier_id}&rfq_ref=${r.rfq_id}&project=<?= $project_id ?>&return_url=${poRetUrl}">Create PO</a>`

// Edit PO from POs tab
`<a href="<?= getUrl('purchase_order_create') ?>?edit=${p.po_id}&project=<?= $project_id ?>&return_url=${poRetUrl}">Edit Order</a>`
```

## Tab Name Map (project_view.php)

| `tab=` param | Bootstrap tab | Notes |
|---|---|---|
| `rfq` | `#proc-rfq` | Procurement → RFQ sub-tab |
| `procurement` | `#purchases-tab` | Procurement → Purchase Orders |
| `grn` | `#proc-grn` | Procurement → GRN sub-tab |
| `dn` | `#proc-dn` | Procurement → DN sub-tab |

Check the tab-switch map in `project_view.php` (~line 18859) to confirm the exact
mapping before constructing return URLs.

## Chain: rfq_view → Create PO

`rfq_view.php` passes a `po_return` URL specifically for the PO create page
(landing on the **POs tab**, not back on the RFQ tab):

```php
$po_return = $from_project
    ? str_replace('tab=rfq', 'tab=procurement', $back_url)
    : getUrl('purchase_orders');

$po_create_url = getUrl('purchase_order_create')
    . '?supplier=' . (int)$rfq['supplier_id']
    . '&rfq_ref='  . $rfq_id
    . (!empty($rfq['project_id']) ? '&project=' . (int)$rfq['project_id'] : '')
    . '&return_url=' . urlencode($po_return);
```

## Auto-filling a Child Form from a Parent Document

When `?rfq_ref=ID` is in the URL for PO create, items are auto-filled via:
1. `loadRFQs(callback)` — loads approved RFQs for the supplier/project/warehouse.
2. In the callback, select the RFQ and trigger `.change()` on `#rfq_reference`.
3. The `change` handler calls `get_rfq_items` and populates the items table.

Same pattern applies to any parent→child auto-fill (e.g. GRN from approved PO).

## Modules Completed with This Pattern

| Module | File | Status |
|---|---|---|
| RFQ list | `rfq.php` | External only (no in-project create) |
| RFQ view | `rfq_view.php` | ✅ return_url, Create PO with context |
| RFQ create/edit | `rfq_create.php` | ✅ return_url, supplier filter |
| PO create/edit | `purchase_order_create.php` | ✅ return_url, supplier+warehouse filter, project lock, rfq_ref auto-fill |

## Modules Pending

Apply the same 5-step checklist to each:

- [ ] GRN create/edit (`grn_create.php`)
- [ ] DN create/edit (`dn_create.php`)
- [ ] Delivery Order create/edit
- [ ] Returns create/edit

**5-step checklist per module:**
1. Read `return_url` at top of page (guard against absolute URLs).
2. When `$project_id > 0`: narrow supplier + warehouse dropdowns to project.
3. Lock project field (hidden input + display-only text + lock icon).
4. Back button + Cancel link → `$back_url`.
5. Post-save JS redirect → `$back_url`.
