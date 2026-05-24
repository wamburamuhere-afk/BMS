<?php
/**
 * View-page audit-trail panel — shows Created / Reviewed / Approved by + when.
 * --------------------------------------------------------------------------
 * Expects in scope:
 *   $wf  array with keys (any may be null):
 *        created_by_name, created_by_role, created_at,
 *        reviewed_by_name, reviewed_by_role, reviewed_at,
 *        approved_by_name, approved_by_role, approved_at
 *
 * Self-contained: this partial defines its own classes prefixed `wf-audit-`
 * so it does NOT collide with or modify any existing page CSS.
 */

if (!isset($wf) || !is_array($wf)) $wf = [];

$fmt = function ($dt) {
    return $dt ? date('d M Y, h:i A', strtotime($dt)) : '';
};
?>
<style>
    .wf-audit-panel {
        display: flex; gap: 16px; flex-wrap: wrap;
        background: #f8f9fa;
        border-left: 4px solid #3498db;
        border-radius: 6px;
        padding: 12px 16px;
        margin: 12px 0 20px 0;
        font-size: 12.5px;
        color: #1a252f;
    }
    .wf-audit-panel .wf-cell { flex: 1; min-width: 180px; }
    .wf-audit-panel .wf-label {
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.4px; color: #6c757d; margin-bottom: 3px;
    }
    .wf-audit-panel .wf-name  { font-weight: 600; color: #1a252f; }
    .wf-audit-panel .wf-role  { font-size: 11px; color: #495057; }
    .wf-audit-panel .wf-when  { font-size: 10.5px; color: #6c757d; margin-top: 2px; }
    .wf-audit-panel .wf-empty { font-style: italic; color: #adb5bd; font-size: 11px; }
</style>

<div class="wf-audit-panel">
    <div class="wf-cell">
        <div class="wf-label">Created By</div>
        <?php if (!empty($wf['created_by_name'])): ?>
            <div class="wf-name"><?= safe_output($wf['created_by_name'], '') ?></div>
            <?php if (!empty($wf['created_by_role'])): ?>
                <div class="wf-role"><?= safe_output($wf['created_by_role'], '') ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['created_at'])): ?>
                <div class="wf-when"><?= $fmt($wf['created_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty">—</div>
        <?php endif; ?>
    </div>

    <div class="wf-cell">
        <div class="wf-label">Reviewed By</div>
        <?php if (!empty($wf['reviewed_by_name'])): ?>
            <div class="wf-name"><?= safe_output($wf['reviewed_by_name'], '') ?></div>
            <?php if (!empty($wf['reviewed_by_role'])): ?>
                <div class="wf-role"><?= safe_output($wf['reviewed_by_role'], '') ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['reviewed_at'])): ?>
                <div class="wf-when"><?= $fmt($wf['reviewed_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty">Pending review</div>
        <?php endif; ?>
    </div>

    <div class="wf-cell">
        <div class="wf-label">Approved By</div>
        <?php if (!empty($wf['approved_by_name'])): ?>
            <div class="wf-name"><?= safe_output($wf['approved_by_name'], '') ?></div>
            <?php if (!empty($wf['approved_by_role'])): ?>
                <div class="wf-role"><?= safe_output($wf['approved_by_role'], '') ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['approved_at'])): ?>
                <div class="wf-when"><?= $fmt($wf['approved_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty">Pending approval</div>
        <?php endif; ?>
    </div>
</div>
