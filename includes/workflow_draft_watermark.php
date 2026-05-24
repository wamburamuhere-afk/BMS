<?php
/**
 * Print-page DRAFT watermark — shown when the document is not yet approved.
 * -------------------------------------------------------------------------
 * Self-contained block: own class (`three-approval-watermark`), own style tag.
 * It CANNOT touch any existing CSS on the host print page.
 *
 * Expects in scope:
 *   $wf_status  string  current status of the document (e.g. 'pending', 'reviewed', 'approved')
 *
 * Renders nothing when $wf_status === 'approved'.
 */

if (!isset($wf_status) || $wf_status === 'approved') return;

$label = strtoupper((string)$wf_status);
?>
<style>
    .three-approval-watermark {
        position: fixed;
        top: 35%; left: 0; right: 0;
        text-align: center;
        font-size: 120px;
        font-weight: 800;
        color: rgba(220, 53, 69, 0.18);
        transform: rotate(-30deg);
        pointer-events: none;
        z-index: 9999;
        letter-spacing: 4px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
</style>
<div class="three-approval-watermark"><?= htmlspecialchars($label) ?></div>
