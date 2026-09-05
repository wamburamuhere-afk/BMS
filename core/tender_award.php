<?php
require_once __DIR__ . '/code_generator.php';

/**
 * core/tender_award.php — the AWARDED -> Project handoff, hardened.
 *
 * Pulled out of api/tender_workflow.php's DECISION case so all six gaps
 * traced in tender.md Sec 2.1 live in one testable place:
 *   1. traceability      - projects.tender_id (UNIQUE)
 *   2. budget promise    - projects.budget is actually set from tender_sum
 *   3. team access       - tender_staff -> user_projects, so the people who
 *                          won the tender can actually see it
 *   4. idempotency       - refuses a tender that's already AWARDED
 *   5. currency          - projects.budget_currency is recorded, not assumed
 *   6. BOQ/Materials carry-over - project_boq_* tables + a project NIP
 *      Material List, per tender.md Sec 3's linkage rule
 */

if (!function_exists('awardTenderToProject')) {
    /**
     * @param array $awardData { tender_sum: float, award_letter_document: ?string }
     * @return array { success: bool, message: string, project_id?: int, project_name?: string }
     */
    function awardTenderToProject(PDO $pdo, int $tenderId, int $userId, array $awardData): array
    {
        // Gap #4 — fail fast with a clear message instead of relying only on
        // the UNIQUE key to reject a second attempt.
        $tenderStmt = $pdo->prepare("SELECT t.*, c.customer_name FROM tenders t LEFT JOIN customers c ON t.customer_id = c.customer_id WHERE t.tender_id = ?");
        $tenderStmt->execute([$tenderId]);
        $tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tender) {
            return ['success' => false, 'message' => 'Tender not found'];
        }
        if (strtoupper($tender['status']) === 'AWARDED') {
            return ['success' => false, 'message' => 'This tender has already been awarded — a project was already created for it.'];
        }

        // Same $ownTxn convention as core/code_generator.php::nextCode() —
        // lets a caller that's already inside a transaction (e.g. a CLI test
        // that wraps everything in one rolled-back transaction) compose this
        // function without a nested-transaction error.
        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            $tenderSum = (float)($awardData['tender_sum'] ?? $tender['tender_sum'] ?? 0);
            $awardLetter = $awardData['award_letter_document'] ?? null;

            $pdo->prepare("UPDATE tenders SET status = 'AWARDED', tender_sum = ?, award_letter_document = COALESCE(?, award_letter_document), award_date = NOW(), updated_at = NOW() WHERE tender_id = ?")
                ->execute([$tenderSum, $awardLetter, $tenderId]);

            // Gap #5 — record which currency this sum is actually in rather
            // than letting every downstream TZS report assume it's TZS.
            $budgetCurrency = (strtoupper((string)($tender['currency'] ?? '')) === 'USD') ? 'USD' : 'TZS';

            // project_manager seeding — plain free-text field (not an FK), so
            // this is just picking a name, not resolving a login. Prefer
            // whoever's role_position reads like a lead; fall back to the
            // first staff member assigned.
            $leadStmt = $pdo->prepare("
                SELECT e.first_name, e.last_name
                FROM tender_staff ts
                JOIN employees e ON ts.employee_id = e.employee_id
                WHERE ts.tender_id = ?
                ORDER BY (
                    ts.role_position LIKE '%lead%' OR ts.role_position LIKE '%manager%' OR ts.role_position LIKE '%coordinator%'
                ) DESC, ts.id ASC
                LIMIT 1
            ");
            $leadStmt->execute([$tenderId]);
            $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
            $projectManager = $lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : null;

            $contractDoc = $awardLetter ?: ($tender['award_letter_document'] ?: ($tender['submission_document_tzs'] ?: $tender['submission_document_usd']));
            $projectName = $tender['tender_description'] ?: ($tender['tender_no'] . ' Project');

            // Gaps #1 + #2 — tender_id for traceability, budget actually set
            // (tenders.php's Decision modal promises this to the user).
            $projStmt = $pdo->prepare("
                INSERT INTO projects (
                    project_name, contract_number, contract_sum, client_name, customer_id,
                    start_date, status, description, contract_attachment, duration,
                    discipline, role_position, project_manager, tender_id, budget, budget_currency, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'planning', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $projStmt->execute([
                $projectName,
                $tender['tender_no'],
                $tenderSum,
                $tender['customer_name'] ?: $tender['procuring_entity_name'],
                $tender['customer_id'],
                date('Y-m-d'),
                $tender['tender_description'],
                $contractDoc,
                $tender['duration'] ?? null,
                $tender['discipline'] ?? null,
                $tender['tender_role'] ?? null,
                $projectManager,
                $tenderId,
                $tenderSum,
                $budgetCurrency,
            ]);
            $projectId = (int)$pdo->lastInsertId();

            // Gap #3 — the winning team must be able to see what it won.
            // Silently skip any staff member with no matching login (a
            // sub-contractor, say) rather than error on them.
            $staffStmt = $pdo->prepare("SELECT DISTINCT employee_id FROM tender_staff WHERE tender_id = ?");
            $staffStmt->execute([$tenderId]);
            $employeeIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($employeeIds) {
                $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                $userLookup = $pdo->prepare("SELECT user_id FROM users WHERE employee_id IN ($placeholders) AND user_id IS NOT NULL");
                $userLookup->execute($employeeIds);
                $userIds = array_unique(array_map('intval', $userLookup->fetchAll(PDO::FETCH_COLUMN)));

                $assignStmt = $pdo->prepare("INSERT INTO user_projects (user_id, project_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                foreach ($userIds as $uid) {
                    $assignStmt->execute([$uid, $projectId, $userId]);
                }
            }

            // Gap #6a — carry the priced BOQ into the project as its baseline
            // budget breakdown. A copy, not a reference: the tender's BOQ
            // stays frozen as submitted evidence even if project costing
            // changes later (tender.md Sec 4 Phase E).
            $bills = $pdo->prepare("SELECT * FROM tender_boq_bills WHERE tender_id = ? ORDER BY sort_order");
            $bills->execute([$tenderId]);
            foreach ($bills->fetchAll(PDO::FETCH_ASSOC) as $bill) {
                $newBill = $pdo->prepare("INSERT INTO project_boq_bills (project_id, bill_title, sort_order) VALUES (?, ?, ?)");
                $newBill->execute([$projectId, $bill['bill_title'], $bill['sort_order']]);
                $newBillId = (int)$pdo->lastInsertId();

                $items = $pdo->prepare("SELECT * FROM tender_boq_items WHERE bill_id = ? ORDER BY sort_order");
                $items->execute([$bill['bill_id']]);
                $itemInsert = $pdo->prepare("INSERT INTO project_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $itemInsert->execute([$newBillId, $item['description'], $item['unit'], $item['qty'], $item['rate'], $item['amount'], $item['sort_order']]);
                }
            }

            // Gap #6b — per tender.md Sec 3's Materials/NIP linkage rule: seed
            // the project's NIP Material List from the tender's Materials
            // Schedule, so nobody re-types the list the bid was priced on.
            $materials = $pdo->prepare("SELECT * FROM tender_materials WHERE tender_id = ? ORDER BY sort_order");
            $materials->execute([$tenderId]);
            $materialRows = $materials->fetchAll(PDO::FETCH_ASSOC);

            if ($materialRows) {
                $listStmt = $pdo->prepare("INSERT INTO nip_material_lists (name, project_id, created_by) VALUES (?, ?, ?)");
                $listStmt->execute(['Materials from Tender ' . $tender['tender_no'], $projectId, $userId]);
                $materialListId = (int)$pdo->lastInsertId();

                $linkNip = $pdo->prepare("INSERT INTO nip_material_list_nips (material_list_id, nip_product_id, quantity) VALUES (?, ?, ?)");
                $createProduct = $pdo->prepare("
                    INSERT INTO products (product_name, unit, is_service, track_inventory, project_id, created_by)
                    VALUES (?, ?, 1, 0, ?, ?)
                ");

                foreach ($materialRows as $material) {
                    $productId = !empty($material['product_id']) ? (int)$material['product_id'] : null;
                    if (!$productId) {
                        $createProduct->execute([$material['material'], $material['unit'], $projectId, $userId]);
                        $productId = (int)$pdo->lastInsertId();
                        $itemCode = nextCode($pdo, 'NIP');
                        $pdo->prepare("UPDATE products SET contract_item_no = ? WHERE product_id = ?")->execute([$itemCode, $productId]);
                    }
                    $linkNip->execute([$materialListId, $productId, $material['qty']]);
                }
            }

            if ($ownTxn) $pdo->commit();

            return ['success' => true, 'message' => 'Tender awarded and moved to Projects.', 'project_id' => $projectId, 'project_name' => $projectName];
        } catch (Throwable $e) {
            if ($ownTxn) $pdo->rollBack();
            throw $e;
        }
    }
}
