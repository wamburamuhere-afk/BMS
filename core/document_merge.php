<?php
/**
 * core/document_merge.php — merge-variable ("mail merge") support for the
 * Create Document letter builder.
 *
 * A template written once can contain {{tokens}} that resolve to real data the
 * system already holds — company profile, the document's own subject/date/code,
 * the recipient, the signing user, and (when project-linked) the project. This
 * is the mechanism that makes a template genuinely reusable instead of static
 * boilerplate the user must retype every time.
 *
 * Token syntax is double-brace ({{company_name}}) so it can't collide with a
 * lone { or } a user legitimately types in a letter.
 *
 * The client (create_document.php) resolves tokens live for the preview and the
 * client-rendered PDF; this PHP resolver is the authoritative safety pass at
 * save time, so a stored document body never persists a raw, unresolved token.
 */

if (!function_exists('documentMergeVariables')) {
    /**
     * The supported tokens, each with a human label for the "Insert Variable"
     * UI. Single source of truth — the JS mirror in create_document.php lists
     * the same names, and this array drives both the UI chips and the resolver.
     */
    function documentMergeVariables(): array
    {
        return [
            // Company profile (system_settings)
            'company_name'    => 'Company Name',
            'company_address' => 'Company Address',
            'company_phone'   => 'Company Phone',
            'company_email'   => 'Company Email',
            'company_tin'     => 'Company TIN',
            'company_vrn'     => 'Company VRN',
            // This document
            'document_code'   => 'Document Code',
            'date'            => 'Letter Date',
            'subject'         => 'Subject',
            // Recipient
            'recipient'         => 'Recipient',
            'recipient_address' => 'Recipient Address',
            // Signing user
            'sender_name'     => 'Sender Name',
            'sender_role'     => 'Sender Role',
            // Project (when the letter is project-linked)
            'project_name'    => 'Project Name',
            'contract_number' => 'Contract Number',
        ];
    }
}

if (!function_exists('resolveDocumentVariables')) {
    /**
     * Replace every {{token}} in $content with its resolved value. $ctx
     * supplies the document-specific values (subject, recipient, date, etc.);
     * company values are read from system_settings automatically. A token whose
     * value is unknown/empty resolves to '' (a finished letter should never show
     * a raw placeholder); a {{something}} that isn't a recognised token is left
     * untouched, so it can't accidentally strip legitimate double-brace text.
     */
    function resolveDocumentVariables(string $content, array $ctx = []): string
    {
        if ($content === '' || strpos($content, '{{') === false) {
            return $content;
        }

        $companyValues = [
            'company_name'    => function_exists('get_setting') ? get_setting('company_name', '') : '',
            'company_address' => function_exists('get_setting') ? get_setting('company_address', '') : '',
            'company_phone'   => function_exists('get_setting') ? get_setting('company_phone', '') : '',
            'company_email'   => function_exists('get_setting') ? get_setting('company_email', '') : '',
            'company_tin'     => function_exists('get_setting') ? get_setting('company_tin', '') : '',
            'company_vrn'     => function_exists('get_setting') ? get_setting('company_vrn', '') : '',
        ];

        $values = array_merge(
            array_fill_keys(array_keys(documentMergeVariables()), ''),
            $companyValues,
            $ctx
        );

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values) {
            $key = $m[1];
            // Recognised token → its value (may be empty). Unknown → leave as typed.
            return array_key_exists($key, $values) ? (string)$values[$key] : $m[0];
        }, $content);
    }
}
