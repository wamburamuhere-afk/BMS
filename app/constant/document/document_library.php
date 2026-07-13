<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Handle document actions (Delete/Download) - MUST BE BEFORE HEADER for downloads
$action = $_GET['action'] ?? '';
$document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;

// Attention mode — dashboard "Document Expiry" deep-link (?attention=1):
// pre-select the "Expiring Soon (<=30d)" filter so only expiring documents show.
$attention = (isset($_GET['attention']) && $_GET['attention'] === '1');

if ($action === 'download' && $document_id > 0) {
    downloadDocumentLocal($pdo, $document_id);
    exit;
}

// Header included at top to ensure common scripts like Swal are available
// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

includeHeader();

// Enforce permission
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('documents');
}

// Helper functions (kept from original but renamed to avoid conflicts if needed)
function handleDocumentUploadLocal($pdo, $post_data, $files) {
    try {
        $upload_dir = 'uploads/document_library/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        $file = $files['document_file'];
        $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'zip', 'rar'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 50 * 1024 * 1024; // 50MB

        if (!in_array($file_ext, $allowed_types)) {
            throw new Exception("File type not allowed.");
        }

        if ($file['size'] > $max_size) {
            throw new Exception("File size exceeds 50MB limit");
        }

        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
        $target_path = $upload_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            throw new Exception("Failed to upload file");
        }

        $stmt = $pdo->prepare("
            INSERT INTO documents (
                document_name, description, file_path, original_filename,
                file_size, file_type, category_id, version, issue_date, expire_date, tags, access_level, uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $post_data['document_name'],
            $post_data['description'] ?? '',
            $target_path,
            $file['name'],
            $file['size'],
            $file_ext,
            !empty($post_data['category_id']) ? $post_data['category_id'] : null,
            $post_data['version'] ?? '1.0',
            !empty($post_data['issue_date']) ? $post_data['issue_date'] : null,
            !empty($post_data['expire_date']) ? $post_data['expire_date'] : null,
            $post_data['tags'] ?? '',
            $post_data['access_level'] ?? 'private',
            $_SESSION['user_id']
        ]);
        $document_id = $pdo->lastInsertId();

        // Audit Log for upload
        logAudit($pdo, $_SESSION['user_id'], 'upload_document', [
            'activity_type' => 'document_management',
            'description' => "Uploaded document: '{$post_data['document_name']}' ({$file['name']})",
            'entity_type' => 'document',
            'entity_id' => $document_id
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteDocumentLocal($pdo, $document_id) {
    try {
        $stmt = $pdo->prepare("SELECT file_path, uploaded_by FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) throw new Exception("Document not found");

        if ($_SESSION['user_role'] !== 'Admin' && $document['uploaded_by'] != $_SESSION['user_id']) {
            throw new Exception("Permission denied");
        }

        if (file_exists($document['file_path'])) {
            unlink($document['file_path']);
        }

        $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$document_id]);
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

        // Audit Log for delete
        logAudit($pdo, $_SESSION['user_id'], 'delete_document', [
            'activity_type' => 'delete',
            'description' => "Deleted document ID: $document_id",
            'entity_type' => 'document',
            'entity_id' => $document_id
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function downloadDocumentLocal($pdo, $document_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            die("Document record not found.");
        }

        $file_path = $document['file_path'];
        // Fix path if it's relative but we are in the root file
        if (!file_exists($file_path) && file_exists('uploads/document_library/' . basename($file_path))) {
             $file_path = 'uploads/document_library/' . basename($file_path);
        }

        if (!file_exists($file_path)) {
            die("Physical file not found at: " . $file_path);
        }

        $pdo->prepare("INSERT INTO document_downloads (document_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")
            ->execute([$document_id, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
        
        $pdo->prepare("UPDATE documents SET download_count = download_count + 1 WHERE id = ?")->execute([$document_id]);

        // Audit Log for download
        logAudit($pdo, $_SESSION['user_id'], 'download_document', [
            'activity_type' => 'download',
            'description' => "Downloaded document: '{$document['document_name']}' (ID: $document_id)",
            'entity_type' => 'document',
            'entity_id' => $document_id
        ]);

        // General Activity Log
        logActivity($pdo, $_SESSION['user_id'], 'DOWNLOAD DOCUMENT', "Downloaded document: '{$document['document_name']}'");

        // IMPORTANT: Clean ALL buffers to prevent corruption
        while (ob_get_level()) {
            ob_end_clean();
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        if (!$mime_type || $mime_type == 'text/plain') {
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if ($ext == 'pdf') $mime_type = 'application/pdf';
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($mime_type ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $document['original_filename'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
        // Final check to ensure no output
        if (headers_sent()) {
            die("Headers already sent. Cannot download file.");
        }
        
        readfile($file_path);
        exit;
    } catch (Exception $e) {
        die("Download Error: " . $e->getMessage());
    }
}

$categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-folder2-open"></i> Document Library</h2>
                            <p class="mb-0 text-muted">Manage and organize your business documents securely</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (canCreate('documents')): ?>
                            <a href="<?= getUrl('create_document') ?>" class="btn btn-outline-primary shadow-sm px-4">
                                <i class="bi bi-file-earmark-plus me-1"></i> Create Document
                            </a>
                            <button type="button" class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                <i class="bi bi-cloud-upload me-1"></i> Upload Document
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-6 col-md-4 col-lg mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-total-docs">0</h4>
                            <p class="small mb-0 opacity-75">Total Documents</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-files opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-total-size">0 KB</h4>
                            <p class="small mb-0 opacity-75">Storage Used</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-hdd-fill opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-recent-uploads">0</h4>
                            <p class="small mb-0 opacity-75">Recent Uploads</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cloud-arrow-up opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-recent-downloads">0</h4>
                            <p class="small mb-0 opacity-75">Recent Downloads</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-download opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-expiring-soon">0</h4>
                            <p class="small mb-0 opacity-75">Expiring Soon</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-hourglass-split opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($attention): ?>
    <div class="alert border-0 shadow-sm d-flex flex-wrap align-items-center gap-2 mb-4 d-print-none" style="background:#fff9e6; border-left:5px solid #ffc107 !important; border-radius:10px;">
        <i class="bi bi-funnel-fill fs-5 text-warning"></i>
        <div class="flex-grow-1">
            <strong>Showing only documents that need attention</strong>
            <span class="text-muted small d-block">Expiring within 30 days.</span>
        </div>
        <a href="<?= getUrl('document_library') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i> Show all documents</a>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="card shadow-sm border-0 mb-4 bg-white" style="border-radius: 12px;">
        <div class="card-body p-3">
            <form id="filterForm">
                <div class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Category</label>
                        <select class="form-select bg-light border-0 select2-static" id="categoryFilter" style="border-radius: 8px;">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted mb-1">File Type</label>
                        <select class="form-select bg-light border-0" id="typeFilter" style="border-radius: 8px;">
                            <option value="">All Types</option>
                            <option value="pdf">PDF</option>
                            <option value="doc">Word (.doc/docx)</option>
                            <option value="xls">Excel (.xls/xlsx)</option>
                            <option value="jpg">Image (JPG/PNG)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted mb-1">Access</label>
                        <select class="form-select bg-light border-0" id="accessFilter" style="border-radius: 8px;">
                            <option value="">All Access</option>
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                            <option value="restricted">Restricted</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted mb-1">Expiry Status</label>
                        <select class="form-select bg-light border-0" id="expiryFilter" style="border-radius: 8px;">
                            <option value="" <?= $attention ? '' : 'selected' ?>>All Expiry</option>
                            <option value="expiring" <?= $attention ? 'selected' : '' ?>>Expiring Soon (&le;30d)</option>
                            <option value="expired">Expired</option>
                            <option value="active">Active</option>
                            <option value="none">No Expiry Date</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="button" class="btn btn-primary px-4 shadow-sm" onclick="applyFilters()" style="border-radius: 8px; height: 38px;">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()" style="border-radius: 8px; height: 38px;">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#documentsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>

            <button type="button" class="btn btn-white shadow-sm border px-3 py-1 d-flex align-items-center gap-2" id="exportDocuments" style="border-radius: 8px; background: white;">
                <i class="bi bi-file-earmark-excel text-success"></i> <span class="small fw-bold text-muted">Export List</span>
            </button>
        </div>

        <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
            <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="lib_search" class="form-control border-0 p-2" placeholder="Search library...">
        </div>
    </div>

    <!-- Documents Table Card -->
    <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-primary">Document List</h5>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                        <button type="button" class="btn btn-primary btn-sm border" onclick="toggleDocsView('table')" id="docs-btn-table"><i class="bi bi-table"></i></button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleDocsView('card')" id="docs-btn-card"><i class="bi bi-grid"></i></button>
                    </div>
                    <span class="badge bg-success-soft text-success" id="stat-records-filtered">0 documents</span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="docsTableView">
            <div class="table-responsive">
                <table id="documentsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>S/NO</th>
                            <th>Document Name</th>
                            <th>Category</th>
                            <th>Size</th>
                            <th>Downloads</th>
                            <th>Uploaded By</th>
                            <th>Uploaded At</th>
                            <th>Access</th>
                            <th>Expiry</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
            </div><!-- #docsTableView -->
            <div id="docsCardGrid" class="row g-3 px-1 d-none mb-3"></div>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload"></i> Upload New Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadDocumentForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="document_name" class="form-label">Document Title</label>
                            <input type="text" class="form-control" id="document_name" name="document_name" required placeholder="e.g. Loan Agreement Template">
                        </div>
                        <div class="col-md-6">
                            <label for="category_id" class="form-label">Category</label>
                            <select class="form-select select2-static" id="category_id" name="category_id">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2" placeholder="Brief details about the document..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="version" class="form-label">Version</label>
                            <input type="text" class="form-control" id="version" name="version" value="1.0">
                        </div>
                        <div class="col-md-6">
                            <label for="access_level" class="form-label">Access Level</label>
                            <select class="form-select" id="access_level" name="access_level">
                                <option value="private">Private</option>
                                <option value="restricted">Restricted</option>
                                <option value="public">Public</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="issue_date" class="form-label">Issue Date</label>
                            <input type="date" class="form-control" id="issue_date" name="issue_date">
                            <div class="form-text">Date the document was issued / became valid.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="expire_date" class="form-label">Expire Date</label>
                            <input type="date" class="form-control" id="expire_date" name="expire_date">
                            <div class="form-text">Leave blank if the document never expires. Reminders fire 30, 14, 7 &amp; 1 day before.</div>
                        </div>
                        <div class="col-12">
                            <label for="tags" class="form-label">Tags (comma separated)</label>
                            <input type="text" class="form-control" id="tags" name="tags" placeholder="legal, loan, agreement">
                        </div>
                        <div class="col-12">
                            <label for="document_file" class="form-label">File Selection</label>
                            <input type="file" class="form-control" id="document_file" name="document_file" required>
                            <div class="form-text">PDF, Word, Excel, Images are allowed. Max size 50MB.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnUploadDoc">Start Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<!-- DataTables JS is handled by footer.php -->

<script>
var dtDocs;

$(document).ready(function() {
    // Audit Log for Page View
    logReportAction('Viewed Document Library', 'User viewed the document library dashboard');

    const userPermissions = {
        canEdit: <?= canEdit('documents') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('documents') ? 'true' : 'false' ?>
    };

    const table = dtDocs = $('#documentsTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/document/get_documents.php`,
            data: d => {
                d.category_id = $('#categoryFilter').val();
                d.file_type = $('#typeFilter').val();
                d.access_level = $('#accessFilter').val();
                d.expiry_status = $('#expiryFilter').val();
                d.search = $('#lib_search').val();
            },
            dataSrc: json => {
                const stats = json.stats;
                $('#stat-total-docs').text(stats.totalDocuments);
                $('#stat-total-size').text(formatFileSize(stats.totalSize));
                $('#stat-recent-uploads').text(stats.recentUploads);
                $('#stat-recent-downloads').text(stats.recentDownloads);
                $('#stat-expiring-soon').text(stats.expiringSoon ?? 0);
                $('#stat-records-filtered').text(json.recordsFiltered + ' documents');
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'document_name',
                render: (data, t, row) => `
                    <div class="d-flex align-items-center">
                        <i class="bi ${getFileIcon(row.file_type)} ${getFileIconColor(row.file_type)} fs-4 me-2"></i>
                        <div>
                            <strong>${escapeHtml(data)}</strong><br>
                            <small class="text-muted">${escapeHtml(row.original_filename)}</small>
                        </div>
                    </div>`
            },
            { 
                data: 'category_name',
                render: (data, t, row) => {
                    let html = data ? `<span class="badge mb-1" style="background-color: ${row.category_color || '#6c757d'}">${escapeHtml(data)}</span>` : '<span class="text-muted small">General</span>';
                    if (row.template_name) {
                        html += `<br><span class="badge bg-purple-subtle text-purple border border-purple-subtle small" style="font-size: 0.7rem;"><i class="bi bi-magic"></i> ${escapeHtml(row.template_name)}</span>`;
                    }
                    return html;
                }
            },
            { 
                data: 'file_size',
                render: data => formatFileSize(data)
            },
            { 
                data: 'download_count',
                className: 'text-center'
            },
            { data: 'uploaded_by_name' },
            { 
                data: 'uploaded_at',
                render: data => new Date(data).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})
            },
            {
                data: 'access_level',
                render: data => {
                    let color = data === 'public' ? 'success' : (data === 'restricted' ? 'warning' : 'secondary');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle text-capitalize px-3">${data}</span>`;
                }
            },
            {
                data: 'expire_date',
                orderable: false,
                render: data => {
                    let badge = getExpiryBadge(data);
                    if (data && data !== '0000-00-00') {
                        badge += `<br><small class="text-muted">${new Date(data + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})}</small>`;
                    }
                    return badge;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> <i class="bi bi-caret-down-fill" style="font-size: 0.75rem;"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="${APP_URL}/document_library?action=download&document_id=${row.id}"><i class="bi bi-download"></i> Download</a></li>
                            <li><a class="dropdown-item" href="${APP_URL}/${row.file_path}" target="_blank" onclick="logReportAction('Viewed Document Online', 'User viewed document: ${escapeHtml(row.document_name).replace(/'/g, '&apos;')} in browser')"><i class="bi bi-eye"></i> View Online</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        order: [[5, 'desc']],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        language: {
            search: "",
            searchPlaceholder: "Search library..."
        },
        drawCallback: function() { renderDocsCards(); }
    });

    checkDocsResponsiveView();
    $(window).on('resize.docsView', function() { checkDocsResponsiveView(); });

    // Select2 for filter
    $('#categoryFilter').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Categories',
        allowClear: true,
        width: '100%'
    });

    // Select2 for upload modal
    $('#uploadDocumentModal').on('shown.bs.modal', function() {
        if (!$('#category_id').hasClass('select2-hidden-accessible')) {
            $('#category_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#uploadDocumentModal'),
                placeholder: 'Select Category',
                allowClear: true,
                width: '100%'
            });
        }
    });

    // Custom search & filters
    $('#lib_search').on('keyup', function() {
        if ($(this).val().length > 2) logReportAction('Searched Document Library', 'User searched for: ' + $(this).val());
        table.ajax.reload();
    });
    $('#categoryFilter, #typeFilter, #accessFilter, #expiryFilter').on('change', function() {
        logReportAction('Filtered Document Library', 'User applied filters: Category=' + ($('#categoryFilter').val() || 'All') + ', Type=' + ($('#typeFilter').val() || 'All'));
        table.ajax.reload();
    });

    // Export functionality
    $('#exportDocuments').on('click', function() {
        const category_id = $('#categoryFilter').val();
        const file_type = $('#typeFilter').val();
        const access_level = $('#accessFilter').val();
        const search = $('#lib_search').val();
        
        logReportAction('Exported Document List', 'User exported the document library list to Excel');
        window.location.href = `${APP_URL}/api/document/export_documents.php?category_id=${category_id}&file_type=${file_type}&access_level=${access_level}&search=${search}`;
    });
});

function applyFilters() { $('#documentsTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#filterForm')[0].reset();
    $('#categoryFilter').val(null).trigger('change');
    $('#documentsTable').DataTable().ajax.reload();
}

function checkDocsResponsiveView() {
    const isMobile = window.innerWidth <= 767;
    toggleDocsView(isMobile ? 'card' : (localStorage.getItem('docsView') || 'table'));
}

function toggleDocsView(mode) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) mode = 'card';
    if (mode === 'card') {
        $('#docsTableView').addClass('d-none');
        $('#docsCardGrid').removeClass('d-none');
        $('#docs-btn-card').removeClass('btn-light').addClass('btn-primary');
        $('#docs-btn-table').removeClass('btn-primary').addClass('btn-light');
        renderDocsCards();
    } else {
        $('#docsTableView').removeClass('d-none');
        $('#docsCardGrid').addClass('d-none');
        $('#docs-btn-table').removeClass('btn-light').addClass('btn-primary');
        $('#docs-btn-card').removeClass('btn-primary').addClass('btn-light');
    }
    if (!isMobile) localStorage.setItem('docsView', mode);
}

function renderDocsCards() {
    const grid = $('#docsCardGrid');
    if (grid.hasClass('d-none')) return;
    grid.empty();
    if (!dtDocs) return;
    const rows = dtDocs.rows({ page: 'current' }).data();
    if (!rows.length) {
        grid.append('<div class="col-12"><p class="text-center text-muted py-4">No documents found.</p></div>');
        return;
    }
    rows.each(function(row) {
        const downloadUrl = `${APP_URL}/document_library?action=download&document_id=${row.id}`;
        const viewUrl = `${APP_URL}/${row.file_path}`;
        const date = new Date(row.uploaded_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
        const categoryHtml = row.category_name
            ? `<span class="badge" style="background-color:${row.category_color || '#6c757d'};font-size:0.68rem;">${escapeHtml(row.category_name)}</span>`
            : '<span class="text-muted" style="font-size:0.75rem;">General</span>';
        grid.append(`
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-start gap-2 mb-1">
                            <i class="bi ${getFileIcon(row.file_type)} ${getFileIconColor(row.file_type)} fs-5 flex-shrink-0"></i>
                            <div style="min-width:0;">
                                <strong style="font-size:0.88rem;word-break:break-word;">${escapeHtml(row.document_name)}</strong>
                                <div style="font-size:0.72rem;color:#888;word-break:break-word;">${escapeHtml(row.original_filename)}</div>
                            </div>
                        </div>
                        <div style="font-size:0.78rem;color:#555;">
                            ${categoryHtml} &nbsp;
                            <small class="text-muted">Size:</small> ${formatFileSize(row.file_size)} &nbsp;
                            <small class="text-muted">By:</small> ${escapeHtml(row.uploaded_by_name)}<br>
                            <small class="text-muted">Date:</small> ${date} &nbsp;
                            <small class="text-muted">Expiry:</small> ${getExpiryBadge(row.expire_date)}
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                        <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                            <a href="${downloadUrl}" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-primary text-center"><i class="bi bi-download"></i></a>
                            <a href="${viewUrl}" target="_blank" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-secondary text-center"><i class="bi bi-eye"></i></a>
                        </div>
                    </div>
                </div>
            </div>`);
    });
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this document deletion!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `${APP_URL}/api/document/delete_document.php`,
                type: 'POST',
                data: { document_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted Document', 'User successfully deleted document ID: ' + id);
                        $('#documentsTable').DataTable().ajax.reload();
                        Swal.fire('Deleted!', 'Document has been deleted.', 'success');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Failed to delete document. Please try again.', 'error');
                }
            });
        }
    });
}

// Upload Form Handler
$('#uploadDocumentForm').on('submit', function(e) {
    e.preventDefault();

    // Validate dates: expire date must be after issue date
    const issueDate = $('#issue_date').val();
    const expireDate = $('#expire_date').val();
    if (issueDate && expireDate && expireDate <= issueDate) {
        Swal.fire('Invalid Dates', 'Expire Date must be later than the Issue Date.', 'warning');
        return;
    }

    const btn = $('#btnUploadDoc');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Uploading...');
    
    const formData = new FormData(this);
    $.ajax({
        url: `${APP_URL}/api/document/upload_document.php`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Uploaded!',
                    text: 'Document uploaded successfully!',
                    timer: 2000,
                    showConfirmButton: false
                });
                $('#uploadDocumentModal').modal('hide');
                $('#documentsTable').DataTable().ajax.reload();
                $('#uploadDocumentForm')[0].reset();
            } else {
                Swal.fire('Error!', res.message, 'error');
            }
        },
        error: () => Swal.fire('Error!', 'System error during upload.', 'error'),
        complete: () => btn.prop('disabled', false).html('Start Upload')
    });
});

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function getFileIcon(ext) {
    const icons = {
        pdf: 'bi-file-earmark-pdf',
        doc: 'bi-file-earmark-word',
        docx: 'bi-file-earmark-word',
        xls: 'bi-file-earmark-excel',
        xlsx: 'bi-file-earmark-excel',
        png: 'bi-file-earmark-image',
        jpg: 'bi-file-earmark-image',
        jpeg: 'bi-file-earmark-image',
        zip: 'bi-file-earmark-zip',
        txt: 'bi-file-earmark-text'
    };
    return icons[ext] || 'bi-file-earmark';
}

function getFileIconColor(ext) {
    if (ext === 'pdf') return 'text-danger';
    if (ext === 'doc' || ext === 'docx') return 'text-primary';
    if (ext === 'xls' || ext === 'xlsx') return 'text-success';
    if (ext.match(/jpg|jpeg|png|gif/)) return 'text-info';
    return 'text-secondary';
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}

// Build an expiry status badge from a document's expire_date
function getExpiryBadge(expireDate) {
    if (!expireDate || expireDate === '0000-00-00') {
        return '<span class="badge bg-light text-muted border">No expiry</span>';
    }
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const exp = new Date(expireDate + 'T00:00:00');
    const days = Math.round((exp - today) / 86400000);
    if (days < 0)   return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Expired</span>';
    if (days === 0) return '<span class="badge bg-danger text-white">Expires today</span>';
    if (days <= 30) return `<span class="badge bg-warning-subtle text-warning border border-warning-subtle">Expires in ${days}d</span>`;
    return '<span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>';
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.bg-success-soft { background-color: #d1e7dd !important; }
.bg-purple-subtle { background-color: #f3e5f5 !important; }
.text-purple { color: #6a1b9a !important; }
.border-purple-subtle { border-color: #e1bee7 !important; }
.custom-table-header { border-bottom: 2px solid #e9ecef; }
#documentsTable thead th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border-bottom: none; }
.dropdown-toggle::after { display: none; }
.btn-white:hover { background-color: #f8f9fa !important; }
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
    #docsCardGrid .card-footer a { flex: 1; min-width: 0; padding: 3px 4px; font-size: 0.72rem; }
}
@media print { #docsCardGrid { display: none !important; } }
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>