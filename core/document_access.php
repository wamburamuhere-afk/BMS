<?php
/**
 * core/document_access.php — shared authorization helpers for the `documents`
 * table. Two functions, one source of truth:
 *
 *  canSeeAllDocuments()      — true for Admin and roles with see_all_documents=1
 *                              (Managing Director, Director, CFO, Credit Manager).
 *                              They bypass the per-document access_level filter and
 *                              see every document in the library.
 *
 *  userCanAccessDocument()   — single-document gate: can this user download/view
 *                              this specific document? True when:
 *                              • canSeeAllDocuments() (admin / manager bypass)
 *                              • access_level = 'public'
 *                              • user uploaded it themselves
 *                              • user is listed in document_assignees
 *
 * Use userCanAccessDocument() wherever a single document row is about to be
 * served. List-level filtering (many rows via SQL WHERE) should mirror the
 * same four conditions; see api/document/get_documents.php for that pattern.
 */

if (!function_exists('canSeeAllDocuments')) {
    /**
     * Does the current user have "see everything" access to the document library?
     *
     * True for:
     *  - any admin (isAdmin() shortcut, covers is_admin=1 roles)
     *  - any role whose see_all_documents column = 1 (Managing Director, Director,
     *    CFO, Credit Manager — set by migration 2026_10_05_document_visibility_policy)
     *
     * The result is cached in $_SESSION['_see_all_docs'] to avoid repeated DB hits.
     */
    function canSeeAllDocuments(): bool
    {
        if (function_exists('isAdmin') && isAdmin()) {
            return true;
        }

        // Return cached value if already resolved this session
        if (isset($_SESSION['_see_all_docs'])) {
            return (bool)$_SESSION['_see_all_docs'];
        }

        if (!isset($_SESSION['role_id'])) {
            $_SESSION['_see_all_docs'] = false;
            return false;
        }

        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(see_all_documents, 0) FROM roles WHERE role_id = ? LIMIT 1"
            );
            $stmt->execute([$_SESSION['role_id']]);
            $flag = (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // Column missing (pre-migration) — fail safe: do not grant extra access
            $flag = false;
        }

        $_SESSION['_see_all_docs'] = $flag;
        return $flag;
    }
}

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
        // Admin and management roles see everything
        if (canSeeAllDocuments()) {
            // Still verify the document exists
            if ($doc !== null) return true;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE id = ?");
            $chk->execute([$documentId]);
            return (bool)$chk->fetchColumn();
        }

        if ($doc === null) {
            $stmt = $pdo->prepare("SELECT access_level, uploaded_by FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                return false;
            }
        }

        // Public documents are visible to anyone with the documents permission
        $level = $doc['access_level'] ?? '';
        if ($level === 'public' || $level === '') {
            return true;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        // Own upload
        if ($userId > 0 && (int)($doc['uploaded_by'] ?? 0) === $userId) {
            return true;
        }

        // Explicitly shared
        if ($userId > 0) {
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM document_assignees WHERE document_id = ? AND user_id = ?"
            );
            $chk->execute([$documentId, $userId]);
            return (bool)$chk->fetchColumn();
        }

        return false;
    }
}
