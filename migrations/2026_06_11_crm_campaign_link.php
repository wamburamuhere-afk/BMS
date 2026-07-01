<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: CRM campaign link + legacy lead migration...\n";

try {

    // 1. Add campaign_id to crm_leads (idempotent)
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'campaign_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `campaign_id` INT NULL DEFAULT NULL AFTER `project_id`");
        echo "  + campaign_id column added to crm_leads.\n";
    } else {
        echo "  ~ campaign_id already exists in crm_leads — skipping.\n";
    }

    // 2. Add is_deleted to marketing_campaigns for soft-delete support (idempotent)
    $col2 = $pdo->query("SHOW COLUMNS FROM `marketing_campaigns` LIKE 'is_deleted'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE `marketing_campaigns` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
        echo "  + is_deleted column added to marketing_campaigns.\n";
    } else {
        echo "  ~ is_deleted already exists in marketing_campaigns — skipping.\n";
    }

    // 3. Check if the old leads table exists before touching it
    $leadsTable = $pdo->query("SHOW TABLES LIKE 'leads'")->fetch();
    if (!$leadsTable) {
        echo "  ~ leads table does not exist — skipping legacy migration.\n";
    } else {
        // Add crm_lead_id to leads for migration tracking (idempotent)
        $col3 = $pdo->query("SHOW COLUMNS FROM `leads` LIKE 'crm_lead_id'")->fetch();
        if (!$col3) {
            $pdo->exec("ALTER TABLE `leads` ADD COLUMN `crm_lead_id` INT NULL DEFAULT NULL");
            echo "  + crm_lead_id column added to leads.\n";
        } else {
            echo "  ~ crm_lead_id already exists in leads — skipping.\n";
        }

        // 4. Load pipeline stages dynamically
        $stages = $pdo->query("
            SELECT stage_id, stage_order, is_won, is_lost
            FROM crm_pipeline_stages WHERE status = 'active' ORDER BY stage_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($stages)) {
            echo "  ! No active pipeline stages found — skipping lead migration.\n";
        } else {
            $normalStages = array_values(array_filter($stages, function ($s) { return !(int)$s['is_won'] && !(int)$s['is_lost']; }));
            $wonStages    = array_values(array_filter($stages, function ($s) { return (int)$s['is_won'] === 1; }));
            $lostStages   = array_values(array_filter($stages, function ($s) { return (int)$s['is_lost'] === 1; }));

            $firstStageId  = !empty($normalStages) ? (int)$normalStages[0]['stage_id'] : (int)$stages[0]['stage_id'];
            $middleIdx     = !empty($normalStages) ? max(0, (int)(count($normalStages) / 2)) : 0;
            $middleStageId = !empty($normalStages) ? (int)$normalStages[$middleIdx]['stage_id'] : $firstStageId;
            $wonStageId    = !empty($wonStages)  ? (int)$wonStages[0]['stage_id']  : $firstStageId;
            $lostStageId   = !empty($lostStages) ? (int)$lostStages[0]['stage_id'] : $firstStageId;

            // 5. Migrate unmigrated old leads → crm_leads
            $oldLeads = $pdo->query("SELECT * FROM `leads` WHERE crm_lead_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($oldLeads)) {
                echo "  ~ No unmigrated legacy leads found.\n";
            } else {
                $sourceMap = [
                    'Website'       => 'website',
                    'Referral'      => 'referral',
                    'Social Media'  => 'social_media',
                    'Event'         => 'exhibition',
                    'Advertisement' => 'other',
                    'Cold Call'     => 'cold_call',
                    'Other'         => 'other',
                ];

                $insertStmt = $pdo->prepare("
                    INSERT INTO crm_leads
                        (lead_code, first_name, last_name, email, phone, lead_source,
                         pipeline_stage_id, lead_value, probability, notes,
                         converted, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, 'active', 1, NOW())
                ");
                $updateOldStmt = $pdo->prepare("UPDATE `leads` SET crm_lead_id = ? WHERE lead_id = ?");

                $migrated = 0;
                foreach ($oldLeads as $old) {
                    $status    = $old['status'] ?? 'New';
                    $converted = 0;

                    if ($status === 'Converted') {
                        $stageId   = $wonStageId;
                        $converted = 1;
                    } elseif ($status === 'Lost') {
                        $stageId = $lostStageId;
                    } elseif ($status === 'Qualified') {
                        $stageId = $middleStageId;
                    } else {
                        // New, Contacted, Nurturing
                        $stageId = $firstStageId;
                    }

                    $leadSource  = $sourceMap[$old['source'] ?? ''] ?? 'other';
                    $probability = max(5, min(100, intval($old['score'] ?? 20)));

                    $nextId   = (int)$pdo->query("SELECT COALESCE(MAX(lead_id), 0) + 1 FROM crm_leads")->fetchColumn();
                    $leadCode = 'LEAD-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

                    $insertStmt->execute([
                        $leadCode,
                        $old['first_name'],
                        $old['last_name'] ?: null,
                        $old['email']     ?: null,
                        $old['phone']     ?: null,
                        $leadSource,
                        $stageId,
                        $probability,
                        $old['notes']     ?: null,
                        $converted,
                    ]);
                    $newLeadId = (int)$pdo->lastInsertId();
                    $updateOldStmt->execute([$newLeadId, $old['lead_id']]);
                    $migrated++;
                }
                echo "  + Migrated $migrated legacy lead(s) to crm_leads.\n";
            }
        }
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
