<?php
/**
 * includes/tables/debit_notes_table.php
 * Reusable Debit Notes table — markup + wiring for
 * assets/js/tables/bms-debit-notes-table.js, fed by api/purchase/get_debit_notes.php.
 *
 * Hosts:
 *   app/bms/purchase/debit_notes/debit_notes.php → full list, filters, stat cards
 *   app/bms/Suppliers/supplier_details.php       → Debit Notes tab, one supplier
 *
 * Config contract matches includes/tables/grn_table.php — see that file's header.
 * Extra key: 'on_suppliers' — JS fn receiving [{id, name}] for a filter dropdown.
 */

$tbl = array_merge([
    'id'           => 'dnTable',
    'supplier_id'  => 0,
    'hide'         => [],
    'card'         => null,
    'on_stats'     => null,
    'on_count'     => null,
    'on_suppliers' => null,
    'page_length'  => 25,
    'dom'          => 'rtip',
    'defer_pane'   => null,
], $tbl ?? []);

$dnote_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['debit_notes'])) {
    $GLOBALS['__bms_table_assets']['debit_notes'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-debit-notes-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-debit-notes-table.js') . '"></script>';
}

// Keys MUST stay in the same order as columns() in the JS module.
$dnote_tbl_columns = [
    'sno'      => ['label' => '#',             'attrs' => 'style="width:60px;"'],
    'number'   => ['label' => 'Debit Note #',  'attrs' => ''],
    'date'     => ['label' => 'Date',          'attrs' => ''],
    'supplier' => ['label' => 'Supplier',      'attrs' => ''],
    'origin'   => ['label' => 'Origin',        'attrs' => ''],
    'amount'   => ['label' => 'Amount (TZS)',  'attrs' => 'class="text-end"'],
    'status'   => ['label' => 'Status',        'attrs' => 'class="text-center"'],
    'actions'  => ['label' => 'Actions',       'attrs' => 'class="text-end d-print-none"'],
];
?>
<div class="table-responsive">
    <table id="<?= htmlspecialchars($tbl['id']) ?>" class="table table-hover align-middle w-100 mb-0">
        <thead class="table-light">
            <tr>
                <?php foreach ($dnote_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $dnote_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= $col['label'] ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
$(function () {
    var cfg = {
        tableId: <?= json_encode($tbl['id']) ?>,
        apiUrl:  <?= json_encode(buildUrl('api/purchase/get_debit_notes.php')) ?>,
        supplierId: <?= (int) $tbl['supplier_id'] ?>,
        urls: {
            view: <?= json_encode(getUrl('debit_note_view')) ?>,
            edit: <?= json_encode(getUrl('debit_note_edit')) ?>,
            del:  <?= json_encode(buildUrl('api/purchase/delete_debit_note.php')) ?>,
            print: {
                standard:  <?= json_encode(getUrl('print_debit_note')) ?>,
                navy:      <?= json_encode(getUrl('print_debit_note_navy')) ?>,
                corporate: <?= json_encode(getUrl('print_debit_note_corporate')) ?>,
                banded:    <?= json_encode(getUrl('print_debit_note_banded')) ?>
            }
        },
        perms: {
            edit: <?= json_encode((bool) canEdit('debit_notes')) ?>,
            del:  <?= json_encode((bool) canDelete('debit_notes')) ?>
        },
        hide: <?= json_encode($dnote_tbl_hide) ?>,
        cardContainer: <?= json_encode($tbl['card']) ?>,
        onStats: <?= $tbl['on_stats'] ? $tbl['on_stats'] : 'null' ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        onSuppliers: <?= $tbl['on_suppliers'] ? $tbl['on_suppliers'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSDebitNotesTable.init(cfg); });
<?php else: ?>
    BMSDebitNotesTable.init(cfg);
<?php endif; ?>
});
</script>
