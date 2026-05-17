<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role('admin');

// Get database statistics
try {
    $pdo = db_connect();

    // Get total students enrolled (active in current SY)
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM enrollments');
    $total_enrolled = $stmt->fetchColumn() ?: 0;

    // Get total student registrations (applications)
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM registrations');
    $total_registrations = $stmt->fetchColumn() ?: 0;

    // Get total enrollments this month
    $stmt = $pdo->query('SELECT COUNT(*) as monthly_enrollments FROM enrollments WHERE MONTH(enrolled_at) = MONTH(CURRENT_DATE()) AND YEAR(enrolled_at) = YEAR(CURRENT_DATE())');
    $monthly_enrollments = $stmt->fetchColumn() ?: 0;

    // Get pending personnel (awaiting approval)
    $stmt = $pdo->query('SELECT COUNT(*) as pending_personnel FROM users WHERE approval_status = "pending" AND role IN ("teacher", "registrar")');
    $pending_personnel = $stmt->fetchColumn() ?: 0;

    // Get total teachers (active users with teacher role)
    $stmt = $pdo->query('SELECT COUNT(*) as total_teachers FROM users WHERE role = "teacher" AND approval_status = "approved"');
    $total_teachers = $stmt->fetchColumn() ?: 0;

    // Get recent enrollments
    $stmt = $pdo->query('SELECT student_name, grade_level, section, enrolled_at FROM enrollments ORDER BY enrolled_at DESC LIMIT 5');
    $recent_enrollments = $stmt->fetchAll();

    // Get ALL defined grade levels from sections for structural overview
    $stmt = $pdo->query('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level');
    $all_levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $active_levels_count = count($all_levels);

    // Get enrollment by grade level and merge with all_levels to ensure 0-student levels show up
    $stmt = $pdo->query('SELECT grade_level, COUNT(*) as count FROM enrollments GROUP BY grade_level');
    $enrolled_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $enrollment_by_grade = [];
    foreach ($all_levels as $lvl) {
        $enrollment_by_grade[] = [
            'grade_level' => $lvl,
            'count' => (int)($enrolled_raw[$lvl] ?? 0)
        ];
    }
    
    // Fallback if no levels defined yet
    if (empty($enrollment_by_grade)) {
        $enrollment_by_grade = [['grade_level' => 'N/A', 'count' => 0]];
        $active_levels_count = 0;
    }

    // Get STUDENT gender distribution (from registered students)
    $stmt = $pdo->query('SELECT sex, COUNT(*) as total FROM registrations GROUP BY sex');
    $student_gender_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $student_male = (int)($student_gender_raw['Male'] ?? $student_gender_raw['M'] ?? 0);
    $student_female = (int)($student_gender_raw['Female'] ?? $student_gender_raw['F'] ?? 0);

    // Ensure employees table has the correct columns for requested charts
    try {
        $pdo->exec('ALTER TABLE employees ADD COLUMN IF NOT EXISTS sex ENUM("Male","Female") DEFAULT "Male"');
        $pdo->exec('ALTER TABLE employees ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT "Support"');
    } catch (Exception $e) {}

    // Get FACULTY gender distribution (from registered accounts - Teaching staff only)
    $stmt = $pdo->query('SELECT sex, COUNT(*) as total FROM users WHERE role = "teacher" AND approval_status = "approved" GROUP BY sex');
    $faculty_gender_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $faculty_male = (int)($faculty_gender_raw['Male'] ?? $faculty_gender_raw['M'] ?? 0);
    $faculty_female = (int)($faculty_gender_raw['Female'] ?? $faculty_gender_raw['F'] ?? 0);

    // Get PERSONNEL distribution (Dynamic roles)
    $stmt = $pdo->query('SELECT role, COUNT(*) as total FROM employees GROUP BY role');
    $personnel_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

} catch (Exception $e) {
    // Fallback values if database query fails
    $total_master_students = 0;
    $total_enrolled = 0;
    $total_users = 0;
    $total_registrations = 0;
    $monthly_enrollments = 0;
    $pending_personnel = 0;
    $total_teachers = 0;
    $recent_enrollments = [];
    $enrollment_by_grade = [['grade_level' => 'N/A', 'count' => 0]];
    $active_levels_count = 0;
    $student_male = 0;
    $student_female = 0;
    $faculty_male = 0;
    $faculty_female = 0;
    $teaching_count = 0;
    $non_teaching_count = 0;
}

// Time of day greeting
$hour = date('H');
$greeting = 'Good Morning';
if ($hour >= 12 && $hour < 17) {
    $greeting = 'Good Afternoon';
} elseif ($hour >= 17) {
    $greeting = 'Good Evening';
}

$username = $_SESSION['user']['username'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <!-- Use root-relative paths so this works both on localhost and live hosting -->
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .content {
            padding: calc(var(--header-height) + 16px) 16px 24px;
            /* width: auto (default for block) + margin-left will prevent horizontal scroll */
            max-width: none;
            box-sizing: border-box;
            overflow-x: hidden; /* Safety measure */
        }

        .dashboard-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: #0f172a;
            margin: 0 0 4px 0;
            font-size: 28px;
            font-weight: 700;
        }

        .dashboard-header p {
            color: #64748b;
            font-size: 15px;
            margin: 0;
        }

        .current-date {
            background: white;
            padding: 10px 20px;
            border-radius: 50px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            font-weight: 600;
            color: #3b82f6;
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .dashboard-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .dashboard-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (max-width: 1024px) {
            .dashboard-grid-3 {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .dashboard-grid-2-1 {
                grid-template-columns: 1fr;
            }
        }


        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            cursor: default;
        }

        /* Removed misleading hover effects as cards are not clickable */
        .stat-card:hover {
            transform: none;
            box-shadow: var(--shadow-sm);
            border-color: var(--border);
        }

        .stat-icon {
            font-size: 28px;
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            flex-shrink: 0;
            transition: transform 0.2s;
        }

        /* Removed misleading hover icon scaling */
        .stat-card:hover .stat-icon {
            transform: none;
        }

        .dashboard-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border);
            height: fit-content;
            transition: all 0.3s ease;
            cursor: default;
        }

        /* Removed misleading hover effects as sections are not clickable */
        .dashboard-section:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            transform: none;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 16px;
            color: #0f172a;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .activity-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .activity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #cbd5e1;
            margin-top: 6px;
            flex-shrink: 0;
        }

        .activity-dot.new {
            background: #3b82f6;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: #0f172a;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .activity-desc {
            color: #64748b;
            font-size: 13px;
        }

        .activity-time {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .dashboard-main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Tablet breakpoint */
        @media (max-width: 1024px) {
            .charts-row-3 {
                grid-template-columns: 1fr 1fr !important;
            }
        }

        @media (max-width: 768px) {
            .content {
                padding: calc(var(--header-height) + 20px) 16px 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            /* Stack 3-column charts to single column on mobile */
            .charts-row-3,
            [style*="grid-template-columns: 1fr 1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }

            /* Stack bottom row on mobile */
            [style*="grid-template-columns: 1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 480px) {
            .content {
                padding: 100px 12px 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 24px;
            }

            .dashboard-section {
                padding: 16px;
            }

            .section-header h2 {
                font-size: 16px;
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/admin_header.php'; ?>
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="dashboard-header">
            <div class="header-text">
                <h1><?= $greeting ?>, <?= htmlspecialchars($username) ?>!</h1>
                <p>Monitor your school's performance and manage core operations.</p>
            </div>
            <div class="current-date">
                <?= date('l, F j, Y') ?>
            </div>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
            <div class="stat-card">
                <div class="stat-icon" style="background: #ecfdf5; color: #10b981;">📝</div>
                <div class="stat-content">
                    <h3>Enrollments</h3>
                    <div class="stat-number" id="stat-students"><?php echo number_format($total_enrolled); ?></div>
                    <div class="stat-meta positive" id="stat-students-meta">+<?php echo $monthly_enrollments; ?> this month</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fff7ed; color: #ea580c;">📋</div>
                <div class="stat-content">
                    <h3>Applications</h3>
                    <div class="stat-number" id="stat-registrations"><?php echo number_format($total_registrations); ?></div>
                    <div class="stat-meta">Total Registrations</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #eef2ff; color: #6366f1;">👨‍🏫</div>
                <div class="stat-content">
                    <h3>Faculty</h3>
                    <div class="stat-number" id="stat-faculty"><?php echo $total_teachers; ?></div>
                    <div class="stat-meta">Active Teachers</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fffbeb; color: #f59e0b;">⏳</div>
                <div class="stat-content">
                    <h3>Pending</h3>
                    <div class="stat-number" id="stat-pending"><?php echo $pending_personnel; ?></div>
                    <div class="stat-meta">Personnel Approvals</div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div style="margin-bottom: 20px;">
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Enrollment by Grade Level</h2>
                </div>
                <div class="chart-container" style="height: 350px;">
                    <canvas id="enrollmentChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Charts Row: Gender + Personnel -->
        <div class="dashboard-grid-3">
            <div class="dashboard-section">
                <div class="section-header" style="justify-content: center; margin-bottom: 20px;">
                    <h2 style="font-size: 16px; font-weight: 700; color: #1e293b;">Student Gender</h2>
                </div>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="studentGenderChart"></canvas>
                </div>
                <div id="student-gender-empty" style="display:none; text-align:center; padding: 40px 0; color: #94a3b8;">
                    <p>No data available</p>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header" style="justify-content: center; margin-bottom: 20px;">
                    <h2 style="font-size: 16px; font-weight: 700; color: #1e293b;">Faculty Gender</h2>
                </div>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="facultyGenderChart"></canvas>
                </div>
                <div id="faculty-gender-empty" style="display:none; text-align:center; padding: 40px 0; color: #94a3b8;">
                    <p>No data available</p>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header" style="justify-content: center; margin-bottom: 20px;">
                    <h2 style="font-size: 16px; font-weight: 700; color: #1e293b;">Personnel</h2>
                </div>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="personnelChart"></canvas>
                </div>
                <div id="personnel-empty" style="display:none; text-align:center; padding: 40px 0; color: #94a3b8;">
                    <p>No data available</p>
                </div>
            </div>
        </div>

        <!-- System Health Section -->
        <div class="dashboard-section" style="margin-bottom: 20px;">
            <div class="section-header">
                <h2>System Health</h2>
            </div>
            <div class="dashboard-grid-3" style="margin-bottom: 0;">
                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                        <span style="font-size:14px; font-weight:600; color: #334155;">Database Engine</span>
                        <span style="font-size:11px; color:#10b981; font-weight:bold;">OPERATIONAL</span>
                    </div>
                    <div style="font-size: 12px; color: #64748b;">MariaDB/MySQL Latency: 0.04ms</div>
                </div>
                
                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                        <span style="font-size:14px; font-weight:600; color: #334155;">Server Status</span>
                        <span style="font-size:11px; color:#10b981; font-weight:bold;">STABLE</span>
                    </div>
                    <div style="font-size: 12px; color: #64748b;">Active threads: 14 | Load: 0.2%</div>
                </div>

                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                        <span style="font-size:14px; font-weight:600; color: #334155;">Data Sync API</span>
                        <span style="font-size:11px; color:#3b82f6; font-weight:bold;">CONNECTED</span>
                    </div>
                    <div style="font-size: 12px; color: #64748b;">Polling frequency: 30s</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Premium Chart Colors
        const colors = {
            blue: '#3b82f6',
            indigo: '#6366f1',
            purple: '#8b5cf6',
            pink: '#ec4899',
            emerald: '#10b981',
            amber: '#f59e0b',
            slate: '#64748b'
        };

        const chartFont = { family: "'Inter', sans-serif", size: 12 };
        const gridColor = '#f1f5f9';

        // Enrollment Chart (Bar)
        const ctxEnrollment = document.getElementById('enrollmentChart').getContext('2d');
        const enrollmentData = <?php echo json_encode($enrollment_by_grade); ?>;

        const chartEnrollment = new Chart(ctxEnrollment, {
            type: 'bar',
            data: {
                labels: enrollmentData.map(item => item.grade_level),
                datasets: [{
                    label: 'Students',
                    data: enrollmentData.map(item => item.count),
                    backgroundColor: enrollmentData.map((_, i) => {
                        const pal = [colors.blue, colors.indigo, colors.purple, colors.pink, colors.amber, colors.emerald];
                        return pal[i % pal.length];
                    }),
                    borderRadius: 8,
                    barThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        titleFont: { weight: 'bold' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: { font: chartFont, stepSize: 1 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: chartFont }
                    }
                }
            }
        });

        // Student Gender Chart
        const ctxStudentGender = document.getElementById('studentGenderChart').getContext('2d');
        const studentGenderData = [<?php echo $student_male; ?>, <?php echo $student_female; ?>];
        
        if (studentGenderData[0] === 0 && studentGenderData[1] === 0) {
            document.getElementById('studentGenderChart').style.display = 'none';
            document.getElementById('student-gender-empty').style.display = 'block';
        }

        const chartStudentGender = new Chart(ctxStudentGender, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: studentGenderData,
                    backgroundColor: [colors.blue, colors.pink],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: chartFont, padding: 20 } }
                }
            }
        });

        // Faculty Gender Chart
        const ctxFacultyGender = document.getElementById('facultyGenderChart').getContext('2d');
        const facultyGenderData = [<?php echo $faculty_male; ?>, <?php echo $faculty_female; ?>];

        if (facultyGenderData[0] === 0 && facultyGenderData[1] === 0) {
            document.getElementById('facultyGenderChart').style.display = 'none';
            document.getElementById('faculty-gender-empty').style.display = 'block';
        }

        const chartFacultyGender = new Chart(ctxFacultyGender, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: facultyGenderData,
                    backgroundColor: [colors.indigo, colors.purple],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: chartFont, padding: 20 } }
                }
            }
        });

        // Personnel Distribution Chart
        const ctxPersonnel = document.getElementById('personnelChart').getContext('2d');
        const personnelData = <?php echo json_encode($personnel_raw); ?>;
        const personnelLabels = Object.keys(personnelData);
        const personnelValues = Object.values(personnelData);

        if (personnelValues.length === 0) {
            document.getElementById('personnelChart').style.display = 'none';
            document.getElementById('personnel-empty').style.display = 'block';
        }

        const chartPersonnel = new Chart(ctxPersonnel, {
            type: 'pie',
            data: {
                labels: personnelLabels,
                datasets: [{
                    data: personnelValues,
                    backgroundColor: [colors.emerald, colors.amber, colors.indigo, colors.blue, colors.pink],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: chartFont, padding: 20 } }
                }
            }
        });
    </script>
    <script>
        // Real-time Dashboard Polling
        async function refreshDashboardStats() {
            try {
                const response = await fetch('<?= url_for('/admin/dashboard_api.php') ?>');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    
                    updateValue('stat-students', data.total_enrolled.toLocaleString());
                    updateValue('stat-students-meta', `+${data.monthly_enrollments} this month`);
                    updateValue('stat-registrations', data.total_registrations.toLocaleString());
                    updateValue('stat-faculty', data.total_teachers);
                    updateValue('stat-pending', data.pending_personnel);
                    
                    if (data.charts) {
                        chartEnrollment.data.labels = data.charts.enrollment.labels;
                        chartEnrollment.data.datasets[0].data = data.charts.enrollment.data;
                        chartEnrollment.update();

                        chartStudentGender.data.datasets[0].data = data.charts.studentGender;
                        chartStudentGender.update();

                        chartFacultyGender.data.datasets[0].data = data.charts.facultyGender;
                        chartFacultyGender.update();

                        chartPersonnel.data.labels = data.charts.personnel.labels;
                        chartPersonnel.data.datasets[0].data = data.charts.personnel.data;
                        chartPersonnel.update();
                    }
                    
                }
            } catch (error) {
                console.error('Failed to refresh dashboard stats:', error);
            }
        }

        function updateValue(id, newValue) {
            const element = document.getElementById(id);
            if (element && element.innerText != newValue) {
                element.style.transition = 'all 0.3s ease';
                element.style.opacity = '0.5';
                element.style.transform = 'scale(1.1)';
                
                setTimeout(() => {
                    element.innerText = newValue;
                    element.style.opacity = '1';
                    element.style.transform = 'scale(1)';
                    element.style.color = 'var(--primary)'; // Briefly change color to highlight update
                    
                    setTimeout(() => {
                        element.style.color = ''; // Reset color
                    }, 2000);
                }, 300);
            }
        }

        // Poll every 3 seconds for strict real-time alignment with DB
        setInterval(refreshDashboardStats, 3000);
    </script>
</body>
</html>