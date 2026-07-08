<?php
/**
 * core/hr_lookups.php
 * -------------------
 * Find-or-create helpers for the self-growing Department / Designation
 * dropdowns on the employee wizard (Step 2 — Employment).
 *
 * The employee form's Department & Designation selects each carry an
 * "➕ Other (specify)…" option. When chosen, the user types a new name which
 * is submitted as `department_other` / `designation_other`. The server resolves
 * that free text into a real `departments` / `designations` row (reusing an
 * existing row of the same name, case-insensitively) and stores the resulting
 * FK id on the employee. Designations are created UNDER the chosen department.
 *
 * Idempotent by name so re-submitting the same value never duplicates a row.
 * Concept mirrors the customers' renderOtherSelect / form_lookups pattern, but
 * targets the relational HR tables instead of the flat lookup catalogue.
 */

if (!function_exists('findOrCreateDepartment')) {
    /**
     * Resolve a department name to its id, creating the row if new.
     * @return int|null department_id, or null when $name is blank.
     */
    function findOrCreateDepartment(PDO $pdo, ?string $name, ?int $userId = null): ?int
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        // Reuse an existing department of the same name (case-insensitive).
        $sel = $pdo->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(?) LIMIT 1");
        $sel->execute([$name]);
        $existing = $sel->fetchColumn();
        if ($existing) return (int)$existing;

        $ins = $pdo->prepare("INSERT INTO departments (department_name, status, created_by, created_at)
                              VALUES (?, 'active', ?, NOW())");
        $ins->execute([$name, $userId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('findOrCreateDesignation')) {
    /**
     * Resolve a designation name to its id, creating the row if new.
     * A newly-created designation is linked to $departmentId (may be null).
     * Matching is scoped to the same department so the same title under two
     * departments stays distinct.
     * @return int|null designation_id, or null when $name is blank.
     */
    function findOrCreateDesignation(PDO $pdo, ?string $name, ?int $departmentId, ?int $userId = null): ?int
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        if ($departmentId !== null) {
            $sel = $pdo->prepare("SELECT designation_id FROM designations
                                  WHERE LOWER(designation_name) = LOWER(?) AND department_id = ? LIMIT 1");
            $sel->execute([$name, $departmentId]);
        } else {
            $sel = $pdo->prepare("SELECT designation_id FROM designations
                                  WHERE LOWER(designation_name) = LOWER(?) AND department_id IS NULL LIMIT 1");
            $sel->execute([$name]);
        }
        $existing = $sel->fetchColumn();
        if ($existing) return (int)$existing;

        $ins = $pdo->prepare("INSERT INTO designations (designation_name, department_id, status, created_by, created_at)
                              VALUES (?, ?, 'active', ?, NOW())");
        $ins->execute([$name, $departmentId, $userId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('findOrCreateEmploymentType')) {
    /**
     * Resolve an employment-type name to its type_id, creating the row if new.
     * Idempotent + case-insensitive.
     * @return int|null type_id, or null when $name is blank.
     */
    function findOrCreateEmploymentType(PDO $pdo, ?string $name, ?int $userId = null): ?int
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        $sel = $pdo->prepare("SELECT type_id FROM employment_types WHERE LOWER(type_name) = LOWER(?) LIMIT 1");
        $sel->execute([$name]);
        $existing = $sel->fetchColumn();
        if ($existing) return (int)$existing;

        $ins = $pdo->prepare("INSERT INTO employment_types (type_name, status, created_by, created_at)
                              VALUES (?, 'active', ?, NOW())");
        $ins->execute([$name, $userId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('resolveEmployeeDeptDesignation')) {
    /**
     * Normalise the posted department_id / designation_id / employment_type_id
     * so the caller can insert/update with plain ints. Handles the "other"
     * sentinel by creating the row from the *_other text. Mutates $post in place.
     *
     * Rules:
     *  - department_id === 'other'      → create from department_other
     *  - designation_id === 'other'     → create from designation_other, under the
     *    (possibly just-created) department.
     *  - employment_type_id === 'other' → create from employment_type_other
     */
    function resolveEmployeeDeptDesignation(PDO $pdo, array &$post, ?int $userId = null): void
    {
        if (($post['department_id'] ?? '') === 'other') {
            $newId = findOrCreateDepartment($pdo, $post['department_other'] ?? '', $userId);
            if ($newId === null) {
                throw new Exception('Please type the new department name.');
            }
            $post['department_id'] = $newId;
        }

        if (($post['designation_id'] ?? '') === 'other') {
            $deptId = isset($post['department_id']) && $post['department_id'] !== ''
                ? (int)$post['department_id'] : null;
            $newId = findOrCreateDesignation($pdo, $post['designation_other'] ?? '', $deptId, $userId);
            if ($newId === null) {
                throw new Exception('Please type the new designation name.');
            }
            $post['designation_id'] = $newId;
        }

        if (($post['employment_type_id'] ?? '') === 'other') {
            $newId = findOrCreateEmploymentType($pdo, $post['employment_type_other'] ?? '', $userId);
            if ($newId === null) {
                throw new Exception('Please type the new employment type.');
            }
            $post['employment_type_id'] = $newId;
        }
    }
}
