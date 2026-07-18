<?php
/**
 * Shared print auto-fit — includes/print_autofit.php
 * ---------------------------------------------------
 * Shrinks a print page's content (via CSS zoom) so it always fits on one
 * A4 sheet, no matter how many rows/items it has. Nothing is removed or
 * hidden — font size, spacing, and row height all scale down together,
 * down to a 75% floor, then window.print() runs.
 *
 * Usage on any print page:
 *   1. In <head>, right after print_footer_css.php:
 *        <?php require_once ROOT_DIR . '/includes/print_autofit.php'; ?>
 *   2. Wrap the printable content (header ... signature block, NOT the
 *      shared footer) in <div class="print-scale-wrapper">...</div>
 *   3. Change <body onload="window.print()"> to <body onload="bmsAutoFitPrint()">
 */
?>
<script>
    function bmsAutoFitPrint() {
        try {
            var wrapper = document.querySelector('.print-scale-wrapper');
            if (wrapper) {
                var MM_TO_PX   = 96 / 25.4;
                var PAGE_H_MM  = 297;   // A4 portrait
                var MARGIN_TOP_MM    = 10;
                var MARGIN_BOTTOM_MM = 16;  // canonical @page margin (leaves room for footer)
                var BODY_PRINT_PAD_BOTTOM_MM = 4;
                var FOOTER_H_PX = 16;
                var SAFETY_PX   = 90;   // buffer for screen-vs-print rendering gap (measured empirically)
                var MIN_SCALE   = 0.75; // never shrink below this

                var targetPx = (PAGE_H_MM - MARGIN_TOP_MM - MARGIN_BOTTOM_MM) * MM_TO_PX
                             - (BODY_PRINT_PAD_BOTTOM_MM * MM_TO_PX)
                             - FOOTER_H_PX
                             - SAFETY_PX;

                var contentPx = wrapper.scrollHeight;

                if (contentPx > targetPx) {
                    var scale = targetPx / contentPx;
                    if (scale < MIN_SCALE) scale = MIN_SCALE;
                    wrapper.style.zoom = scale;
                }
            }
        } catch (e) {
            // Never let auto-fit block printing.
        }
        window.print();
    }
</script>
