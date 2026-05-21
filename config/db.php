<?php
// Simple PDO connection with auto-initialize of database and tables

if (!function_exists('db_connect')) {
	function db_connect(): PDO
	{
		// Use global constants if defined (from app.php/hosting.php), otherwise fallback to environment-specific defaults
		$host = defined('DB_HOST') ? DB_HOST : "127.0.0.1";
		$dbname = defined('DB_NAME') ? DB_NAME : "u957255050_db_marine_reg";
		$user = defined('DB_USER') ? DB_USER : "u957255050_marine_reg";
		$pass = defined('DB_PASS') ? DB_PASS : "M~rphsx7!+/5";

		// Environment-specific overrides if constants aren't set (Legacy/Cli support)
		if (!defined('DB_HOST')) {
			if (php_sapi_name() === 'cli' || (isset($_SERVER['SERVER_NAME']) && ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1'))) {
				$host = "127.0.0.1";
				$dbname = "sampleweb";
				$user = "root";
				$pass = "";
			} else {
				$host = "127.0.0.1";
				$dbname = "u957255050_db_marine_reg";
				$user = "u957255050_marine_reg";
				$pass = "M~rphsx7!+/5";
			}
		}

		$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

		$pdo = new PDO($dsn, $user, $pass, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]);

		// Auto-migration for SF2 summary columns if missing
		try {
			$check = $pdo->query("SHOW COLUMNS FROM sf2_monthly_summary LIKE 'ada_male'")->fetch();
			if (!$check) {
				$pdo->exec("ALTER TABLE sf2_monthly_summary 
					ADD COLUMN ada_male DECIMAL(8,2) DEFAULT 0.00 AFTER average_daily_attendance,
					ADD COLUMN ada_female DECIMAL(8,2) DEFAULT 0.00 AFTER ada_male,
					ADD COLUMN perc_male DECIMAL(5,2) DEFAULT 0.00 AFTER percentage_attendance,
					ADD COLUMN perc_female DECIMAL(5,2) DEFAULT 0.00 AFTER perc_male");
			}
			
			// Ensure sf2_student_records exists
			$pdo->exec("CREATE TABLE IF NOT EXISTS sf2_student_records (
				id INT AUTO_INCREMENT PRIMARY KEY,
				sf2_report_id INT NOT NULL,
				student_id VARCHAR(50) NOT NULL,
				student_name VARCHAR(200) NOT NULL,
				sex ENUM('M', 'F') NOT NULL,
				total_absent INT DEFAULT 0,
				total_present INT DEFAULT 0,
				remarks VARCHAR(500) NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				FOREIGN KEY (sf2_report_id) REFERENCES sf2_reports(id) ON DELETE CASCADE,
				KEY idx_sf2_report_id (sf2_report_id),
				KEY idx_student_id (student_id)
			) ENGINE=InnoDB");

			// Fix attendance_status enum to varchar
			$checkAtt = $pdo->query("DESCRIBE sf2_daily_attendance attendance_status")->fetch();
			if ($checkAtt && strpos(strtolower($checkAtt['Type']), 'enum') !== false) {
				$pdo->exec("ALTER TABLE sf2_daily_attendance MODIFY COLUMN attendance_status VARCHAR(10) NOT NULL");
			}

			// Initialize new attendance system tables
			$pdo->exec("CREATE TABLE IF NOT EXISTS school_calendar (
				id INT AUTO_INCREMENT PRIMARY KEY,
				event_date DATE NOT NULL UNIQUE,
				event_name VARCHAR(200) NULL,
				event_type ENUM('Holiday', 'Suspended', 'School Day', 'Event') DEFAULT 'School Day',
				is_school_day TINYINT(1) DEFAULT 1,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB");

			$pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
				id INT AUTO_INCREMENT PRIMARY KEY,
				student_id VARCHAR(50) NOT NULL,
				attendance_date DATE NOT NULL,
				status VARCHAR(10) NOT NULL,
				school_year_id INT NOT NULL,
				section_id INT NOT NULL,
				remarks TEXT NULL,
				updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				UNIQUE KEY unique_attendance (student_id, attendance_date),
				KEY idx_student (student_id),
				KEY idx_date (attendance_date),
				KEY idx_section_sy (section_id, school_year_id)
			) ENGINE=InnoDB");

		} catch (Exception $e) {
			// Silently fail if table doesn't exist or other issues, to avoid blocking connection
		}

		return $pdo;
	}
}

if (!function_exists('generateEmployeeCode')) {
	/**
	 * Generates a unique employee code in the format EMP-YYYY-XXX
	 */
	function generateEmployeeCode(PDO $pdo)
	{
		$year = date('Y');
		$stmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_code LIKE ? ORDER BY id DESC LIMIT 1");
		$stmt->execute(["EMP-$year-%"]);
		$lastCode = $stmt->fetchColumn();

		if ($lastCode) {
			$parts = explode('-', $lastCode);
			$num = (int) end($parts);
			$newNum = str_pad($num + 1, 3, '0', STR_PAD_LEFT);
		} else {
			$newNum = '001';
		}

		$newCode = "EMP-$year-$newNum";

		// Double check for collisions
		$check = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ?");
		$check->execute([$newCode]);
		if ($check->fetchColumn() > 0) {
			// If collision, just append a random string or try next number
			$newNum = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
			$newCode = "EMP-$year-$newNum-" . rand(10, 99);
		}

		return $newCode;
	}
}

if (!function_exists('syncEmployeeFromUser')) {
	/**
	 * Centralized function to sync user account data to the employees table.
	 */
	function syncEmployeeFromUser(PDO $pdo, $user_id)
	{
		try {
			// Fetch fresh user data
			$stmt = $pdo->prepare("SELECT id, first_name, last_name, role, email, approval_status FROM users WHERE id = ?");
			$stmt->execute([$user_id]);
			$user = $stmt->fetch();

			if (!$user) return false;

			$full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
			if (empty($full_name)) $full_name = "User #{$user_id}";

			$is_active = ($user['approval_status'] === 'approved') ? 1 : 0;

			$dept = null;
			$spec = null;
			if ($user['role'] === 'teacher') {
				$t_stmt = $pdo->prepare("SELECT department, specialization FROM teachers WHERE user_id = ?");
				$t_stmt->execute([$user_id]);
				$teacher = $t_stmt->fetch();
				if ($teacher) {
					$dept = $teacher['department'];
					$spec = $teacher['specialization'];
				}
			}

			$pos = $spec ?: (($user['role'] === 'teacher') ? 'Teacher' : (($user['role'] === 'registrar') ? 'Registrar' : ucfirst($user['role'])));

			$check = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
			$check->execute([$user_id]);
			$employee = $check->fetch();

			if ($employee) {
				$upt = $pdo->prepare("UPDATE employees SET full_name = ?, email = ?, department = ?, position_title = ?, is_active = ?, updated_at = NOW() WHERE user_id = ?");
				$upt->execute([$full_name, $user['email'], $dept, $pos, $is_active, $user_id]);
			} else {
				$code = generateEmployeeCode($pdo);
				$ins = $pdo->prepare("INSERT INTO employees (user_id, employee_code, full_name, email, department, position_title, date_hired, is_active) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
				$ins->execute([$user_id, $code, $full_name, $user['email'], $dept, $pos, $is_active]);
			}

			return true;
		} catch (Exception $e) {
			error_log("Failed to sync employee for user {$user_id}: " . $e->getMessage());
			return false;
		}
	}
}

if (!function_exists('get_current_school_year')) {
	function get_current_school_year(PDO $pdo)
	{
		$stmt = $pdo->query("SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1");
		return $stmt->fetchColumn() ?: date('Y') . '-' . (date('Y') + 1);
	}
}

if (!function_exists('initialize_schema')) {
	function initialize_schema(PDO $pdo)
	{
		$pdo->exec('CREATE TABLE IF NOT EXISTS users (
		id INT AUTO_INCREMENT PRIMARY KEY,
		username VARCHAR(100) NOT NULL UNIQUE,
		password_hash VARCHAR(255) NOT NULL,
		role ENUM("admin","registrar","teacher","student","employee") DEFAULT "teacher",
		email VARCHAR(150) NULL UNIQUE,
		profile_photo VARCHAR(255) NULL,
		last_activity TIMESTAMP NULL,
		approval_status ENUM("pending","approved","rejected") DEFAULT "pending",
		approved_by INT NULL,
		approved_at TIMESTAMP NULL,
		FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS school_years (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL UNIQUE,
		is_current TINYINT(1) DEFAULT 0,
		is_archived TINYINT(1) DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB');

		$stmt_sy = $pdo->query('SELECT COUNT(*) FROM school_years');
		if ($stmt_sy && $stmt_sy->fetchColumn() == 0) {
			$current_year = date('Y');
			$sy = $current_year . "-" . ($current_year + 1);
			$pdo->prepare("INSERT INTO school_years (school_year, is_current) VALUES (?, 1)")->execute([$sy]);
		}

		$pdo->exec('CREATE TABLE IF NOT EXISTS enrollments (
		id INT AUTO_INCREMENT PRIMARY KEY,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL
	) ENGINE=InnoDB');

		// Add new columns to existing enrollments table if they don't exist
		// Using individual try-catch for better compatibility with different MySQL/MariaDB versions
		$alters = [
			'ALTER TABLE enrollments ADD COLUMN registration_id INT NULL',
			'ALTER TABLE enrollments ADD COLUMN enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
			'ALTER TABLE enrollments ADD COLUMN photo_path VARCHAR(255) NULL',
			'ALTER TABLE enrollments ADD COLUMN school_year VARCHAR(20) NULL',
			'ALTER TABLE enrollments ADD COLUMN lrn VARCHAR(12) NULL',
			'ALTER TABLE enrollments ADD COLUMN birthdate DATE NULL',
			'ALTER TABLE enrollments ADD COLUMN guardian_first VARCHAR(100) NULL',
			'ALTER TABLE enrollments ADD COLUMN guardian_last VARCHAR(100) NULL',
			'ALTER TABLE enrollments ADD COLUMN guardian_contact VARCHAR(50) NULL',
			'ALTER TABLE enrollments ADD COLUMN address VARCHAR(255) NULL',
			'ALTER TABLE enrollments ADD COLUMN id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian"',
			'ALTER TABLE enrollments ADD COLUMN status ENUM("Enrolled","Transferred In","Transferred Out","Dropped","Promoted","Retained") DEFAULT "Enrolled"',
			'ALTER TABLE enrollments ADD COLUMN qr_code_path VARCHAR(255) NULL',
			'ALTER TABLE enrollments ADD COLUMN status_date DATE NULL'
		];

		foreach ($alters as $sql) {
			try {
				$pdo->exec($sql);
			} catch (Exception $e) {
				// Column likely already exists
			}
		}

		// Add foreign key constraint if it doesn't exist
		try {
			$pdo->exec('ALTER TABLE enrollments ADD CONSTRAINT fk_enrollment_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL');
		} catch (PDOException $e) {
			// Foreign key might already exist, ignore error
		}

		// Registrations table for comprehensive learner registration data
		$pdo->exec('CREATE TABLE IF NOT EXISTS registrations (
		id INT AUTO_INCREMENT PRIMARY KEY,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		-- school year and enrollment
		school_year_start SMALLINT NULL,
		school_year_end SMALLINT NULL,
		grade_level_to_enroll VARCHAR(50) NULL,
		with_lrn TINYINT(1) NOT NULL DEFAULT 0,
		is_returning TINYINT(1) NOT NULL DEFAULT 0,
		-- learner info
		psa_birth_cert_no VARCHAR(64) NULL,
		lrn VARCHAR(12) NULL,
		last_name VARCHAR(100) NOT NULL,
		first_name VARCHAR(100) NOT NULL,
		middle_name VARCHAR(100) NULL,
		ext_name VARCHAR(20) NULL,
		birthdate DATE NULL,
		sex VARCHAR(10) NULL,
		age SMALLINT NULL,
		birthplace_city VARCHAR(100) NULL,
		birthplace_province VARCHAR(100) NULL,
		mother_tongue VARCHAR(100) NULL,
		is_ip VARCHAR(10) NULL,
		ip_ethnicity VARCHAR(100) NULL,
		is_4ps_beneficiary TINYINT(1) NOT NULL DEFAULT 0,
		four_ps_household_id VARCHAR(50) NULL,
		is_pwd TINYINT(1) NOT NULL DEFAULT 0,
		disability_types VARCHAR(255) NULL,
		-- current address
		curr_house_no VARCHAR(50) NULL,
		curr_street VARCHAR(100) NULL,
		curr_barangay VARCHAR(100) NULL,
		curr_city VARCHAR(100) NULL,
		curr_province VARCHAR(100) NULL,
		curr_country VARCHAR(100) NULL,
		curr_zip VARCHAR(20) NULL,
		-- permanent address
		perm_same_as_current TINYINT(1) NOT NULL DEFAULT 0,
		perm_house_no VARCHAR(50) NULL,
		perm_street VARCHAR(100) NULL,
		perm_barangay VARCHAR(100) NULL,
		perm_city VARCHAR(100) NULL,
		perm_province VARCHAR(100) NULL,
		perm_country VARCHAR(100) NULL,
		perm_zip VARCHAR(20) NULL,
		-- parents/guardians
		father_last VARCHAR(100) NULL,
		father_first VARCHAR(100) NULL,
		father_middle VARCHAR(100) NULL,
		father_contact VARCHAR(50) NULL,
		mother_last VARCHAR(100) NULL,
		mother_first VARCHAR(100) NULL,
		mother_middle VARCHAR(100) NULL,
		mother_contact VARCHAR(50) NULL,
		guardian_last VARCHAR(100) NULL,
		guardian_first VARCHAR(100) NULL,
		guardian_middle VARCHAR(100) NULL,
		guardian_contact VARCHAR(50) NULL,
		id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian",
		-- returnees/transferees
		last_grade_completed VARCHAR(50) NULL,
		last_sy_completed VARCHAR(20) NULL,
		last_school_attended VARCHAR(150) NULL,
		last_school_id VARCHAR(50) NULL,
		-- senior high
		semester VARCHAR(10) NULL,
		track VARCHAR(100) NULL,
		strand VARCHAR(100) NULL,
		-- distance learning
		preferred_modalities VARCHAR(255) NULL,
		-- approval status
		approval_status ENUM("pending","approved","rejected") DEFAULT "pending",
		approved_by INT NULL,
		approved_at TIMESTAMP NULL,
		rejection_reason TEXT NULL,
		KEY idx_created_at (created_at),
		UNIQUE KEY idx_lrn (lrn),
		KEY idx_lastname (last_name),
		KEY idx_grade_to_enroll (grade_level_to_enroll),
		KEY idx_approval_status (approval_status),
		FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		// Enforce unique LRN on existing registrations table (if index was previously non-unique)
		try {
			$pdo->exec('ALTER TABLE registrations DROP INDEX idx_lrn');
			$pdo->exec('ALTER TABLE registrations ADD UNIQUE INDEX idx_lrn (lrn)');
		} catch (PDOException $e) {
			// Already unique or doesn't exist, ignore
		}

		// Create subjects table
		$pdo->exec('CREATE TABLE IF NOT EXISTS subjects (
		id INT AUTO_INCREMENT PRIMARY KEY,
		subject_code VARCHAR(20) NOT NULL UNIQUE,
		subject_name VARCHAR(200) NOT NULL,
		description TEXT NULL,
		grade_level VARCHAR(50) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) ENGINE=InnoDB');

		// Create employees table (non-teaching and staff)
		$pdo->exec('CREATE TABLE IF NOT EXISTS employees (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NULL,
		employee_code VARCHAR(20) NOT NULL UNIQUE,
		full_name VARCHAR(200) NOT NULL,
		email VARCHAR(150) NULL UNIQUE,
		contact_number VARCHAR(20) NULL UNIQUE,
		department VARCHAR(100) NULL,
		position_title VARCHAR(150) NULL,
		date_hired DATE NULL,
		is_active TINYINT(1) DEFAULT 1,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
		KEY idx_user_id (user_id),
		KEY idx_department (department),
		KEY idx_is_active (is_active)
	) ENGINE=InnoDB');

		// Create SF8 Health Profile table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf8_health_profile (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		weight_kg DECIMAL(5,2) NULL,
		height_m DECIMAL(4,2) NULL,
		bmi DECIMAL(5,2) NULL,
		nutritional_status VARCHAR(50) NULL,
		hfa VARCHAR(50) DEFAULT "Normal",
		vision_screening VARCHAR(100) NULL,
		is_dewormed TINYINT(1) DEFAULT 0,
		has_condition TINYINT(1) DEFAULT 0,
		condition_remarks TEXT NULL,
		measurement_date DATE NULL,
		school_year VARCHAR(20) NOT NULL,
		UNIQUE KEY idx_student_sy (student_id, school_year)
	) ENGINE=InnoDB');

		// Create SF8 Reports Tracking table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf8_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		status ENUM("Draft", "For Validation", "Validated", "Finalized") DEFAULT "Draft",
		submitted_by INT NULL,
		submitted_at TIMESTAMP NULL,
		validated_by INT NULL,
		validated_at TIMESTAMP NULL,
		finalized_by INT NULL,
		finalized_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY idx_sy_grade_section (school_year, grade_level, section)
	) ENGINE=InnoDB');

		// Ensure hfa and condition_remarks column exist for older databases
		try {
			$pdo->exec("ALTER TABLE sf8_health_profile ADD COLUMN hfa VARCHAR(50) DEFAULT 'Normal' AFTER nutritional_status");
		} catch (Exception $e) { /* ignore */ }
		try {
			$pdo->exec("ALTER TABLE sf8_health_profile ADD COLUMN condition_remarks TEXT NULL AFTER has_condition");
		} catch (Exception $e) { /* ignore */ }

		$user_alters = [
			'ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL',
			'ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL',
			'ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) NULL',
			'ALTER TABLE users ADD COLUMN role ENUM("admin","registrar","teacher","student","employee") DEFAULT "teacher"',
			'ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL',
			'ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL',
			'ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL',
			'ALTER TABLE users ADD COLUMN approval_status ENUM("pending","approved","rejected") DEFAULT "pending"',
			'ALTER TABLE users ADD COLUMN approved_by INT NULL',
			'ALTER TABLE users ADD COLUMN approved_at TIMESTAMP NULL',
			'ALTER TABLE users ADD COLUMN registered_role ENUM("admin","registrar","teacher","student","employee") NULL',
			'ALTER TABLE users ADD COLUMN department VARCHAR(100) NULL',
			'ALTER TABLE users ADD COLUMN user_status ENUM("active","inactive") DEFAULT "active"',
			'ALTER TABLE users ADD COLUMN sex VARCHAR(10) NULL',
			'ALTER TABLE users ADD COLUMN e_signature VARCHAR(255) NULL'
		];
		foreach ($user_alters as $sql) {
			try {
				$pdo->exec($sql);
			} catch (Exception $e) { /* ignore */
			}
		}

		// Backfill registered_role if null
		try {
			$pdo->exec('UPDATE users SET registered_role = role WHERE registered_role IS NULL');
		} catch (Exception $e) { /* ignore */
		}

		// Add foreign key constraint for approved_by if it doesn't exist
		try {
			$pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL');
		} catch (PDOException $e) {
			// Foreign key might already exist, ignore error
		}

		// Add approval status columns to existing registrations table if they don't exist
		$reg_alters = [
			'ALTER TABLE registrations ADD COLUMN approval_status ENUM("pending","approved","rejected") DEFAULT "pending"',
			'ALTER TABLE registrations ADD COLUMN approved_by INT NULL',
			'ALTER TABLE registrations ADD COLUMN approved_at TIMESTAMP NULL',
			'ALTER TABLE registrations ADD COLUMN rejection_reason TEXT NULL',
			'ALTER TABLE registrations ADD COLUMN section VARCHAR(100) NULL',
			'ALTER TABLE registrations ADD COLUMN guardian_relationship VARCHAR(100) NULL',
			'ALTER TABLE registrations ADD COLUMN religion VARCHAR(100) NULL'
		];
		foreach ($reg_alters as $sql) {
			try {
				$pdo->exec($sql);
			} catch (Exception $e) { /* ignore */
			}
		}

		// Add foreign key constraint for registrations approved_by if it doesn't exist
		try {
			$pdo->exec('ALTER TABLE registrations ADD CONSTRAINT fk_registrations_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL');
		} catch (PDOException $e) {
			// Foreign key might already exist, ignore error
		}

		// Add full_name column to existing employees table if it doesn't exist
		try {
			$pdo->exec('ALTER TABLE employees ADD COLUMN full_name VARCHAR(200) NULL');
		} catch (Exception $e) { /* ignore */
		}

		// Add user_id to employees if missing
		try {
			$pdo->exec('ALTER TABLE employees ADD COLUMN user_id INT NULL AFTER id');
			$pdo->exec('ALTER TABLE employees ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
		} catch (Exception $e) { /* ignore */ }

		// Migrate existing data from separate name fields to full_name
		try {
			$pdo->exec('UPDATE employees SET full_name = CONCAT_WS(" ", first_name, middle_name, last_name) WHERE full_name IS NULL AND (first_name IS NOT NULL OR last_name IS NOT NULL)');
		} catch (PDOException $e) {
			// Columns might not exist in a fresh schema, ignore
		}

		// Make full_name NOT NULL after migration
		try {
			$pdo->exec('ALTER TABLE employees MODIFY COLUMN full_name VARCHAR(200) NOT NULL');
		} catch (PDOException $e) {
			// Table or column might not exist yet if fresh, ignore
		}

		// Add unique constraints to prevent duplicates
		try {
			$pdo->exec('ALTER TABLE employees ADD CONSTRAINT unique_email UNIQUE (email)');
		} catch (PDOException $e) {
			// Constraint might already exist, ignore error
		}

		try {
			$pdo->exec('ALTER TABLE employees ADD CONSTRAINT unique_contact_number UNIQUE (contact_number)');
		} catch (PDOException $e) {
			// Constraint might already exist, ignore error
		}

		// Drop old name columns (commented out for safety - uncomment if you want to remove them)
		// $pdo->exec('ALTER TABLE employees DROP COLUMN IF EXISTS first_name');
		// $pdo->exec('ALTER TABLE employees DROP COLUMN IF EXISTS last_name');
		// $pdo->exec('ALTER TABLE employees DROP COLUMN IF EXISTS middle_name');

		// Create teachers table
		$pdo->exec('CREATE TABLE IF NOT EXISTS teachers (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NULL,
		teacher_id VARCHAR(20) NOT NULL UNIQUE,
		first_name VARCHAR(100) NOT NULL,
		last_name VARCHAR(100) NOT NULL,
		middle_name VARCHAR(100) NULL,
		email VARCHAR(150) NULL,
		contact_number VARCHAR(20) NULL,
		department VARCHAR(100) NULL,
		specialization VARCHAR(200) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
		KEY idx_user_id (user_id)
	) ENGINE=InnoDB');

		// Ensure user_id column exists
		try {
			$pdo->exec('ALTER TABLE teachers ADD COLUMN user_id INT NULL AFTER id');
			$pdo->exec('ALTER TABLE teachers ADD CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
		} catch (Exception $e) { /* ignore */ }

		// Sync user_id from teacher_id pattern (TCH-0005 -> user_id 5)
		try {
			$pdo->exec("UPDATE teachers SET user_id = CAST(SUBSTRING(teacher_id, 5) AS UNSIGNED) 
					   WHERE user_id IS NULL AND teacher_id LIKE 'TCH-%'");
		} catch (Exception $e) { /* ignore */ }

		// Add advisory columns to teachers if they don't exist
		try {
			$pdo->exec("ALTER TABLE teachers ADD COLUMN grade_level VARCHAR(50) NULL");
			$pdo->exec("ALTER TABLE teachers ADD COLUMN section VARCHAR(100) NULL");
		} catch (Exception $e) { /* ignore */ }


		// Create employee_esignatures table
		$pdo->exec('CREATE TABLE IF NOT EXISTS employee_esignatures (
		id INT AUTO_INCREMENT PRIMARY KEY,
		employee_id INT NOT NULL,
		file_path VARCHAR(255) NOT NULL,
		position_type VARCHAR(50) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
		KEY idx_employee_id (employee_id),
		KEY idx_position_type (position_type)
	) ENGINE=InnoDB');

		// Create position_assignments table if it doesn't exist
		$pdo->exec('CREATE TABLE IF NOT EXISTS position_assignments (
		id INT AUTO_INCREMENT PRIMARY KEY,
		employee_id INT NULL,
		user_id INT NULL,
		position_type VARCHAR(50) NOT NULL,
		grade_level VARCHAR(50) NULL,
		section VARCHAR(100) NULL,
		school_year VARCHAR(20) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_employee_id (employee_id),
		KEY idx_user_id (user_id),
		KEY idx_position_type (position_type),
		KEY idx_school_year (school_year)
	) ENGINE=InnoDB');

		// Add updated_at column to position_assignments if it doesn't exist
		try {
			$pdo->exec('ALTER TABLE position_assignments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
		} catch (Exception $e) { /* ignore */
		}

		// Create subject_teachers table (many-to-many relationship)
		$pdo->exec('CREATE TABLE IF NOT EXISTS subject_teachers (
		id INT AUTO_INCREMENT PRIMARY KEY,
		subject_id INT NOT NULL,
		teacher_id INT NOT NULL,
		section_id INT NULL,
		school_year VARCHAR(20) NOT NULL,
		semester VARCHAR(10) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		UNIQUE KEY unique_subject_teacher_section (subject_id, teacher_id, section_id, school_year)
	) ENGINE=InnoDB');

		// Create strands table for Grade 12
		$pdo->exec('CREATE TABLE IF NOT EXISTS strands (
		id INT AUTO_INCREMENT PRIMARY KEY,
		strand_code VARCHAR(20) NOT NULL UNIQUE,
		strand_name VARCHAR(200) NOT NULL,
		description TEXT NULL,
		track VARCHAR(100) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) ENGINE=InnoDB');

		// Create curriculum_programs table
		$pdo->exec('CREATE TABLE IF NOT EXISTS curriculum_programs (
		id INT AUTO_INCREMENT PRIMARY KEY,
		program_code VARCHAR(20) NOT NULL UNIQUE,
		program_name VARCHAR(200) NOT NULL,
		program_type ENUM("Grade School", "Basic Education", "Senior High School", "Special Program", "Alternative Learning") NOT NULL,
		grade_levels VARCHAR(100) NOT NULL,
		duration_years DECIMAL(2,1) DEFAULT 1.0,
		total_units DECIMAL(5,1) DEFAULT 0.0,
		description TEXT NULL,
		is_active TINYINT(1) DEFAULT 1,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_program_type (program_type),
		KEY idx_grade_levels (grade_levels),
		KEY idx_is_active (is_active)
	) ENGINE=InnoDB');

		// Ensure program_semester column exists
		try {
			$pdo->exec('ALTER TABLE curriculum_programs ADD COLUMN program_semester VARCHAR(20) NULL AFTER grade_levels');
		} catch (Exception $e) { /* ignore */
		}
		try {
			$exists = $pdo->query("SHOW COLUMNS FROM curriculum_programs LIKE 'program_semester'")->fetch();
			if (!$exists) {
				$pdo->exec('ALTER TABLE curriculum_programs ADD COLUMN program_semester VARCHAR(20) NULL AFTER grade_levels');
			}
		} catch (Exception $ignored) {
		}

		// Ensure program_type enum includes 'Grade School'
		try {
			$pdo->exec("ALTER TABLE curriculum_programs MODIFY COLUMN program_type ENUM('Grade School', 'Basic Education', 'Senior High School', 'Special Program', 'Alternative Learning') NOT NULL");
		} catch (Exception $ignored) {
		}

		// Create curriculum table
		$pdo->exec('CREATE TABLE IF NOT EXISTS curriculum (
		id INT AUTO_INCREMENT PRIMARY KEY,
		program_id INT NULL,
		subject_code VARCHAR(20) NOT NULL,
		subject_name VARCHAR(200) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		semester VARCHAR(20) NULL,
		units DECIMAL(3,1) DEFAULT 0,
		description TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_grade_level (grade_level),
		KEY idx_subject_code (subject_code),
		KEY idx_program_id (program_id),
		FOREIGN KEY (program_id) REFERENCES curriculum_programs(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		// Ensure subject_code column exists for existing tables
		try {
			$pdo->exec('ALTER TABLE curriculum ADD COLUMN subject_code VARCHAR(20) NOT NULL AFTER program_id');
		} catch (Exception $e) { /* ignore */
		}
		try {
			$exists = $pdo->query("SHOW COLUMNS FROM curriculum LIKE 'subject_code'")->fetch();
			if (!$exists) {
				$pdo->exec('ALTER TABLE curriculum ADD COLUMN subject_code VARCHAR(20) NOT NULL AFTER program_id');
			}
		} catch (Exception $ignored) {
		}


		// Create system_settings table
		$pdo->exec('CREATE TABLE IF NOT EXISTS system_settings (
		id INT AUTO_INCREMENT PRIMARY KEY,
		setting_key VARCHAR(100) NOT NULL UNIQUE,
		setting_value TEXT NULL,
		setting_type ENUM("text", "number", "boolean", "json") DEFAULT "text",
		description TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) ENGINE=InnoDB');

		// Create audit_trail table
		$pdo->exec('CREATE TABLE IF NOT EXISTS audit_trail (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NULL,
		username VARCHAR(100) NULL,
		action VARCHAR(255) NOT NULL,
		details TEXT NULL,
		ip_address VARCHAR(45) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		KEY idx_user_id (user_id),
		KEY idx_action (action),
		KEY idx_created_at (created_at)
	) ENGINE=InnoDB');

		// Create enrollment_archives table for storing archived enrollment data
		$pdo->exec('CREATE TABLE IF NOT EXISTS enrollment_archives (
		id INT AUTO_INCREMENT PRIMARY KEY,
		original_enrollment_id INT NOT NULL,
		registration_id INT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		lrn VARCHAR(20) NULL,
		birthdate DATE NULL,
		guardian_first VARCHAR(100) NULL,
		guardian_last VARCHAR(100) NULL,
		guardian_contact VARCHAR(50) NULL,
		address VARCHAR(255) NULL,
		id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian",
		qr_code_path VARCHAR(255) NULL,
		enrolled_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		archived_by INT NULL,
		KEY idx_school_year (school_year),
		KEY idx_student_id (student_id),
		KEY idx_grade_level (grade_level),
		KEY idx_archived_at (archived_at),
		FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		// Create school_years table for managing school year transitions
		$pdo->exec('CREATE TABLE IF NOT EXISTS school_years (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL UNIQUE,
		start_date DATE NOT NULL,
		end_date DATE NOT NULL,
		is_current TINYINT(1) DEFAULT 0,
		is_archived TINYINT(1) DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_is_current (is_current),
		KEY idx_is_archived (is_archived)
	) ENGINE=InnoDB');

		// Seed default users if none
		$stmt = $pdo->query('SELECT COUNT(*) AS c FROM users');
		$count = (int) $stmt->fetchColumn();
		if ($count === 0) {
			seed_users($pdo);
		}

		// Initialize current school year if none exists
		$stmt = $pdo->query('SELECT COUNT(*) AS c FROM school_years WHERE is_current = 1');
		$sy_count = (int) $stmt->fetchColumn();
		if ($sy_count === 0) {
			$current_year = date('Y');
			$next_year = $current_year + 1;
			$school_year = $current_year . '-' . $next_year;
			$start_date = $current_year . '-06-01';
			$end_date = $next_year . '-05-31';

			$pdo->exec("INSERT INTO school_years (school_year, start_date, end_date, is_current) VALUES ('$school_year', '$start_date', '$end_date', 1)");
			$pdo->exec("INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES ('current_school_year', '$school_year', 'text', 'Current school year') ON DUPLICATE KEY UPDATE setting_value = '$school_year'");
		}

		// Create SF1 reports table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf1_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		KEY idx_teacher_id (teacher_id),
		KEY idx_school_year (school_year),
		KEY idx_grade_section (grade_level, section)
	) ENGINE=InnoDB');

		// Create SF1 student records table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf1_student_records (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf1_report_id INT NOT NULL,
		lrn VARCHAR(20) NULL,
		last_name VARCHAR(100) NOT NULL,
		first_name VARCHAR(100) NOT NULL,
		middle_name VARCHAR(100) NULL,
		sex ENUM("M", "F") NOT NULL,
		birth_date DATE NOT NULL,
		age_as_of_oct31 INT NULL,
		mother_tongue VARCHAR(100) NULL,
		ip_ethnicity VARCHAR(100) NULL,
		religion VARCHAR(100) NULL,
		house_no_street VARCHAR(200) NULL,
		barangay VARCHAR(100) NULL,
		municipality_city VARCHAR(100) NULL,
		province VARCHAR(100) NULL,
		father_last_name VARCHAR(100) NULL,
		father_first_name VARCHAR(100) NULL,
		father_middle_name VARCHAR(100) NULL,
		mother_last_name VARCHAR(100) NULL,
		mother_first_name VARCHAR(100) NULL,
		mother_middle_name VARCHAR(100) NULL,
		guardian_name VARCHAR(200) NULL,
		guardian_relationship VARCHAR(100) NULL,
		contact_number VARCHAR(50) NULL,
		learning_modality VARCHAR(100) NULL,
		remarks VARCHAR(500) NULL,
		remarks_code VARCHAR(10) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (sf1_report_id) REFERENCES sf1_reports(id) ON DELETE CASCADE,
		KEY idx_sf1_report_id (sf1_report_id),
		KEY idx_lrn (lrn),
		KEY idx_name (last_name, first_name)
	) ENGINE=InnoDB');

		// Create SF1 summary table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf1_summary (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf1_report_id INT NOT NULL,
		total_male INT DEFAULT 0,
		total_female INT DEFAULT 0,
		total_combined INT DEFAULT 0,
		registered_male_bosy INT DEFAULT 0,
		registered_female_bosy INT DEFAULT 0,
		registered_total_bosy INT DEFAULT 0,
		registered_male_eosy INT DEFAULT 0,
		registered_female_eosy INT DEFAULT 0,
		registered_total_eosy INT DEFAULT 0,
		prepared_by_signature VARCHAR(200) NULL,
		prepared_by_name VARCHAR(200) NULL,
		prepared_bosy_date DATE NULL,
		prepared_eosy_date DATE NULL,
		certified_by_signature VARCHAR(200) NULL,
		certified_by_name VARCHAR(200) NULL,
		certified_bosy_date DATE NULL,
		certified_eosy_date DATE NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (sf1_report_id) REFERENCES sf1_reports(id) ON DELETE CASCADE,
		UNIQUE KEY unique_sf1_summary (sf1_report_id)
	) ENGINE=InnoDB');

		// Create SF2 reports table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf2_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		report_month VARCHAR(20) NOT NULL,
		report_year INT NOT NULL,
		school_id VARCHAR(50) DEFAULT "300750",
		school_name VARCHAR(200) DEFAULT "Malolos Marine Fishery School",
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		KEY idx_teacher_id (teacher_id),
		KEY idx_school_year (school_year),
		KEY idx_grade_section (grade_level, section),
		KEY idx_report_month (report_month, report_year),
		UNIQUE KEY unique_sf2_report (teacher_id, school_year, grade_level, section, report_month, report_year)
	) ENGINE=InnoDB');

		// Create SF2 student records table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf2_student_records (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf2_report_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		sex ENUM("M", "F") NOT NULL,
		total_absent INT DEFAULT 0,
		total_present INT DEFAULT 0,
		remarks VARCHAR(500) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf2_report_id) REFERENCES sf2_reports(id) ON DELETE CASCADE,
		KEY idx_sf2_report_id (sf2_report_id),
		KEY idx_student_id (student_id)
	) ENGINE=InnoDB');

		// Create SF2 daily attendance table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf2_daily_attendance (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf2_report_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		sex ENUM("M", "F") NOT NULL,
		attendance_date DATE NOT NULL,
		attendance_status VARCHAR(10) NOT NULL,
		remarks VARCHAR(500) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf2_report_id) REFERENCES sf2_reports(id) ON DELETE CASCADE,
		KEY idx_sf2_report_id (sf2_report_id),
		KEY idx_student_id (student_id),
		KEY idx_attendance_date (attendance_date),
		KEY idx_attendance_status (attendance_status),
		UNIQUE KEY unique_student_date (sf2_report_id, student_id, attendance_date)
	) ENGINE=InnoDB');

		// Create SF2 monthly summary table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf2_monthly_summary (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf2_report_id INT NOT NULL,
		month VARCHAR(20) NOT NULL,
		days_of_classes INT DEFAULT 0,
		-- Enrolment statistics
		enrolment_male_bosy INT DEFAULT 0,
		enrolment_female_bosy INT DEFAULT 0,
		enrolment_total_bosy INT DEFAULT 0,
		late_enrolment_male INT DEFAULT 0,
		late_enrolment_female INT DEFAULT 0,
		late_enrolment_total INT DEFAULT 0,
		registered_male_eom INT DEFAULT 0,
		registered_female_eom INT DEFAULT 0,
		registered_total_eom INT DEFAULT 0,
		percentage_enrolment DECIMAL(5,2) DEFAULT 0.00,
		-- Attendance statistics
		average_daily_attendance DECIMAL(8,2) DEFAULT 0.00,
		ada_male DECIMAL(8,2) DEFAULT 0.00,
		ada_female DECIMAL(8,2) DEFAULT 0.00,
		percentage_attendance DECIMAL(5,2) DEFAULT 0.00,
		perc_male DECIMAL(5,2) DEFAULT 0.00,
		perc_female DECIMAL(5,2) DEFAULT 0.00,
		-- Absenteeism tracking
		absent_5_consecutive_days INT DEFAULT 0,
		nls_count INT DEFAULT 0,
		transferred_out INT DEFAULT 0,
		transferred_in INT DEFAULT 0,
		-- Certification
		adviser_signature VARCHAR(200) NULL,
		adviser_name VARCHAR(200) NULL,
		attested_by_signature VARCHAR(200) NULL,
		attested_by_name VARCHAR(200) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf2_report_id) REFERENCES sf2_reports(id) ON DELETE CASCADE,
		UNIQUE KEY unique_sf2_summary (sf2_report_id)
	) ENGINE=InnoDB');

		// Create SF2 student records table (for student list in the form)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf2_student_records (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf2_report_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		sex ENUM("M", "F") NOT NULL,
		total_absent INT DEFAULT 0,
		total_present INT DEFAULT 0,
		remarks VARCHAR(500) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (sf2_report_id) REFERENCES sf2_reports(id) ON DELETE CASCADE,
		KEY idx_sf2_report_id (sf2_report_id),
		KEY idx_student_id (student_id),
		KEY idx_sex (sex),
		UNIQUE KEY unique_student_report (sf2_report_id, student_id)
	) ENGINE=InnoDB');

		// Create SF3 reports table (Books Issued)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf3_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		KEY idx_teacher_id (teacher_id),
		KEY idx_school_year (school_year),
		KEY idx_grade_section (grade_level, section),
		UNIQUE KEY unique_sf3_report (teacher_id, school_year, grade_level, section)
	) ENGINE=InnoDB');

		// Create SF3 books table
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf3_books (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf3_report_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		sex ENUM("M", "F") NOT NULL,
		math VARCHAR(50) NULL,
		science VARCHAR(50) NULL,
		english VARCHAR(50) NULL,
		filipino VARCHAR(50) NULL,
		ap VARCHAR(50) NULL,
		mapeh VARCHAR(50) NULL,
		tle VARCHAR(50) NULL,
		values_ed VARCHAR(50) NULL,
		computer VARCHAR(50) NULL,
		research VARCHAR(50) NULL,
		remarks VARCHAR(500) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
		KEY idx_sf3_report_id (sf3_report_id),
		KEY idx_student_id (student_id),
		UNIQUE KEY unique_sf3_student (sf3_report_id, student_id)
	) ENGINE=InnoDB');

		// Create SF3 Books Inventory table (for the new SF3 workflow)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf3_books_inventory (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf3_report_id INT NOT NULL,
		subject VARCHAR(100) NOT NULL,
		title VARCHAR(200) NOT NULL,
		total_copies_received INT DEFAULT 0,
		copies_in_good_condition INT DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
		KEY idx_sf3_report_id (sf3_report_id)
	) ENGINE=InnoDB');

		// Create SF3 Student Books table (Distribution and Collection tracking)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf3_student_books (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf3_report_id INT NOT NULL,
		student_lrn VARCHAR(50) NOT NULL,
		inventory_id INT NOT NULL,
		date_issued DATE NULL,
		condition_issued ENUM("Good", "Fair", "Poor") DEFAULT "Good",
		date_returned DATE NULL,
		condition_returned ENUM("Good", "Fair", "Damaged", "Lost", "Repairable") NULL,
		remarks VARCHAR(500) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
		FOREIGN KEY (inventory_id) REFERENCES sf3_books_inventory(id) ON DELETE CASCADE,
		KEY idx_sf3_report_id (sf3_report_id),
		KEY idx_student_lrn (student_lrn),
		UNIQUE KEY unique_student_book (sf3_report_id, student_lrn, inventory_id)
	) ENGINE=InnoDB');

		// Create Global Master Book List table
		$pdo->exec('CREATE TABLE IF NOT EXISTS admin_books (
		id INT AUTO_INCREMENT PRIMARY KEY,
		title VARCHAR(255) NOT NULL,
		subject VARCHAR(100) NOT NULL,
		category VARCHAR(50) DEFAULT "Core",
		total_copies INT DEFAULT 0,
		condition_repairable INT DEFAULT 0,
		grade_level VARCHAR(50) DEFAULT NULL,
		is_obsolete TINYINT(1) DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) ENGINE=InnoDB');

		// Student ID & QR Re-Enrollment System Tables
		$pdo->exec('CREATE TABLE IF NOT EXISTS school_ids (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			id_number VARCHAR(20) NOT NULL UNIQUE,
			status ENUM("Active", "Lost", "Expired", "Revoked") DEFAULT "Active",
			issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			expires_at DATE NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			KEY idx_student_id (student_id),
			KEY idx_status (status)
		) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS qr_tokens (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL UNIQUE,
			token VARCHAR(255) NOT NULL UNIQUE,
			expires_at TIMESTAMP NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			KEY idx_student_token (student_id, token)
		) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS reenrollment_logs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			scanned_by INT NOT NULL,
			old_enrollment_id INT NULL,
			new_enrollment_id INT NULL,
			school_year VARCHAR(20) NOT NULL,
			status ENUM("Success", "Failed", "Pending") DEFAULT "Success",
			details TEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE,
			KEY idx_student_id (student_id),
			KEY idx_school_year (school_year)
		) ENGINE=InnoDB');

		// Create Book Allocations table
		$pdo->exec('CREATE TABLE IF NOT EXISTS book_allocations (
		id INT AUTO_INCREMENT PRIMARY KEY,
		book_id INT NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		allocated_copies INT DEFAULT 0,
		school_year VARCHAR(20) NOT NULL,
		is_locked TINYINT(1) DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (book_id) REFERENCES admin_books(id) ON DELETE CASCADE,
		UNIQUE KEY unique_allocation (book_id, grade_level, school_year)
	) ENGINE=InnoDB');

		// Create Inventory Adjustments table
		$pdo->exec('CREATE TABLE IF NOT EXISTS inventory_adjustments (
		id INT AUTO_INCREMENT PRIMARY KEY,
		book_id INT NOT NULL,
		adjustment_type ENUM("delivery", "repair", "audit", "damage", "loss") NOT NULL,
		quantity INT NOT NULL,
		remarks TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (book_id) REFERENCES admin_books(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		// Create Textbook Audit Log table for administrative changes
		$pdo->exec('CREATE TABLE IF NOT EXISTS textbook_audit_log (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NOT NULL,
		action VARCHAR(100) NOT NULL,
		book_id INT NULL,
		old_value TEXT NULL,
		new_value TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		// Create position_assignments table for managing principals and class advisers
		$pdo->exec('CREATE TABLE IF NOT EXISTS position_assignments (
		id INT AUTO_INCREMENT PRIMARY KEY,
		employee_id INT NULL,
		user_id INT NULL,
		position_type ENUM("principal", "class_adviser") NOT NULL,
		grade_level VARCHAR(50) NULL,
		section VARCHAR(100) NULL,
		school_year VARCHAR(20) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
		KEY idx_employee_id (employee_id),
		KEY idx_user_id (user_id),
		KEY idx_position_type (position_type),
		KEY idx_grade_section (grade_level, section),
		KEY idx_school_year (school_year),
		UNIQUE KEY unique_principal_per_sy (position_type, school_year),
		UNIQUE KEY unique_adviser_per_section (position_type, grade_level, section, school_year),
		CHECK (employee_id IS NOT NULL OR user_id IS NOT NULL)
	) ENGINE=InnoDB');

		// Migrate existing position_assignments table to support both employee_id and user_id
		try {
			// Add employee_id column if it doesn't exist
			try {
				$pdo->exec('ALTER TABLE position_assignments ADD COLUMN employee_id INT NULL');
			} catch (Exception $e) {
			}

			// Add foreign key constraint for employee_id
			$pdo->exec('ALTER TABLE position_assignments ADD CONSTRAINT fk_position_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE');
		} catch (PDOException $e) {
			// Foreign key might already exist, ignore error
		}

		// Create SF4 reports table (Monthly Movement)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf4_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		report_month VARCHAR(20) NOT NULL,
		school_id VARCHAR(50) DEFAULT "300750",
		status ENUM("Draft", "Finalized") DEFAULT "Draft",
		finalized_by INT NULL,
		finalized_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY unique_sf4_report (school_year, report_month),
		FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		// Alter sf4_reports for existing databases
		$sf4_rep_alters = [
			'ALTER TABLE sf4_reports ADD COLUMN status ENUM("Draft", "Finalized") DEFAULT "Draft"',
			'ALTER TABLE sf4_reports ADD COLUMN finalized_by INT NULL',
			'ALTER TABLE sf4_reports ADD COLUMN finalized_at TIMESTAMP NULL',
			'ALTER TABLE sf4_reports ADD CONSTRAINT fk_sf4_finalized_by FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL'
		];
		foreach ($sf4_rep_alters as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

		$pdo->exec('CREATE TABLE IF NOT EXISTS sf4_rows (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf4_report_id INT NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		adviser VARCHAR(200) NULL,
		-- Beginning Enrollment
		beg_m INT DEFAULT 0, beg_f INT DEFAULT 0,
		-- Registered (End of Month)
		reg_m INT DEFAULT 0, reg_f INT DEFAULT 0,
		-- Attendance
		num_school_days INT DEFAULT 0,
		total_att_m INT DEFAULT 0, total_att_f INT DEFAULT 0,
		daily_att_m_json TEXT NULL, daily_att_f_json TEXT NULL,
		avg_m DECIMAL(10,2) DEFAULT 0, avg_f DECIMAL(10,2) DEFAULT 0,
		perc_m DECIMAL(5,2) DEFAULT 0, perc_f DECIMAL(5,2) DEFAULT 0,
		-- Transferred IN
		tin_prev_m INT DEFAULT 0, tin_prev_f INT DEFAULT 0,
		tin_curr_m INT DEFAULT 0, tin_curr_f INT DEFAULT 0,
		tin_cum_m INT DEFAULT 0, tin_cum_f INT DEFAULT 0,
		-- Late Enrollment
		late_prev_m INT DEFAULT 0, late_prev_f INT DEFAULT 0,
		late_curr_m INT DEFAULT 0, late_curr_f INT DEFAULT 0,
		late_cum_m INT DEFAULT 0, late_cum_f INT DEFAULT 0,
		-- Transferred OUT
		tout_prev_m INT DEFAULT 0, tout_prev_f INT DEFAULT 0,
		tout_curr_m INT DEFAULT 0, tout_curr_f INT DEFAULT 0,
		tout_cum_m INT DEFAULT 0, tout_cum_f INT DEFAULT 0,
		-- Dropped (NLPA)
		nlpa_prev_m INT DEFAULT 0, nlpa_prev_f INT DEFAULT 0,
		nlpa_curr_m INT DEFAULT 0, nlpa_curr_f INT DEFAULT 0,
		nlpa_cum_m INT DEFAULT 0, nlpa_cum_f INT DEFAULT 0,
		-- Mortality
		mort_prev_m INT DEFAULT 0, mort_prev_f INT DEFAULT 0,
		mort_curr_m INT DEFAULT 0, mort_curr_f INT DEFAULT 0,
		mort_cum_m INT DEFAULT 0, mort_cum_f INT DEFAULT 0,
		
		FOREIGN KEY (sf4_report_id) REFERENCES sf4_reports(id) ON DELETE CASCADE,
		UNIQUE KEY unique_row_section (sf4_report_id, grade_level, section)
	) ENGINE=InnoDB');

		// Alter sf4_rows for existing databases
		$sf4_row_alters = [
			'ALTER TABLE sf4_rows ADD COLUMN beg_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN beg_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN reg_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN reg_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN num_school_days INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN total_att_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN total_att_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN daily_att_m_json TEXT NULL',
			'ALTER TABLE sf4_rows ADD COLUMN daily_att_f_json TEXT NULL',
			'ALTER TABLE sf4_rows ADD COLUMN avg_m DECIMAL(10,2) DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN avg_f DECIMAL(10,2) DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN perc_m DECIMAL(5,2) DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN perc_f DECIMAL(5,2) DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_prev_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_prev_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_curr_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_curr_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_cum_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tin_cum_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_prev_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_prev_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_curr_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_curr_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_cum_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN late_cum_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_prev_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_prev_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_curr_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_curr_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_cum_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN tout_cum_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_prev_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_prev_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_curr_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_curr_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_cum_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN nlpa_cum_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_prev_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_prev_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_curr_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_curr_f INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_cum_m INT DEFAULT 0',
			'ALTER TABLE sf4_rows ADD COLUMN mort_cum_f INT DEFAULT 0'
		];
		foreach ($sf4_row_alters as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }
		
		// Create school_calendar table
		$pdo->exec('CREATE TABLE IF NOT EXISTS school_calendar (
			id INT AUTO_INCREMENT PRIMARY KEY,
			school_year VARCHAR(20) NOT NULL,
			month VARCHAR(20) NOT NULL,
			num_days INT DEFAULT 20,
			UNIQUE KEY unique_sy_month (school_year, month)
		) ENGINE=InnoDB');

		// Create student_movements table for tracking transfers, dropouts, etc.
		$pdo->exec('CREATE TABLE IF NOT EXISTS student_movements (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		movement_type ENUM("Transferred In", "Transferred Out", "Dropped Out", "Late Enrollment", "Mortality") NOT NULL,
		movement_date DATE NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		remarks TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		INDEX idx_student_movement (student_id),
		INDEX idx_movement_date (movement_date),
		INDEX idx_movement_sy (school_year)
	) ENGINE=InnoDB');

		// Create SF5 reports table (Promotion)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf5_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY unique_sf5_report (school_year, grade_level, section)
	) ENGINE=InnoDB');

		// Create SF5 students table (Per-student promotion data)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf5_students (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf4_report_id INT NOT NULL, -- Keep for backward compatibility if any
		sf5_report_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		student_name VARCHAR(200) NOT NULL,
		lrn VARCHAR(50) NULL,
		sex ENUM("M", "F") NOT NULL,
		general_average DECIMAL(5,2) DEFAULT 0,
		action_taken VARCHAR(50) DEFAULT "PROMOTED",
		learning_areas_not_met TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf5_report_id) REFERENCES sf5_reports(id) ON DELETE CASCADE,
		UNIQUE KEY unique_sf5_student (sf5_report_id, student_id)
	) ENGINE=InnoDB');

		// Create SF6 reports table (Summarized Promotion)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf6_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		school_id VARCHAR(50) DEFAULT "300750",
		status ENUM("Draft", "Finalized") DEFAULT "Draft",
		finalized_by INT NULL,
		finalized_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY unique_sf6_report (school_year, grade_level),
		FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		// Create SF6 rows table (Sectional breakdown)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf6_rows (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf6_report_id INT NOT NULL,
		section VARCHAR(100) NOT NULL,
		adviser VARCHAR(200) NULL,
		-- Enrollment
		beg_m INT DEFAULT 0, beg_f INT DEFAULT 0,
		end_m INT DEFAULT 0, end_f INT DEFAULT 0,
		-- Status Counts
		promoted_m INT DEFAULT 0, promoted_f INT DEFAULT 0,
		retained_m INT DEFAULT 0, retained_f INT DEFAULT 0,
		conditional_m INT DEFAULT 0, conditional_f INT DEFAULT 0,
		completers_m INT DEFAULT 0, completers_f INT DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf6_report_id) REFERENCES sf6_reports(id) ON DELETE CASCADE,
		UNIQUE KEY unique_sf6_section (sf6_report_id, section)
	) ENGINE=InnoDB');

		// Create SF7 reports table (Personnel Assignment)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf7_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL UNIQUE,
		school_id VARCHAR(50) DEFAULT "300750",
		status ENUM("Draft", "Finalized") DEFAULT "Draft",
		finalized_by INT NULL,
		finalized_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS sf7_personnel_data (
		id INT AUTO_INCREMENT PRIMARY KEY,
		sf7_report_id INT NOT NULL,
		user_id INT NOT NULL,
		employment_status VARCHAR(50) DEFAULT "Permanent",
		degree_major VARCHAR(255) NULL,
		years_in_service INT DEFAULT 0,
		office_assignment VARCHAR(150) NULL,
		role_function VARCHAR(150) NULL,
		remarks TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (sf7_report_id) REFERENCES sf7_reports(id) ON DELETE CASCADE,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
		UNIQUE KEY unique_personnel_report (sf7_report_id, user_id)
	) ENGINE=InnoDB');

		// Add health profile columns if they don't exist
		try {
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN is_dewormed TINYINT(1) DEFAULT 0');
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN vision_screening VARCHAR(100) NULL');
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN has_condition TINYINT(1) DEFAULT 0');
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN condition_remarks TEXT NULL');
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN bmi DECIMAL(4,1) NULL');
			$pdo->exec('ALTER TABLE sf8_health_profile ADD COLUMN nutritional_status VARCHAR(50) NULL');
		} catch (Exception $e) { /* ignore existing */ }
		
		// Create school_calendar table for managing school days per month (for SF4)
		$pdo->exec('CREATE TABLE IF NOT EXISTS school_calendar (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		month VARCHAR(20) NOT NULL,
		num_days INT DEFAULT 20,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY unique_sy_month (school_year, month)
	) ENGINE=InnoDB');

		// Create SF8 reports table (Sectional Health Summary)
		$pdo->exec('CREATE TABLE IF NOT EXISTS sf8_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		school_year VARCHAR(20) NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		status ENUM("Draft", "For Validation", "Validated", "Finalized") DEFAULT "Draft",
		submitted_by INT NULL,
		submitted_at TIMESTAMP NULL,
		validated_by INT NULL,
		validated_at TIMESTAMP NULL,
		finalized_by INT NULL,
		finalized_at TIMESTAMP NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
		FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL,
		FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
		UNIQUE KEY unique_sf8_report (school_year, grade_level, section)
	) ENGINE=InnoDB');

		// Migrate status enum if needed
		try {
			$pdo->exec("ALTER TABLE sf8_reports MODIFY COLUMN status ENUM('Draft', 'For Validation', 'Validated', 'Finalized') DEFAULT 'Draft'");
			$pdo->exec("ALTER TABLE sf8_reports ADD COLUMN submitted_by INT NULL, ADD COLUMN submitted_at TIMESTAMP NULL, ADD COLUMN validated_by INT NULL, ADD COLUMN validated_at TIMESTAMP NULL");
		} catch (Exception $e) {}

		// Create school_forms table (School Register Database - eClass Record)
		$pdo->exec('CREATE TABLE IF NOT EXISTS school_forms (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NULL,
		lrn VARCHAR(12) NULL,
		last_name VARCHAR(100) NOT NULL,
		first_name VARCHAR(100) NOT NULL,
		middle_name VARCHAR(100) NULL,
		sex VARCHAR(10) NULL,
		date_of_birth DATE NULL,
		age INT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		school_year VARCHAR(20) NULL,
		date_enrolled DATE NULL,
		status ENUM("Active","Transferred","Dropped","Graduated","Suspended","Mortality") DEFAULT "Active",
		father_name VARCHAR(200) NULL,
		mother_name VARCHAR(200) NULL,
		guardian_name VARCHAR(200) NULL,
		parent_contact_no VARCHAR(50) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_sf_grade (grade_level),
		KEY idx_sf_section (section),
		KEY idx_sf_status (status),
		KEY idx_sf_lrn (lrn)
	) ENGINE=InnoDB');

		// Create eclass_records table
		$pdo->exec('CREATE TABLE IF NOT EXISTS eclass_records (
		id INT AUTO_INCREMENT PRIMARY KEY,
		adviser_name VARCHAR(200) NOT NULL,
		grade VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		enrollment_bosy_male INT DEFAULT 0,
		enrollment_bosy_female INT DEFAULT 0,
		enrollment_bosy_total INT DEFAULT 0,
		enrollment_q1_male INT DEFAULT 0,
		enrollment_q1_female INT DEFAULT 0,
		enrollment_q1_total INT DEFAULT 0,
		enrollment_q2_male INT DEFAULT 0,
		enrollment_q2_female INT DEFAULT 0,
		enrollment_q2_total INT DEFAULT 0,
		enrollment_q3_male INT DEFAULT 0,
		enrollment_q3_female INT DEFAULT 0,
		enrollment_q3_total INT DEFAULT 0,
		enrollment_q4_male INT DEFAULT 0,
		enrollment_q4_female INT DEFAULT 0,
		enrollment_q4_total INT DEFAULT 0,
		dropped_bosy_male INT DEFAULT 0,
		dropped_bosy_female INT DEFAULT 0,
		dropped_bosy_total INT DEFAULT 0,
		dropped_q1_male INT DEFAULT 0,
		dropped_q1_female INT DEFAULT 0,
		dropped_q1_total INT DEFAULT 0,
		dropped_q2_male INT DEFAULT 0,
		dropped_q2_female INT DEFAULT 0,
		dropped_q2_total INT DEFAULT 0,
		dropped_q3_male INT DEFAULT 0,
		dropped_q3_female INT DEFAULT 0,
		dropped_q3_total INT DEFAULT 0,
		dropped_q4_male INT DEFAULT 0,
		dropped_q4_female INT DEFAULT 0,
		dropped_q4_total INT DEFAULT 0,
		balik_aral_male INT DEFAULT 0,
		balik_aral_female INT DEFAULT 0,
		balik_aral_total INT DEFAULT 0,
		transferred_in_male INT DEFAULT 0,
		transferred_in_female INT DEFAULT 0,
		transferred_in_total INT DEFAULT 0,
		transferred_out_male INT DEFAULT 0,
		transferred_out_female INT DEFAULT 0,
		transferred_out_total INT DEFAULT 0,
		created_by VARCHAR(100) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY unique_eclass_record (grade, section, school_year, adviser_name)
	) ENGINE=InnoDB');

		// Create sections table for managing section details and advisers
		$pdo->exec('CREATE TABLE IF NOT EXISTS sections (
		id INT AUTO_INCREMENT PRIMARY KEY,
		grade_level VARCHAR(50) NOT NULL,
		section_name VARCHAR(100) NOT NULL,
		adviser_id INT NULL,
		room_number VARCHAR(50) NULL,
		capacity INT DEFAULT 40,
		school_year VARCHAR(20) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (adviser_id) REFERENCES teachers(id) ON DELETE SET NULL,
		UNIQUE KEY unique_section (grade_level, section_name, school_year),
		KEY idx_adviser (adviser_id),
		KEY idx_school_year (school_year)
	) ENGINE=InnoDB');

		// Create section_subjects junction table (links sections to curriculum subjects)
		try {
			$pdo->exec('CREATE TABLE IF NOT EXISTS section_subjects (
			id INT AUTO_INCREMENT PRIMARY KEY,
			section_id INT NOT NULL,
			curriculum_id INT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
			FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
			UNIQUE KEY unique_section_subject (section_id, curriculum_id)
		) ENGINE=InnoDB');
		} catch (Exception $e) {
			// Table may already exist or referenced tables may not exist yet
		}

		// Create students table for persistent student data (extended for Marine Registrar System)
		$pdo->exec('CREATE TABLE IF NOT EXISTS students (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(20) NOT NULL UNIQUE,
		first_name VARCHAR(100) NOT NULL,
		middle_name VARCHAR(100) NULL,
		last_name VARCHAR(100) NOT NULL,
		sex VARCHAR(10) NULL,
		birthdate DATE NULL,
		address TEXT NULL,
		course VARCHAR(100) NULL,
		year_level VARCHAR(50) NULL,
		section VARCHAR(50) NULL,
		photo VARCHAR(255) NULL,
		qr_code_path VARCHAR(255) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		KEY idx_student_id (student_id)
	) ENGINE=InnoDB');

		// Ensure all Marine Registrar specific columns exist when upgrading an older schema
		$student_alters = [
			'ALTER TABLE students ADD COLUMN middle_name VARCHAR(100) NULL',
			'ALTER TABLE students ADD COLUMN sex VARCHAR(10) NULL',
			'ALTER TABLE students ADD COLUMN birthdate DATE NULL',
			'ALTER TABLE students ADD COLUMN address TEXT NULL',
			'ALTER TABLE students ADD COLUMN section VARCHAR(50) NULL',
			'ALTER TABLE students ADD COLUMN photo VARCHAR(255) NULL'
		];
		foreach ($student_alters as $sql) {
			try {
				$pdo->exec($sql);
			} catch (Exception $e) {
			}
		}

		// Textbook related alters
		$book_alters = [
			'ALTER TABLE admin_books ADD COLUMN category VARCHAR(50) DEFAULT "Core"',
			'ALTER TABLE admin_books ADD COLUMN condition_repairable INT DEFAULT 0',
			'ALTER TABLE admin_books ADD COLUMN is_obsolete TINYINT(1) DEFAULT 0'
		];
		foreach ($book_alters as $sql) {
			try {
				$pdo->exec($sql);
			} catch (Exception $e) {
			}
		}

		try {
			$pdo->exec("ALTER TABLE sf3_student_books MODIFY COLUMN condition_returned ENUM('Good', 'Fair', 'Damaged', 'Lost', 'Repairable') NULL");
		} catch (Exception $e) {
		}

		// Core Marine Registrar academic tables
		$pdo->exec('CREATE TABLE IF NOT EXISTS courses (
		id INT AUTO_INCREMENT PRIMARY KEY,
		course_name VARCHAR(100) NOT NULL
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS subjects (
		id INT AUTO_INCREMENT PRIMARY KEY,
		subject_code VARCHAR(20) NOT NULL,
		subject_name VARCHAR(100) NOT NULL,
		units INT NOT NULL,
		course VARCHAR(100) NOT NULL,
		year_level VARCHAR(20) NOT NULL,
		UNIQUE KEY uq_subject_code (subject_code)
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS enrollments (
		id INT AUTO_INCREMENT PRIMARY KEY,
		registration_id INT NULL,
		student_name VARCHAR(255) NULL,
		student_id VARCHAR(50) NOT NULL,
		course VARCHAR(100) NOT NULL,
		year_level VARCHAR(20) NOT NULL,
		section VARCHAR(50) NOT NULL,
		semester VARCHAR(20) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		lrn VARCHAR(20) NULL,
		birthdate DATE NULL,
		guardian_first VARCHAR(100) NULL,
		guardian_last VARCHAR(100) NULL,
		guardian_contact VARCHAR(50) NULL,
		address TEXT NULL,
		id_contact_person VARCHAR(50) DEFAULT "guardian",
		qr_code_path VARCHAR(255) NULL,
		sex VARCHAR(10) DEFAULT "Male",
		enrollment_date DATE NOT NULL,
		enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		status VARCHAR(20) NOT NULL DEFAULT "Active",
		status_date DATE NULL,
		KEY idx_enrollment_student (student_id),
		KEY idx_enrollment_sy (school_year),
		KEY idx_registration_id (registration_id)
	) ENGINE=InnoDB');

		// Ensure columns exist for older installations
		$enrollment_alters = [
			'ALTER TABLE enrollments ADD COLUMN registration_id INT NULL',
			'ALTER TABLE enrollments ADD COLUMN lrn VARCHAR(20) NULL',
			'ALTER TABLE enrollments ADD COLUMN sex VARCHAR(10) DEFAULT "Male"',
			'ALTER TABLE enrollments ADD COLUMN status_date DATE NULL',
			'ALTER TABLE enrollments ADD COLUMN enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
		];
		foreach ($enrollment_alters as $sql) {
			try { $pdo->exec($sql); } catch (Exception $e) {}
		}

		$pdo->exec('CREATE TABLE IF NOT EXISTS teacher_subjects (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		subject_id INT NOT NULL,
		section VARCHAR(20) NOT NULL,
		semester VARCHAR(20) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
		KEY idx_teacher (teacher_id),
		KEY idx_teacher_sy (teacher_id, school_year, semester)
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS grades (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		subject_id INT NOT NULL,
		teacher_id INT NULL,
		quarter VARCHAR(10) NULL,
		grade DECIMAL(5,2) DEFAULT 0.00,
		semester VARCHAR(20) NULL,
		school_year VARCHAR(20) NOT NULL,
		q1 DECIMAL(5,2) DEFAULT NULL,
		q2 DECIMAL(5,2) DEFAULT NULL,
		q3 DECIMAL(5,2) DEFAULT NULL,
		q4 DECIMAL(5,2) DEFAULT NULL,
		prelim DECIMAL(5,2) DEFAULT 0.00,
		midterm DECIMAL(5,2) DEFAULT 0.00,
		finals DECIMAL(5,2) DEFAULT 0.00,
		final_grade DECIMAL(5,2) DEFAULT 0.00,
		remarks VARCHAR(255) NULL,
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
		KEY idx_grades_student (student_id),
		KEY idx_grades_sy (school_year),
		UNIQUE KEY unique_grade (student_id, subject_id, quarter, school_year)
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS attendance (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(20) NOT NULL,
		date DATE NOT NULL,
		status VARCHAR(10) NOT NULL,
		KEY idx_attendance_student (student_id),
		KEY idx_attendance_date (date)
	) ENGINE=InnoDB');

		// --- ENHANCED REPORTING TABLES ---

		$pdo->exec('CREATE TABLE IF NOT EXISTS accomplishment_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		month VARCHAR(20) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		activities TEXT NULL,
		outcomes TEXT NULL,
		challenges TEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS exam_papers (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		subject_id INT NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		title VARCHAR(200) NOT NULL,
		instructions TEXT NULL,
		school_year VARCHAR(20) NOT NULL,
		period VARCHAR(20) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS exam_scores (
		id INT AUTO_INCREMENT PRIMARY KEY,
		exam_id INT NOT NULL,
		student_id VARCHAR(50) NOT NULL,
		score INT NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (exam_id) REFERENCES exam_papers(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS tos_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		teacher_id INT NOT NULL,
		subject_id INT NOT NULL,
		grade_level VARCHAR(50) NOT NULL,
		section VARCHAR(100) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		period VARCHAR(20) NOT NULL,
		total_days INT DEFAULT 0,
		total_items INT DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS sf9_grades (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		subject_id INT NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		q1 DECIMAL(5,2) DEFAULT NULL,
		q2 DECIMAL(5,2) DEFAULT NULL,
		q3 DECIMAL(5,2) DEFAULT NULL,
		q4 DECIMAL(5,2) DEFAULT NULL,
		final_grade DECIMAL(5,2) DEFAULT NULL,
		remarks VARCHAR(255) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY unique_grade (student_id, subject_id, school_year),
		FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
	) ENGINE=InnoDB');

		$pdo->exec('CREATE TABLE IF NOT EXISTS observed_values (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		quarter VARCHAR(2) NOT NULL,
		behavior_statement_id VARCHAR(100) NOT NULL,
		rating VARCHAR(5) NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY unique_observed (student_id, school_year, quarter, behavior_statement_id)
	) ENGINE=InnoDB');

		// Standardized attendance records (ensuring fallback from original definition)
		try {
			$pdo->exec("ALTER TABLE attendance_records MODIFY COLUMN status ENUM('P', 'A', 'L', 'E') NOT NULL");
		} catch (Exception $e) {}

		$pdo->exec('CREATE TABLE IF NOT EXISTS sf9_reports (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id VARCHAR(50) NOT NULL,
		school_year VARCHAR(20) NOT NULL,
		adviser_remarks TEXT NULL,
		promotion_status ENUM("Promoted", "Conditional", "Retained") DEFAULT "Promoted",
		final_rating DECIMAL(5,2) NULL,
		promotion_date DATE NULL,
		finalized_at TIMESTAMP NULL,
		finalized_by INT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
		UNIQUE KEY unique_sf9_report (student_id, school_year)
	) ENGINE=InnoDB');

	// Ensure final_rating column exists if table already existed
	try {
		$pdo->exec('ALTER TABLE sf9_reports ADD COLUMN final_rating DECIMAL(5,2) NULL AFTER promotion_status');
	} catch (Exception $e) { /* already exists */ }

		// Seed default system settings
		seed_system_settings($pdo);

		// --- Fix Foreign Key logic for Sections -> Users migration ---
		try {
			// Find and drop the old constraint referencing teachers
			$stmt = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'sections' AND COLUMN_NAME = 'adviser_id' AND REFERENCED_TABLE_NAME = 'teachers' AND TABLE_SCHEMA = DATABASE()");
			$constraints_dropped = false;
			if ($stmt) {
				while ($constraint = $stmt->fetchColumn()) {
					$pdo->exec("ALTER TABLE sections DROP FOREIGN KEY `$constraint`");
					$constraints_dropped = true;
				}
				// If we dropped the old one, apply the new one referencing users table
				if ($constraints_dropped) {
					$pdo->exec("ALTER TABLE sections ADD CONSTRAINT fk_sections_users_new FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE SET NULL");
				}
			}
		} catch (Exception $e) { /* ignore to prevent breaking initialization on permissions issues */ }

		// --- Add missing section_id column to subject_teachers if migrating ---
		try {
			$pdo->exec("ALTER TABLE subject_teachers ADD COLUMN section_id INT NULL");
		} catch (Exception $e) { /* ignore if already exists */ }

		// --- Ensure LRN uniqueness in enrollments for the same school year ---
		try {
			// Check if index exists first (optional for IGNORE but good for cleanliness)
			$pdo->exec("CREATE UNIQUE INDEX unique_lrn_sy ON enrollments (lrn, school_year)");
		} catch (Exception $e) { /* ignore if already exists or if LRN contains NULLs that conflict */ }

		// --- SF3 ENHANCEMENTS ---
		$sf3_alters = [
			"ALTER TABLE sf3_reports ADD COLUMN quarter VARCHAR(20) NULL AFTER section",
			"ALTER TABLE sf3_reports ADD COLUMN semester VARCHAR(20) NULL AFTER quarter",
			"ALTER TABLE sf3_reports ADD COLUMN bosy_date DATE NULL AFTER semester",
			"ALTER TABLE sf3_reports ADD COLUMN eosy_date DATE NULL AFTER bosy_date",
			"ALTER TABLE sf3_reports ADD COLUMN prepared_by VARCHAR(255) NULL",
			"ALTER TABLE sf3_reports ADD COLUMN property_custodian VARCHAR(255) NULL",
			"ALTER TABLE sf3_reports ADD COLUMN school_head VARCHAR(255) NULL"
		];
		foreach ($sf3_alters as $sql) {
			try { $pdo->exec($sql); } catch (Exception $e) {}
		}

		$pdo->exec('CREATE TABLE IF NOT EXISTS sf3_audit_logs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			user_id INT NOT NULL,
			action VARCHAR(100) NOT NULL,
			report_id INT,
			details TEXT,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB');

		// --- SF10 ENHANCEMENTS ---
		$pdo->exec("CREATE TABLE IF NOT EXISTS conduct_records (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			school_year VARCHAR(20) NOT NULL,
			grade_level VARCHAR(50) NOT NULL,
			core_values TEXT NULL,
			remarks TEXT NULL,
			adviser_id INT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY unique_conduct (student_id, school_year)
		) ENGINE=InnoDB");

		$pdo->exec("CREATE TABLE IF NOT EXISTS school_history (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			school_name VARCHAR(255) NOT NULL,
			school_id VARCHAR(50) NULL,
			district VARCHAR(100) NULL,
			division VARCHAR(100) NULL,
			region VARCHAR(100) NULL,
			grade_level VARCHAR(50) NOT NULL,
			school_year VARCHAR(20) NOT NULL,
			general_average DECIMAL(5,2) NULL,
			promotion_status VARCHAR(50) NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");

		$pdo->exec("CREATE TABLE IF NOT EXISTS transfer_records (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			transfer_type ENUM('IN', 'OUT') NOT NULL,
			date_of_transfer DATE NOT NULL,
			school_name VARCHAR(255) NOT NULL,
			reason TEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");

		$pdo->exec("CREATE TABLE IF NOT EXISTS awards (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			school_year VARCHAR(20) NOT NULL,
			award_name VARCHAR(255) NOT NULL,
			award_type VARCHAR(100) NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");

		$pdo->exec("CREATE TABLE IF NOT EXISTS sf10_records (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id VARCHAR(50) NOT NULL,
			status ENUM('Draft', 'Verified', 'Finalized', 'Locked') DEFAULT 'Draft',
			verified_by INT NULL,
			verified_at TIMESTAMP NULL,
			finalized_by INT NULL,
			finalized_at TIMESTAMP NULL,
			remarks TEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY unique_sf10 (student_id)
		) ENGINE=InnoDB");

		// Redundant sf4 definitions removed to prevent schema confusion
		// (Already defined at lines 1195 and 1218)
	}


}

if (!function_exists('seed_users')) {
	function seed_users(PDO $pdo)
	{
		$users = [
			['admin', 'password', 'admin', 'John', 'Admin', 'Doe'],
			['registrar1', 'password', 'registrar', 'Jane', 'Registrar', 'Smith'],
			['teacher1', 'password', 'teacher', 'Michael', 'Teacher', 'Johnson'],
			['student1', 'password', 'student', 'Sarah', 'Student', 'Williams'],
			['employee1', 'password', 'employee', 'David', 'Employee', 'Brown'],
		];
		$ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, first_name, last_name, middle_name, approval_status) VALUES (?,?,?,?,?,?,?)');
		foreach ($users as $u) {
			list($username, $password, $role, $first_name, $last_name, $middle_name) = $u;
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$ins->execute([$username, $hash, $role, $first_name, $last_name, $middle_name, 'approved']);
		}
	}
}

if (!function_exists('seed_system_settings')) {
	function seed_system_settings(PDO $pdo)
	{
		$settings = [
			['school_name', 'Malolos Marine Fishery School and Laboratory', 'text', 'Name of the school'],
			['school_address', '123 Ocean Drive, Coastal City', 'text', 'School address'],
			['school_phone', '+63 2 1234 5678', 'text', 'School contact number'],
			['school_email', 'info@marinescience.edu.ph', 'text', 'School email address'],
			['current_school_year', '2024-2025', 'text', 'Current school year'],
			['max_students_per_section', '40', 'number', 'Maximum number of students per section'],
			['enable_online_enrollment', 'true', 'boolean', 'Enable online enrollment system'],
			['require_student_photo', 'true', 'boolean', 'Require student photo during enrollment'],
			['principal_name', 'Dr. Maria Santos', 'text', 'School Principal Name'],
			['class_adviser_name', 'Ms. Ana Cruz', 'text', 'Default Class Adviser Name'],
			['principal_esignature', '', 'text', 'Principal E-Signature Image Path'],
			['class_adviser_esignature', '', 'text', 'Class Adviser E-Signature Image Path'],
			['textbook_stage', 'inventory', 'text', 'Current textbook lifecycle stage (inventory, distribution, collection)'],
			['textbook_lock_status', '0', 'number', 'Global lock for textbook edits (0=Unlocked, 1=Locked)'],
			['textbook_deadline', '', 'text', 'Deadline for current textbook stage'],
			['textbook_default_per_learner', '7', 'number', 'Default number of books per student'],
			['textbook_shortage_threshold', '5', 'number', 'Alert threshold for low stock notifications'],
			['signatory_registrar', '', 'text', 'School Registrar Name'],
		];

		$stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_type = VALUES(setting_type), description = VALUES(description)');
		foreach ($settings as $setting) {
			$stmt->execute($setting);
		}

		// Seed default school days if none exist
		$stmt = $pdo->query('SELECT COUNT(*) FROM school_calendar');
		if ($stmt->fetchColumn() == 0) {
			$current_sy = get_current_school_year($pdo);
			if ($current_sy) {
				$months = ['June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May'];
				$stmt_cal = $pdo->prepare('INSERT INTO school_calendar (school_year, month, num_days) VALUES (?, ?, ?)');
				foreach ($months as $m) {
					$stmt_cal->execute([$current_sy, $m, 20]); // Default 20 days
				}
			}
		}
	}
}

// School Year Management Functions
if (!function_exists('get_current_school_year')) {
	function get_current_school_year($pdo)
	{
		$stmt = $pdo->query('SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1');
		$result = $stmt->fetch();
		return $result ? $result['school_year'] : null;
	}
}

if (!function_exists('set_current_school_year')) {
	function set_current_school_year($pdo, $school_year)
	{
		// First, unset current school year
		$pdo->exec('UPDATE school_years SET is_current = 0');

		// Set new current school year
		$stmt = $pdo->prepare('UPDATE school_years SET is_current = 1 WHERE school_year = ?');
		$stmt->execute([$school_year]);

		// Update system settings
		$stmt = $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = "current_school_year"');
		$stmt->execute([$school_year]);

		return $stmt->rowCount() > 0;
	}
}

if (!function_exists('archive_enrollments_for_school_year')) {
	function archive_enrollments_for_school_year($pdo, $school_year, $archived_by = null)
	{
		try {
			$pdo->beginTransaction();

			// Get all enrollments for the specified school year
			$stmt = $pdo->prepare('SELECT * FROM enrollments WHERE school_year = ?');
			$stmt->execute([$school_year]);
			$enrollments = $stmt->fetchAll();

			if (empty($enrollments)) {
				$pdo->rollBack();
				return ['success' => false, 'message' => 'No enrollments found for school year ' . $school_year];
			}

			// Insert enrollments into archive table
			$archive_stmt = $pdo->prepare('
			INSERT INTO enrollment_archives (
				original_enrollment_id, registration_id, student_id, student_name, 
				grade_level, section, school_year, lrn, birthdate, guardian_first, 
				guardian_last, guardian_contact, address, id_contact_person, 
				qr_code_path, enrolled_at, archived_by
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		');

			foreach ($enrollments as $enrollment) {
				$archive_stmt->execute([
					$enrollment['id'],
					$enrollment['registration_id'],
					$enrollment['student_id'],
					$enrollment['student_name'],
					$enrollment['grade_level'],
					$enrollment['section'],
					$enrollment['school_year'],
					$enrollment['lrn'],
					$enrollment['birthdate'],
					$enrollment['guardian_first'],
					$enrollment['guardian_last'],
					$enrollment['guardian_contact'],
					$enrollment['address'],
					$enrollment['id_contact_person'],
					$enrollment['qr_code_path'],
					$enrollment['enrolled_at'],
					$archived_by
				]);
			}

			// Delete enrollments from main table
			$delete_stmt = $pdo->prepare('DELETE FROM enrollments WHERE school_year = ?');
			$delete_stmt->execute([$school_year]);

			// Mark school year as archived
			$update_stmt = $pdo->prepare('UPDATE school_years SET is_archived = 1 WHERE school_year = ?');
			$update_stmt->execute([$school_year]);

			$pdo->commit();

			return [
				'success' => true,
				'message' => 'Successfully archived ' . count($enrollments) . ' enrollments for school year ' . $school_year
			];

		} catch (Exception $e) {
			$pdo->rollBack();
			return ['success' => false, 'message' => 'Failed to archive enrollments: ' . $e->getMessage()];
		}
	}
}

if (!function_exists('transition_to_new_school_year')) {
	function transition_to_new_school_year($pdo, $new_school_year, $start_date, $end_date, $archived_by = null)
	{
		try {
			$pdo->beginTransaction();

			// Get current school year
			$current_sy = get_current_school_year($pdo);

			// Archive current school year enrollments if they exist
			if ($current_sy) {
				$stmt = $pdo->prepare('SELECT * FROM enrollments WHERE school_year = ?');
				$stmt->execute([$current_sy]);
				$enrollments = $stmt->fetchAll();

				if (!empty($enrollments)) {
					// Insert into archive
					$archive_stmt = $pdo->prepare('
					INSERT INTO enrollment_archives (
						original_enrollment_id, registration_id, student_id, student_name, 
						grade_level, section, school_year, lrn, birthdate, guardian_first, 
						guardian_last, guardian_contact, address, id_contact_person, 
						qr_code_path, enrolled_at, archived_by
					) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				');

					foreach ($enrollments as $enrollment) {
						$archive_stmt->execute([
							$enrollment['id'],
							$enrollment['registration_id'] ?? null,
							$enrollment['student_id'],
							$enrollment['student_name'],
							$enrollment['grade_level'],
							$enrollment['section'],
							$enrollment['school_year'],
							$enrollment['lrn'] ?? null,
							$enrollment['birthdate'] ?? null,
							$enrollment['guardian_first'] ?? null,
							$enrollment['guardian_last'] ?? null,
							$enrollment['guardian_contact'] ?? null,
							$enrollment['address'] ?? null,
							$enrollment['id_contact_person'] ?? 'guardian',
							$enrollment['qr_code_path'] ?? null,
							$enrollment['enrolled_at'] ?? null,
							$archived_by
						]);
					}

					// Delete archived enrollments
					$delete_stmt = $pdo->prepare('DELETE FROM enrollments WHERE school_year = ?');
					$delete_stmt->execute([$current_sy]);
				}

				// Mark old school year as archived and not current
				$pdo->prepare('UPDATE school_years SET is_archived = 1, is_current = 0 WHERE school_year = ?')->execute([$current_sy]);
			}

			// Unset any current school year flags
			$pdo->exec('UPDATE school_years SET is_current = 0');

			// Create new school year record
			$stmt = $pdo->prepare('INSERT INTO school_years (school_year, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
			$stmt->execute([$new_school_year, $start_date, $end_date]);

			// Update system settings
			$stmt = $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = "current_school_year"');
			$stmt->execute([$new_school_year]);

			$pdo->commit();

			$archived_count = isset($enrollments) ? count($enrollments) : 0;
			return [
				'success' => true,
				'message' => 'Successfully transitioned to new school year ' . $new_school_year . ($archived_count > 0 ? ' (' . $archived_count . ' enrollments archived)' : ''),
				'archived_count' => $archived_count
			];

		} catch (Exception $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'message' => 'Failed to transition to new school year: ' . $e->getMessage()];
		}
	}
}

if (!function_exists('get_school_year_list')) {
	function get_school_year_list($pdo)
	{
		$stmt = $pdo->query('SELECT * FROM school_years ORDER BY school_year DESC');
		return $stmt->fetchAll();
	}
}

if (!function_exists('get_archived_enrollments')) {
	function get_archived_enrollments($pdo, $school_year = null)
	{
		if ($school_year) {
			$stmt = $pdo->prepare('SELECT * FROM enrollment_archives WHERE school_year = ? ORDER BY archived_at DESC');
			$stmt->execute([$school_year]);
		} else {
			$stmt = $pdo->query('SELECT * FROM enrollment_archives ORDER BY archived_at DESC');
		}
		return $stmt->fetchAll();
	}
}

/**
 * Get a system setting by key
 */
if (!function_exists('get_active_school_year')) {
    /**
     * Retrieves the currently active school year.
     * Priority: 1. school_years.is_current, 2. system_settings.current_school_year, 3. Academic calendar fallback
     */
    function get_active_school_year(PDO $pdo) {
        // 1. Check school_years table
        $stmt = $pdo->query("SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1");
        $sy = $stmt->fetchColumn();
        
        // 2. Fallback to system_settings
        if (!$sy) {
            $sy = get_system_setting($pdo, 'current_school_year');
        }
        
        // 3. Fallback to academic calendar calculation
        if (!$sy) {
            $current_month = (int)date('n');
            $current_year = (int)date('Y');
            if ($current_month < 6) {
                $sy = ($current_year - 1) . '-' . $current_year;
            } else {
                $sy = $current_year . '-' . ($current_year + 1);
            }
        }
        return $sy;
    }
}

if (!function_exists('get_system_setting')) {
	function get_system_setting($pdo, $key, $default = '')
	{
		try {
			$stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
			$stmt->execute([$key]);
			$val = $stmt->fetchColumn();
			$val = ($val !== false && $val !== null) ? trim($val) : false;

			// Fallback for principal name standardization
			if ($key === 'principal_name' && ($val === false || $val === '')) {
				$stmt->execute(['signatory_principal']);
				$val = $stmt->fetchColumn();
				$val = ($val !== false && $val !== null) ? trim($val) : false;
			}

			return ($val !== false && $val !== '') ? $val : $default;
		} catch (Exception $e) {
			return $default;
		}
	}
}
