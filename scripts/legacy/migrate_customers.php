<?php
require_once 'roots.php';
require_once CONFIG_FILE;

try {
    $columns_to_add = [
        ['registration_number', 'VARCHAR(100)', 'company_name'],
        ['tin_number', 'VARCHAR(100)', 'registration_number'],
        ['occupation_business', 'VARCHAR(255)', 'customer_type'],
        ['incorporation_cert_path', 'VARCHAR(255)', 'id_attachment_path'],
        ['tin_cert_path', 'VARCHAR(255)', 'incorporation_cert_path'],
        ['vat_cert_path', 'VARCHAR(255)', 'tin_cert_path'],
        ['tax_clearance_path', 'VARCHAR(255)', 'vat_cert_path'],
        ['business_license_path', 'VARCHAR(255)', 'tax_clearance_path'],
        ['memart_cert_path', 'VARCHAR(255)', 'business_license_path'],
        ['board_resolution_path', 'VARCHAR(255)', 'memart_cert_path'],
        ['application_letter_path', 'VARCHAR(255)', 'board_resolution_path'],
        ['intro_letter_path', 'VARCHAR(255)', 'application_letter_path'],
        ['bank_statement_path', 'VARCHAR(255)', 'intro_letter_path'],
        ['financial_statement_path', 'VARCHAR(255)', 'bank_statement_path'],
        ['lease_agreement_path', 'VARCHAR(255)', 'financial_statement_path'],
        ['local_gov_letter_path', 'VARCHAR(255)', 'lease_agreement_path'],
        ['brela_certificate_path', 'VARCHAR(255)', 'local_gov_letter_path'],
        ['other_attachment_1_path', 'VARCHAR(255)', 'brela_certificate_path'],
        ['other_attachment_1_label', 'VARCHAR(255)', 'other_attachment_1_path'],
        ['other_attachment_2_path', 'VARCHAR(255)', 'other_attachment_1_label'],
        ['other_attachment_2_label', 'VARCHAR(255)', 'other_attachment_2_path'],
        ['other_attachment_3_path', 'VARCHAR(255)', 'other_attachment_2_label'],
        ['other_attachment_3_label', 'VARCHAR(255)', 'other_attachment_3_path'],
        ['other_attachment_4_path', 'VARCHAR(255)', 'other_attachment_3_label'],
        ['other_attachment_4_label', 'VARCHAR(255)', 'other_attachment_4_path']
    ];

    $existing_columns = $pdo->query("DESCRIBE customers")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($columns_to_add as $col) {
        $name = $col[0];
        $type = $col[1];
        $after = $col[2];

        if (!in_array($name, $existing_columns)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $name $type AFTER $after");
            echo "Added column: $name\n";
        } else {
            echo "Column already exists: $name\n";
        }
    }

    echo "Successfully updated customers table schema.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
