<?php
/**
 * core/document_access.php — shared authorization check for the `documents`
 * table, one source of truth for the rule already proven correct in
 * api/document/get_document_activity.php: admins see everything; a public
 * document is visible to anyone with `documents` view permission; a private/
 * restricted document is visible only to its uploader or someone explicitly
 * listed in document_assignees.
 *
 * Use this everywhere a single document's row is about to be shown/served —
 * list-level filtering (many rows via SQL WHERE) is a different, valid
 * pattern already used correctly in api/document/get_documents.php and is
 * not what this helper is for.
 */

if (!function_exists('userCanAccessDocument')) {
    /**
     * @param PDO        $pdo
     * @param int        $documentId
     * @param array|null $doc  Pass ['access_level' => ..., 'uploaded_by' => ...]
     *                         if already fetched, to skip a redundant query.
     * @return bool  false also when the document doesn't exist.
     */
    function userCanAccessDocument(PDO $pdo, int $documentId, ?array $doc = null): bool
    {
        if (function_exists('isAdmin') && isAdmin()) {
            return true;
        }

        if ($doc === null) {
            $stmt = $pdo->prepare("SELECT access_level, uploaded_by FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                return false;
            }
        }

        if (($doc['access_level'] ?? 'public') === 'public') {
            return true;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0 && (int)($doc['uploaded_by'] ?? 0) === $userId) {
            return true;
        }

        $chk = $pdo->prepare("SELECT COUNT(*) FROM document_assignees WHERE document_id = ? AND user_id = ?");
        $chk->execute([$documentId, $userId]);
        return (bool)$chk->fetchColumn();
    }
}
