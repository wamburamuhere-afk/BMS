<?php
/**
 * 2026_10_05_document_visibility_policy
 *
 * Adds see_all_documents flag to roles table.
 * Roles with this flag (Admin, Managing Director, Director, CFO, Credit Manager)
 * bypass the per-document access_level filter and see every document in the
 * library regardless of who uploaded it or what its access_level is.
 *
 * Other roles with document_library view permission see only:
 *   - public documents
 *   - documents they uploaded themselves
 *   - documents explicitly shared with them via document_assignees
 */

defined('MIGRATION_RUNNER') or exit('Direct access not allowed');

return [
    'up' => function (PDO $pdo) {
        // 1. Add column — idempotent
        $cols = array_column(
            $pdo->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        if (!in_array('see_all_documents', $cols, true)) {
            $pdo->exec("
                ALTER TABLE roles
                ADD COLUMN see_all_documents tinyint(1) NOT NULL DEFAULT 0
                    COMMENT 'Bypass per-document access filter: sees all docs in the library'
                AFTER is_admin
            ");
        }

        // 2. Grant flag to management roles by name so it is robust across
        //    tenants (role_ids may differ per tenant, names are canonical).
        $managementRoles = ['Admin', 'Managing Director', 'Director', 'CFO', 'Credit Manager'];
        $placeholders = implode(',', array_fill(0, count($managementRoles), '?'));
        $pdo->prepare("
            UPDATE roles SET see_all_documents = 1
            WHERE role_name IN ($placeholders)
        ")->execute($managementRoles);

        // is_admin roles always get the flag regardless of name
        $pdo->exec("UPDATE roles SET see_all_documents = 1 WHERE is_admin = 1");
    },

    'down' => function (PDO $pdo) {
        $cols = array_column(
            $pdo->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        if (in_array('see_all_documents', $cols, true)) {
            $pdo->exec("ALTER TABLE roles DROP COLUMN see_all_documents");
        }
    },
];
