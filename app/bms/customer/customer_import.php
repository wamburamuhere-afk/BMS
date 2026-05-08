<?php
// Start the buffer
ob_start();

// Include the header
require_once HEADER_FILE;
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-upload"></i> Import Customers</h2>
                    <p class="text-muted mb-0">Bulk import customers from CSV or Excel files</p>
                </div>
                <div>
                    <a href="<?= getUrl('customers') ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to Customers
                    </a>
                    <a href="<?= getUrl('customers') ?>?action=add" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-plus"></i> Add Single Customer
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Import Instructions -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Import Instructions</h6>
                </div>
                <div class="card-body">
                    <h6>Supported Formats:</h6>
                    <ul class="list-unstyled mb-3">
                        <li><i class="bi bi-filetype-csv text-success"></i> CSV Files (.csv)</li>
                        <li><i class="bi bi-filetype-xlsx text-primary"></i> Excel Files (.xlsx, .xls)</li>
                    </ul>

                    <h6>File Requirements:</h6>
                    <ul class="small">
                        <li>Maximum file size: 10MB</li>
                        <li>First row should contain headers</li>
                        <li>Required fields: <code>entity_type</code>, <code>first_name</code> or <code>company_name</code></li>
                    </ul>

                    <h6>Download Template:</h6>
                    <a href="downloads/customer_import_template.csv" class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="bi bi-download"></i> CSV Template
                    </a>
                    <a href="downloads/customer_import_template.xlsx" class="btn btn-outline-success btn-sm w-100">
                        <i class="bi bi-download"></i> Excel Template
                    </a>
                </div>
            </div>

            <!-- Field Mapping Guide -->
            <div class="card mt-4">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="bi bi-list-check"></i> Field Mapping Guide</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Field Name</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>entity_type</code></td>
                                    <td><span class="badge bg-danger">Yes</span></td>
                                    <td>"individual" or "company"</td>
                                </tr>
                                <tr>
                                    <td><code>first_name</code></td>
                                    <td><span class="badge bg-warning">Conditional</span></td>
                                    <td>Required if entity_type = "individual"</td>
                                </tr>
                                <tr>
                                    <td><code>last_name</code></td>
                                    <td><span class="badge bg-warning">Conditional</span></td>
                                    <td>Required if entity_type = "individual"</td>
                                </tr>
                                <tr>
                                    <td><code>company_name</code></td>
                                    <td><span class="badge bg-warning">Conditional</span></td>
                                    <td>Required if entity_type = "company"</td>
                                </tr>
                                <tr>
                                    <td><code>phone_number</code></td>
                                    <td><span class="badge bg-success">No</span></td>
                                    <td>Phone number</td>
                                </tr>
                                <tr>
                                    <td><code>email_address</code></td>
                                    <td><span class="badge bg-success">No</span></td>
                                    <td>Email address</td>
                                </tr>
                                <tr>
                                    <td><code>address</code></td>
                                    <td><span class="badge bg-success">No</span></td>
                                    <td>Postal address</td>
                                </tr>
                                <tr>
                                    <td><code>id_number</code></td>
                                    <td><span class="badge bg-success">No</span></td>
                                    <td>ID number for individuals</td>
                                </tr>
                                <tr>
                                    <td><code>id_type</code></td>
                                    <td><span class="badge bg-success">No</span></td>
                                    <td>ID type for individuals</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Form -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-cloud-upload"></i> Upload Customer File</h6>
                </div>
                <div class="card-body">
                    <div id="form-message" class="mb-3"></div>
                    
                    <form id="import-form" method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="import_file" class="form-label">Select File <span class="text-danger">*</span></label>
                                <input type="file" name="import_file" id="import_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                                <div class="form-text">Supported formats: CSV, Excel (.xlsx, .xls)</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="file_type" class="form-label">File Type <span class="text-danger">*</span></label>
                                <select name="file_type" id="file_type" class="form-select" required>
                                    <option value="">Select file type</option>
                                    <option value="csv">CSV File</option>
                                    <option value="excel">Excel File</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="has_headers" class="form-label">File Contains Headers</label>
                                <select name="has_headers" id="has_headers" class="form-select">
                                    <option value="1" selected>Yes, first row contains headers</option>
                                    <option value="0">No, first row contains data</option>
                                </select>
                            </div>

                            <!-- Preview Section -->
                            <div class="col-12 mb-3">
                                <div id="file-preview" class="d-none">
                                    <h6 class="border-bottom pb-2">File Preview</h6>
                                    <div class="table-responsive">
                                        <table id="preview-table" class="table table-sm table-striped">
                                            <thead id="preview-headers"></thead>
                                            <tbody id="preview-data"></tbody>
                                        </table>
                                    </div>
                                    <div class="mt-2 text-muted small" id="preview-info"></div>
                                </div>
                            </div>

                            <!-- Import Options -->
                            <div class="col-12 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Import Options</h6>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="skip_duplicates" id="skip_duplicates" value="1" checked>
                                            <label class="form-check-label" for="skip_duplicates">
                                                Skip duplicate customers (based on phone number or ID number)
                                            </label>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="send_welcome_email" id="send_welcome_email" value="1">
                                            <label class="form-check-label" for="send_welcome_email">
                                                Send welcome email to new customers
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div class="col-12 mb-3 d-none" id="progress-section">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">Import Progress</h6>
                                        <div class="progress mb-2">
                                            <div id="import-progress" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                        </div>
                                        <div class="small text-muted" id="progress-text">Preparing import...</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="col-12 text-center">
                                <button type="submit" class="btn btn-primary btn-lg me-3" id="submit-btn">
                                    <i class="bi bi-upload"></i> Start Import
                                </button>
                                <button type="reset" class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Imports -->
            <div class="card mt-4">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> Recent Imports</h6>
                </div>
                <div class="card-body">
                    <?php
                    // Fetch recent import history
                    $stmt = $pdo->query("
                        SELECT * FROM import_logs 
                        WHERE import_type = 'customers' 
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ");
                    $recent_imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if (count($recent_imports) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>File Name</th>
                                        <th>Records</th>
                                        <th>Status</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_imports as $import): ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($import['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($import['file_name']) ?></td>
                                            <td><?= $import['total_records'] ?> records</td>
                                            <td>
                                                <span class="badge bg-<?= $import['status'] === 'completed' ? 'success' : ($import['status'] === 'failed' ? 'danger' : 'warning') ?>">
                                                    <?= ucfirst($import['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($import['imported_by']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i> No recent imports found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<!-- Include SheetJS for Excel file reading -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
$(document).ready(function() {
    // File preview functionality
    $('#import_file').change(function() {
        const file = this.files[0];
        if (!file) return;

        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            $('#form-message').html('<div class="alert alert-danger">File size must be less than 10MB</div>');
            return;
        }

        const fileType = $('#file_type').val();
        const hasHeaders = $('#has_headers').val() === '1';

        if (fileType === 'csv') {
            previewCSV(file, hasHeaders);
        } else if (fileType === 'excel') {
            previewExcel(file, hasHeaders);
        }
    });

    function previewCSV(file, hasHeaders) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const csvData = e.target.result;
            const rows = csvData.split('\n').slice(0, 6); // Show first 6 rows
            
            displayPreview(rows, hasHeaders);
        };
        reader.readAsText(file);
    }

    function previewExcel(file, hasHeaders) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
            
            const rows = jsonData.slice(0, 6); // Show first 6 rows
            displayPreview(rows.map(row => Array.isArray(row) ? row.join(',') : row), hasHeaders);
        };
        reader.readAsArrayBuffer(file);
    }

    function displayPreview(rows, hasHeaders) {
        const $preview = $('#file-preview');
        const $headers = $('#preview-headers');
        const $data = $('#preview-data');
        const $info = $('#preview-info');

        $headers.empty();
        $data.empty();

        if (rows.length === 0) {
            $preview.addClass('d-none');
            return;
        }

        // Parse headers and data
        let headers = [];
        let dataRows = [];

        if (hasHeaders && rows.length > 0) {
            headers = rows[0].split(',');
            dataRows = rows.slice(1, 6); // Show up to 5 data rows
        } else {
            // Generate generic headers
            const firstRow = rows[0].split(',');
            headers = firstRow.map((_, index) => `Column ${index + 1}`);
            dataRows = rows.slice(0, 5); // Show up to 5 data rows
        }

        // Display headers
        $headers.html('<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>');

        // Display data
        dataRows.forEach(row => {
            const cells = row.split(',');
            $data.append('<tr>' + cells.map(cell => `<td>${cell}</td>`).join('') + '</tr>');
        });

        // Show preview info
        $info.text(`Showing ${dataRows.length} of ${rows.length - (hasHeaders ? 1 : 0)} rows`);
        $preview.removeClass('d-none');
    }

    // Form submission
    $('#import-form').on('submit', function(e) {
        e.preventDefault();

        // Clear previous messages
        $('#form-message').html('');

        // Validate file type
        const fileType = $('#file_type').val();
        if (!fileType) {
            $('#form-message').html('<div class="alert alert-danger">Please select file type</div>');
            return;
        }

        // Validate file
        const fileInput = $('#import_file')[0];
        if (!fileInput.files.length) {
            $('#form-message').html('<div class="alert alert-danger">Please select a file to import</div>');
            return;
        }

        // Show progress bar
        $('#progress-section').removeClass('d-none');
        $('#submit-btn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        // Get form data
        const formData = new FormData(this);

        // AJAX request
        $.ajax({
            url: 'api/import_customers.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            contentType: false,
            processData: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                
                // Upload progress
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = (evt.loaded / evt.total) * 100;
                        $('#import-progress').css('width', percentComplete + '%');
                        $('#progress-text').text('Uploading file: ' + Math.round(percentComplete) + '%');
                    }
                }, false);

                // Download progress (processing)
                xhr.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = (evt.loaded / evt.total) * 100;
                        $('#import-progress').css('width', percentComplete + '%');
                        $('#progress-text').text('Processing data: ' + Math.round(percentComplete) + '%');
                    }
                }, false);

                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    $('#form-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    $('#import-progress').css('width', '100%').addClass('bg-success');
                    $('#progress-text').text('Import completed successfully!');
                    
                    // Redirect to customers page after 2 seconds
                    setTimeout(function() {
                        window.location.href = '<?= getUrl('customers') ?>';
                    }, 2000);
                } else {
                    $('#form-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    $('#submit-btn').prop('disabled', false).html('<i class="bi bi-upload"></i> Start Import');
                }
            },
            error: function(xhr, status, error) {
                $('#form-message').html('<div class="alert alert-danger">An error occurred during import. Please try again. (' + error + ')</div>');
                $('#submit-btn').prop('disabled', false).html('<i class="bi bi-upload"></i> Start Import');
            }
        });
    });

    // Reset form
    $('button[type="reset"]').click(function() {
        $('#file-preview').addClass('d-none');
        $('#progress-section').addClass('d-none');
        $('#import-progress').css('width', '0%').removeClass('bg-success');
        $('#form-message').html('');
        $('#submit-btn').prop('disabled', false).html('<i class="bi bi-upload"></i> Start Import');
    });
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
    font-size: 0.875rem;
}

.badge {
    font-size: 0.75em;
}

#preview-table {
    max-height: 300px;
    overflow-y: auto;
}

.progress {
    height: 20px;
}

.progress-bar {
    transition: width 0.3s ease;
}

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
</style>

<?php
// Include the footer
require_once FOOTER_FILE;

// Flush the buffer
ob_end_flush();
?>