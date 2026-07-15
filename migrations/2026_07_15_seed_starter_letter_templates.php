<?php
/**
 * 2026_07_15_seed_starter_letter_templates.php
 * ------------------------------------------------------
 * Seeds three ready-to-use letter templates that demonstrate the merge-variable
 * engine, so Create Document ships with working professional examples instead of
 * an empty picker:
 *
 *   1. Notice of Meeting        -> General Documents
 *   2. Payment Reminder         -> General Documents
 *   3. Letter of Undertaking    -> Legal & Contracts
 *
 * Each body uses {{tokens}} ({{recipient}}, {{company_name}}, {{company_phone}},
 * {{document_code}}, {{date}}, {{company_address}}) that auto-fill from real data
 * when a letter is created from the template.
 *
 * Idempotent — each template is inserted only if no template of that name exists,
 * so re-running (and a user later editing/deleting one) is respected.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed starter letter templates...\n";

try {
    // Resolve target categories by name (ids differ per environment); fall back
    // to General Documents, and skip gracefully if the curated set isn't present.
    $cats = [];
    foreach ($pdo->query("SELECT id, category_name FROM document_categories") as $r) {
        $cats[$r['category_name']] = (int)$r['id'];
    }
    $general = $cats['General Documents'] ?? null;
    $legal   = $cats['Legal & Contracts'] ?? $general;
    if ($general === null) {
        echo "  ~ 'General Documents' category not found — skipping seed.\n";
        echo "Migration complete.\n";
        return;
    }

    $noticeBody = <<<HTML
<p>Dear {{recipient}},</p>
<p>You are hereby invited to attend a meeting convened by {{company_name}} to discuss matters of mutual interest.</p>
<p><strong>Date:</strong> ____________________<br><strong>Time:</strong> ____________________<br><strong>Venue:</strong> ____________________</p>
<p>Your attendance and active participation will be highly appreciated. Kindly confirm your availability in advance.</p>
<p>Should you require any clarification, please contact us on {{company_phone}} or by email at {{company_email}}, quoting reference {{document_code}}.</p>
<p>Yours faithfully,</p>
HTML;

    $reminderBody = <<<HTML
<p>Dear {{recipient}},</p>
<p>Our records indicate that there is an outstanding balance on your account with {{company_name}}. This is a kind reminder to settle the amount due at your earliest convenience.</p>
<p>If payment has already been made, please disregard this notice and accept our sincere thanks.</p>
<p>For any queries regarding your account, please contact us on {{company_phone}} or {{company_email}}, quoting reference {{document_code}}.</p>
<p>We value your continued business and look forward to your prompt response.</p>
<p>Yours sincerely,</p>
HTML;

    $undertakingBody = <<<HTML
<p>Dear {{recipient}},</p>
<p>We, {{company_name}}, of {{company_address}}, do hereby undertake and confirm the following in respect of the matter referenced below:</p>
<ol>
<li>____________________________________________</li>
<li>____________________________________________</li>
</ol>
<p>This undertaking is given in good faith and shall remain binding upon {{company_name}}.</p>
<p>Reference: {{document_code}}, dated {{date}}.</p>
<p>Yours faithfully,</p>
HTML;

    $templates = [
        [
            'name'    => 'Notice of Meeting',
            'subject' => 'Notice of Meeting',
            'cat'     => $general,
            'content' => $noticeBody,
            'desc'    => 'Formal invitation to a meeting — auto-fills company + recipient details.',
        ],
        [
            'name'    => 'Payment Reminder',
            'subject' => 'Reminder: Outstanding Payment',
            'cat'     => $general,
            'content' => $reminderBody,
            'desc'    => 'Courteous reminder for an outstanding balance — auto-fills company + reference.',
        ],
        [
            'name'    => 'Letter of Undertaking',
            'subject' => 'Letter of Undertaking',
            'cat'     => $legal,
            'content' => $undertakingBody,
            'desc'    => 'Formal undertaking on company letterhead — auto-fills company details.',
        ],
    ];

    $check = $pdo->prepare("SELECT id FROM document_templates WHERE template_name = ? LIMIT 1");
    $ins = $pdo->prepare("
        INSERT INTO document_templates
            (template_name, subject, recipient, recipient_address, use_letterhead, signature_align,
             category_id, content, description, is_active, created_by)
        VALUES (?, ?, NULL, NULL, 1, 'left', ?, ?, ?, 1, NULL)
    ");

    foreach ($templates as $t) {
        $check->execute([$t['name']]);
        if ($check->fetch()) {
            echo "  ~ Template '{$t['name']}' already exists — skipped.\n";
            continue;
        }
        $ins->execute([$t['name'], $t['subject'], $t['cat'], $t['content'], $t['desc']]);
        echo "  + Seeded template '{$t['name']}'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
