<?php
// ajax_get_warehouse.php
require_once __DIR__ . '/roots.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// This endpoint renders the warehouse EDIT form — gate it on the edit verb.
if (!canEdit('warehouses')) {
    http_response_code(403);
    exit('Permission denied');
}

$warehouse_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($warehouse_id <= 0) {
    http_response_code(400);
    exit('Invalid Warehouse ID');
}

// Row-level gate: a warehouse tied to a project outside the caller's scope is
// not theirs to open. Plain-text 403 (this endpoint returns HTML, not JSON).
assertScopeForRecordHtml('warehouses', 'warehouse_id', $warehouse_id);

try {
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
    $stmt->execute([$warehouse_id]);
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch active projects for selection — admins see all; non-admins see only
    // their assigned projects. Mirrors app/bms/stock/warehouses.php.
    if (isAdmin()) {
        $all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
        if (empty($assigned)) {
            $all_projects = [];
        } else {
            $ph = implode(',', array_fill(0, count($assigned), '?'));
            $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name ASC");
            $pstmt->execute($assigned);
            $all_projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!$warehouse) {
        http_response_code(404);
        exit('Warehouse not found');
    }

    // Since address might be combined in the database, we handle it
    // The main app seems to combine them, but the edit form might need individual fields
    // For now, let's output the form fields with the data we have
    ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_warehouse_name" class="form-label">Warehouse Name *</label>
            <input type="text" class="form-control" id="edit_warehouse_name" name="warehouse_name" value="<?= htmlspecialchars($warehouse['warehouse_name']) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_warehouse_code" class="form-label">Warehouse Code <span class="text-muted">(System assigned)</span></label>
            <input type="text" class="form-control bg-light" id="edit_warehouse_code" name="warehouse_code" value="<?= htmlspecialchars($warehouse['warehouse_code']) ?>" readonly required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_project_id" class="form-label">Project (Optional)</label>
            <select class="form-select" id="edit_project_id" name="project_id">
                <option value="">-- No Specific Project --</option>
                <?php foreach ($all_projects as $project): ?>
                    <option value="<?= $project['project_id'] ?>" <?= ($warehouse['project_id'] == $project['project_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($project['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Link this warehouse to a specific project</small>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_address" class="form-label">Address</label>
            <input type="text" class="form-control" id="edit_address" name="address" value="<?= htmlspecialchars($warehouse['address'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_city" class="form-label">City</label>
            <input type="text" class="form-control" id="edit_city" name="city" value="<?= htmlspecialchars($warehouse['city'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="edit_country" class="form-label">Country</label>
            <input type="text" class="form-control" id="edit_country" name="country" value="<?= htmlspecialchars($warehouse['country'] ?? 'Tanzania') ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_state" class="form-label">Region</label>
            <input type="text" class="form-control" id="edit_state" name="state" value="<?= htmlspecialchars($warehouse['state'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_postal_code" class="form-label">Postal Code</label>
            <input type="text" class="form-control" id="edit_postal_code" name="postal_code" value="<?= htmlspecialchars($warehouse['postal_code'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_phone" class="form-label">Phone</label>
            <input type="tel" class="form-control" id="edit_phone" name="phone" value="<?= htmlspecialchars($warehouse['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_email" class="form-label">Email</label>
            <input type="email" class="form-control" id="edit_email" name="email" value="<?= htmlspecialchars($warehouse['email'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="edit_manager_name" class="form-label">Manager Name</label>
            <input type="text" class="form-control" id="edit_manager_name" name="manager_name" value="<?= htmlspecialchars($warehouse['contact_person'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="edit_manager_phone" class="form-label">Manager Phone</label>
            <input type="tel" class="form-control" id="edit_manager_phone" name="manager_phone" value="<?= htmlspecialchars($warehouse['phone'] ?? '') ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4 mb-3">
            <label for="edit_capacity" class="form-label">Capacity (units)</label>
            <input type="number" class="form-control" id="edit_capacity" name="capacity" value="<?= htmlspecialchars($warehouse['capacity'] ?? '') ?>" min="0" step="1">
        </div>
        <div class="col-md-4 mb-3">
            <label for="edit_status" class="form-label">Status</label>
            <select class="form-select" id="edit_status" name="status" required>
                <option value="active" <?= $warehouse['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $warehouse['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="maintenance" <?= $warehouse['status'] == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="edit_is_primary" name="is_primary" <?= $warehouse['is_primary'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="edit_is_primary">
                    Set as Primary Warehouse
                </label>
            </div>
        </div>
    </div>
    
    <div class="mb-3">
        <label for="edit_notes" class="form-label">Notes</label>
        <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?= htmlspecialchars($warehouse['notes'] ?? '') ?></textarea>
    </div>
    <?php
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error');
}
