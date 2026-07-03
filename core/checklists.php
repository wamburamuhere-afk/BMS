<?php
/**
 * core/checklists.php — Tier 4, Phase 4.4 (D28/D30).
 *
 * spawnChecklistIfConfigured($pdo, $employee_id, $type, $created_by=0)
 *   If an active default template exists for $type ('onboarding'|'offboarding'),
 *   spawn a NEW employee_checklists row and SNAPSHOT the template's item text
 *   into employee_checklist_items (D30 — editing the template later never
 *   rewrites in-flight checklists). Returns the new checklist_id, or 0 if there
 *   is no default template. Never throws — a checklist problem must never fail
 *   the employee-creation or lifecycle-approval flow that calls it (the two
 *   call sites wrap it in function_exists + try/catch and treat 0 as fine).
 *
 * spawnChecklistFromTemplate($pdo, $employee_id, $template_id, $created_by=0)
 *   Manual spawn from a specific template (used by api/spawn_checklist.php).
 */

if (!function_exists('spawnChecklistFromTemplate')) {
    function spawnChecklistFromTemplate(PDO $pdo, int $employee_id, int $template_id, int $created_by = 0): int
    {
        $tpl = $pdo->prepare("SELECT template_id, template_type FROM checklist_templates WHERE template_id = ? AND status = 'active'");
        $tpl->execute([$template_id]);
        $t = $tpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) return 0;

        $items = $pdo->prepare("SELECT item_text, sort_order FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, item_id");
        $items->execute([$template_id]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare("INSERT INTO employee_checklists (employee_id, template_id, checklist_type, status, created_by)
                       VALUES (?, ?, ?, 'in_progress', ?)")
            ->execute([$employee_id, $template_id, $t['template_type'], $created_by ?: ($_SESSION['user_id'] ?? 0)]);
        $checklist_id = (int)$pdo->lastInsertId();

        // Snapshot the item text (D30)
        $ins = $pdo->prepare("INSERT INTO employee_checklist_items (checklist_id, item_text, sort_order) VALUES (?, ?, ?)");
        foreach ($rows as $r) $ins->execute([$checklist_id, $r['item_text'], (int)$r['sort_order']]);

        return $checklist_id;
    }
}

if (!function_exists('spawnChecklistIfConfigured')) {
    function spawnChecklistIfConfigured(PDO $pdo, int $employee_id, string $type, int $created_by = 0): int
    {
        if (!in_array($type, ['onboarding', 'offboarding'], true)) return 0;
        try {
            // Don't double-spawn: if the employee already has an in-progress
            // checklist of this type, leave it alone.
            $chk = $pdo->prepare("SELECT checklist_id FROM employee_checklists WHERE employee_id = ? AND checklist_type = ? AND status = 'in_progress' LIMIT 1");
            $chk->execute([$employee_id, $type]);
            if ($chk->fetch()) return 0;

            $def = $pdo->prepare("SELECT template_id FROM checklist_templates WHERE template_type = ? AND is_default = 1 AND status = 'active' ORDER BY template_id LIMIT 1");
            $def->execute([$type]);
            $tid = (int)($def->fetchColumn() ?: 0);
            if (!$tid) return 0;

            return spawnChecklistFromTemplate($pdo, $employee_id, $tid, $created_by);
        } catch (Throwable $e) {
            // Non-fatal by design (D28) — never break the calling flow.
            error_log('spawnChecklistIfConfigured: ' . $e->getMessage());
            return 0;
        }
    }
}
