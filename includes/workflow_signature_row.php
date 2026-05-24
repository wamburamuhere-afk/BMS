<?php
/**
 * Print-page signature row — Created By / Reviewed By / Approved By.
 * ------------------------------------------------------------------
 * CSS + HTML are copied verbatim from `app/bms/sales/quotations/print_quotation.php`
 * (CSS lines 346-368, HTML lines 588-600). This is the canonical signature
 * pattern across every BMS print page per three_approval.md §6.3.
 *
 * Expects in scope:
 *   $wf  array with keys (any may be null):
 *        created_by_name, created_by_role,
 *        reviewed_by_name, reviewed_by_role,
 *        approved_by_name, approved_by_role
 *
 * IMPORTANT: This partial defines `.signature-box` / `.signature-line`
 * styles. If the host print page already defines them (e.g. PO print),
 * the host CSS wins by source order — leave the host page CSS alone.
 * Output here is HTML only when the host already has the CSS.
 */

if (!isset($wf) || !is_array($wf)) $wf = [];

// Output CSS only when the host page asks for it via $wf['__include_css'] = true.
if (!empty($wf['__include_css'])):
?>
<style>
    /* ── SIGNATURE ── (verbatim from print_quotation.php:346-368) */
    .signature-box {
        margin-top: 46px;
        display: flex;
        justify-content: space-around;
        gap: 40px;
    }
    .signature-line {
        width: 210px;
        padding-top: 7px;
        text-align: center;
        border-top: 1.5px solid #1a252f;
        font-size: 11px;
        color: #1a252f;
        font-weight: 600;
    }
    .signature-line small {
        display: block;
        margin-top: 4px;
        font-size: 10px;
        font-weight: 400;
        color: #495057;
    }
</style>
<?php endif; ?>

<div class="signature-box">
    <div class="signature-line">
        Created By<br>
        <small><?php
            $n = $wf['created_by_name']  ?? '';
            $r = $wf['created_by_role']  ?? '';
            echo htmlspecialchars($n) . ($r ? ' — ' . htmlspecialchars($r) : '');
        ?></small>
    </div>
    <div class="signature-line">
        Reviewed By<br>
        <small><?php
            $n = $wf['reviewed_by_name'] ?? '';
            $r = $wf['reviewed_by_role'] ?? '';
            echo htmlspecialchars($n) . ($r ? ' — ' . htmlspecialchars($r) : '');
        ?></small>
    </div>
    <div class="signature-line">
        Approved By<br>
        <small><?php
            $n = $wf['approved_by_name'] ?? '';
            $r = $wf['approved_by_role'] ?? '';
            echo htmlspecialchars($n) . ($r ? ' — ' . htmlspecialchars($r) : '');
        ?></small>
    </div>
</div>
