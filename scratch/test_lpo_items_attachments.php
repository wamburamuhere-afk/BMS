<?php
/**
 * Test: LPO Line Items + Multi-file Attachments (Update 32)
 * Run at: http://dev.bms.local/scratch/test_lpo_items_attachments.php
 */
$pass = 0; $fail = 0; $results = [];
function ok($l){global $pass,$results;$pass++;$results[]=['pass',$l];}
function no($l,$d=''){global $fail,$results;$fail++;$results[]=['fail',$l.($d?" — $d":'')];}
function has($l,$h,$n){str_contains($h,$n)?ok($l):no($l,'Expected: '.substr($n,0,80));}
function hasNot($l,$h,$n){!str_contains($h,$n)?ok($l):no($l,'Should NOT contain: '.substr($n,0,80));}
function syntax($path){$c=file_get_contents($path);if(!$c)return false;try{token_get_all($c,TOKEN_PARSE);return true;}catch(ParseError $e){return false;}}

$root = dirname(__DIR__);
$files = [
    'app/bms/customer/customer_details.php',
    'api/customer/add_lpo.php','api/customer/update_lpo.php',
    'api/customer/get_lpo.php','api/customer/delete_lpo_attachment.php',
    'migrations/2026_05_20_create_lpo_items.php',
    'migrations/2026_05_20_create_lpo_attachments.php',
];

echo "<h3>Phase 1 — PHP Syntax</h3>";
foreach ($files as $f) {
    syntax($root.'/'.$f) ? ok("Syntax OK: $f") : no("Syntax FAIL: $f");
}

$src = file_get_contents($root.'/app/bms/customer/customer_details.php');

echo "<h3>Phase 2 — Modals are modal-xl</h3>";
has('Add modal is modal-xl', $src, 'id="addLpoModal"');
has('Add modal dialog is modal-xl', $src, 'class="modal-dialog modal-xl"');
$editIdx = strpos($src, 'id="editLpoModal"');
$editSlice = substr($src, $editIdx, 200);
has('Edit modal dialog is modal-xl', $editSlice, 'modal-xl');

echo "<h3>Phase 3 — Items Table</h3>";
has('Add modal items table id', $src, 'id="addLpoItemsTable"');
has('Edit modal items table id', $src, 'id="editLpoItemsTable"');
has('Add items tbody id', $src, 'id="addLpoItemsBody"');
has('Edit items tbody id', $src, 'id="editLpoItemsBody"');
has('Add grand total cell id', $src, 'id="addLpoGrandTotal"');
has('Edit grand total cell id', $src, 'id="editLpoGrandTotal"');
has('lpoAddRow function defined', $src, 'function lpoAddRow(');
has('lpoCalcRow function defined', $src, 'function lpoCalcRow(');
has('lpoRemoveRow function defined', $src, 'function lpoRemoveRow(');
has('lpoUpdateGrandTotal function defined', $src, 'function lpoUpdateGrandTotal(');
has('Add Item button in add modal', $src, "onclick=\"lpoAddRow('add')\"");
has('Add Item button in edit modal', $src, "onclick=\"lpoAddRow('edit')\"");
has('Trash icon used (not x-lg)', $src, 'bi-trash');
hasNot('x-lg icon NOT used for row delete', $src, 'bi-x-lg');

echo "<h3>Phase 4 — Table Header Colors (white, no table-primary)</h3>";
hasNot('Items table header NOT table-primary in add modal', $src, 'thead class="table-primary small"');
has('Items table header is white style in add modal', $src, 'background:#fff;border-bottom:2px solid #dee2e6;');
// view modal items table header also white
$viewBlock = substr($src, strpos($src,'function viewLpo'), 8000);
has('View modal items table header is white', $viewBlock, 'background:#fff;border-bottom:2px solid #dee2e6;');

echo "<h3>Phase 5 — Multi-file Attachment UI (row-based)</h3>";
// Tbody IDs for row-based attach tables
has('Add modal attach tbody id', $src, 'id="addLpoAttachBody"');
has('Edit modal attach tbody id', $src, 'id="editLpoAttachBody"');
// "Add Attachment" buttons
has('Add modal has Add Attachment button', $src, "lpoAddAttachRow('add')");
has('Edit modal has Add Attachment button', $src, "lpoAddAttachRow('edit')");
// Row-based JS functions
has('lpoAddAttachRow function defined', $src, 'function lpoAddAttachRow(');
has('lpoRemoveAttachRow function defined', $src, 'function lpoRemoveAttachRow(');
has('lpoRenumberAttach function defined', $src, 'function lpoRenumberAttach(');
// File inputs use indexed array names (FormData picks them up automatically)
has('attach_files[] indexed name used in row', $src, "name=\"attach_files[");
has('attach_names[] indexed name used in row', $src, "name=\"attach_names[");
// Existing attachment AJAX delete calls delete_lpo_attachment.php
has('delete_lpo_attachment.php API called from JS', $src, 'delete_lpo_attachment.php');
// Old DataTransfer approach must be gone
hasNot('DataTransfer accumulator removed', $src, 'new DataTransfer()');
hasNot('lpoAttachFiles accumulator removed', $src, 'let lpoAttachFiles');
hasNot('Old fd.append attachments[] removed', $src, "fd.append('attachments[]'");
hasNot('addLpoFileInput single-input removed', $src, 'id="addLpoFileInput"');
hasNot('editLpoFileInput single-input removed', $src, 'id="editLpoFileInput"');

echo "<h3>Phase 6 — API: save items + attachments</h3>";
$addApi = file_get_contents($root.'/api/customer/add_lpo.php');
has('add_lpo: saves items via INSERT', $addApi, 'INSERT INTO customer_lpo_items');
has('add_lpo: saves attachments', $addApi, 'INSERT INTO customer_lpo_attachments');
has('add_lpo: recalculates amount from items', $addApi, 'UPDATE customer_lpos SET amount');
has('add_lpo: MIME check on attachments', $addApi, 'FILEINFO_MIME_TYPE');

$updApi = file_get_contents($root.'/api/customer/update_lpo.php');
has('update_lpo: deletes old items before reinsert', $updApi, 'DELETE FROM customer_lpo_items');
has('update_lpo: inserts new items', $updApi, 'INSERT INTO customer_lpo_items');
has('update_lpo: saves new attachments', $updApi, 'INSERT INTO customer_lpo_attachments');
has('update_lpo: fixed status includes pending', $updApi, "'pending'");
has('update_lpo: fixed status includes reviewed', $updApi, "'reviewed'");

$getLpo = file_get_contents($root.'/api/customer/get_lpo.php');
has('get_lpo: returns items array', $getLpo, "lpo['items']");
has('get_lpo: returns attachments array', $getLpo, "lpo['attachments']");
has('get_lpo: builds download_url', $getLpo, 'download_url');

$delApi = file_get_contents($root.'/api/customer/delete_lpo_attachment.php');
has('delete_lpo_attachment: isAuthenticated', $delApi, 'isAuthenticated()');
has('delete_lpo_attachment: canEdit check', $delApi, "canEdit('customers')");
has('delete_lpo_attachment: csrf_check', $delApi, 'csrf_check()');
has('delete_lpo_attachment: deletes DB row', $delApi, 'DELETE FROM customer_lpo_attachments');
has('delete_lpo_attachment: unlinks file', $delApi, 'unlink');
has('delete_lpo_attachment: logActivity', $delApi, 'logActivity(');

echo "<h3>Phase 7 — Migrations</h3>";
$m1 = file_get_contents($root.'/migrations/2026_05_20_create_lpo_items.php');
has('lpo_items migration: creates table', $m1, 'CREATE TABLE IF NOT EXISTS customer_lpo_items');
has('lpo_items migration: product_name column', $m1, 'product_name');
has('lpo_items migration: tax_rate column', $m1, 'tax_rate');

$m2 = file_get_contents($root.'/migrations/2026_05_20_create_lpo_attachments.php');
has('lpo_attachments migration: creates table', $m2, 'CREATE TABLE IF NOT EXISTS customer_lpo_attachments');
has('lpo_attachments migration: original_name column', $m2, 'original_name');

echo "<h3>Phase 8 — View modal shows items + attachments</h3>";
has('viewLpo renders items table when items exist', $viewBlock, 'Items Ordered');
has('viewLpo renders attachments list when exist', $viewBlock, 'Attachments');
has('viewLpo maps items with product_name', $viewBlock, 'product_name');
has('viewLpo shows download_url for attachments', $viewBlock, 'download_url');

$total = $pass + $fail;
?><!DOCTYPE html><html><head><title>LPO Items+Attach Test</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h2>LPO Items + Attachments — Test Results (Update 32)</h2>
<div class="alert alert-<?= $fail===0?'success':'danger' ?> fw-bold">
    <?= $pass ?> / <?= $total ?> passed <?= $fail>0?" — $fail FAILED":' — All passed!' ?>
</div>
<table class="table table-sm table-bordered"><thead class="table-dark"><tr><th width="80">Result</th><th>Test</th></tr></thead><tbody>
<?php foreach($results as [$s,$l]):?>
<tr class="<?= $s==='pass'?'table-success':'table-danger' ?>">
<td class="fw-bold"><?= $s==='pass'?'✓ PASS':'✗ FAIL' ?></td>
<td><?= htmlspecialchars($l) ?></td></tr>
<?php endforeach;?>
</tbody></table></body></html>
