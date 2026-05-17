## System Modules

This document lists the modules in the system and (where applicable) maps them to existing pages in the codebase.

### Admin Module
- **Manage users**: `admin/user_management.php`
- **Assign teachers**: `admin/teacher_approval.php` (approvals) + teacher loads are handled via tables used by `teacher/my_classes.php` (e.g., `subject_teachers`, `position_assignments`)
- **Manage subjects**: (data model present; UI pages vary) related tables used in `teacher/my_classes.php` and `teacher/grades.php`
- **Manage sections**: `admin/section_management.php`
- **System configuration**: `admin/settings_admin.php` + settings read from `system_settings` (e.g., in `teacher/reports/sf2_form.php`)
- **Generate all reports**:
  - **School Forms**: `admin/reports/school_forms/dashboard.php` (SF1–SF10 templates exist under `admin/reports/school_forms/`)
  - **Other reports index**: `admin/reports/index.php`

### Registrar Module
- **Student registration**: `registration_final.php`
- **Enrollment management**: `registrar/enrollment.php`
- **Section assignment**: (implied by enrollment/section management flows) see `registrar/enrollment.php`
- **Student records**: `registrar/dashboard.php` (entry) + enrollment/registration pages as above
- **Generate SF1, SF4, SF6, SF8, SF10**:
  - **School Forms dashboard**: `registrar/reports/school_forms/dashboard.php`
  - **Forms**: `registrar/reports/school_forms/sf1.php`, `sf4.php`, `sf6.php`, `sf8.php`, `sf10.php` (and others also present)

### Teacher Module
- **Enrollment management**: class/advisory roster management is in `teacher/advisory_list.php`
- **Encode grades**: `teacher/grades.php`
- **Encode attendance**: `teacher/reports/sf2_form.php` (SF2 daily attendance)
- **View class list**: `teacher/my_classes.php` (assigned loads) + `teacher/advisory_list.php` (advisory masterlist)
- **Generate SF2, SF5, SF9**:
  - `teacher/reports/sf2_form.php`
  - `teacher/reports/sf5_form.php`
  - `teacher/reports/sf9_form.php`

