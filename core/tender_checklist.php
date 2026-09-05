<?php
/**
 * core/tender_checklist.php — the 19-item standard PPRA/East African tender
 * compliance checklist, seeded once per tender at creation time.
 *
 * A missing document is the most common cause of bid disqualification; this
 * checklist exists so completeness is checked before submission, not after
 * the procuring entity rejects the bid. Standard items (is_custom = 0) can be
 * ticked/unticked but never deleted, so the "X / N ready" counter stays a
 * meaningful measure against the real standard — only user-added extras
 * (is_custom = 1) can be removed.
 */

if (!function_exists('tenderChecklistStandardItems')) {
    function tenderChecklistStandardItems(): array
    {
        return [
            'Form of Tender — completed, signed & stamped',
            'Power of Attorney for the signatory',
            'Certificate of Incorporation / Business Registration',
            'Valid Business Licence',
            'TIN Certificate',
            'VAT Registration Certificate',
            'Valid Tax Clearance Certificate',
            'NeST / PPRA registration (supplier registered on nest.go.tz)',
            'Bid Security / Bid-Securing Declaration',
            'Priced Bills of Quantities — signed & stamped',
            'Materials Schedule & delivery plan',
            'Work programme / delivery schedule',
            'Audited financial statements (last 3 years)',
            'Experience: similar contracts & reference letters',
            'CVs & certificates of key personnel',
            'Equipment / plant schedule',
            'Litigation history declaration',
            'Anti-bribery / integrity declaration',
            'Joint venture agreement (if bidding as JV)',
        ];
    }
}

if (!function_exists('seedTenderChecklist')) {
    function seedTenderChecklist(PDO $pdo, int $tenderId): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO tender_checklist_items (tender_id, item_text, is_ready, is_custom, sort_order)
            VALUES (?, ?, 0, 0, ?)
        ");
        foreach (tenderChecklistStandardItems() as $i => $itemText) {
            $stmt->execute([$tenderId, $itemText, $i]);
        }
    }
}
