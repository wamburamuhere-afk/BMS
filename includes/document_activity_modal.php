<?php
// File: includes/document_activity_modal.php
// Shared Comments / Notes / Access modal for a single document. Included by
// both document_library.php and project_view.php's Docs tab — both surfaces
// render rows from the same `documents` table, so they share one modal + one
// set of APIs (api/document/get_document_activity.php and friends).
//
// Usage: include this file once per page, then call
//   openDocActivity(documentId, documentName)
// from a row action.
global $pdo;
$__docActivityUsers = $pdo->query("
    SELECT user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) AS name
    FROM users WHERE is_active = 1 ORDER BY first_name, username
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="modal fade" id="docActivityModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-square-text me-2"></i><span id="docActivityTitle">Document</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="docActivityTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#docCommentsPane" type="button">Comments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#docNotesPane" type="button">Notes</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#docAccessPane" type="button">Access</button>
                    </li>
                </ul>
                <div class="tab-content pt-3">
                    <!-- Comments -->
                    <div class="tab-pane fade show active" id="docCommentsPane">
                        <div id="docCommentsList" class="mb-3" style="max-height:280px; overflow-y:auto;"></div>
                        <div class="d-flex gap-2">
                            <textarea id="docCommentText" class="form-control" rows="2" placeholder="Write a comment..."></textarea>
                            <button type="button" class="btn btn-primary align-self-end" onclick="postDocComment()">
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Notes -->
                    <div class="tab-pane fade" id="docNotesPane">
                        <div id="docNotesList" class="mb-3" style="max-height:280px; overflow-y:auto;"></div>
                        <div class="d-flex gap-2">
                            <textarea id="docNoteText" class="form-control" rows="2" placeholder="Write a note..."></textarea>
                            <button type="button" class="btn btn-primary align-self-end" onclick="postDocNote()">
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Access -->
                    <div class="tab-pane fade" id="docAccessPane">
                        <p class="text-muted small">
                            Current visibility: <span id="docAccessLevelBadge" class="badge bg-secondary text-capitalize">—</span>.
                            When visibility is <strong>private</strong> or <strong>restricted</strong>, only the people
                            listed below (plus the uploader and admins) can see this document.
                        </p>
                        <label class="form-label">Shared with</label>
                        <select id="docAssigneesSelect" class="form-select select2-static" multiple style="width:100%;">
                            <?php foreach ($__docActivityUsers as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>"><?= safe_output($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="docAccessReadOnlyNote" class="form-text d-none">Only the uploader or an admin can change who this document is shared with.</div>
                        <div class="mt-3 text-end">
                            <button type="button" id="docAccessSaveBtn" class="btn btn-primary" onclick="saveDocAssignees()">
                                <i class="bi bi-check2 me-1"></i> Save Access List
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let DOC_ACTIVITY_ID = null;

// Self-contained escaper — the two pages this modal is included from define
// their own JS-side escaping helper under different names (safeOutput vs.
// escapeHtml), so this modal can't rely on either being present.
function docActEsc(text) {
    const div = document.createElement('div');
    div.textContent = (text === null || text === undefined) ? '' : String(text);
    return div.innerHTML;
}

function openDocActivity(documentId, documentName) {
    DOC_ACTIVITY_ID = documentId;
    $('#docActivityTitle').text(documentName || 'Document');
    $('#docCommentsList').html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></div>');
    $('#docNotesList').html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></div>');
    new bootstrap.Modal(document.getElementById('docActivityModal')).show();
    loadDocActivity();
}

function loadDocActivity() {
    $.getJSON(APP_URL + '/api/document/get_document_activity.php', { document_id: DOC_ACTIVITY_ID }, function (res) {
        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load document activity.' });
            return;
        }
        renderDocComments(res.comments);
        renderDocNotes(res.notes);

        const level = res.document.access_level || 'public';
        const color = level === 'public' ? 'success' : (level === 'restricted' ? 'warning' : 'secondary');
        $('#docAccessLevelBadge').removeClass('bg-success bg-warning bg-secondary').addClass('bg-' + color).text(level);

        const $select = $('#docAssigneesSelect');
        $select.val((res.assignee_ids || []).map(String)).trigger('change');
        if (res.can_manage_access) {
            $select.prop('disabled', false);
            $('#docAccessSaveBtn').prop('disabled', false);
            $('#docAccessReadOnlyNote').addClass('d-none');
        } else {
            $select.prop('disabled', true);
            $('#docAccessSaveBtn').prop('disabled', true);
            $('#docAccessReadOnlyNote').removeClass('d-none');
        }
    }).fail(function (xhr) {
        let msg = 'Server error.';
        try { const r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    });
}

function docActivityTimeAgo(dateStr) {
    return new Date(dateStr.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function renderDocComments(comments) {
    if (!comments.length) {
        $('#docCommentsList').html('<div class="text-center text-muted py-3">No comments yet.</div>');
        return;
    }
    let html = '';
    comments.forEach(c => {
        html += `<div class="d-flex justify-content-between align-items-start border-bottom py-2">
            <div>
                <div><strong>${docActEsc(c.user_name)}</strong> <small class="text-muted">${docActivityTimeAgo(c.created_at)}</small></div>
                <div>${docActEsc(c.comment)}</div>
            </div>
            ${c.can_delete ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="deleteDocComment(${c.id})"><i class="bi bi-trash"></i></button>` : ''}
        </div>`;
    });
    $('#docCommentsList').html(html);
}

function renderDocNotes(notes) {
    if (!notes.length) {
        $('#docNotesList').html('<div class="text-center text-muted py-3">No notes yet.</div>');
        return;
    }
    let html = '';
    notes.forEach(n => {
        html += `<div class="d-flex justify-content-between align-items-start border-bottom py-2">
            <div>
                <div><strong>${docActEsc(n.user_name)}</strong> <small class="text-muted">${docActivityTimeAgo(n.created_at)}</small></div>
                <div>${docActEsc(n.note)}</div>
            </div>
            ${n.can_delete ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="deleteDocNote(${n.id})"><i class="bi bi-trash"></i></button>` : ''}
        </div>`;
    });
    $('#docNotesList').html(html);
}

function postDocComment() {
    const text = $('#docCommentText').val().trim();
    if (!text) return;
    $.post(APP_URL + '/api/document/add_document_comment.php', { document_id: DOC_ACTIVITY_ID, comment: text }, function (res) {
        if (res.success) {
            $('#docCommentText').val('');
            loadDocActivity();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json').fail(function () {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
    });
}

function deleteDocComment(id) {
    Swal.fire({ title: 'Delete comment?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(APP_URL + '/api/document/delete_document_comment.php', { comment_id: id }, function (res) {
                if (res.success) loadDocActivity();
                else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }, 'json');
        });
}

function postDocNote() {
    const text = $('#docNoteText').val().trim();
    if (!text) return;
    $.post(APP_URL + '/api/document/add_document_note.php', { document_id: DOC_ACTIVITY_ID, note: text }, function (res) {
        if (res.success) {
            $('#docNoteText').val('');
            loadDocActivity();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json').fail(function () {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
    });
}

function deleteDocNote(id) {
    Swal.fire({ title: 'Delete note?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(APP_URL + '/api/document/delete_document_note.php', { note_id: id }, function (res) {
                if (res.success) loadDocActivity();
                else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }, 'json');
        });
}

function saveDocAssignees() {
    const ids = $('#docAssigneesSelect').val() || [];
    const btn = $('#docAccessSaveBtn');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    $.post(APP_URL + '/api/document/save_document_assignees.php', { document_id: DOC_ACTIVITY_ID, user_ids: ids }, function (res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Saved', text: 'Access list updated.', timer: 1500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json').fail(function () {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
    }).always(function () {
        btn.prop('disabled', false).html(orig);
    });
}

$('#docActivityModal').on('shown.bs.modal', function () {
    const $sel = $('#docAssigneesSelect');
    if (!$sel.hasClass('select2-hidden-accessible')) {
        $sel.select2({ theme: 'bootstrap-5', dropdownParent: $('#docActivityModal'), placeholder: 'Select staff...', width: '100%' });
    }
});
</script>
