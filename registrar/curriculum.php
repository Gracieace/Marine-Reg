<?php require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']); ?>
<?php
require_once dirname(__DIR__) . '/config/db.php';

// Initialize database connection
$pdo = db_connect();

// Ensure tables exist
try {
    initialize_schema($pdo);
} catch (Exception $e) {
    // Schema initialization may partially fail on some hosts
}

// Initialize messages
$success_message = '';
$error_message = '';

// Helper: attempt to drop UNIQUE indexes on curriculum that block duplicates, then return list dropped
if (!function_exists('dropCurriculumSubjectUniqueIndexes')) {
    function dropCurriculumSubjectUniqueIndexes(PDO $pdo): array
    {
        $dropped = [];
        try {
            $idx = $pdo->query("SHOW INDEX FROM `curriculum`");
            if ($idx) {
                $rows = $idx->fetchAll(PDO::FETCH_ASSOC);
                $seen = [];
                foreach ($rows as $r) {
                    if ((int) ($r['Non_unique'] ?? 1) === 0) {
                        $col = $r['Column_name'] ?? '';
                        if (in_array($col, ['subject_code', 'subject_name', 'grade_level', 'semester', 'program_id'], true)) {
                            $seen[$r['Key_name']] = true;
                        }
                    }
                }
                foreach (array_keys($seen) as $keyName) {
                    if (!empty($keyName)) {
                        $pdo->exec("DROP INDEX `" . str_replace('`', '', $keyName) . "` ON `curriculum`");
                        $dropped[] = $keyName;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $dropped;
    }
}

// Ensure curriculum table has required columns (idempotent)
if (!function_exists('ensureCurriculumColumns')) {
    function ensureCurriculumColumns(PDO $pdo): array
    {
        $added = [];
        try {
            $cols = [];
            $res = $pdo->query("SHOW COLUMNS FROM `curriculum`");
            if ($res) {
                foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!empty($row['Field'])) {
                        $cols[] = $row['Field'];
                    }
                }
            }
            $alterParts = [];
            if (!in_array('grade_level', $cols, true)) {
                $alterParts[] = "ADD COLUMN `grade_level` VARCHAR(50) NULL DEFAULT '' AFTER `subject_name`";
                $added[] = 'grade_level';
            }
            if (!in_array('semester', $cols, true)) {
                $alterParts[] = "ADD COLUMN `semester` VARCHAR(50) NULL DEFAULT '' AFTER `grade_level`";
                $added[] = 'semester';
            }
            if (!in_array('units', $cols, true)) {
                $alterParts[] = "ADD COLUMN `units` INT NULL DEFAULT 0 AFTER `semester`";
                $added[] = 'units';
            }
            if (!in_array('description', $cols, true)) {
                $alterParts[] = "ADD COLUMN `description` TEXT NULL AFTER `units`";
                $added[] = 'description';
            }
            if (!empty($alterParts)) {
                $pdo->exec("ALTER TABLE `curriculum` " . implode(', ', $alterParts));
            }
        } catch (Throwable $e) {
            // ignore; will surface on insert if still missing
        }
        return $added;
    }
}

// Ensure schema allows the same subject to appear in multiple programs by
// dropping global unique indexes and creating a program-scoped unique index
if (!function_exists('ensureProgramSubjectUniqueness')) {
    function ensureProgramSubjectUniqueness(PDO $pdo): array
    {
        $changes = [];
        try {
            $idx_stmt = $pdo->query("SHOW INDEX FROM `curriculum`");
            $indexes = $idx_stmt ? $idx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            // Group columns by index name
            $byKey = [];
            foreach ($indexes as $ix) {
                $key = $ix['Key_name'] ?? '';
                if ($key === '')
                    continue;
                $byKey[$key]['non_unique'] = (int) ($ix['Non_unique'] ?? 1);
                $byKey[$key]['cols'][] = $ix['Column_name'] ?? '';
            }

            // Drop UNIQUE indexes that do not include program_id (exclude PRIMARY)
            foreach ($byKey as $keyName => $meta) {
                if (($meta['non_unique'] ?? 1) === 0) {
                    $cols = $meta['cols'] ?? [];
                    $hasProgramId = in_array('program_id', $cols, true);
                    if (strtoupper($keyName) === 'PRIMARY') {
                        continue;
                    }
                    if (!$hasProgramId) {
                        $pdo->exec("DROP INDEX `" . str_replace('`', '', $keyName) . "` ON `curriculum`");
                        $changes[] = "dropped:" . $keyName;
                    }
                }
            }

            // Ensure program-scoped unique index exists
            $haveProgramScoped = false;
            $idx_stmt2 = $pdo->query("SHOW INDEX FROM `curriculum`");
            $indexes2 = $idx_stmt2 ? $idx_stmt2->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($indexes2 as $ix) {
                if (!empty($ix['Key_name']) && $ix['Key_name'] === 'uniq_program_subject') {
                    $haveProgramScoped = true;
                    break;
                }
            }
            if (!$haveProgramScoped) {
                $pdo->exec("CREATE UNIQUE INDEX `uniq_program_subject` ON `curriculum` (`program_id`,`subject_name`,`grade_level`,`semester`)");
                $changes[] = 'created:uniq_program_subject';
            }
        } catch (Throwable $e) {
            // swallow errors to avoid breaking the request
        }
        return $changes;
    }
}

// Aggressive: remove ALL unique indexes on curriculum (except PRIMARY)
// to allow full repeats regardless of program
if (!function_exists('ensureNoUniqueOnCurriculum')) {
    function ensureNoUniqueOnCurriculum(PDO $pdo): array
    {
        $changes = [];
        try {
            $idx_stmt = $pdo->query("SHOW INDEX FROM `curriculum`");
            $indexes = $idx_stmt ? $idx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $byKey = [];
            foreach ($indexes as $ix) {
                $key = $ix['Key_name'] ?? '';
                if ($key === '' || strtoupper($key) === 'PRIMARY')
                    continue;
                $byKey[$key]['non_unique'] = (int) ($ix['Non_unique'] ?? 1);
            }
            foreach ($byKey as $keyName => $meta) {
                if (($meta['non_unique'] ?? 1) === 0) {
                    $pdo->exec("DROP INDEX `" . str_replace('`', '', $keyName) . "` ON `curriculum`");
                    $changes[] = "dropped:" . $keyName;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $changes;
    }
}

// Optional one-time maintenance: drop unique indexes that block duplicate subjects
if (isset($_GET['drop_subject_unique']) && $_GET['drop_subject_unique'] == '1') {
    try {
        $idx_stmt = $pdo->query("SHOW INDEX FROM `curriculum`");
        $indexes = $idx_stmt ? $idx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $keys_to_drop = [];
        foreach ($indexes as $ix) {
            // Drop UNIQUE indexes that include subject-related columns
            if (isset($ix['Non_unique']) && (int) $ix['Non_unique'] === 0) {
                $col = $ix['Column_name'] ?? '';
                if (in_array($col, ['subject_code', 'grade_level', 'semester', 'program_id'], true)) {
                    $keys_to_drop[] = $ix['Key_name'];
                }
            }
        }
        $keys_to_drop = array_unique($keys_to_drop);
        foreach ($keys_to_drop as $keyName) {
            if (!empty($keyName)) {
                $pdo->exec("DROP INDEX `" . str_replace('`', '', $keyName) . "` ON `curriculum`");
            }
        }
        $success_message = empty($keys_to_drop)
            ? "No matching unique indexes found on 'curriculum'."
            : "Dropped unique index(es): " . implode(', ', $keys_to_drop) . ".";
    } catch (PDOException $e) {
        $error_message = "Failed to drop unique index: " . $e->getMessage();
    }
    $active_tab = 'subjects';
}

// Optional: recreate UNIQUE indexes via URL for convenience
// 1) Per-program uniqueness (recommended): ?create_program_unique=1
// 2) Global uniqueness (legacy): ?create_global_unique=1
try {
    if (isset($_GET['create_program_unique']) && $_GET['create_program_unique'] == '1') {
        $have = false;
        $idx_stmt = $pdo->query("SHOW INDEX FROM `curriculum`");
        $indexes = $idx_stmt ? $idx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($indexes as $ix) {
            if (!empty($ix['Key_name']) && $ix['Key_name'] === 'uniq_program_subject') {
                $have = true;
                break;
            }
        }
        if (!$have) {
            $pdo->exec("CREATE UNIQUE INDEX `uniq_program_subject` ON `curriculum` (`program_id`,`subject_name`,`grade_level`,`semester`)");
            $success_message = "Created unique index uniq_program_subject.";
        } else {
            $success_message = "Index uniq_program_subject already exists.";
        }
        $active_tab = 'subjects';
    }
    if (isset($_GET['create_global_unique']) && $_GET['create_global_unique'] == '1') {
        $have = false;
        $idx_stmt = $pdo->query("SHOW INDEX FROM `curriculum`");
        $indexes = $idx_stmt ? $idx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($indexes as $ix) {
            if (!empty($ix['Key_name']) && $ix['Key_name'] === 'uniq_subject_grade') {
                $have = true;
                break;
            }
        }
        if (!$have) {
            $pdo->exec("CREATE UNIQUE INDEX `uniq_subject_grade` ON `curriculum` (`subject_name`,`grade_level`,`semester`)");
            $success_message = "Created unique index uniq_subject_grade.";
        } else {
            $success_message = "Index uniq_subject_grade already exists.";
        }
        $active_tab = 'subjects';
    }
} catch (PDOException $e) {
    $error_message = "Failed to create unique index: " . $e->getMessage();
    $active_tab = 'subjects';
}

// Get active tab from URL parameter
$active_tab = $_GET['tab'] ?? 'programs';

// Pre-read action to avoid undefined warnings
$action = $_POST['action'] ?? null;

// Read search/filter params BEFORE building queries
$program_search = $_GET['program_search'] ?? '';
$program_type_filter = $_GET['program_type_filter'] ?? '';
$subject_search = $_GET['subject_search'] ?? '';
$show_add_form = isset($_GET['show_add_form']) && $_GET['show_add_form'] == '1';
// Ensure program_filter exists before template uses it
$program_filter = (isset($_GET['program_filter']) && is_numeric($_GET['program_filter'])) ? (int) $_GET['program_filter'] : null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            error_log("ACTION RECEIVED: " . $action);

            // Handle Programs
            if ($action === 'add_program') {
                $program_code = $_POST['program_code'] ?? '';
                $program_name = $_POST['program_name'] ?? '';
                $program_type = $_POST['program_type'] ?? '';
                $grade_levels = $_POST['grade_levels'] ?? '';
                if (is_array($grade_levels)) {
                    $grade_levels = implode(',', array_map('trim', $grade_levels));
                }
                $duration_years = $_POST['duration_years'] ?? 1.0;
                $total_units = $_POST['total_units'] ?? 0.0;
                $description = $_POST['description'] ?? '';
                $program_semester = $_POST['program_semester'] ?? null;
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if (!empty($program_code) && !empty($program_name) && !empty($program_type) && !empty($grade_levels)) {
                    // Prevent duplicate program_code (unique)
                    $dup = $pdo->prepare("SELECT id FROM curriculum_programs WHERE program_code = ?");
                    $dup->execute([$program_code]);
                    if ($dup->fetch()) {
                        $error_message = "Program code '$program_code' already exists.";
                        $active_tab = 'programs';
                    } else {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO curriculum_programs (program_code, program_name, program_type, grade_levels, program_semester, duration_years, total_units, description, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$program_code, $program_name, $program_type, $grade_levels, $program_semester, $duration_years, $total_units, $description, $is_active]);
                            $success_message = "Curriculum program added successfully!";
                            $active_tab = 'programs';
                        } catch (PDOException $e) {
                            $error_message = "Unable to add program. Program code must be unique.";
                            $active_tab = 'programs';
                        }
                    }
                } else {
                    // Suppress generic required-fields error notice for add program
                    $active_tab = 'programs';
                }
            } elseif ($action === 'edit_program') {
                $id = $_POST['id'] ?? 0;
                $program_code = $_POST['program_code'] ?? '';
                $program_name = $_POST['program_name'] ?? '';
                $program_type = $_POST['program_type'] ?? '';
                $grade_levels = $_POST['grade_levels'] ?? '';
                if (is_array($grade_levels)) {
                    $grade_levels = implode(',', array_map('trim', $grade_levels));
                }
                $duration_years = $_POST['duration_years'] ?? 1.0;
                $total_units = $_POST['total_units'] ?? 0.0;
                $description = $_POST['description'] ?? '';
                $program_semester = $_POST['program_semester'] ?? null;
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if ($id > 0 && !empty($program_code) && !empty($program_name) && !empty($program_type) && !empty($grade_levels)) {
                    // Ensure program_code is unique (excluding current)
                    $dup = $pdo->prepare("SELECT id FROM curriculum_programs WHERE program_code = ? AND id != ?");
                    $dup->execute([$program_code, $id]);
                    if ($dup->fetch()) {
                        $error_message = "Program code '$program_code' already exists.";
                        $active_tab = 'programs';
                    } else {
                        try {
                            $stmt = $pdo->prepare("UPDATE curriculum_programs SET program_code = ?, program_name = ?, program_type = ?, grade_levels = ?, program_semester = ?, duration_years = ?, total_units = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                            $stmt->execute([$program_code, $program_name, $program_type, $grade_levels, $program_semester, $duration_years, $total_units, $description, $is_active, $id]);
                            // Redirect to clear edit state and show add view
                            header('Location: ?tab=programs');
                            exit;
                        } catch (PDOException $e) {
                            $error_message = "Unable to update program. Program code must be unique.";
                            $active_tab = 'programs';
                        }
                    }
                } else {
                    $active_tab = 'programs';
                }
            } elseif ($action === 'delete_program') {
                $id = $_POST['id'] ?? 0;
                if ($id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM curriculum_programs WHERE id = ?");
                    $stmt->execute([$id]);
                    $success_message = "Curriculum program deleted successfully!";
                    $active_tab = 'programs';
                }
            }

            // Handle Subjects
            if ($action === 'add_subject') {
                $program_id = $_POST['program_id'] ?? null;
                $subject_name = $_POST['subject_name'] ?? '';
                $description = $_POST['description'] ?? '';
                $manual_grade_level = $_POST['manual_grade_level'] ?? '';
                $manual_semester = $_POST['manual_semester'] ?? '';

                if (!empty($subject_name)) {
                    // Add subject to selected program (allow repeats)
                    if ($program_id) {
                        // Derive grade level and semester from selected program
                        $prog = null;
                        $stmt = $pdo->prepare("SELECT program_type, grade_levels, program_semester FROM curriculum_programs WHERE id = ?");
                        $stmt->execute([$program_id]);
                        $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                        $grade_level = $prog['grade_levels'] ?? '';
                        $semester = ($prog && ($prog['program_type'] === 'Senior High School')) ? ($prog['program_semester'] ?? '') : '';
                        if (empty($grade_level) && !empty($manual_grade_level)) {
                            $grade_level = $manual_grade_level;
                            $semester = $manual_semester; // may be empty
                        }
                        $units = 0;

                        // Insert directly (allow repeats). Before insert, try to drop blocking unique indexes once.
                        try {
                            // Drop all unique indexes that might block duplicates
                            ensureCurriculumColumns($pdo);
                            $changes = ensureNoUniqueOnCurriculum($pdo);
                            $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            $ins->execute([$program_id, $subject_name, $grade_level, $semester, $units, $description]);
                            $success_message = "Curriculum subject added successfully!";
                            if (!empty($changes)) {
                                $success_message .= " (Schema adjusted: " . implode(', ', $changes) . ")";
                            }
                        } catch (PDOException $e) {
                            // Retry once after ensuring unique indexes are removed
                            try {
                                ensureCurriculumColumns($pdo);
                                ensureNoUniqueOnCurriculum($pdo);
                                $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                $ins->execute([$program_id, $subject_name, $grade_level, $semester, $units, $description]);
                                $success_message = "Curriculum subject added successfully!";
                            } catch (PDOException $e2) {
                                $error_message = "Unable to add subject: " . $e2->getMessage();
                            }
                        }
                        $active_tab = 'subjects';
                    } else {
                        // Add subject without program association
                        try {
                            ensureCurriculumColumns($pdo);
                            $changes = ensureNoUniqueOnCurriculum($pdo);
                            $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            $ins->execute([null, $subject_name, '', '', 0, $description]);
                            $success_message = "Curriculum subject added successfully!";
                            if (!empty($changes)) {
                                $success_message .= " (Schema adjusted: " . implode(', ', $changes) . ")";
                            }
                        } catch (PDOException $e) {
                            try {
                                ensureCurriculumColumns($pdo);
                                ensureNoUniqueOnCurriculum($pdo);
                                $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                $ins->execute([null, $subject_name, '', '', 0, $description]);
                                $success_message = "Curriculum subject added successfully!";
                            } catch (PDOException $e2) {
                                $error_message = "Unable to add subject: " . $e2->getMessage();
                            }
                        }
                        $active_tab = 'subjects';
                    }
                } else {
                    $active_tab = 'subjects';
                }
            } elseif ($action === 'edit_subject') {
                $id = $_POST['id'] ?? 0;
                $program_id = $_POST['program_id'] ?? null;
                $subject_name = $_POST['subject_name'] ?? '';
                $description = $_POST['description'] ?? '';

                if ($id > 0 && !empty($subject_name)) {
                    // Check duplicate by (subject_code, grade_level, semester)
                    if ($program_id) {
                        // Derive grade level and semester from selected program
                        $stmt = $pdo->prepare("SELECT program_type, grade_levels, program_semester FROM curriculum_programs WHERE id = ?");
                        $stmt->execute([$program_id]);
                        $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                        $grade_level = $prog['grade_levels'] ?? '';
                        $semester = ($prog && ($prog['program_type'] === 'Senior High School')) ? ($prog['program_semester'] ?? '') : '';
                        $units = 0;

                        try {
                            $upd = $pdo->prepare("UPDATE curriculum SET program_id = ?, subject_name = ?, grade_level = ?, semester = ?, units = ?, description = ?, updated_at = NOW() WHERE id = ?");
                            $upd->execute([$program_id, $subject_name, $grade_level, $semester, $units, $description, $id]);
                            // Redirect to clear edit state and show subjects tab
                            header('Location: ?tab=subjects');
                            exit;
                        } catch (PDOException $e) {
                            $error_message = "Unable to update subject. Database constraint blocked duplicates. Remove the unique index to allow repeats.";
                        }
                        $active_tab = 'subjects';
                    }
                } else {
                    // Update subject without program association
                    try {
                        $upd = $pdo->prepare("UPDATE curriculum SET program_id = ?, subject_name = ?, grade_level = ?, semester = ?, units = ?, description = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute([null, $subject_name, '', '', 0, $description, $id]);
                        // Redirect to clear edit state and show subjects tab
                        header('Location: ?tab=subjects');
                        exit;
                    } catch (PDOException $e) {
                        $error_message = "Unable to update subject. It may already exist.";
                    }
                }
            } else {
                $active_tab = 'subjects';
            }
        } elseif ($action === 'remove_subject') {
            $id = $_POST['id'] ?? 0;
            $subject_name = $_POST['subject_name'] ?? '';

            // Debug: Log the deletion attempt
            error_log("DELETE ATTEMPT: ID=$id, Subject=$subject_name");

            if ($id > 0) {
                try {
                    // First, let's check if the subject exists
                    $check_stmt = $pdo->prepare("SELECT id, subject_name FROM curriculum WHERE id = ?");
                    $check_stmt->execute([$id]);
                    $subject_to_delete = $check_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($subject_to_delete) {
                        // Completely delete the subject from the database
                        $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id = ?");
                        $result = $stmt->execute([$id]);

                        if ($stmt->rowCount() > 0) {
                            $success_message = "Subject '{$subject_to_delete['subject_name']}' deleted successfully!";
                            error_log("DELETE SUCCESS: Subject '{$subject_to_delete['subject_name']}' deleted");
                        } else {
                            $error_message = "Subject not found or already deleted.";
                            error_log("DELETE FAILED: No rows affected for ID $id");
                        }
                    } else {
                        $error_message = "Subject with ID $id not found.";
                        error_log("DELETE FAILED: Subject with ID $id not found");
                    }
                } catch (PDOException $e) {
                    $error_message = "Error deleting subject: " . $e->getMessage();
                    error_log("DELETE ERROR: " . $e->getMessage());
                }

                // Preserve current program filter if provided (fallback to GET if needed)
                $pf = isset($_POST['program_filter']) ? (int) $_POST['program_filter'] : 0;
                if ($pf <= 0 && isset($_GET['program_filter']) && is_numeric($_GET['program_filter'])) {
                    $pf = (int) $_GET['program_filter'];
                }
                // Always redirect to refresh the list with cache-busting parameter
                $timestamp = time();
                if ($pf > 0) {
                    header('Location: ?tab=subjects&program_filter=' . $pf . '&t=' . $timestamp);
                } else {
                    header('Location: ?tab=subjects&t=' . $timestamp);
                }
                exit;
            } else {
                $error_message = "Invalid subject ID provided.";
                // If no valid ID, redirect without error message
                $pf = isset($_POST['program_filter']) ? (int) $_POST['program_filter'] : 0;
                $timestamp = time();
                if ($pf > 0) {
                    header('Location: ?tab=subjects&program_filter=' . $pf . '&t=' . $timestamp);
                } else {
                    header('Location: ?tab=subjects&t=' . $timestamp);
                }
                exit;
            }
        } elseif ($action === 'add_multiple_subjects') {
            $program_id = $_POST['program_id'] ?? '';
            $subjects_data = $_POST['subjects_data'] ?? '';
            // Optional manual grade/semester fallbacks from modal form
            $manual_grade_level = $_POST['manual_grade_level'] ?? '';
            $manual_semester = $_POST['manual_semester'] ?? '';

            if (!empty($program_id) && !empty($subjects_data)) {
                $subjects = json_decode($subjects_data, true);

                if (is_array($subjects) && count($subjects) > 0) {
                    // Get program details
                    $stmt = $pdo->prepare("SELECT program_type, grade_levels, program_semester FROM curriculum_programs WHERE id = ?");
                    $stmt->execute([$program_id]);
                    $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                    $grade_level = $prog['grade_levels'] ?? '';
                    $semester = ($prog && ($prog['program_type'] === 'Senior High School')) ? ($prog['program_semester'] ?? '') : '';
                    if (empty($grade_level) && !empty($manual_grade_level)) {
                        $grade_level = $manual_grade_level;
                        $semester = $manual_semester; // may be empty
                    }
                    $units = 0;

                    if (!empty($grade_level)) {
                        $success_count = 0;
                        $error_count = 0;

                        foreach ($subjects as $subject) {
                            try {
                                ensureCurriculumColumns($pdo);
                                ensureNoUniqueOnCurriculum($pdo);
                                $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                $ins->execute([$program_id, $subject['name'], $grade_level, $semester, $units, $subject['description']]);
                                $success_count++;
                            } catch (PDOException $e) {
                                try {
                                    ensureNoUniqueOnCurriculum($pdo);
                                    $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                    $ins->execute([$program_id, $subject['name'], $grade_level, $semester, $units, $subject['description']]);
                                    $success_count++;
                                } catch (PDOException $e2) {
                                    $error_count++;
                                }
                            }
                        }

                        if ($success_count > 0) {
                            $success_message = "Successfully added {$success_count} subjects to the program!";
                            if ($error_count > 0) {
                                $success_message .= " {$error_count} subjects had errors during insert.";
                            }
                        } else {
                            $error_message = "No subjects were added.";
                        }
                    } else {
                        $error_message = "Selected program has no grade level configured.";
                    }
                } else {
                    $error_message = "No valid subjects data received.";
                }
                $active_tab = 'subjects';
            } else {
                $error_message = "Missing program ID or subjects data.";
                $active_tab = 'subjects';
            }
        }

        // Get all curriculum programs with search and filter
        $programs_where_conditions = [];
        $programs_params = [];

        if (!empty($program_search)) {
            $programs_where_conditions[] = "(program_name LIKE ? OR program_code LIKE ? OR description LIKE ?)";
            $search_term = "%$program_search%";
            $programs_params[] = $search_term;
            $programs_params[] = $search_term;
            $programs_params[] = $search_term;
        }

        if (!empty($program_type_filter)) {
            $programs_where_conditions[] = "program_type = ?";
            $programs_params[] = $program_type_filter;
        }

        $programs_query = "SELECT * FROM curriculum_programs";
        if (!empty($programs_where_conditions)) {
            $programs_query .= " WHERE " . implode(" AND ", $programs_where_conditions);
        }
        $programs_query .= " ORDER BY program_type, grade_levels, program_code";

        if (!empty($programs_params)) {
            $programs_stmt = $pdo->prepare($programs_query);
            $programs_stmt->execute($programs_params);
            $curriculum_programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $programs_result = $pdo->query($programs_query);
            $curriculum_programs = $programs_result->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get all curriculum subjects with program information
        $program_filter = null;
        if (isset($_GET['program_filter']) && is_numeric($_GET['program_filter'])) {
            $program_filter = (int) $_GET['program_filter'];
        }

        // Build subjects query with search and filter
        $subjects_where_conditions = [];
        $subjects_params = [];

        if ($program_filter) {
            $subjects_where_conditions[] = "c.program_id = ?";
            $subjects_params[] = $program_filter;
        }


        if (!empty($subject_search)) {
            $subjects_where_conditions[] = "(c.subject_name LIKE ? OR c.description LIKE ?)";
            $search_term = "%$subject_search%";
            $subjects_params[] = $search_term;
            $subjects_params[] = $search_term;
        }

        $curriculum_query = "SELECT c.*, cp.program_name, cp.program_code 
                     FROM curriculum c 
                     LEFT JOIN curriculum_programs cp ON c.program_id = cp.id";

        if (!empty($subjects_where_conditions)) {
            $curriculum_query .= " WHERE " . implode(" AND ", $subjects_where_conditions);
        }

        $curriculum_query .= " ORDER BY cp.program_name, c.subject_name ASC";

        if (!empty($subjects_params)) {
            $curriculum_stmt = $pdo->prepare($curriculum_query);
            $curriculum_stmt->execute($subjects_params);
            $curriculum_subjects = $curriculum_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $curriculum_result = $pdo->query($curriculum_query);
            $curriculum_subjects = $curriculum_result->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get active curriculum programs for the dropdown
        $active_programs_query = "SELECT * FROM curriculum_programs WHERE is_active = 1 ORDER BY program_type, program_name";
        $active_programs_result = $pdo->query($active_programs_query);
        $active_curriculum_programs = $active_programs_result->fetchAll(PDO::FETCH_ASSOC);

        // Try to read existing subjects for auto-population (optional – tolerant of schema differences)
        $db_subjects = [];
        try {
            $existing_subjects_query = "SELECT DISTINCT subject_name, description FROM curriculum ORDER BY subject_name";
            $existing_subjects_result = $pdo->query($existing_subjects_query);
            $existing_subjects = $existing_subjects_result->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existing_subjects as $subject) {
                if (!isset($subject['subject_name']))
                    continue;
                $db_subjects[$subject['subject_name']] = [
                    'description' => $subject['description'] ?? ''
                ];
            }
        } catch (PDOException $e) {
            // Schema may not have subject_code/description – fallback to predefined list only
            $db_subjects = [];
        }

        // Predefined subjects database for auto-population
        $predefined_subjects = [
            'Mathematics' => [
                'code' => 'MATH101',
                'description' => 'Basic mathematical concepts and problem-solving techniques'
            ],
            'English' => [
                'code' => 'ENG101',
                'description' => 'English language and literature fundamentals'
            ],
            'Science' => [
                'code' => 'SCI101',
                'description' => 'General science principles and laboratory work'
            ],
            'Physical Education' => [
                'code' => 'PE101',
                'description' => 'Physical fitness, sports, and health education'
            ],
            'History' => [
                'code' => 'HIST101',
                'description' => 'World history and historical analysis'
            ],
            'Geography' => [
                'code' => 'GEO101',
                'description' => 'Physical and human geography studies'
            ],
            'Biology' => [
                'code' => 'BIO101',
                'description' => 'Life sciences and biological processes'
            ],
            'Chemistry' => [
                'code' => 'CHEM101',
                'description' => 'Chemical principles and laboratory experiments'
            ],
            'Physics' => [
                'code' => 'PHYS101',
                'description' => 'Physical laws and scientific principles'
            ],
            'Computer Science' => [
                'code' => 'CS101',
                'description' => 'Introduction to computer programming and technology'
            ],
            'Economics' => [
                'code' => 'ECON101',
                'description' => 'Economic principles and market analysis'
            ],
            'Psychology' => [
                'code' => 'PSY101',
                'description' => 'Human behavior and mental processes'
            ],
            'Sociology' => [
                'code' => 'SOC101',
                'description' => 'Social behavior and society studies'
            ],
            'Philosophy' => [
                'code' => 'PHIL101',
                'description' => 'Critical thinking and ethical reasoning'
            ],
            'Art' => [
                'code' => 'ART101',
                'description' => 'Visual arts and creative expression'
            ],
            'Music' => [
                'code' => 'MUS101',
                'description' => 'Musical theory and performance'
            ],
            'Literature' => [
                'code' => 'LIT101',
                'description' => 'Literary analysis and creative writing'
            ],
            'Statistics' => [
                'code' => 'STAT101',
                'description' => 'Data analysis and statistical methods'
            ],
            'Environmental Science' => [
                'code' => 'ENV101',
                'description' => 'Environmental studies and sustainability'
            ],
            'Political Science' => [
                'code' => 'POL101',
                'description' => 'Government systems and political theory'
            ]
        ];

        // Handle search and filter parameters
// Moved earlier to avoid undefined variable notices

        // Get edit data if editing
        $edit_program_data = null;
        $edit_subject_data = null;

        if (isset($_GET['edit_program']) && is_numeric($_GET['edit_program'])) {
            $edit_id = $_GET['edit_program'];
            $stmt = $pdo->prepare("SELECT * FROM curriculum_programs WHERE id = ?");
            $stmt->execute([$edit_id]);
            $edit_program_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $active_tab = 'programs';
        }

        if (isset($_GET['edit_subject']) && is_numeric($_GET['edit_subject'])) {
            $edit_id = $_GET['edit_subject'];
            $stmt = $pdo->prepare("SELECT * FROM curriculum WHERE id = ?");
            $stmt->execute([$edit_id]);
            $edit_subject_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $active_tab = 'subjects';
        }
    } catch (PDOException $e) {
        $error_message = "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum & Programs Management - Registrar</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        .content {
            margin-left: 0;
            padding: 20px;
            background: #f4f7fb;
            min-height: 100vh;
        }

        .page-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .page-title {
            margin: 0;
            color: #0f2a44;
            font-size: 24px;
            font-weight: bold;
        }

        .tab-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .tab-nav {
            display: flex;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        .tab-nav button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .tab-nav button.active {
            background: white;
            color: #0f2a44;
            border-bottom: 3px solid #0f2a44;
        }

        .tab-nav button:hover:not(.active) {
            background: #f3f4f6;
            color: #374151;
        }

        .tab-content {
            display: none;
            padding: 20px;
        }

        .tab-content.active {
            display: block;
        }

        .form-container {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }

        .form-title {
            margin: 0 0 20px 0;
            color: #0f2a44;
            font-size: 18px;
            font-weight: bold;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .form-group.program-code,
        .form-group.subject-code {
            flex: 0 0 150px;
            max-width: 200px;
            min-width: 150px;
        }

        .form-group.program-name,
        .form-group.subject-name {
            flex: 1;
            min-width: 250px;
            max-width: calc(100% - 165px);
        }

        .form-group.duration,
        .form-group.units {
            flex: 0 0 120px;
            max-width: 150px;
            min-width: 120px;
        }

        /* Responsive design for smaller screens */
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 10px;
            }

            .form-group {
                min-width: 100%;
            }

            .form-group.program-code,
            .form-group.subject-code,
            .form-group.duration,
            .form-group.units {
                flex: 1;
                max-width: 100%;
            }
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #374151;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            max-width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group select:disabled {
            background-color: #f9fafb;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .form-group textarea {
            height: 80px;
            resize: vertical;
        }

        .form-group input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 25px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: #0f2a44;
            color: white;
        }

        .btn-primary:hover {
            background: #1e3a5f;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-outline {
            background: transparent;
            color: #6b7280;
            border: 1px solid #6b7280;
        }

        .btn-outline:hover {
            background: #6b7280;
            color: white;
        }

        .btn-info {
            background: #0ea5e9;
            color: white;
        }

        .btn-info:hover {
            background: #0284c7;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .table th {
            background: #f9fafb;
            font-weight: bold;
            color: #374151;
        }

        .table tbody tr:hover {
            background: #f9fafb;
        }

        /* Presentable column widths and truncation */
        .table thead th:nth-child(1),
        .table tbody td:nth-child(1) {
            width: 35%;
            font-weight: 600;
            color: #0f2a44;
        }

        .table thead th:nth-child(2),
        .table tbody td:nth-child(2) {
            width: 50%;
            max-width: 0;
            /* enables ellipsis with table layout */
        }

        .table tbody td:nth-child(2) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #4b5563;
        }

        .table thead th:nth-child(3),
        .table tbody td:nth-child(3) {
            width: 15%;
            white-space: nowrap;
        }

        .table .btn-group {
            justify-content: flex-end;
        }

        /* Responsive tweaks */
        @media (max-width: 768px) {

            .table thead th:nth-child(1),
            .table tbody td:nth-child(1) {
                width: 45%;
            }

            .table thead th:nth-child(2),
            .table tbody td:nth-child(2) {
                width: 40%;
            }

            .table thead th:nth-child(3),
            .table tbody td:nth-child(3) {
                width: 15%;
            }
        }

        /* Column width controls for subjects table */
        /* removed subject code column */

        .table th:nth-child(2),
        .table td:nth-child(2) {
            width: 25%;
            /* Subject Name */
        }

        .table th:nth-child(3),
        .table td:nth-child(3) {
            width: 45%;
            /* Description - larger than Subject Name */
        }

        .table th:nth-child(4),
        .table td:nth-child(4) {
            width: 15%;
            /* Actions */
        }

        .status-active {
            color: #059669;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc2626;
            font-weight: bold;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .btn-group {
            display: flex;
            gap: 5px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            background: #0f2a44;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 20px;
        }

        .subject-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: end;
        }

        .subject-input-group .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .subject-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
            background: #f9fafb;
        }

        .subject-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        .subject-item:last-child {
            margin-bottom: 0;
        }

        .subject-info {
            flex: 1;
        }

        .subject-code {
            font-weight: bold;
            color: #0f2a44;
        }

        .subject-name {
            color: #6b7280;
            font-size: 14px;
        }

        .remove-subject {
            background: #dc2626;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .remove-subject:hover {
            background: #b91c1c;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../header.php'; ?>
    <?php include __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">Curriculum & Programs Management</h1>
            <p style="margin: 10px 0 0 0; color: #666; font-size: 14px;">
                Manage curriculum programs and subjects in one place.
            </p>
        </div>

        <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
        <?php endif; ?>

        <div class="tab-container">
            <div class="tab-nav">
                <button class="tab-btn <?php echo $active_tab === 'programs' ? 'active' : ''; ?>"
                    onclick="switchTab('programs')">
                    📚 Programs
                </button>
                <button class="tab-btn <?php echo $active_tab === 'subjects' ? 'active' : ''; ?>"
                    onclick="switchTab('subjects')">
                    📖 Subjects
                </button>
            </div>

            <!-- Programs Tab -->
            <div id="programs-tab" class="tab-content <?php echo $active_tab === 'programs' ? 'active' : ''; ?>">
                <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="toggleForm('programs-form')">
                        <?php echo $edit_program_data ? 'Edit Program' : '+ Add New Program'; ?>
                    </button>

                    <!-- Programs Search and Filter -->
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="tab" value="programs">
                            <input type="text" name="program_search" placeholder="Search programs..."
                                value="<?php echo htmlspecialchars($program_search); ?>"
                                style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
                            <select name="program_type_filter"
                                style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Types</option>
                                <option value="Grade School" <?php echo $program_type_filter === 'Grade School' ? 'selected' : ''; ?>>Grade School</option>
                                <option value="Basic Education" <?php echo $program_type_filter === 'Basic Education' ? 'selected' : ''; ?>>Basic Education</option>
                                <option value="Senior High School" <?php echo $program_type_filter === 'Senior High School' ? 'selected' : ''; ?>>Senior High
                                    School</option>
                                <option value="Special Program" <?php echo $program_type_filter === 'Special Program' ? 'selected' : ''; ?>>Special Program</option>
                                <option value="Alternative Learning" <?php echo $program_type_filter === 'Alternative Learning' ? 'selected' : ''; ?>>Alternative
                                    Learning</option>
                            </select>
                            <button type="submit" class="btn btn-secondary">Search</button>
                            <?php if (!empty($program_search) || !empty($program_type_filter)): ?>
                                    <a href="?tab=programs" class="btn btn-outline">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div id="programs-form" class="form-container"
                    style="display: <?php echo $edit_program_data ? 'block' : 'none'; ?>;">
                    <h2 class="form-title">
                        <?php echo $edit_program_data ? 'Edit Curriculum Program' : 'Add New Curriculum Program'; ?>
                    </h2>
                    <form method="POST">
                        <input type="hidden" name="action"
                            value="<?php echo $edit_program_data ? 'edit_program' : 'add_program'; ?>">
                        <?php if ($edit_program_data): ?>
                                <input type="hidden" name="id"
                                    value="<?php echo htmlspecialchars($edit_program_data['id']); ?>">
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group program-code">
                                <label for="program_code">Program Code *</label>
                                <input type="text" id="program_code" name="program_code"
                                    value="<?php echo htmlspecialchars($edit_program_data['program_code'] ?? ''); ?>"
                                    placeholder="e.g., BED-2024" required>
                            </div>
                            <div class="form-group program-name">
                                <label for="program_name">Program Name *</label>
                                <input type="text" id="program_name" name="program_name"
                                    value="<?php echo htmlspecialchars($edit_program_data['program_name'] ?? ''); ?>"
                                    placeholder="e.g., Basic Education Program" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="program_type">Program Type *</label>
                                <select id="program_type" name="program_type" required>
                                    <option value="">Select Program Type</option>
                                    <option value="Grade School" <?php echo ($edit_program_data['program_type'] ?? '') === 'Grade School' ? 'selected' : ''; ?>>Grade School</option>
                                    <option value="Basic Education" <?php echo ($edit_program_data['program_type'] ?? '') === 'Basic Education' ? 'selected' : ''; ?>>Junior High School</option>
                                    <option value="Senior High School" <?php echo ($edit_program_data['program_type'] ?? '') === 'Senior High School' ? 'selected' : ''; ?>>Senior High School
                                    </option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="grade_levels">Grade Level *</label>
                                <?php $gl_value = trim($edit_program_data['grade_levels'] ?? ''); ?>
                                <select id="grade_levels" name="grade_levels" required>
                                    <option value="">Select Grade Level</option>
                                    <option value="Grade 1" <?php echo $gl_value === 'Grade 1' ? 'selected' : ''; ?>
                                        >Grade
                                        1</option>
                                    <option value="Grade 2" <?php echo $gl_value === 'Grade 2' ? 'selected' : ''; ?>
                                        >Grade
                                        2</option>
                                    <option value="Grade 3" <?php echo $gl_value === 'Grade 3' ? 'selected' : ''; ?>
                                        >Grade
                                        3</option>
                                    <option value="Grade 4" <?php echo $gl_value === 'Grade 4' ? 'selected' : ''; ?>
                                        >Grade
                                        4</option>
                                    <option value="Grade 5" <?php echo $gl_value === 'Grade 5' ? 'selected' : ''; ?>
                                        >Grade
                                        5</option>
                                    <option value="Grade 6" <?php echo $gl_value === 'Grade 6' ? 'selected' : ''; ?>
                                        >Grade
                                        6</option>
                                    <option value="Grade 7" <?php echo $gl_value === 'Grade 7' ? 'selected' : ''; ?>
                                        >Grade
                                        7</option>
                                    <option value="Grade 8" <?php echo $gl_value === 'Grade 8' ? 'selected' : ''; ?>
                                        >Grade
                                        8</option>
                                    <option value="Grade 9" <?php echo $gl_value === 'Grade 9' ? 'selected' : ''; ?>
                                        >Grade
                                        9</option>
                                    <option value="Grade 10" <?php echo $gl_value === 'Grade 10' ? 'selected' : ''; ?>>
                                        Grade 10</option>
                                    <option value="Grade 11" <?php echo $gl_value === 'Grade 11' ? 'selected' : ''; ?>>
                                        Grade 11</option>
                                    <option value="Grade 12" <?php echo $gl_value === 'Grade 12' ? 'selected' : ''; ?>>
                                        Grade 12</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="program_semester">Semester</label>
                                <select id="program_semester" name="program_semester" disabled>
                                    <option value="">Select Semester</option>
                                    <option value="1st Semester" <?php echo (($edit_program_data['program_semester'] ?? '') === '1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2nd Semester" <?php echo (($edit_program_data['program_semester'] ?? '') === '2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                            </div>
                        </div>



                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description"
                                placeholder="Enter program description..."><?php echo htmlspecialchars($edit_program_data['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="is_active" name="is_active" <?php echo ($edit_program_data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="is_active">Active Program</label>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $edit_program_data ? 'Update Program' : 'Add Program'; ?>
                            </button>
                            <?php if ($edit_program_data): ?>
                                    <a href="?tab=programs" class="btn btn-secondary">Cancel</a>
                            <?php else: ?>
                                    <button type="button" class="btn btn-secondary"
                                        onclick="toggleForm('programs-form')">Cancel</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Program Code</th>
                                <th>Program Name</th>
                                <th>Program Type</th>
                                <th>Grade Levels</th>
                                <th>Semester</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($curriculum_programs)): ?>
                                    <tr>
                                        <td colspan="8" class="no-data">No curriculum programs found. Add some programs to get
                                            started.</td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($curriculum_programs as $program): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($program['program_code']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($program['program_type']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($program['grade_levels']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($program['program_semester'] ?? ''); ?>
                                                </td>
                                                <td>
                                                    <span
                                                        class="<?php echo $program['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $program['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="?tab=programs&edit_program=<?php echo $program['id']; ?>"
                                                            class="btn btn-success btn-sm">Edit</a>
                                                        <a href="?tab=subjects&program_filter=<?php echo $program['id']; ?>"
                                                            class="btn btn-primary btn-sm">View</a>
                                                        <form method="POST" style="display: inline;" class="confirm-delete"
                                                            data-confirm="This will permanently delete the program and its associations. Proceed?">
                                                            <input type="hidden" name="action" value="delete_program">
                                                            <input type="hidden" name="id" value="<?php echo $program['id']; ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Subjects Tab -->
            <div id="subjects-tab" class="tab-content <?php echo $active_tab === 'subjects' ? 'active' : ''; ?>">
                <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="toggleForm('subjects-form')">
                        <?php echo $edit_subject_data ? 'Edit Subject' : ($show_add_form ? 'Add Subject to Program' : '+ Add New Subject'); ?>
                    </button>
                    <button type="button" class="btn btn-info" onclick="openAddSubjectForProgramModal()">
                        + Add Subject for Program
                    </button>

                    <!-- Subjects Search -->
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="tab" value="subjects">
                            <input type="text" name="subject_search" placeholder="Search subjects..."
                                value="<?php echo htmlspecialchars($subject_search); ?>"
                                style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
                            <button type="submit" class="btn btn-secondary">Search</button>
                            <?php if (!empty($subject_search)): ?>
                                    <a href="?tab=subjects" class="btn btn-outline">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div id="subjects-form" class="form-container"
                    style="display: <?php echo ($edit_subject_data || $show_add_form) ? 'block' : 'none'; ?>;">
                    <h2 class="form-title">
                        <?php echo $edit_subject_data ? 'Edit Subject' : ($show_add_form ? 'Add Subject to Program' : 'Add New Subject'); ?>
                    </h2>

                    <form method="POST">
                        <input type="hidden" name="action"
                            value="<?php echo $edit_subject_data ? 'edit_subject' : 'add_subject'; ?>">
                        <?php if ($edit_subject_data): ?>
                                <input type="hidden" name="id"
                                    value="<?php echo htmlspecialchars($edit_subject_data['id']); ?>">
                        <?php endif; ?>

                        <input type="hidden" id="program_id" name="program_id"
                            value="<?php echo $show_add_form ? $program_filter : ($edit_subject_data['program_id'] ?? ''); ?>">

                        <div class="form-row">
                            <!-- Subject code removed -->
                            <div class="form-group subject-name">
                                <label for="subject_name">Subject Name *</label>
                                <input type="text" id="subject_name" name="subject_name"
                                    value="<?php echo htmlspecialchars($edit_subject_data['subject_name'] ?? ''); ?>"
                                    placeholder="Type subject name (e.g., Mathematics)"
                                    oninput="autoPopulateSubjectFields()" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description"
                                placeholder="Enter subject description..."><?php echo htmlspecialchars($edit_subject_data['description'] ?? ''); ?></textarea>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $edit_subject_data ? 'Update Subject' : ($show_add_form ? 'Add Subject to Program' : 'Add Subject'); ?>
                            </button>
                            <?php if ($edit_subject_data): ?>
                                    <a href="?tab=subjects" class="btn btn-secondary">Cancel</a>
                            <?php else: ?>
                                    <button type="button" class="btn btn-secondary"
                                        onclick="toggleForm('subjects-form')">Cancel</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($program_filter): ?>
                                    <tr>
                                        <td colspan="4" style="background:#f9fafb;">
                                            <strong>Filtered by Program:</strong>
                                            <?php
                                            $pf = array_values(array_filter($curriculum_programs, function ($p) use ($program_filter) {
                                                return (int) $p['id'] === (int) $program_filter;
                                            }));
                                            echo isset($pf[0]) ? htmlspecialchars($pf[0]['program_name'] . ' (' . $pf[0]['program_code'] . ')') : 'Unknown';
                                            ?>
                                            <a href="?tab=subjects" class="btn btn-secondary btn-sm"
                                                style="margin-left:10px;">Clear</a>
                                        </td>
                                    </tr>
                            <?php endif; ?>
                            <?php if (empty($curriculum_subjects)): ?>
                                    <tr>
                                        <td colspan="4" class="no-data">No curriculum subjects found. Add some subjects to get
                                            started.</td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($curriculum_subjects as $subject): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($subject['description']); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="?tab=subjects&edit_subject=<?php echo $subject['id']; ?>"
                                                            class="btn btn-success btn-sm">Edit</a>
                                                        <button type="button" class="btn btn-danger btn-sm"
                                                            onclick="deleteSubject(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['subject_name'], ENT_QUOTES); ?>', <?php echo $program_filter ? (int) $program_filter : 'null'; ?>)">Delete</button>
                                                    </div>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Input Modal -->
    <div id="subjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add Subjects to Program</h2>
                <button class="close" onclick="closeSubjectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="subjectForm">
                    <input type="hidden" id="selectedProgramId" name="program_id">

                    <div class="subject-input-group">
                        <div class="form-group">
                            <label for="modalSubjectName">Subject Name *</label>
                            <input type="text" id="modalSubjectName" name="subject_name" placeholder="e.g., Mathematics"
                                required>
                        </div>
                        <div class="form-group">
                            <label for="modalDescription">Description</label>
                            <input type="text" id="modalDescription" name="description"
                                placeholder="Subject description">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="addSubjectToList()">Add</button>
                    </div>

                    <div id="subjectList" class="subject-list" style="display: none;">
                        <h4>Subjects to be added:</h4>
                        <div id="subjectsContainer"></div>
                    </div>

                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="btn btn-secondary" onclick="closeSubjectModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveSubjects()" id="saveSubjectsBtn"
                            style="display: none;">Save All Subjects</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Custom Confirm Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h2 class="modal-title">Please Confirm</h2>
                <button class="close" onclick="closeConfirmModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmMessage" style="margin:0 0 10px 0;">Are you sure?</p>
                <div style="text-align:right;">
                    <button type="button" class="btn btn-secondary" onclick="closeConfirmModal()">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmOkBtn">Remove</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Subject for Program Modal -->
    <div id="addSubjectForProgramModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add Subject for Program</h2>
                <button class="close" onclick="closeAddSubjectForProgramModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addSubjectForProgramForm">
                    <div class="form-group">
                        <label for="selectProgram">Select Program *</label>
                        <select id="selectProgram" name="program_id" required>
                            <option value="">Choose a program...</option>
                            <?php foreach ($active_curriculum_programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>">
                                        <?php echo htmlspecialchars($program['program_name'] . ' (' . $program['program_code'] . ') - ' . $program['program_type']); ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">

                        <div class="form-group subject-name">
                            <label for="modalSubjectNameForProgram">Subject Name *</label>
                            <input type="text" id="modalSubjectNameForProgram" name="subject_name"
                                placeholder="Type subject name (e.g., Mathematics)"
                                oninput="autoPopulateModalSubjectFields()" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="modalDescriptionForProgram">Description</label>
                        <textarea id="modalDescriptionForProgram" name="description"
                            placeholder="Enter subject description..."></textarea>
                    </div>

                    <!-- Manual grade/semester fallback when program has none -->
                    <div class="form-row" id="manualGradeSemesterRow" style="display:none; gap:10px;">
                        <div class="form-group">
                            <label for="manualGradeLevel">Grade Level (required)</label>
                            <select id="manualGradeLevel">
                                <option value="">Select Grade Level</option>
                                <option value="Grade 1">Grade 1</option>
                                <option value="Grade 2">Grade 2</option>
                                <option value="Grade 3">Grade 3</option>
                                <option value="Grade 4">Grade 4</option>
                                <option value="Grade 5">Grade 5</option>
                                <option value="Grade 6">Grade 6</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="manualSemester">Semester</label>
                            <select id="manualSemester">
                                <option value="">Select Semester</option>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="btn btn-secondary"
                            onclick="closeAddSubjectForProgramModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveSubjectForProgram()">Add
                            Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));

            // Remove active class from all tab buttons
            const tabButtons = document.querySelectorAll('.tab-btn');
            tabButtons.forEach(button => button.classList.remove('active'));

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');

            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        function toggleForm(formId) {
            const form = document.getElementById(formId);
            const button = event.target;

            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                button.textContent = button.textContent.replace('+ ', '');
            } else {
                form.style.display = 'none';
                if (button.textContent.includes('Add New')) {
                    button.textContent = '+ ' + button.textContent;
                }
            }
        }

        // Subject Modal Functions
        let subjectsToAdd = [];
        let currentProgramId = null;

        function openSubjectModal(programId, programName, programCode) {
            currentProgramId = programId;
            document.getElementById('modalTitle').textContent = `Add Subjects to ${programName} (${programCode})`;
            document.getElementById('selectedProgramId').value = programId;
            document.getElementById('subjectModal').style.display = 'block';
            subjectsToAdd = [];
            updateSubjectList();
            clearModalForm();
        }

        function closeSubjectModal() {
            document.getElementById('subjectModal').style.display = 'none';
            subjectsToAdd = [];
            clearModalForm();
        }

        function clearModalForm() {
            // subject code removed
            document.getElementById('modalSubjectName').value = '';
            document.getElementById('modalDescription').value = '';
        }

        function addSubjectToList() {
            const code = '';
            const name = document.getElementById('modalSubjectName').value.trim();
            const description = document.getElementById('modalDescription').value.trim();

            if (!name) {
                alert('Please fill in Subject Name');
                return;
            }

            // Check for duplicates
            if (subjectsToAdd.some(subject => subject.name.toLowerCase() === name.toLowerCase())) {
                alert('Subject already added to the list');
                return;
            }

            subjectsToAdd.push({
                code: '',
                name: name,
                description: description
            });

            updateSubjectList();
            clearModalForm();
        }

        function updateSubjectList() {
            const container = document.getElementById('subjectsContainer');
            const listDiv = document.getElementById('subjectList');
            const saveBtn = document.getElementById('saveSubjectsBtn');

            if (subjectsToAdd.length === 0) {
                listDiv.style.display = 'none';
                saveBtn.style.display = 'none';
                return;
            }

            listDiv.style.display = 'block';
            saveBtn.style.display = 'inline-block';

            container.innerHTML = '';
            subjectsToAdd.forEach((subject, index) => {
                const subjectDiv = document.createElement('div');
                subjectDiv.className = 'subject-item';
                subjectDiv.innerHTML = `
                    <div class="subject-info">
                        <div class="subject-code">${subject.code}</div>
                        <div class="subject-name">${subject.name}</div>
                        ${subject.description ? `<div style="font-size: 12px; color: #6b7280;">${subject.description}</div>` : ''}
                    </div>
                    <button type="button" class="remove-subject" onclick="removeSubjectFromList(${index})">Remove</button>
                `;
                container.appendChild(subjectDiv);
            });
        }

        function removeSubjectFromList(index) {
            subjectsToAdd.splice(index, 1);
            updateSubjectList();
        }

        function saveSubjects() {
            if (subjectsToAdd.length === 0) {
                alert('No subjects to save');
                return;
            }

            // Create a form to submit all subjects
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            // Add action
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'add_multiple_subjects';
            form.appendChild(actionInput);

            // Add program ID
            const programInput = document.createElement('input');
            programInput.type = 'hidden';
            programInput.name = 'program_id';
            programInput.value = currentProgramId;
            form.appendChild(programInput);

            // Add subjects as JSON
            const subjectsInput = document.createElement('input');
            subjectsInput.type = 'hidden';
            subjectsInput.name = 'subjects_data';
            subjectsInput.value = JSON.stringify(subjectsToAdd);
            form.appendChild(subjectsInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modal = document.getElementById('subjectModal');
            const addSubjectModal = document.getElementById('addSubjectForProgramModal');
            if (event.target === modal) {
                closeSubjectModal();
            }
            if (event.target === addSubjectModal) {
                closeAddSubjectForProgramModal();
            }
        }

        // Auto-populate subject fields function
        function autoPopulateSubjectFields() {
            const subjectInput = document.getElementById('subject_name');
            // Guard: subject_code input was removed; keep a safe reference
            const subjectCodeInput = { value: '' };
            const descriptionTextarea = document.getElementById('description');

            const subjectName = subjectInput.value.trim();

            if (subjectName) {
                // Find matching subject in database subjects
                const dbSubjects = <?php echo json_encode($db_subjects); ?>;
                const predefinedSubjects = <?php echo json_encode($predefined_subjects); ?>;

                // Check database subjects first
                if (dbSubjects[subjectName]) {
                    subjectCodeInput.value = '';
                    descriptionTextarea.value = dbSubjects[subjectName].description;
                } else {
                    // Check for partial matches in database subjects
                    const dbMatches = Object.keys(dbSubjects).filter(name =>
                        name.toLowerCase().startsWith(subjectName.toLowerCase()) ||
                        name.toLowerCase().includes(subjectName.toLowerCase())
                    );

                    if (dbMatches.length === 1) {
                        // Auto-populate if only one database match
                        const matchName = dbMatches[0];
                        subjectCodeInput.value = '';
                        descriptionTextarea.value = dbSubjects[matchName].description;
                    } else if (dbMatches.length > 1) {
                        // If multiple database matches, try to find the best one
                        const bestMatch = dbMatches.find(name =>
                            name.toLowerCase().startsWith(subjectName.toLowerCase())
                        );
                        if (bestMatch) {
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = dbSubjects[bestMatch].description;
                        } else {
                            // Use the first match if no starts-with match
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = dbSubjects[dbMatches[0]].description;
                        }
                    } else {
                        // No database matches, check predefined subjects as fallback
                        if (predefinedSubjects[subjectName]) {
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = predefinedSubjects[subjectName].description;
                        } else {
                            // Check for partial matches in predefined subjects
                            const predefinedMatches = Object.keys(predefinedSubjects).filter(name =>
                                name.toLowerCase().startsWith(subjectName.toLowerCase()) ||
                                name.toLowerCase().includes(subjectName.toLowerCase())
                            );

                            if (predefinedMatches.length === 1) {
                                const matchName = predefinedMatches[0];
                                subjectCodeInput.value = '';
                                descriptionTextarea.value = predefinedSubjects[matchName].description;
                            } else if (predefinedMatches.length > 1) {
                                const bestMatch = predefinedMatches.find(name =>
                                    name.toLowerCase().startsWith(subjectName.toLowerCase())
                                );
                                if (bestMatch) {
                                    subjectCodeInput.value = '';
                                    descriptionTextarea.value = predefinedSubjects[bestMatch].description;
                                } else {
                                    subjectCodeInput.value = '';
                                    descriptionTextarea.value = predefinedSubjects[predefinedMatches[0]].description;
                                }
                            } else {
                                // No matches found - clear fields
                                subjectCodeInput.value = '';
                                descriptionTextarea.value = '';
                            }
                        }
                    }
                }
            } else {
                // Clear all fields if input is empty
                subjectCodeInput.value = '';
                descriptionTextarea.value = '';
            }
        }

        // Add Subject for Program Modal Functions
        function openAddSubjectForProgramModal() {
            document.getElementById('addSubjectForProgramModal').style.display = 'block';
            clearAddSubjectForProgramForm();
        }

        function closeAddSubjectForProgramModal() {
            document.getElementById('addSubjectForProgramModal').style.display = 'none';
            clearAddSubjectForProgramForm();
        }

        function clearAddSubjectForProgramForm() {
            document.getElementById('selectProgram').value = '';
            // subject code removed
            document.getElementById('modalSubjectNameForProgram').value = '';
            document.getElementById('modalDescriptionForProgram').value = '';
            document.getElementById('manualGradeLevel').value = '';
            document.getElementById('manualSemester').value = '';
            document.getElementById('manualGradeSemesterRow').style.display = 'none';
        }

        // Auto-populate modal subject fields function
        function autoPopulateModalSubjectFields() {
            const subjectInput = document.getElementById('modalSubjectNameForProgram');
            const subjectCodeInput = { value: '' };
            const descriptionTextarea = document.getElementById('modalDescriptionForProgram');

            const subjectName = subjectInput.value.trim();

            if (subjectName) {
                // Find matching subject in database subjects
                const dbSubjects = <?php echo json_encode($db_subjects); ?>;
                const predefinedSubjects = <?php echo json_encode($predefined_subjects); ?>;

                // Check database subjects first
                if (dbSubjects[subjectName]) {
                    subjectCodeInput.value = '';
                    descriptionTextarea.value = dbSubjects[subjectName].description;
                } else {
                    // Check for partial matches in database subjects
                    const dbMatches = Object.keys(dbSubjects).filter(name =>
                        name.toLowerCase().startsWith(subjectName.toLowerCase()) ||
                        name.toLowerCase().includes(subjectName.toLowerCase())
                    );

                    if (dbMatches.length === 1) {
                        // Auto-populate if only one database match
                        const matchName = dbMatches[0];
                        subjectCodeInput.value = '';
                        descriptionTextarea.value = dbSubjects[matchName].description;
                    } else if (dbMatches.length > 1) {
                        // If multiple database matches, try to find the best one
                        const bestMatch = dbMatches.find(name =>
                            name.toLowerCase().startsWith(subjectName.toLowerCase())
                        );
                        if (bestMatch) {
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = dbSubjects[bestMatch].description;
                        } else {
                            // Use the first match if no starts-with match
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = dbSubjects[dbMatches[0]].description;
                        }
                    } else {
                        // No database matches, check predefined subjects as fallback
                        if (predefinedSubjects[subjectName]) {
                            subjectCodeInput.value = '';
                            descriptionTextarea.value = predefinedSubjects[subjectName].description;
                        } else {
                            // Check for partial matches in predefined subjects
                            const predefinedMatches = Object.keys(predefinedSubjects).filter(name =>
                                name.toLowerCase().startsWith(subjectName.toLowerCase()) ||
                                name.toLowerCase().includes(subjectName.toLowerCase())
                            );

                            if (predefinedMatches.length === 1) {
                                const matchName = predefinedMatches[0];
                                subjectCodeInput.value = '';
                                descriptionTextarea.value = predefinedSubjects[matchName].description;
                            } else if (predefinedMatches.length > 1) {
                                const bestMatch = predefinedMatches.find(name =>
                                    name.toLowerCase().startsWith(subjectName.toLowerCase())
                                );
                                if (bestMatch) {
                                    subjectCodeInput.value = '';
                                    descriptionTextarea.value = predefinedSubjects[bestMatch].description;
                                } else {
                                    subjectCodeInput.value = '';
                                    descriptionTextarea.value = predefinedSubjects[predefinedMatches[0]].description;
                                }
                            } else {
                                // No matches found - clear fields
                                subjectCodeInput.value = '';
                                descriptionTextarea.value = '';
                            }
                        }
                    }
                }
            } else {
                // Clear all fields if input is empty
                subjectCodeInput.value = '';
                descriptionTextarea.value = '';
            }
        }

        function saveSubjectForProgram() {
            const programId = document.getElementById('selectProgram').value;
            // subject code removed
            const subjectName = document.getElementById('modalSubjectNameForProgram').value.trim();
            const description = document.getElementById('modalDescriptionForProgram').value.trim();
            const manualGrade = document.getElementById('manualGradeLevel').value;
            const manualSem = document.getElementById('manualSemester').value;

            // Validation removed as requested

            // Create a form to submit the subject
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            // Add action
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'add_subject';
            form.appendChild(actionInput);

            // Add program ID
            const programInput = document.createElement('input');
            programInput.type = 'hidden';
            programInput.name = 'program_id';
            programInput.value = programId;
            form.appendChild(programInput);

            // Add subject name
            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'subject_name';
            nameInput.value = subjectName;
            form.appendChild(nameInput);

            // Add description
            const descInput = document.createElement('input');
            descInput.type = 'hidden';
            descInput.name = 'description';
            descInput.value = description;
            form.appendChild(descInput);

            // Add manual grade/semester if provided
            if (manualGrade) {
                const mg = document.createElement('input');
                mg.type = 'hidden';
                mg.name = 'manual_grade_level';
                mg.value = manualGrade;
                form.appendChild(mg);
            }
            if (manualSem) {
                const ms = document.createElement('input');
                ms.type = 'hidden';
                ms.name = 'manual_semester';
                ms.value = manualSem;
                form.appendChild(ms);
            }

            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const programIdInput = document.getElementById('program_id');
            const gradeLevelSelect = document.getElementById('grade_level');
            const semesterSelect = document.getElementById('semester');
            const programTypeField = document.getElementById('program_type');
            const gradeLevelsField = document.getElementById('grade_levels');
            const selectProgramEl = document.getElementById('selectProgram');

            const allGradeOptions = Array.from(gradeLevelSelect ? gradeLevelSelect.options : []);

            function isSeniorHigh(programType) {
                return programType === 'Senior High School';
            }

            function syncProgramGradeLevels() {
                if (!programTypeField || !gradeLevelsField) return;
                const programType = programTypeField.value;
                const all = ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                const allowed = isSeniorHigh(programType) ? ['', 'Grade 11', 'Grade 12'] : ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

                // Disable disallowed options
                Array.from(gradeLevelsField.options).forEach(opt => {
                    opt.disabled = !allowed.includes(opt.value);
                });

                // Reset if current selection not allowed
                if (!allowed.includes(gradeLevelsField.value)) {
                    gradeLevelsField.value = allowed[0];
                }

                // Toggle semester for programs (Senior High only)
                const programSemester = document.getElementById('program_semester');
                if (programSemester) {
                    if (isSeniorHigh(programType)) {
                        programSemester.disabled = false;
                        programSemester.required = true;
                    } else {
                        programSemester.disabled = true;
                        programSemester.required = false;
                        programSemester.value = '';
                    }
                }
            }

            function filterGradeLevels() {
                if (!programIdInput || !gradeLevelSelect) return;

                // Get program data from PHP (we'll need to pass this data)
                const programId = programIdInput.value;
                const programData = window.programData ? window.programData[programId] : null;

                if (!programData) return;

                const programType = programData.program_type;

                // Restore options then filter
                gradeLevelSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select Grade Level';
                gradeLevelSelect.appendChild(placeholder);

                const targetGrades = isSeniorHigh(programType)
                    ? ['Grade 11', 'Grade 12']
                    : ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

                allGradeOptions.forEach(opt => {
                    if (!opt.value || targetGrades.includes(opt.value)) {
                        const clone = opt.cloneNode(true);
                        gradeLevelSelect.appendChild(clone);
                    }
                });

                // If current selection not allowed, reset
                if (!targetGrades.includes(gradeLevelSelect.value)) {
                    gradeLevelSelect.value = '';
                }

                // Auto-select a default grade if none selected
                if (!gradeLevelSelect.value && targetGrades.length > 0) {
                    const defaultGrade = targetGrades[0];
                    const defaultOption = Array.from(gradeLevelSelect.options).find(o => o.value === defaultGrade);
                    if (defaultOption) {
                        gradeLevelSelect.value = defaultGrade;
                    }
                }

                toggleSemesterField();
            }

            function toggleSemesterField() {
                if (!gradeLevelSelect || !semesterSelect || !programIdInput) return;

                const programId = programIdInput.value;
                const programData = window.programData ? window.programData[programId] : null;

                if (!programData) return;

                const programType = programData.program_type;
                const senior = isSeniorHigh(programType);
                if (senior && (gradeLevelSelect.value === 'Grade 11' || gradeLevelSelect.value === 'Grade 12')) {
                    semesterSelect.disabled = false;
                    semesterSelect.required = true;
                } else {
                    semesterSelect.disabled = true;
                    semesterSelect.required = false;
                    semesterSelect.value = '';
                }
            }

            // Initialize on page load
            filterGradeLevels();
            syncProgramGradeLevels();

            // No need for program select listener since it's hidden
            if (programTypeField) programTypeField.addEventListener('change', syncProgramGradeLevels);
            if (gradeLevelSelect) gradeLevelSelect.addEventListener('change', toggleSemesterField);

            // Toggle manual grade/semester on program change in modal
            if (selectProgramEl) {
                selectProgramEl.addEventListener('change', function () {
                    const pid = this.value;
                    const pd = window.programData ? window.programData[pid] : null;
                    const needManual = !pd || !pd.grade_levels;
                    document.getElementById('manualGradeSemesterRow').style.display = needManual ? 'flex' : 'none';
                });
            }
        });

        // Pass program data to JavaScript
        window.programData = {
            <?php foreach ($active_curriculum_programs as $program): ?>
                                <?php echo $program['id']; ?>: {
                    program_type: '<?php echo htmlspecialchars($program['program_type']); ?>',
                        grade_levels: '<?php echo htmlspecialchars($program['grade_levels']); ?>',
                            program_semester: '<?php echo htmlspecialchars($program['program_semester'] ?? ''); ?>'
                }<?php echo $program !== end($active_curriculum_programs) ? ',' : ''; ?>
            <?php endforeach; ?>
        };

        // Delete confirmation logic
        let pendingDeleteForm = null;
        function openConfirmModal(message, onConfirm) {
            document.getElementById('confirmMessage').textContent = message || 'Are you sure?';
            const okBtn = document.getElementById('confirmOkBtn');
            // Remove previous handler
            const newOkBtn = okBtn.cloneNode(true);
            okBtn.parentNode.replaceChild(newOkBtn, okBtn);
            document.getElementById('confirmOkBtn').addEventListener('click', function () {
                closeConfirmModal();
                if (typeof onConfirm === 'function') onConfirm();
            });
            document.getElementById('confirmModal').style.display = 'block';
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            pendingDeleteForm = null;
        }

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (form.classList && form.classList.contains('confirm-delete')) {
                // If already confirmed, allow submission
                if (form.querySelector('input[name="confirmed"][value="1"]')) {
                    return;
                }
                e.preventDefault();
                pendingDeleteForm = form;
                const msg = form.getAttribute('data-confirm') || 'Are you sure you want to remove?';
                openConfirmModal(msg, function () {
                    if (pendingDeleteForm) {
                        // Mark as confirmed with hidden input so listener allows the submit
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'confirmed';
                        input.value = '1';
                        pendingDeleteForm.appendChild(input);
                        pendingDeleteForm.submit();
                    }
                });
            }
        }, true);

        // Simple delete function with confirmation
        function deleteSubject(id, subjectName, programFilter) {
            console.log('Delete button clicked for subject:', subjectName, 'ID:', id);
            if (confirm('Permanently delete subject "' + subjectName + '"?')) {
                console.log('User confirmed deletion');
                // Create a form to submit the deletion
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                // Add action
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'remove_subject';
                form.appendChild(actionInput);

                // Add ID
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;
                form.appendChild(idInput);

                // Add subject name
                const nameInput = document.createElement('input');
                nameInput.type = 'hidden';
                nameInput.name = 'subject_name';
                nameInput.value = subjectName;
                form.appendChild(nameInput);

                // Add program filter if exists
                if (programFilter) {
                    const filterInput = document.createElement('input');
                    filterInput.type = 'hidden';
                    filterInput.name = 'program_filter';
                    filterInput.value = programFilter;
                    form.appendChild(filterInput);
                }

                document.body.appendChild(form);
                console.log('Submitting delete form for subject:', subjectName);
                form.submit();
            }
        }
    </script>
</body>

</html>