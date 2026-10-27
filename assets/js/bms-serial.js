/**
 * bms-serial.js — the S/No column, one definition for every DataTable.
 *
 * What S/No means: a row's position in the list's normal order. The number
 * stays with its row, so clicking S/No sorts the list back to that order
 * (ascending) or reverses it (descending), and sorting by any other column
 * keeps each row's own number.
 *
 *   columns: [ BMSSerial.column({ className: 'text-center' }), ... ]
 *   columnDefs: [ $.extend({ targets: 0 }, BMSSerial.column()) ]
 *
 * Client-side tables: rows are numbered the first time they are drawn, in the
 * order they are shown (the table's configured default order), and keep that
 * number until they are replaced (clear/add, ajax reload).
 *
 * Server-side tables: the server sends one page, so the number comes from the
 * page position — counting down when S/No is sorted descending, in which case
 * the API returns its normal order reversed.
 *
 * Loaded once in header.php — it needs only jQuery, so column lists built
 * before DataTables itself has loaded can still reference it.
 */
(function ($) {
    'use strict';

    function render(d, type, row, meta) {
        var s = meta.settings;
        if (s.oFeatures.bServerSide) {
            var o = s.aaSorting && s.aaSorting[0];
            var desc = !!(o && o[0] === meta.col && o[1] === 'desc');
            return desc ? s._iRecordsDisplay - s._iDisplayStart - meta.row
                        : s._iDisplayStart + meta.row + 1;
        }
        var r = s.aoData[meta.row];
        return r && r._bmsSn !== undefined ? r._bmsSn : meta.row + 1;
    }

    function serialColumns(settings) {
        var cols = [];
        for (var c = 0; c < settings.aoColumns.length; c++) {
            if (settings.aoColumns[c].bmsSerial) cols.push(c);
        }
        return cols;
    }

    window.BMSSerial = {
        render: render,
        column: function (extra) {
            return $.extend({
                data: null, orderable: true, searchable: false, type: 'num',
                bmsSerial: true, render: render
            }, extra || {});
        }
    };

    // Before a client-side table draws, number any rows that have no number
    // yet (first draw, or rows replaced/added since).
    $(document).on('preDraw.dt', function (e, settings) {
        if (settings.oFeatures.bServerSide) return;
        var cols = serialColumns(settings);
        if (!cols.length) return;
        var master = settings.aiDisplayMaster, i;
        var missing = false;
        for (i = 0; i < master.length; i++) {
            if (settings.aoData[master[i]]._bmsSn === undefined) { missing = true; break; }
        }
        if (!missing) return;

        // Sorted by S/No right now? Then the sort already used load order
        // (index + 1), so keep numbers consistent with it; otherwise number
        // the rows in the order they are about to be shown.
        var bySerial = false;
        for (i = 0; i < settings.aaSorting.length; i++) {
            if (cols.indexOf(settings.aaSorting[i][0]) !== -1) { bySerial = true; break; }
        }
        for (i = 0; i < master.length; i++) {
            var idx = master[i], row = settings.aoData[idx];
            row._bmsSn = bySerial ? idx + 1 : i + 1;
            row._aSortData = null;                      // sort on the new number
            if (row.anCells) {
                for (var c = 0; c < cols.length; c++) {
                    var cell = row.anCells[cols[c]];
                    if (cell) cell.innerHTML = row._bmsSn;
                }
            }
        }
    });
})(jQuery);
