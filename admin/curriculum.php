<?php require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin']); ?>
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

// Get active tab from URL parameter
$active_tab = $_GET['tab'] ?? 'programs';

// Initialize messages
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
        $action = $_POST['action'];

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
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!empty($program_code) && !empty($program_name) && !empty($program_type) && !empty($grade_levels)) {
                // Check if program code already exists
                $checkStmt = $pdo->prepare("SELECT id FROM curriculum_programs WHERE program_code = ?");
                $checkStmt->execute([$program_code]);
                if ($checkStmt->rowCount() > 0) {
                    $error_message = "Error: A program with the code '$program_code' already exists.";
                    $active_tab = 'programs';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO curriculum_programs (program_code, program_name, program_type, grade_levels, duration_years, total_units, description, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$program_code, $program_name, $program_type, $grade_levels, $duration_years, $total_units, $description, $is_active]);
                    $success_message = "Curriculum program added successfully!";
                    $active_tab = 'programs';
                }
            } else {
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
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($id > 0 && !empty($program_code) && !empty($program_name) && !empty($program_type) && !empty($grade_levels)) {
                // Check if program code already exists in ANOTHER record
                $checkStmt = $pdo->prepare("SELECT id FROM curriculum_programs WHERE program_code = ? AND id != ?");
                $checkStmt->execute([$program_code, $id]);
                if ($checkStmt->rowCount() > 0) {
                    $error_message = "Error: Another program with the code '$program_code' already exists.";
                    $active_tab = 'programs';
                } else {
                    $stmt = $pdo->prepare("UPDATE curriculum_programs SET program_code = ?, program_name = ?, program_type = ?, grade_levels = ?, duration_years = ?, total_units = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$program_code, $program_name, $program_type, $grade_levels, $duration_years, $total_units, $description, $is_active, $id]);
                    header('Location: ?tab=programs');
                    exit;
                }
            } else {
                $error_message = "Please fill in all required fields.";
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
        } elseif ($action === 'export_programs') {
            $program_search = $_POST['program_search'] ?? '';
            $program_type_filter = $_POST['program_type_filter'] ?? '';
            $grade_level_filter = $_POST['grade_level_filter'] ?? '';

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

            if (!empty($grade_level_filter)) {
                $programs_where_conditions[] = "grade_levels LIKE ?";
                $programs_params[] = "%$grade_level_filter%";
            }

            $sql = "SELECT program_code, program_name, program_type, grade_levels, program_semester, duration_years, total_units, description, is_active FROM curriculum_programs";
            if (!empty($programs_where_conditions)) {
                $sql .= " WHERE " . implode(" AND ", $programs_where_conditions);
            }
            $sql .= " ORDER BY program_type, grade_levels, program_code";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($programs_params);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="programs_export_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Program Code', 'Program Name', 'Program Type', 'Grade Levels', 'Semester', 'Duration (Years)', 'Total Units', 'Description', 'Active']);

            foreach ($programs as $program) {
                fputcsv($output, [
                    $program['program_code'],
                    $program['program_name'],
                    $program['program_type'],
                    $program['grade_levels'],
                    $program['program_semester'],
                    $program['duration_years'],
                    $program['total_units'],
                    $program['description'],
                    $program['is_active'] ? 'Yes' : 'No'
                ]);
            }
            fclose($output);
            exit;

        } elseif ($action === 'import_programs') {
             if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['import_file']['tmp_name'];
                $fileName = $_FILES['import_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if ($fileExtension === 'csv') {
                    $handle = fopen($fileTmpPath, 'r');
                    $header = fgetcsv($handle); // Skip header

                    $success_count = 0;
                    $error_count = 0;

                    while (($data = fgetcsv($handle)) !== FALSE) {
                        // Expected: Code, Name, Type, Grades, Semester, Duration, Units, Description, Active (Yes/No or 1/0)
                        $p_code = $data[0] ?? '';
                        $p_name = $data[1] ?? '';
                        $p_type = $data[2] ?? '';
                        $p_grades = $data[3] ?? '';
                        $p_sem = $data[4] ?? '';
                        $p_dur = !empty($data[5]) ? floatval($data[5]) : 1.0;
                        $p_units = !empty($data[6]) ? floatval($data[6]) : 0.0;
                        $p_desc = $data[7] ?? '';
                        $p_active_raw = strtolower($data[8] ?? 'yes');
                        $p_active = ($p_active_raw === 'yes' || $p_active_raw === '1' || $p_active_raw === 'true') ? 1 : 0;

                        if (!empty($p_code) && !empty($p_name) && !empty($p_type) && !empty($p_grades)) {
                            // Check for duplicate program code
                            $check = $pdo->prepare("SELECT id FROM curriculum_programs WHERE program_code = ?");
                            $check->execute([$p_code]);
                            if ($check->rowCount() > 0) {
                                $error_count++; // Duplicate
                                continue;
                            }

                            $stmt = $pdo->prepare("INSERT INTO curriculum_programs (program_code, program_name, program_type, grade_levels, program_semester, duration_years, total_units, description, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            try {
                                $stmt->execute([$p_code, $p_name, $p_type, $p_grades, $p_sem, $p_dur, $p_units, $p_desc, $p_active]);
                                $success_count++;
                            } catch (Exception $e) {
                                $error_count++;
                            }
                        } else {
                            $error_count++;
                        }
                    }
                    fclose($handle);
                    
                    if ($success_count > 0) {
                        $success_message = "Successfully imported $success_count programs." . ($error_count > 0 ? " ($error_count skipped/failed)" : "");
                    } else {
                        $error_message = "No programs imported. Please check the CSV format.";
                    }
                } else {
                    $error_message = "Invalid file type. Please upload a CSV file.";
                }
             } else {
                 $error_message = "Error uploading file.";
             }
             $active_tab = 'programs';
        }

        // Handle Subjects
        elseif ($action === 'add_subject') {
            $program_id = $_POST['program_id'] ?? '';
            $subject_code = $_POST['subject_code'] ?? '';
            $subject_name = $_POST['subject_name'] ?? '';
            $description = $_POST['description'] ?? '';

            if (!empty($program_id) && !empty($subject_code) && !empty($subject_name)) {
                // Derive grade level from selected program
                $prog = null;
                $stmt = $pdo->prepare("SELECT program_type, grade_levels FROM curriculum_programs WHERE id = ?");
                $stmt->execute([$program_id]);
                $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                $grade_level = $prog['grade_levels'] ?? '';
                $units = 0;

                if (!empty($grade_level)) {
                    $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_code, subject_name, grade_level, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $ins->execute([$program_id, $subject_code, $subject_name, $grade_level, $units, $description]);
                    $success_message = "Curriculum subject added successfully!";
                } else {
                    $error_message = "Selected program has no grade level configured.";
                }
                $active_tab = 'subjects';
            } else {
                $error_message = "Please fill in all required fields including program selection.";
                $active_tab = 'subjects';
            }
        } elseif ($action === 'edit_subject') {
            $id = $_POST['id'] ?? 0;
            $program_id = $_POST['program_id'] ?? '';
            $subject_code = $_POST['subject_code'] ?? '';
            $subject_name = $_POST['subject_name'] ?? '';
            $description = $_POST['description'] ?? '';

            if ($id > 0 && !empty($program_id) && !empty($subject_code) && !empty($subject_name)) {
                // Derive grade level from selected program
                $stmt = $pdo->prepare("SELECT program_type, grade_levels FROM curriculum_programs WHERE id = ?");
                $stmt->execute([$program_id]);
                $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                $grade_level = $prog['grade_levels'] ?? '';
                $units = 0;

                if (!empty($grade_level)) {
                    $upd = $pdo->prepare("UPDATE curriculum SET program_id = ?, subject_code = ?, subject_name = ?, grade_level = ?, units = ?, description = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$program_id, $subject_code, $subject_name, $grade_level, $units, $description, $id]);
                    $success_message = "Curriculum subject updated successfully!";
                } else {
                    $error_message = "Selected program has no grade level configured.";
                }
                $active_tab = 'subjects';
            } else {
                $error_message = "Please fill in all required fields including program selection.";
                $active_tab = 'subjects';
            }
        } elseif ($action === 'remove_subject') {
            $id = $_POST['id'] ?? 0;
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE curriculum SET program_id = NULL, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $pf = isset($_POST['program_filter']) ? (int) $_POST['program_filter'] : 0;
                if ($pf > 0) {
                    header('Location: ?tab=subjects&program_filter=' . $pf);
                    exit;
                }
                $success_message = "Subject removed from the program.";
                $active_tab = 'subjects';
            }
        } elseif ($action === 'delete_subject') {
            $id = $_POST['id'] ?? 0;
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id = ?");
                $stmt->execute([$id]);
                $success_message = "Curriculum subject deleted successfully!";
                $active_tab = 'subjects';
            }
        } elseif ($action === 'add_multiple_subjects') {
            $program_id = $_POST['program_id'] ?? '';
            $subjects_data = $_POST['subjects_data'] ?? '';

            if (!empty($program_id) && !empty($subjects_data)) {
                $subjects = json_decode($subjects_data, true);

                if (is_array($subjects) && count($subjects) > 0) {
                    // Get program details
                    $stmt = $pdo->prepare("SELECT program_type, grade_levels FROM curriculum_programs WHERE id = ?");
                    $stmt->execute([$program_id]);
                    $prog = $stmt->fetch(PDO::FETCH_ASSOC);

                    $grade_level = $prog['grade_levels'] ?? '';
                    $units = 0;

                    if (!empty($grade_level)) {
                        $success_count = 0;

                        foreach ($subjects as $subject) {
                            $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_code, subject_name, grade_level, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            $ins->execute([$program_id, $subject['code'], $subject['name'], $grade_level, $units, $subject['description']]);
                            $success_count++;
                        }

                        if ($success_count > 0) {
                            $success_message = "Successfully added {$success_count} subjects to the program!";
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
        } elseif ($action === 'export_subjects') {
            $program_filter = $_POST['program_filter'] ?? '';
            
            // Build query
            $sql = "SELECT cp.program_code, c.subject_code, c.subject_name, c.description, c.units, c.grade_level, c.semester 
                    FROM curriculum c 
                    LEFT JOIN curriculum_programs cp ON c.program_id = cp.id";
            
            $params = [];
            if (!empty($program_filter)) {
                $sql .= " WHERE c.program_id = ?";
                $params[] = $program_filter;
            }
            $sql .= " ORDER BY cp.program_name, c.grade_level, c.semester, c.subject_code";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Headers for CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="subjects_export_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV Header
            fputcsv($output, ['Program Code', 'Subject Code', 'Subject Name', 'Description', 'Units', 'Grade Level', 'Semester']);
            
            foreach ($subjects as $subject) {
                fputcsv($output, [
                    $subject['program_code'],
                    $subject['subject_code'],
                    $subject['subject_name'],
                    $subject['description'],
                    $subject['units'],
                    $subject['grade_level'],
                    $subject['semester']
                ]);
            }
            
            fclose($output);
            exit;

        } elseif ($action === 'import_subjects') {
            $program_id = $_POST['program_id'] ?? '';
            
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['import_file']['tmp_name'];
                $fileName = $_FILES['import_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if ($fileExtension === 'csv') {
                    $handle = fopen($fileTmpPath, 'r');
                    $header = fgetcsv($handle); // Skip header row
                    
                    $success_count = 0;
                    $error_count = 0;
                    
                    // Get program defaults if program selected
                    $grade_level_default = '';
                    $semester_default = '';
                    
                    if (!empty($program_id)) {
                        $stmt = $pdo->prepare("SELECT program_type, grade_levels, program_semester FROM curriculum_programs WHERE id = ?");
                        $stmt->execute([$program_id]);
                        $prog = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($prog) {
                            $grade_level_default = $prog['grade_levels'] ?? '';
                            $semester_default = ($prog['program_type'] === 'Senior High School') ? ($prog['program_semester'] ?? '') : '';
                        }
                    }
                    
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        // Expected format: Subject Code, Subject Name, Description, Units, Grade Level, Semester
                        // Map indices based on template, assuming standard order
                        $subj_code = $data[0] ?? '';
                        $subj_name = $data[1] ?? '';
                        $desc = $data[2] ?? '';
                        $units = $data[3] ?? 0;
                        $g_level = !empty($data[4]) ? $data[4] : $grade_level_default;
                        
                        if (!empty($subj_code) && !empty($subj_name)) {
                            // Insert
                            // If program_id provided, link it, otherwise NULL (or handle based on Program Code in CSV if implemented)
                            // Ideally we might want to lookup program by code if provided in CSV, but for now let's rely on the dropdown or just insert without program if none selected.
                            
                            // Simple approach: Use selected program ID.
                            if (!empty($program_id) && !empty($g_level)) {
                                $ins = $pdo->prepare("INSERT INTO curriculum (program_id, subject_code, subject_name, grade_level, semester, units, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                                try {
                                    $ins->execute([$program_id, $subj_code, $subj_name, $g_level, $sem, $units, $desc]);
                                    $success_count++;
                                } catch (Exception $e) {
                                    $error_count++;
                                }
                            } else {
                                $error_count++; // Cannot insert without program/grade level for now securely
                            }
                        } else {
                            $error_count++;
                        }
                    }
                    fclose($handle);
                    
                    if ($success_count > 0) {
                        $success_message = "Successfully imported $success_count subjects." . ($error_count > 0 ? " ($error_count skipped/failed)" : "");
                    } else {
                        $error_message = "No subjects imported. Please check the CSV format.";
                    }
                    
                } else {
                    $error_message = "Invalid file type. Please upload a CSV file.";
                }
            } else {
                $error_message = "Error uploading file.";
            }
            $active_tab = 'subjects';
        } elseif ($action === 'delete_all_subjects') {
            $program_filter = $_POST['program_filter'] ?? '';
            
            if (!empty($program_filter)) {
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE program_id = ?");
                $stmt->execute([$program_filter]);
                $success_message = "All subjects for the selected program have been deleted.";
            } else {
                $stmt = $pdo->query("DELETE FROM curriculum");
                $success_message = "All subjects have been deleted from the system.";
            }
            $active_tab = 'subjects';
        }
        }
    } catch (PDOException $e) {
        $error_message = "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Pagination Parameters
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$grade_level_filter = $_GET['grade_level_filter'] ?? '';

// Valid limits
$allowed_limits = [10, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 10;
}

// Handle search and filter parameters
$program_search = $_GET['program_search'] ?? '';
$program_type_filter = $_GET['program_type_filter'] ?? '';
$subject_search = $_GET['subject_search'] ?? '';
$subject_program_filter = $_GET['subject_program_filter'] ?? '';
$show_add_form = isset($_GET['show_add_form']) && $_GET['show_add_form'] == '1';

// Initialize variables to prevent undefined warnings
$curriculum_programs = [];
$total_programs = 0;
$total_pages = 0;
$curriculum_subjects = [];
$active_curriculum_programs = [];
$edit_program_data = null;
$edit_subject_data = null;

try {
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

    if (!empty($grade_level_filter)) {
        $programs_where_conditions[] = "grade_levels LIKE ?";
        $programs_params[] = "%$grade_level_filter%";
    }

    // Build Query
    $programs_query = "SELECT * FROM curriculum_programs";
    $count_query = "SELECT COUNT(*) FROM curriculum_programs";
    
    if (!empty($programs_where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $programs_where_conditions);
        $programs_query .= $where_clause;
        $count_query .= $where_clause;
    }
    $programs_query .= " ORDER BY program_type, grade_levels, program_code LIMIT $limit OFFSET $offset";

    // Execute Query
    if (!empty($programs_params)) {
        $programs_stmt = $pdo->prepare($programs_query);
        $programs_stmt->execute($programs_params);
        $curriculum_programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count_stmt = $pdo->prepare($count_query);
        $count_stmt->execute($programs_params);
        $total_programs = $count_stmt->fetchColumn();
    } else {
        $programs_result = $pdo->query($programs_query);
        $curriculum_programs = $programs_result->fetchAll(PDO::FETCH_ASSOC);
        
        $count_stmt = $pdo->query($count_query);
        $total_programs = $count_stmt->fetchColumn();
    }

    $total_pages = ceil($total_programs / $limit);

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

    if (!empty($subject_program_filter)) {
        $subjects_where_conditions[] = "c.program_id = ?";
        $subjects_params[] = $subject_program_filter;
    }

    if (!empty($subject_search)) {
        $subjects_where_conditions[] = "(c.subject_code LIKE ? OR c.subject_name LIKE ? OR c.description LIKE ?)";
        $search_term = "%$subject_search%";
        $subjects_params[] = $search_term;
        $subjects_params[] = $search_term;
        $subjects_params[] = $search_term;
    }

    $curriculum_query = "SELECT c.*, cp.program_name, cp.program_code 
                         FROM curriculum c 
                         LEFT JOIN curriculum_programs cp ON c.program_id = cp.id";

    if (!empty($subjects_where_conditions)) {
        $curriculum_query .= " WHERE " . implode(" AND ", $subjects_where_conditions);
    }

    $curriculum_query .= " ORDER BY cp.program_name ASC, 
                           (CASE WHEN c.subject_name LIKE '%MAPEH%' OR c.subject_name LIKE '%Music%' OR c.subject_name LIKE '%Arts%' OR c.subject_name LIKE '%Physical Education%' OR c.subject_name LIKE '%Health%' THEN 1 ELSE 0 END) ASC,
                           (CASE WHEN c.subject_name = 'MAPEH' THEN 0 ELSE 1 END) ASC,
                           c.subject_name ASC";

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

    // Get edit data if editing
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
    if (empty($error_message)) {
        $error_message = "Database Error: " . $e->getMessage();
    }
} catch (Exception $e) {
    if (empty($error_message)) {
        $error_message = "Error: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum & Programs Management - Admin</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/curriculum.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        .main-content {
            margin-top: var(--header-height); /* Desktop */
            /* Margin left handled by sidebar.css */
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px; /* Mobile */
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/admin_header.php'; ?>
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Programs</h1>
            <p style="margin: 10px 0 0 0; color: #666; font-size: 14px;">
                Manage curriculum programs and subjects in one place.
            </p>
        </div>
        
        <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <div class="tab-container">
            <div class="tab-nav">
                <button class="tab-btn <?php echo $active_tab === 'programs' ? 'active' : ''; ?>" onclick="switchTab('programs')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                    Programs
                </button>
                <button class="tab-btn <?php echo $active_tab === 'subjects' ? 'active' : ''; ?>" onclick="switchTab('subjects')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path>
                    </svg>
                    Subjects
                </button>
            </div>
            
            <!-- Programs Tab -->
            <div id="programs-tab" class="tab-content <?php echo $active_tab === 'programs' ? 'active' : ''; ?>">
                <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="toggleForm('programs-form')">
                            <?php echo $edit_program_data ? 'Edit Program' : '+ Add New Program'; ?>
                        </button>
                        <button type="button" class="btn btn-info" onclick="openImportProgramsModal()">
                            📥 Import CSV
                        </button>
                        <form method="POST" action="" style="display:inline;">
                             <input type="hidden" name="action" value="export_programs">
                             <input type="hidden" name="program_search" value="<?php echo htmlspecialchars($program_search); ?>">
                             <input type="hidden" name="program_type_filter" value="<?php echo htmlspecialchars($program_type_filter); ?>">
                             <input type="hidden" name="grade_level_filter" value="<?php echo htmlspecialchars($grade_level_filter); ?>">
                             <button type="submit" class="btn btn-secondary">
                                📤 Export CSV
                             </button>
                        </form>
                    </div>
                    
                    <!-- Programs Search and Filter -->
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="tab" value="programs">
                            
                            <!-- Limit Dropdown -->
                            <select name="limit" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 records</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 records</option>
                                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 records</option>
                            </select>

                            <input type="text" name="program_search" placeholder="Search programs..." 
                                   value="<?php echo htmlspecialchars($program_search); ?>" 
                                   style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
                            
                            <select name="program_type_filter" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Types</option>
                                <option value="Grade School" <?php echo $program_type_filter === 'Grade School' ? 'selected' : ''; ?>>Grade School</option>
                                <option value="Basic Education" <?php echo $program_type_filter === 'Basic Education' ? 'selected' : ''; ?>>Junior High School</option>
                                <option value="Special Program" <?php echo $program_type_filter === 'Special Program' ? 'selected' : ''; ?>>Special Program</option>
                                <option value="Alternative Learning" <?php echo $program_type_filter === 'Alternative Learning' ? 'selected' : ''; ?>>Alternative Learning</option>
                            </select>

                            <select name="grade_level_filter" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Grade Levels</option>
                                <option value="Grade 1" <?php echo $grade_level_filter === 'Grade 1' ? 'selected' : ''; ?>>Grade 1</option>
                                <option value="Grade 2" <?php echo $grade_level_filter === 'Grade 2' ? 'selected' : ''; ?>>Grade 2</option>
                                <option value="Grade 3" <?php echo $grade_level_filter === 'Grade 3' ? 'selected' : ''; ?>>Grade 3</option>
                                <option value="Grade 4" <?php echo $grade_level_filter === 'Grade 4' ? 'selected' : ''; ?>>Grade 4</option>
                                <option value="Grade 5" <?php echo $grade_level_filter === 'Grade 5' ? 'selected' : ''; ?>>Grade 5</option>
                                <option value="Grade 6" <?php echo $grade_level_filter === 'Grade 6' ? 'selected' : ''; ?>>Grade 6</option>
                                <option value="Grade 7" <?php echo $grade_level_filter === 'Grade 7' ? 'selected' : ''; ?>>Grade 7</option>
                                <option value="Grade 8" <?php echo $grade_level_filter === 'Grade 8' ? 'selected' : ''; ?>>Grade 8</option>
                                <option value="Grade 9" <?php echo $grade_level_filter === 'Grade 9' ? 'selected' : ''; ?>>Grade 9</option>
                                <option value="Grade 10" <?php echo $grade_level_filter === 'Grade 10' ? 'selected' : ''; ?>>Grade 10</option>
                            </select>

                            <button type="submit" class="btn btn-secondary">Search</button>
                            <?php if (!empty($program_search) || !empty($program_type_filter) || !empty($grade_level_filter)): ?>
                                    <a href="?tab=programs" class="btn btn-outline">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div id="programs-form" class="form-container" style="display: <?php echo $edit_program_data ? 'block' : 'none'; ?>;">
                    <h2 class="form-title"><?php echo $edit_program_data ? 'Edit Curriculum Program' : 'Add New Curriculum Program'; ?></h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="<?php echo $edit_program_data ? 'edit_program' : 'add_program'; ?>">
                        <?php if ($edit_program_data): ?>
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_program_data['id']); ?>">
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
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="grade_levels">Grade Level *</label>
                                <?php $gl_value = trim($edit_program_data['grade_levels'] ?? ''); ?>
                                <select id="grade_levels" name="grade_levels" required>
                                    <option value="">Select Grade Level</option>
                                    <option value="Grade 1" <?php echo $gl_value === 'Grade 1' ? 'selected' : ''; ?>>Grade 1</option>
                                    <option value="Grade 2" <?php echo $gl_value === 'Grade 2' ? 'selected' : ''; ?>>Grade 2</option>
                                    <option value="Grade 3" <?php echo $gl_value === 'Grade 3' ? 'selected' : ''; ?>>Grade 3</option>
                                    <option value="Grade 4" <?php echo $gl_value === 'Grade 4' ? 'selected' : ''; ?>>Grade 4</option>
                                    <option value="Grade 5" <?php echo $gl_value === 'Grade 5' ? 'selected' : ''; ?>>Grade 5</option>
                                    <option value="Grade 6" <?php echo $gl_value === 'Grade 6' ? 'selected' : ''; ?>>Grade 6</option>
                                    <option value="Grade 7" <?php echo $gl_value === 'Grade 7' ? 'selected' : ''; ?>>Grade 7</option>
                                    <option value="Grade 8" <?php echo $gl_value === 'Grade 8' ? 'selected' : ''; ?>>Grade 8</option>
                                    <option value="Grade 9" <?php echo $gl_value === 'Grade 9' ? 'selected' : ''; ?>>Grade 9</option>
                                    <option value="Grade 10" <?php echo $gl_value === 'Grade 10' ? 'selected' : ''; ?>>Grade 10</option>
                                </select>
                            </div>
                        </div>
                        
                        
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" 
                                      placeholder="Enter program description..."><?php echo htmlspecialchars($edit_program_data['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_active" name="is_active" 
                                   <?php echo ($edit_program_data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="is_active">Active Program</label>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $edit_program_data ? 'Update Program' : 'Add Program'; ?>
                            </button>
                            <?php if ($edit_program_data): ?>
                                    <a href="?tab=programs" class="btn btn-secondary">Cancel</a>
                            <?php else: ?>
                                    <button type="button" class="btn btn-secondary" onclick="toggleForm('programs-form')">Cancel</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 30px;"></th>
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
                                        <td colspan="8" class="no-data">No curriculum programs found. Add some programs to get started.</td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($curriculum_programs as $program):
                                        // Get subjects for this program
                                        $prog_subjects = array_filter($curriculum_subjects, function ($s) use ($program) {
                                            return $s['program_id'] == $program['id'];
                                        });
                                        $subject_count = count($prog_subjects);
                                        ?>
                                            <tr class="program-row" onclick="toggleProgramSubjects(<?php echo $program['id']; ?>)" style="cursor: pointer;">
                                                <td style="text-align: center;">
                                                    <span id="chevron-<?php echo $program['id']; ?>" style="transition: transform 0.3s;">▶</span>
                                                </td>
                                                <td><?php echo htmlspecialchars($program['program_code']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                                    <span style="color: #64748b; font-size: 12px; margin-left: 8px;">(<?php echo $subject_count; ?> subjects)</span>
                                                </td>
                                                <td><?php echo htmlspecialchars($program['program_type']); ?></td>
                                                <td><?php echo htmlspecialchars($program['grade_levels']); ?></td>
                                                <td><?php echo htmlspecialchars($program['program_semester'] ?? ''); ?></td>
                                                <td>
                                                    <span class="<?php echo $program['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $program['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td onclick="event.stopPropagation();">
                                                    <div class="btn-group">
                                                        <a href="?edit_program=<?php echo $program['id']; ?>" class="btn btn-success btn-sm">Edit</a>
                                                        <button type="button" class="btn btn-info btn-sm" onclick="openSubjectModal(<?php echo $program['id']; ?>, '<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES); ?>')">+ Subject</button>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this program?')">
                                                            <input type="hidden" name="action" value="delete_program">
                                                            <input type="hidden" name="id" value="<?php echo $program['id']; ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <!-- Nested Subjects Row -->
                                            <tr id="subjects-row-<?php echo $program['id']; ?>" class="subjects-row" style="display: none;">
                                                <td colspan="8" style="background: #f8fafc; padding: 0;">
                                                    <div style="padding: 15px 15px 15px 50px;">
                                                        <?php if (empty($prog_subjects)): ?>
                                                                <p style="color: #64748b; margin: 0;">No subjects added to this program yet. <button type="button" class="btn btn-info btn-sm" onclick="openSubjectModal(<?php echo $program['id']; ?>, '<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES); ?>')">Add Subject</button></p>
                                                        <?php else: ?>
                                                                <table class="table" style="margin: 0; background: white; border-radius: 8px;">
                                                                    <thead>
                                                                        <tr style="background: #e0f2fe;">
                                                                            <th>Subject Code</th>
                                                                            <th>Subject Name</th>
                                                                            <th>Description</th>
                                                                            <th style="width: 200px;">Actions</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($prog_subjects as $subject): ?>
                                                                                <tr>
                                                                                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                                                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                                                    <td><?php echo htmlspecialchars($subject['description']); ?></td>
                                                                                    <td>
                                                                                        <div class="btn-group">
                                                                                            <a href="?edit_subject=<?php echo $subject['id']; ?>" class="btn btn-success btn-sm">Edit</a>
                                                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this subject?')">
                                                                                                <input type="hidden" name="action" value="delete_subject">
                                                                                                <input type="hidden" name="id" value="<?php echo $subject['id']; ?>">
                                                                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                                                            </form>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
                    <div style="color: #6b7280; font-size: 14px;">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_programs); ?> of <?php echo $total_programs; ?> entries
                    </div>
                    <div style="display: flex; gap: 5px;">
                        <?php if ($page > 1): ?>
                            <a href="?tab=programs&page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&program_search=<?php echo urlencode($program_search); ?>&program_type_filter=<?php echo urlencode($program_type_filter); ?>&grade_level_filter=<?php echo urlencode($grade_level_filter); ?>" class="btn btn-outline btn-sm">Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?tab=programs&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&program_search=<?php echo urlencode($program_search); ?>&program_type_filter=<?php echo urlencode($program_type_filter); ?>&grade_level_filter=<?php echo urlencode($grade_level_filter); ?>" class="btn btn-sm <?php echo $i == $page ? 'btn-primary' : 'btn-outline'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?tab=programs&page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&program_search=<?php echo urlencode($program_search); ?>&program_type_filter=<?php echo urlencode($program_type_filter); ?>&grade_level_filter=<?php echo urlencode($grade_level_filter); ?>" class="btn btn-outline btn-sm">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <script>
                function toggleProgramSubjects(programId) {
                    const row = document.getElementById('subjects-row-' + programId);
                    const chevron = document.getElementById('chevron-' + programId);
                    if (row.style.display === 'none') {
                        row.style.display = 'table-row';
                        chevron.style.transform = 'rotate(90deg)';
                    } else {
                        row.style.display = 'none';
                        chevron.style.transform = 'rotate(0deg)';
                    }
                }
                </script>
            </div>
            
            <!-- Subjects Tab -->
            <div id="subjects-tab" class="tab-content <?php echo $active_tab === 'subjects' ? 'active' : ''; ?>">
                <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="toggleForm('subjects-form')">
                            <?php echo $edit_subject_data ? 'Edit Subject' : ($show_add_form ? 'Add Subject to Program' : '+ Add New Subject'); ?>
                        </button>
                        <button type="button" class="btn btn-info" onclick="openImportModal()">
                            📥 Import CSV
                        </button>
                        <form method="POST" action="" style="display:inline;">
                             <input type="hidden" name="action" value="export_subjects">
                             <?php if ($program_filter): ?>
                                <input type="hidden" name="program_filter" value="<?php echo htmlspecialchars($program_filter); ?>">
                             <?php endif; ?>
                             <button type="submit" class="btn btn-secondary">
                                📤 Export CSV
                             </button>
                        </form>
                        
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('⚠️ WARNING: Are you sure? \n\nThis will delete <?php echo $program_filter ? 'ALL subjects in this program' : 'ALL subjects in the system'; ?>. \n\nThis action cannot be undone.');">
                             <input type="hidden" name="action" value="delete_all_subjects">
                             <?php if ($program_filter): ?>
                                <input type="hidden" name="program_filter" value="<?php echo htmlspecialchars($program_filter); ?>">
                             <?php endif; ?>
                             <button type="submit" class="btn btn-danger">
                                🗑️ Delete All
                             </button>
                        </form>
                    </div>
                    
                    <!-- Subjects Search and Filter -->
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="tab" value="subjects">
                            <input type="text" name="subject_search" placeholder="Search subjects..." 
                                   value="<?php echo htmlspecialchars($subject_search); ?>" 
                                   style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
                            <select name="subject_program_filter" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Programs</option>
                                <?php foreach ($active_curriculum_programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>" <?php echo $subject_program_filter == $program['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($program['program_name'] . ' (' . $program['program_code'] . ')'); ?>
                                        </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary">Search</button>
                            <?php if (!empty($subject_search) || !empty($subject_program_filter)): ?>
                                    <a href="?tab=subjects" class="btn btn-outline">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div id="subjects-form" class="form-container" style="display: <?php echo ($edit_subject_data || $show_add_form) ? 'block' : 'none'; ?>;">
                    <h2 class="form-title"><?php echo $edit_subject_data ? 'Edit Subject' : ($show_add_form ? 'Add Subject to Program' : 'Add New Subject'); ?></h2>
                    
                    <?php if (empty($active_curriculum_programs)): ?>
                            <div class="alert alert-error">
                                <strong>No curriculum programs found!</strong> Please create a curriculum program first.
                                <button type="button" class="btn btn-primary" style="margin-left: 10px;" onclick="switchTab('programs')">Create Program</button>
                            </div>
                    <?php endif; ?>
                    
                    <form method="POST" <?php echo empty($active_curriculum_programs) ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
                        <input type="hidden" name="action" value="<?php echo $edit_subject_data ? 'edit_subject' : 'add_subject'; ?>">
                        <?php if ($edit_subject_data): ?>
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_subject_data['id']); ?>">
                        <?php endif; ?>
                        
                        <input type="hidden" id="program_id" name="program_id" value="<?php echo $show_add_form ? $program_filter : ($edit_subject_data['program_id'] ?? ''); ?>">
                        
                        <div class="form-row">
                            <div class="form-group subject-code">
                                <label for="subject_code">Subject Code *</label>
                                <input type="text" id="subject_code" name="subject_code" 
                                       value="<?php echo htmlspecialchars($edit_subject_data['subject_code'] ?? ''); ?>" 
                                       placeholder="e.g., MATH101" required>
                            </div>
                            <div class="form-group subject-name">
                                <label for="subject_name">Subject Name *</label>
                                <input type="text" id="subject_name" name="subject_name" 
                                       value="<?php echo htmlspecialchars($edit_subject_data['subject_name'] ?? ''); ?>" 
                                       placeholder="e.g., Mathematics" required>
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
                                    <button type="button" class="btn btn-secondary" onclick="toggleForm('subjects-form')">Cancel</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
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
                                                return (int) $p['id'] === (int) $program_filter; }));
                                            echo isset($pf[0]) ? htmlspecialchars($pf[0]['program_name'] . ' (' . $pf[0]['program_code'] . ')') : 'Unknown';
                                            ?>
                                            <a href="?tab=subjects" class="btn btn-secondary btn-sm" style="margin-left:10px;">Clear</a>
                                        </td>
                                    </tr>
                            <?php endif; ?>
                            <?php if (empty($curriculum_subjects)): ?>
                                    <tr>
                                        <td colspan="4" class="no-data">No curriculum subjects found. Create a curriculum program first, then add subjects to it.</td>
                                    </tr>
                            <?php else: ?>
                                    <?php
                                    $current_program_id = -1;
                                    $mapeh_header_shown = false;
                                    $mapeh_keywords = ['Music', 'Arts', 'Physical Education', 'Health', 'P.E.', 'PE', 'MAPEH'];
                                    
                                    foreach ($curriculum_subjects as $subject):
                                        // Detect if this is a MAPEH component
                                        $is_mapeh_component = false;
                                        foreach ($mapeh_keywords as $keyword) {
                                            if (stripos($subject['subject_name'], $keyword) !== false) {
                                                $is_mapeh_component = true;
                                                break;
                                            }
                                        }
                                        
                                        $is_parent_mapeh = (strtoupper(trim($subject['subject_name'])) === 'MAPEH');
                                        $should_indent = $is_mapeh_component && !$is_parent_mapeh;

                                        // Program Header logic
                                        if ($subject['program_id'] !== $current_program_id):
                                            $current_program_id = $subject['program_id'];
                                            $mapeh_header_shown = false; // Reset for new program
                                            ?>
                                                <tr style="background: #e0f2fe; border-top: 2px solid #0369a1;">
                                                    <td colspan="4" style="font-weight: 700; color: #0369a1; padding: 14px;">
                                                        📚 <?php echo htmlspecialchars($subject['program_name'] ?? 'General / Unassigned Subjects'); ?>
                                                        <?php if (!empty($subject['program_code'])): ?>
                                                                <span style="font-weight: 400; color: #64748b; font-size: 0.9em;">(<?php echo htmlspecialchars($subject['program_code']); ?>)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                        <?php endif; ?>

                                        <?php 
                                        // Virtual MAPEH Group Header (Only if MAPEH parent doesn't exist)
                                        if ($is_mapeh_component && !$mapeh_header_shown && !$is_parent_mapeh): 
                                            $mapeh_header_shown = true;
                                        ?>
                                            <tr style="background: #f8fafc; border-left: 4px solid #0369a1;">
                                                <td colspan="4" style="padding: 10px 15px 10px 25px; font-weight: 800; color: #0369a1; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; background: linear-gradient(to right, #f8fafc, #ffffff);">
                                                    <i class="fa fa-th-large" style="margin-right: 8px;"></i> MAPEH (Components)
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                            <tr <?php echo $is_mapeh_component ? 'style="background: #ffffff;"' : ''; ?>>
                                                <td style="<?php echo $should_indent ? 'padding-left: 45px; border-left: 4px solid #e2e8f0;' : ($is_parent_mapeh ? 'font-weight: 700; border-left: 4px solid #0369a1; background: #f8fafc;' : ''); ?>">
                                                    <?php if($should_indent): ?><span style="color: #cbd5e1; margin-right: 8px;">└</span><?php endif; ?>
                                                    <?php echo htmlspecialchars($subject['subject_code']); ?>
                                                </td>
                                                <td style="<?php echo $should_indent ? 'font-weight: 500; color: #334155;' : ($is_parent_mapeh ? 'font-weight: 700; color: #0369a1;' : ''); ?>">
                                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($subject['description']); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="?tab=subjects&edit_subject=<?php echo $subject['id']; ?>" class="btn btn-success btn-sm">Edit</a>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this subject from the program?')">
                                                            <input type="hidden" name="action" value="remove_subject">
                                                            <input type="hidden" name="id" value="<?php echo $subject['id']; ?>">
                                                            <?php if ($program_filter): ?>
                                                                    <input type="hidden" name="program_filter" value="<?php echo (int) $program_filter; ?>">
                                                            <?php endif; ?>
                                                            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                                        </form>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete this subject? This cannot be undone.')">
                                                            <input type="hidden" name="action" value="delete_subject">
                                                            <input type="hidden" name="id" value="<?php echo $subject['id']; ?>">
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
                            <label for="modalSubjectCode">Subject Code *</label>
                            <input type="text" id="modalSubjectCode" name="subject_code" placeholder="e.g., MATH101" required>
                        </div>
                        <div class="form-group">
                            <label for="modalSubjectName">Subject Name *</label>
                            <input type="text" id="modalSubjectName" name="subject_name" placeholder="e.g., Mathematics" required>
                        </div>
                        <div class="form-group">
                            <label for="modalDescription">Description</label>
                            <input type="text" id="modalDescription" name="description" placeholder="Subject description">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="addSubjectToList()">Add</button>
                    </div>
                    
                    <div id="subjectList" class="subject-list" style="display: none;">
                        <h4>Subjects to be added:</h4>
                        <div id="subjectsContainer"></div>
                    </div>
                    
                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="btn btn-secondary" onclick="closeSubjectModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveSubjects()" id="saveSubjectsBtn" style="display: none;">Save All Subjects</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Import Subjects (CSV)</h2>
                <button class="close" onclick="closeImportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_subjects">
                    
                    <div class="alert alert-success" style="background: #e0f2fe; border-color: #bae6fd; color: #0369a1;">
                        <small><strong>CSV Format:</strong> Subject Code, Subject Name, Description, Units, Grade Level, Semester</small><br>
                        <small><em>Row 1 is assumed to be a header and will be skipped.</em></small>
                    </div>

                    <div class="form-group">
                        <label for="import_program_id">Select Target Program (Optional but Recommended)</label>
                        <select name="program_id" id="import_program_id" style="width: 100%; padding: 10px;" required>
                            <option value="">-- Select Program --</option>
                             <?php foreach ($active_curriculum_programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>">
                                    <?php echo htmlspecialchars($program['program_name'] . ' (' . $program['program_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666;">If selected, subjects will be linked to this program.</small>
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label for="import_file">Choose CSV File</label>
                        <input type="file" name="import_file" id="import_file" accept=".csv" required style="padding: 10px; border: 1px solid #ddd; width: 100%;">
                    </div>
                    
                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="btn btn-secondary" onclick="closeImportModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload & Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Programs Modal -->
    <div id="importProgramsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Import Programs (CSV)</h2>
                <button class="close" onclick="closeImportProgramsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_programs">
                    
                    <div class="alert alert-success" style="background: #e0f2fe; border-color: #bae6fd; color: #0369a1;">
                        <small><strong>CSV Format:</strong> Program Code, Program Name, Type, Grades, Semester, Duration, Units, Description, Active (Yes/No)</small><br>
                        <small><em>Row 1 is assumed to be a header and will be skipped.</em></small>
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label for="import_program_file">Choose CSV File</label>
                        <input type="file" name="import_file" id="import_program_file" accept=".csv" required style="padding: 10px; border: 1px solid #ddd; width: 100%;">
                    </div>
                    
                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="btn btn-secondary" onclick="closeImportProgramsModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload & Import</button>
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
            document.getElementById('modalSubjectCode').value = '';
            document.getElementById('modalSubjectName').value = '';
            document.getElementById('modalDescription').value = '';
        }

        function addSubjectToList() {
            const code = document.getElementById('modalSubjectCode').value.trim();
            const name = document.getElementById('modalSubjectName').value.trim();
            const description = document.getElementById('modalDescription').value.trim();

            if (!code || !name) {
                alert('Please fill in Subject Code and Subject Name');
                return;
            }

            // Check for duplicates
            if (subjectsToAdd.some(subject => subject.code.toLowerCase() === code.toLowerCase())) {
                alert('Subject code already added to the list');
                return;
            }

            subjectsToAdd.push({
                code: code,
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

        function openImportModal() {
            document.getElementById('importModal').style.display = 'block';
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }

        function openImportProgramsModal() {
            document.getElementById('importProgramsModal').style.display = 'block';
        }

        function closeImportProgramsModal() {
            document.getElementById('importProgramsModal').style.display = 'none';
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
        window.onclick = function(event) {
            const modal = document.getElementById('subjectModal');
            if (event.target === modal) {
                closeSubjectModal();
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const programIdInput = document.getElementById('program_id');
            const gradeLevelSelect = document.getElementById('grade_level');
            const semesterSelect = document.getElementById('semester');
            const programTypeField = document.getElementById('program_type');
            const gradeLevelsField = document.getElementById('grade_levels');

            const allGradeOptions = Array.from(gradeLevelSelect ? gradeLevelSelect.options : []);

            function syncProgramGradeLevels() {
                if (!programTypeField || !gradeLevelsField) return;
                const programType = programTypeField.value;
                const allowed = ['','Grade 7','Grade 8','Grade 9','Grade 10'];
                Array.from(gradeLevelsField.options).forEach(opt => {
                    opt.disabled = !allowed.includes(opt.value);
                });
                if (!allowed.includes(gradeLevelsField.value)) {
                    gradeLevelsField.value = allowed[0];
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

                const targetGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

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
            }

            // Initialize on page load
            filterGradeLevels();
            syncProgramGradeLevels();

            // No need for program select listener since it's hidden
            if (programTypeField) programTypeField.addEventListener('change', syncProgramGradeLevels);
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
    </script>
</body>
</html>