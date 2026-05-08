<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>

<?php
require_once HEADER_FILE;

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Fetch customer details if customer_id is provided
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               CONCAT(c.first_name, ' ', c.last_name) AS full_name,
               c.customer_id,
               c.email,
               c.phone_number
        FROM customers c 
        WHERE c.customer_id = ?
    ");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    $response = handleCustomerDocumentUpload($pdo, $customer_id, $_POST, $_FILES);
    if ($response['success']) {
        echo '<script>alert("Document uploaded successfully!");</script>';
    } else {
        echo '<script>alert("Error: ' . $response['message'] . '");</script>';
    }
}

// Handle document deletion
if ($action === 'delete' && isset($_GET['doc_id'])) {
    $doc_id = (int)$_GET['doc_id'];
    $response = deleteCustomerDocument($pdo, $doc_id);
    if ($response['success']) {
        echo '<script>alert("Document deleted successfully!");</script>';
    } else {
        echo '<script>alert("Error: ' . $response['message'] . '");</script>';
    }
}

// Fetch documents for the customer
$documents = [];
if ($customer_id > 0) {
    // Check if customer_documents table exists
    $table_exists = false;
    try {
        $pdo->query("SELECT 1 FROM customer_documents LIMIT 1");
        $table_exists = true;
    } catch (Exception $e) {
        $table_exists = false;
    }
    
    if ($table_exists) {
        $stmt = $pdo->prepare("
            SELECT d.*, u.username AS uploaded_by_name 
            FROM customer_documents d 
            LEFT JOIN users u ON d.uploaded_by = u.user_id 
            WHERE d.customer_id = ? 
            ORDER BY d.uploaded_at DESC
        ");
        $stmt->execute([$customer_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch all customers for the dropdown
$customers = $pdo->query("
    SELECT customer_id, CONCAT(first_name, ' ', last_name) AS full_name, email, phone_number AS phone 
    FROM customers 
    ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function getCustomerStatusClass($status) {
    switch ($status) {
        case 'active': return 'success';
        case 'inactive': return 'secondary';
        case 'blocked': return 'danger';
        case 'pending': return 'warning';
        default: return 'secondary';
    }
}

function getFileIcon($file_path) {
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return 'file-pdf';
        case 'doc': case 'docx': return 'file-word';
        case 'jpg': case 'jpeg': case 'png': case 'gif': return 'file-image';
        case 'txt': return 'file-alt';
        case 'xls': case 'xlsx': return 'file-excel';
        default: return 'file';
    }
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return number_format(($bytes / pow($k, $i)), 2) . ' ' . $sizes[$i];
}

function handleCustomerDocumentUpload($pdo, $customer_id, $post_data, $files) {
    try {
        $upload_dir = '../uploads/customer_documents/' . $customer_id . '/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        $file = $files['document_file'];
        
        // Validate file
        $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'xls', 'xlsx'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 10 * 1024 * 1024; // 10MB

        if (!in_array($file_ext, $allowed_types)) {
            throw new Exception("File type not allowed. Allowed types: " . implode(', ', $allowed_types));
        }

        if ($file['size'] > $max_size) {
            throw new Exception("File size exceeds 10MB limit");
        }

        // Generate unique filename
        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
        $target_path = $upload_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            throw new Exception("Failed to upload file");
        }

        // Check if customer_documents table exists, create if not
        try {
            $pdo->query("SELECT 1 FROM customer_documents LIMIT 1");
        } catch (Exception $e) {
            // Table doesn't exist, create it
            $create_table_sql = "
                CREATE TABLE customer_documents (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    customer_id INT NOT NULL,
                    document_type VARCHAR(50) NOT NULL,
                    document_name VARCHAR(255) NOT NULL,
                    description TEXT,
                    file_path VARCHAR(500) NOT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    file_size INT NOT NULL,
                    file_type VARCHAR(10) NOT NULL,
                    tags VARCHAR(255),
                    document_date DATE,
                    expiry_date DATE,
                    uploaded_by INT,
                    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
                    FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
                )
            ";
            $pdo->exec($create_table_sql);
        }

        // Insert document record
        $stmt = $pdo->prepare("
            INSERT INTO customer_documents (
                customer_id, document_type, document_name, description, file_path, 
                original_filename, file_size, file_type, tags, document_date,
                expiry_date, uploaded_by, uploaded_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $customer_id,
            $post_data['document_type'],
            $post_data['document_name'],
            $post_data['description'] ?? '',
            $target_path,
            $file['name'],
            $file['size'],
            $file_ext,
            $post_data['tags'] ?? '',
            $post_data['document_date'] ?? date('Y-m-d'),
            $post_data['expiry_date'] ?? null,
            $_SESSION['user_id']
        ]);

        return ['success' => true, 'message' => 'Document uploaded successfully'];

    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteCustomerDocument($pdo, $doc_id) {
    try {
        // Get file path first
        $stmt = $pdo->prepare("SELECT file_path FROM customer_documents WHERE id = ?");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            throw new Exception("Document not found");
        }

        // Delete file from server
        if (file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }

        // Delete database record
        $stmt = $pdo->prepare("DELETE FROM customer_documents WHERE id = ?");
        $stmt->execute([$doc_id]);

        return ['success' => true, 'message' => 'Document deleted successfully'];

    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Check for expired documents
function checkExpiredDocuments($pdo, $customer_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expired_count 
            FROM customer_documents 
            WHERE customer_id = ? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
        ");
        $stmt->execute([$customer_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['expired_count'] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="h3 mb-4">Customer Documents Management</h1>
            
            <!-- Customer Selection Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Select Customer</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-8">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-control" id="customer_id" name="customer_id" required onchange="this.form.submit()">
                                <option value="">Select a customer...</option>
                                <?php foreach ($customers as $customer_item): ?>
                                    <option value="<?= $customer_item['customer_id'] ?>" 
                                        <?= $customer_id == $customer_item['customer_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($customer_item['full_name']) ?> 
                                        (<?= htmlspecialchars($customer_item['email'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <?php if ($customer_id > 0): ?>
                                <a href="<?= getUrl('customers/documents') ?>" class="btn btn-secondary">Clear Selection</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($customer_id > 0 && $customer): ?>
            
            <!-- Customer Information -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Customer: <?= htmlspecialchars($customer['full_name']) ?>
                    </h5>
                    <div>
                        <a href="<?= getUrl('customers/details') ?>?id=<?= $customer_id ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> View Customer Details
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Email:</strong> <?= htmlspecialchars($customer['email'] ?? 'N/A') ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Phone:</strong> <?= htmlspecialchars($customer['phone'] ?? 'N/A') ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Customer ID:</strong> <?= htmlspecialchars($customer['customer_id']) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Documents:</strong> 
                            <span class="badge bg-primary"><?= count($documents) ?></span>
                            <?php
                            $expired_count = checkExpiredDocuments($pdo, $customer_id);
                            if ($expired_count > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $expired_count ?> expired</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Document Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Upload New Document</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="document_type" class="form-label">Document Type</label>
                                <select class="form-control" id="document_type" name="document_type" required>
                                    <option value="">Select Document Type</option>
                                    <option value="national_id">National ID</option>
                                    <option value="passport">Passport</option>
                                    <option value="driving_license">Driving License</option>
                                    <option value="birth_certificate">Birth Certificate</option>
                                    <option value="utility_bill">Utility Bill</option>
                                    <option value="bank_statement">Bank Statement</option>
                                    <option value="pay_slip">Pay Slip</option>
                                    <option value="tax_return">Tax Return</option>
                                    <option value="business_license">Business License</option>
                                    <option value="company_registration">Company Registration</option>
                                    <option value="credit_report">Credit Report</option>
                                    <option value="reference_letter">Reference Letter</option>
                                    <option value="employment_letter">Employment Letter</option>
                                    <option value="rental_agreement">Rental Agreement</option>
                                    <option value="property_deed">Property Deed</option>
                                    <option value="academic_certificate">Academic Certificate</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="document_name" class="form-label">Document Name</label>
                                <input type="text" class="form-control" id="document_name" name="document_name" 
                                       placeholder="e.g., Passport Copy, Salary Slip" required>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="document_date" class="form-label">Document Date</label>
                                <input type="date" class="form-control" id="document_date" name="document_date" 
                                       value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-3">
                                <label for="expiry_date" class="form-label">Expiry Date (if applicable)</label>
                                <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                <div class="form-text">Leave blank if no expiry</div>
                            </div>
                            
                            <div class="col-md-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="2" placeholder="Optional description of the document"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="document_file" class="form-label">Document File</label>
                                <input type="file" class="form-control" id="document_file" name="document_file" 
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.txt,.xls,.xlsx" required>
                                <div class="form-text">
                                    Allowed files: PDF, Word, Excel, Images (JPG, PNG, GIF), Text. Max size: 10MB
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="tags" class="form-label">Tags</label>
                                <input type="text" class="form-control" id="tags" name="tags" 
                                       placeholder="e.g., verified, pending_review, important">
                                <div class="form-text">
                                    Separate multiple tags with commas
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> Upload Document
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetUploadForm()">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Documents List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Customer Documents (<?= count($documents) ?>)</h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-success" onclick="exportDocumentList()">
                            <i class="bi bi-download"></i> Export List
                        </button>
                        <button type="button" class="btn btn-sm btn-info" onclick="toggleExpiryFilter()">
                            <i class="bi bi-clock"></i> Show Expired
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($documents) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="documentsTable">
                                <thead>
                                    <tr>
                                        <th>Document Name</th>
                                        <th>Type</th>
                                        <th>File</th>
                                        <th>Size</th>
                                        <th>Document Date</th>
                                        <th>Expiry Date</th>
                                        <th>Uploaded By</th>
                                        <th>Upload Date</th>
                                        <th>Tags</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $doc): 
                                        $is_expired = $doc['expiry_date'] && strtotime($doc['expiry_date']) < time();
                                        $expiry_class = $is_expired ? 'table-danger' : '';
                                    ?>
                                        <tr class="<?= $expiry_class ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($doc['document_name']) ?></strong>
                                                <?php if ($doc['description']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($doc['description']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($is_expired): ?>
                                                    <br><small class="text-danger"><i class="bi bi-exclamation-triangle"></i> Expired</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <i class="bi bi-<?= getFileIcon($doc['file_path']) ?> me-1"></i>
                                                <?= htmlspecialchars($doc['original_filename']) ?>
                                            </td>
                                            <td><?= formatFileSize($doc['file_size']) ?></td>
                                            <td><?= $doc['document_date'] ? date('M j, Y', strtotime($doc['document_date'])) : '-' ?></td>
                                            <td>
                                                <?php if ($doc['expiry_date']): ?>
                                                    <span class="<?= $is_expired ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                        <?= date('M j, Y', strtotime($doc['expiry_date'])) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($doc['uploaded_by_name'] ?? 'System') ?></td>
                                            <td><?= date('M j, Y H:i', strtotime($doc['uploaded_at'])) ?></td>
                                            <td>
                                                <?php if ($doc['tags']): ?>
                                                    <?php
                                                    $tags = explode(',', $doc['tags']);
                                                    foreach ($tags as $tag):
                                                        if (trim($tag)):
                                                    ?>
                                                        <span class="badge bg-secondary me-1"><?= htmlspecialchars(trim($tag)) ?></span>
                                                    <?php 
                                                        endif;
                                                    endforeach; 
                                                    ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= htmlspecialchars($doc['file_path']) ?>" 
                                                       class="btn btn-outline-primary" 
                                                       target="_blank" 
                                                       title="View Document">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars($doc['file_path']) ?>" 
                                                       class="btn btn-outline-success" 
                                                       download="<?= htmlspecialchars($doc['original_filename']) ?>"
                                                       title="Download">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                                                    <button type="button" 
                                                            class="btn btn-outline-danger" 
                                                            onclick="confirmDelete(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['document_name']) ?>')"
                                                            title="Delete Document">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="bi bi-info-circle"></i> No documents found for this customer. Upload documents using the form above.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($customer_id > 0): ?>
                <div class="alert alert-danger text-center">
                    <i class="bi bi-exclamation-triangle"></i> Customer not found.
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle"></i> Please select a customer to manage documents.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete document "<span id="deleteDocName"></span>"?</p>
                <p class="text-danger"><strong>This action cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete Document</a>
            </div>
        </div>
    </div>
</div>

<script>
function resetUploadForm() {
    document.getElementById('uploadForm').reset();
}

function confirmDelete(docId, docName) {
    $('#deleteDocName').text(docName);
    $('#confirmDeleteBtn').attr('href', '<?= getUrl('customers/documents') ?>?customer_id=<?= $customer_id ?>&action=delete&doc_id=' + docId);
    $('#deleteModal').modal('show');
}

function exportDocumentList() {
    // Simple table export
    const table = document.getElementById('documentsTable');
    const html = table.outerHTML;
    const url = 'data:application/vnd.ms-excel,' + escape(html);
    window.open(url, '_blank');
}

function toggleExpiryFilter() {
    const table = $('#documentsTable').DataTable();
    table.draw(); // This will trigger the custom filter
}

// Initialize DataTable
$(document).ready(function() {
    $('#documentsTable').DataTable({
        pageLength: 25,
        order: [[7, 'desc']], // Sort by upload date descending
        responsive: true,
        columnDefs: [
            { targets: [4, 5, 6, 7], orderable: true },
            { targets: [9], orderable: false }
        ],
        language: {
            search: "Search documents:",
            lengthMenu: "Show _MENU_ documents per page",
            info: "Showing _START_ to _END_ of _TOTAL_ documents",
            infoEmpty: "No documents available",
            infoFiltered: "(filtered from _MAX_ total documents)"
        }
    });
});

// File upload preview and validation
document.getElementById('document_file').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024; // MB
        if (fileSize > 10) {
            alert('File size exceeds 10MB limit. Please choose a smaller file.');
            e.target.value = '';
        }
        
        // Auto-fill document name if empty
        const docNameInput = document.getElementById('document_name');
        if (!docNameInput.value) {
            const fileName = file.name.replace(/\.[^/.]+$/, ""); // Remove extension
            docNameInput.value = fileName.replace(/[_-]/g, ' '); // Replace underscores and dashes with spaces
        }
    }
});

// Form submission handling
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('document_file');
    if (fileInput.files.length === 0) {
        e.preventDefault();
        alert('Please select a file to upload.');
        return;
    }
    
    const file = fileInput.files[0];
    const fileSize = file.size / 1024 / 1024;
    if (fileSize > 10) {
        e.preventDefault();
        alert('File size exceeds 10MB limit. Please choose a smaller file.');
        return;
    }
});

// Auto-set expiry date for specific document types
document.getElementById('document_type').addEventListener('change', function() {
    const docType = this.value;
    const expiryDateInput = document.getElementById('expiry_date');
    
    // Clear expiry date when type changes
    expiryDateInput.value = '';
    
    // Set default expiry dates for specific document types
    const today = new Date();
    let expiryDate = new Date();
    
    switch(docType) {
        case 'national_id':
        case 'passport':
        case 'driving_license':
            // Set to 10 years from now
            expiryDate.setFullYear(today.getFullYear() + 10);
            break;
        case 'bank_statement':
        case 'pay_slip':
            // Set to 3 months from now
            expiryDate.setMonth(today.getMonth() + 3);
            break;
        case 'utility_bill':
            // Set to 6 months from now
            expiryDate.setMonth(today.getMonth() + 6);
            break;
        default:
            return; // Don't set expiry for other types
    }
    
    // Format date as YYYY-MM-DD
    const formattedDate = expiryDate.toISOString().split('T')[0];
    expiryDateInput.value = formattedDate;
});
</script>

<?php
require_once FOOTER_FILE;
?>