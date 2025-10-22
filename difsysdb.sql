-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 24, 2025 at 08:07 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `difsysdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `applicantlist`
--

CREATE TABLE `applicantlist` (
  `id` int(11) NOT NULL,
  `app_id` int(11) DEFAULT NULL,
  `firstName` varchar(50) DEFAULT NULL,
  `lastName` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `number` varchar(20) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `middle_name` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `date_of_birth` varchar(50) DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `citizenship` varchar(100) DEFAULT NULL,
  `height` varchar(20) DEFAULT NULL,
  `weight` varchar(20) DEFAULT NULL,
  `objective` text DEFAULT NULL,
  `education` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`education`)),
  `work_experience` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`work_experience`)),
  `skills` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`skills`)),
  `traits` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`traits`)),
  `resume_status` varchar(15) DEFAULT 'No Resume'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applicant_files`
--

CREATE TABLE `applicant_files` (
  `id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_content` longblob NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT 'application/pdf',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applicant_requirements`
--

CREATE TABLE `applicant_requirements` (
  `id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`requirements`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendancelist`
--

CREATE TABLE `attendancelist` (
  `id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `firstName` varchar(50) NOT NULL,
  `lastName` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `present` tinyint(1) DEFAULT 0,
  `absent` tinyint(1) DEFAULT 0,
  `on_leave` tinyint(1) DEFAULT 0,
  `status` varchar(50) DEFAULT NULL,
  `total_workhours` int(10) DEFAULT NULL,
  `break_time` int(10) DEFAULT NULL,
  `overtime` int(10) DEFAULT NULL,
  `late_minutes` int(11) DEFAULT 0 COMMENT 'Minutes late beyond 8:10 AM grace period',
  `work_start_time` time DEFAULT NULL COMMENT 'Effective work start time (adjusted to 8:00 AM if early)',
  `break_start_time` time DEFAULT NULL COMMENT 'Break start time if applicable',
  `break_end_time` time DEFAULT NULL COMMENT 'Break end time if applicable',
  `break_duration` int(11) DEFAULT 0 COMMENT 'Total break duration in minutes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `undertime_minutes` int(11) DEFAULT 0,
  `late_undertime` int(11) DEFAULT 0 COMMENT 'Combined late and undertime minutes',
  `late` int(10) DEFAULT NULL,
  `regular_ot_nd` int(11) DEFAULT 0 COMMENT 'Regular OT + Night Differential minutes',
  `rest_day_ot` int(11) DEFAULT 0 COMMENT 'Rest Day OT minutes',
  `rest_day_ot_plus_ot` int(11) DEFAULT 0 COMMENT 'Rest Day OT + OT minutes',
  `rest_day_nd` int(11) DEFAULT 0 COMMENT 'Rest Day Night Differential minutes',
  `regular_holiday` int(11) DEFAULT 0 COMMENT 'Regular Holiday work hours in minutes',
  `regular_holiday_ot` int(11) DEFAULT 0 COMMENT 'Regular Holiday OT minutes',
  `regular_holiday_nd` int(11) DEFAULT 0 COMMENT 'Regular Holiday + Night Diff minutes',
  `regular_holiday_rot_ot` int(11) DEFAULT 0 COMMENT 'Regular Holiday + ROT + OT minutes',
  `special_holiday` int(11) DEFAULT 0 COMMENT 'Special Holiday work hours in minutes',
  `special_holiday_ot` int(11) DEFAULT 0 COMMENT 'Special Holiday OT minutes',
  `special_holiday_nd` int(11) DEFAULT 0 COMMENT 'Special Holiday + Night Diff minutes',
  `special_holiday_rot` int(11) DEFAULT 0,
  `special_holiday_rot_ot` int(11) DEFAULT 0 COMMENT 'Special Holiday + ROT + OT minutes',
  `special_holiday_rot_nd` int(11) DEFAULT 0 COMMENT 'Special Holiday + ROT + ND minutes',
  `current_payroll_period_id` int(11) DEFAULT NULL COMMENT 'Reference to current payroll period',
  `is_holiday` tinyint(1) DEFAULT 0 COMMENT 'Flag if this date is a holiday',
  `holiday_type` enum('Regular','Special') DEFAULT NULL COMMENT 'Type of holiday if applicable',
  `regular_ot` int(11) DEFAULT NULL,
  `is_rest_day` tinyint(1) DEFAULT 0 COMMENT 'Flag if this date is employee rest day',
  `shift_type` enum('day','night') DEFAULT 'day' COMMENT 'Type of shift worked',
  `session_count` int(11) DEFAULT 1 COMMENT 'Number of time in/out sessions',
  `session_data` text DEFAULT NULL COMMENT 'JSON data of all time in/out sessions',
  `last_time_in` time DEFAULT NULL COMMENT 'Most recent time in for re-entry tracking',
  `accumulated_hours` int(11) DEFAULT 0 COMMENT 'Accumulated work hours from previous sessions in minutes',
  `night_differential` int(11) DEFAULT 0,
  `session_type` int(11) DEFAULT 1 COMMENT 'Session number for re-entries'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL,
  `attendance_id` int(11) DEFAULT NULL,
  `emp_id` int(11) NOT NULL,
  `action_type` enum('time_in','time_out','break_start','break_end','auto_absent','manual_edit') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `source` enum('web','biometric','manual','system') DEFAULT 'web',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `attendance_report`
-- (See below for the actual view)
--
CREATE TABLE `attendance_report` (
`id` int(11)
,`emp_id` int(11)
,`employee_name` varchar(101)
,`position` varchar(100)
,`workarrangement` varchar(20)
,`date` date
,`time_in` time
,`time_out` time
,`total_workhours` int(10)
,`overtime` int(10)
,`late` int(10)
,`late_minutes` int(11)
,`status` varchar(50)
,`present` tinyint(1)
,`absent` tinyint(1)
,`on_leave` tinyint(1)
,`computed_status` varchar(15)
,`day_type` varchar(7)
,`holiday_name` varchar(255)
,`created_at` timestamp
,`updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_settings`
--

CREATE TABLE `attendance_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','integer','float','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_settings`
--

INSERT INTO `attendance_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'work_days', '1,2,3,4,5', 'string', 'Working days (1=Monday, 7=Sunday)', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(2, 'grace_period_minutes', '10', 'integer', 'Grace period before marking as late', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(3, 'auto_break_enabled', '1', 'boolean', 'Enable automatic lunch break', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(4, 'overtime_threshold_hours', '8', 'integer', 'Hours after which overtime starts', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(5, 'weekend_attendance_allowed', '0', 'boolean', 'Allow attendance on weekends', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(6, 'max_work_hours_per_day', '12', 'integer', 'Maximum work hours per day', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(7, 'auto_absent_time', '18:00:00', 'string', 'Time to automatically mark absent employees', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(8, 'biometric_sync_interval', '30', 'integer', 'Biometric sync interval in seconds', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(9, 'late_notification_enabled', '1', 'boolean', 'Send notifications for late arrivals', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47'),
(10, 'overtime_approval_required', '0', 'boolean', 'Require approval for overtime', 1, '2025-07-12 17:07:03', '2025-07-12 17:07:47');

-- --------------------------------------------------------

--
-- Table structure for table `benefit_files`
--

CREATE TABLE `benefit_files` (
  `id` int(11) NOT NULL,
  `benefit_id` int(11) NOT NULL COMMENT 'References employee_benefits.id',
  `file_name` varchar(255) NOT NULL COMMENT 'Generated unique filename',
  `original_name` varchar(255) NOT NULL COMMENT 'Original uploaded filename',
  `file_type` varchar(100) NOT NULL COMMENT 'MIME type of the file',
  `file_size` int(11) NOT NULL COMMENT 'File size in bytes',
  `file_path` varchar(500) NOT NULL COMMENT 'Full path to the uploaded file',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `department_list`
--

CREATE TABLE `department_list` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `work_arrangement` enum('On-site','Remote','Hybrid') NOT NULL DEFAULT 'On-site',
  `status` varchar(50) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employeelist`
--

CREATE TABLE `employeelist` (
  `emp_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `firstName` varchar(50) DEFAULT NULL,
  `lastName` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `work_days` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `number` int(11) DEFAULT NULL,
  `workarrangement` varchar(20) DEFAULT NULL,
  `fingerprint_uid` int(10) DEFAULT NULL,
  `id` int(10) DEFAULT NULL,
  `total_leave_days` int(11) DEFAULT 15,
  `used_leave_days` int(11) DEFAULT 0,
  `remaining_leave_days` int(11) DEFAULT 15,
  `schedule_id` int(11) DEFAULT 1,
  `overtime_rate` decimal(5,2) DEFAULT 1.50 COMMENT 'Overtime rate multiplier',
  `max_overtime_hours` int(11) DEFAULT 4 COMMENT 'Maximum overtime hours per day',
  `rest_day` varchar(50) DEFAULT 'Saturday - Sunday',
  `date_hired` date DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employeelist`
--

INSERT INTO `employeelist` (`emp_id`, `department_id`, `firstName`, `lastName`, `email`, `role`, `position`, `work_days`, `status`, `address`, `number`, `workarrangement`, `fingerprint_uid`, `id`, `total_leave_days`, `used_leave_days`, `remaining_leave_days`, `schedule_id`, `overtime_rate`, `max_overtime_hours`, `rest_day`, `date_hired`, `department`) VALUES
(117, NULL, 'Emil', 'Santos', 'emil.santos@gmail.com', 'employee', 'Software Developer', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(118, NULL, 'Emilio', 'Rodriguez', 'emilio.rodriguez@gmail.com', 'employee', 'Data Analyst', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(119, NULL, 'Melissa', 'Cruz', 'melissa.cruz@gmail.com', 'employee', 'UI/UX Designer', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(120, NULL, 'Mikka', 'Dela Rosa', 'mikka.delarosa@gmail.com', 'employee', 'Project Manager', 'Monday-Friday', 'active', NULL, NULL, 'Work From Home', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(121, NULL, 'Dominic', 'Garcia', 'dominic.garcia@gmail.com', 'employee', 'Software Developer', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(122, NULL, 'Aldrin', 'Reyes', 'aldrin.reyes@gmail.com', 'employee', 'System Administrator', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(123, NULL, 'JM', 'Villanueva', 'jm.villanueva@gmail.com', 'employee', 'Data Analyst', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(124, NULL, 'Analyn', 'Mendoza', 'jaslyn.mendoza@gmail.com', 'employee', 'UI/UX Designer', 'Monday-Friday', 'active', NULL, NULL, 'Work From Home', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(125, NULL, 'Jonathan', 'Torres', 'jonathan.torres@gmail.com', 'employee', 'Project Manager', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(126, NULL, 'Paolo', 'Morales', 'paolo.morales@gmail.com', 'employee', 'Software Developer', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(127, NULL, 'Tan', 'Lim', 'tan.lim@gmail.com', 'employee', 'Data Analyst', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(128, NULL, 'Ericka', 'Flores', 'ericka.flores@gmail.com', 'employee', 'UI/UX Designer', 'Monday-Friday', 'active', NULL, NULL, 'Work From Home', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(129, NULL, 'Bryan', 'Aquino', 'bryan.aquino@gmail.com', 'employee', 'System Administrator', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(130, NULL, 'Jelvin', 'Castillo', 'jelvin.castillo@gmail.com', 'employee', 'Project Manager', 'Monday-Friday', 'active', NULL, NULL, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(131, NULL, 'Employee', 'WFH', 'emp1@gmail.com', 'employee', 'Software Developer', 'Monday-Friday', 'active', '', 0, 'Work From Home', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL),
(132, NULL, 'Employee', 'OnSite', 'emp2@gmail.com', 'employee', 'Software Developer', 'Monday-Friday', 'active', '', 0, 'On-Site', NULL, NULL, 15, 0, 15, 1, 1.50, 4, 'Saturday - Sunday', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `employee_benefits`
--

CREATE TABLE `employee_benefits` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'References employeelist.emp_id',
  `payroll_period_id` int(11) NOT NULL COMMENT 'References payroll_periods.id',
  `status` enum('pending','completed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `released_at` timestamp NULL DEFAULT NULL COMMENT 'When payslip was released',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `employee_dashboard_stats`
-- (See below for the actual view)
--
CREATE TABLE `employee_dashboard_stats` (
`employee_id` int(11)
,`total_tickets` bigint(21)
,`open_tickets` decimal(22,0)
,`in_progress_tickets` decimal(22,0)
,`closed_tickets` decimal(22,0)
,`cancelled_tickets` decimal(22,0)
,`unread_tickets` decimal(22,0)
);

-- --------------------------------------------------------

--
-- Table structure for table `employee_files`
--

CREATE TABLE `employee_files` (
  `id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_content` longblob NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT 'application/pdf',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `migrated_from_applicant` tinyint(1) DEFAULT 0,
  `original_applicant_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_payroll`
--

CREATE TABLE `employee_payroll` (
  `id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `basic_pay_monthly` decimal(15,2) DEFAULT 0.00,
  `basic_pay_semi_monthly` decimal(15,2) DEFAULT 0.00,
  `rate_per_day` decimal(10,2) DEFAULT 0.00,
  `rate_per_hour` decimal(10,2) DEFAULT 0.00,
  `rate_per_minute` decimal(10,2) DEFAULT 0.00,
  `non_taxable_allowances_enabled` tinyint(1) DEFAULT 0,
  `site_allowance` decimal(10,2) DEFAULT 0.00,
  `transportation_allowance` decimal(10,2) DEFAULT 0.00,
  `number_of_training_days` int(11) DEFAULT 0,
  `regular_ot_rate` decimal(10,2) DEFAULT 0.00,
  `regular_ot_nd` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot_plus_ot` decimal(10,2) DEFAULT 0.00,
  `rest_day_nd` decimal(10,2) DEFAULT 0.00,
  `rh_rate` decimal(10,2) DEFAULT 0.00,
  `rh_ot_rate` decimal(10,2) DEFAULT 0.00,
  `rh_nd_rate` decimal(10,2) DEFAULT 0.00,
  `rh_rot_ot_rate` decimal(10,2) DEFAULT 0.00,
  `sh_rate` decimal(10,2) DEFAULT 0.00,
  `sh_ot_rate` decimal(10,2) DEFAULT 0.00,
  `sh_nd_rate` decimal(10,2) DEFAULT 0.00,
  `sh_rot_ot_rate` decimal(10,2) DEFAULT 0.00,
  `sh_rot_ot_plus_ot_rate` decimal(10,2) DEFAULT 0.00,
  `sh_rot_nd` decimal(10,2) DEFAULT 0.00,
  `sh_rot` decimal(10,2) DEFAULT 0.00,
  `sss` decimal(10,2) DEFAULT 0.00,
  `phic` decimal(10,2) DEFAULT 0.00,
  `hdmf` decimal(10,2) DEFAULT 0.00,
  `tax` decimal(10,2) DEFAULT 0.00,
  `sss_loan` decimal(10,2) DEFAULT 0.00,
  `hdmf_loan` decimal(10,2) DEFAULT 0.00,
  `teed` decimal(10,2) DEFAULT 0.00,
  `staff_house` decimal(10,2) DEFAULT 0.00,
  `cash_advance` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sss_account` varchar(20) DEFAULT NULL,
  `phic_account` varchar(20) DEFAULT NULL,
  `hdmf_account` varchar(20) DEFAULT NULL,
  `tax_account` varchar(20) DEFAULT NULL,
  `grosspay` int(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `employee_payroll`
--
DELIMITER $$
CREATE TRIGGER `after_employee_payroll_update` AFTER UPDATE ON `employee_payroll` FOR EACH ROW BEGIN
    -- Track basic pay changes
    IF OLD.basic_pay_monthly != NEW.basic_pay_monthly THEN
        INSERT INTO payroll_history (emp_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, 'basic_pay_monthly', OLD.basic_pay_monthly, NEW.basic_pay_monthly, 'System Update', CURDATE());
    END IF;
    
    -- Track SSS changes
    IF OLD.sss != NEW.sss THEN
        INSERT INTO payroll_history (emp_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, 'sss', OLD.sss, NEW.sss, 'Benefits Update', CURDATE());
    END IF;
    
    -- Track PHIC changes
    IF OLD.phic != NEW.phic THEN
        INSERT INTO payroll_history (emp_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, 'phic', OLD.phic, NEW.phic, 'Benefits Update', CURDATE());
    END IF;
    
    -- Track HDMF changes
    IF OLD.hdmf != NEW.hdmf THEN
        INSERT INTO payroll_history (emp_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, 'hdmf', OLD.hdmf, NEW.hdmf, 'Benefits Update', CURDATE());
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `employee_payroll_summary`
-- (See below for the actual view)
--
CREATE TABLE `employee_payroll_summary` (
`emp_id` int(11)
,`firstName` varchar(50)
,`lastName` varchar(50)
,`position` varchar(100)
,`status` varchar(50)
,`basic_pay_monthly` decimal(15,2)
,`basic_pay_semi_monthly` decimal(15,2)
,`rate_per_day` decimal(10,2)
,`rate_per_hour` decimal(10,2)
,`sss` decimal(10,2)
,`phic` decimal(10,2)
,`hdmf` decimal(10,2)
,`tax` decimal(10,2)
,`payroll_created` timestamp
,`payroll_updated` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `employee_payslip_pdfs`
--

CREATE TABLE `employee_payslip_pdfs` (
  `id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `pdf_filename` varchar(255) NOT NULL,
  `pdf_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','archived') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `location` varchar(255) NOT NULL,
  `status` enum('active','cancelled','completed') DEFAULT 'active',
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `examinations`
--

CREATE TABLE `examinations` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `duration` int(11) NOT NULL COMMENT 'Duration in minutes',
  `questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`questions`)),
  `status` enum('Draft','Active','Inactive') DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_assignments`
--

CREATE TABLE `exam_assignments` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `due_date` datetime DEFAULT NULL,
  `status` enum('Assigned','In Progress','Completed','Overdue') DEFAULT 'Assigned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_attempts`
--

CREATE TABLE `exam_attempts` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answers`)),
  `total_score` decimal(5,2) DEFAULT 0.00,
  `max_score` decimal(5,2) DEFAULT 0.00,
  `time_taken` int(11) DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('Not Started','In Progress','Completed','Submitted') DEFAULT 'Not Started',
  `current_question_index` int(11) DEFAULT 0,
  `time_elapsed` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hiring_positions`
--

CREATE TABLE `hiring_positions` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `short_description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`requirements`)),
  `duties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`duties`)),
  `status` enum('active','inactive','closed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `holiday_id` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `holiday_type` enum('Regular','Special') NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `hr_dashboard_stats`
-- (See below for the actual view)
--
CREATE TABLE `hr_dashboard_stats` (
`total_tickets` bigint(21)
,`open_tickets` decimal(22,0)
,`in_progress_tickets` decimal(22,0)
,`closed_tickets` decimal(22,0)
,`cancelled_tickets` decimal(22,0)
,`unread_hr_tickets` decimal(22,0)
,`unread_active_tickets` decimal(22,0)
);

-- --------------------------------------------------------

--
-- Table structure for table `inquiries`
--

CREATE TABLE `inquiries` (
  `id` varchar(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `status` enum('Open','In Progress','Closed','Cancelled') DEFAULT 'Open',
  `date_submitted` date NOT NULL,
  `date_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `unread_hr` tinyint(1) DEFAULT 1,
  `unread_employee` tinyint(1) DEFAULT 0,
  `original_ticket_id` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Main table storing all employee inquiries and tickets';

--
-- Triggers `inquiries`
--
DELIMITER $$
CREATE TRIGGER `inquiry_status_change_log` AFTER UPDATE ON `inquiries` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO inquiry_activities (ticket_id, activity_type, description, old_value, new_value)
        VALUES (NEW.id, 'status_changed', CONCAT('Status changed from ', OLD.status, ' to ', NEW.status), OLD.status, NEW.status);
    END IF;
    
    IF OLD.priority != NEW.priority THEN
        INSERT INTO inquiry_activities (ticket_id, activity_type, description, old_value, new_value)
        VALUES (NEW.id, 'priority_changed', CONCAT('Priority changed from ', OLD.priority, ' to ', NEW.priority), OLD.priority, NEW.priority);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `inquiry_activities`
--

CREATE TABLE `inquiry_activities` (
  `id` int(11) NOT NULL,
  `ticket_id` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` enum('created','replied','status_changed','priority_changed','assigned','closed','cancelled','reopened') NOT NULL,
  `description` text DEFAULT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Activity log for tracking all changes and actions on tickets';

-- --------------------------------------------------------

--
-- Table structure for table `inquiry_assignments`
--

CREATE TABLE `inquiry_assignments` (
  `id` int(11) NOT NULL,
  `ticket_id` varchar(20) NOT NULL,
  `hr_user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','transferred','completed') DEFAULT 'active',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='HR staff assignments for ticket management';

-- --------------------------------------------------------

--
-- Table structure for table `inquiry_messages`
--

CREATE TABLE `inquiry_messages` (
  `id` int(11) NOT NULL,
  `ticket_id` varchar(20) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `sender_type` enum('employee','hr') NOT NULL,
  `message` text NOT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores all messages/replies for each inquiry ticket';

--
-- Triggers `inquiry_messages`
--
DELIMITER $$
CREATE TRIGGER `inquiry_message_log` AFTER INSERT ON `inquiry_messages` FOR EACH ROW BEGIN
    INSERT INTO inquiry_activities (ticket_id, user_id, activity_type, description)
    VALUES (NEW.ticket_id, NEW.sender_id, 'replied', CONCAT(NEW.sender_type, ' added a reply'));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `inquiry_summary`
-- (See below for the actual view)
--
CREATE TABLE `inquiry_summary` (
`id` varchar(20)
,`employee_id` int(11)
,`employee_name` varchar(101)
,`employee_email` varchar(50)
,`subject` varchar(255)
,`description` text
,`priority` enum('Low','Medium','High')
,`status` enum('Open','In Progress','Closed','Cancelled')
,`date_submitted` date
,`date_updated` timestamp
,`unread_hr` tinyint(1)
,`unread_employee` tinyint(1)
,`original_ticket_id` varchar(20)
,`message_count` bigint(21)
,`last_message_time` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `latest_payroll_calculations`
-- (See below for the actual view)
--
CREATE TABLE `latest_payroll_calculations` (
`id` int(11)
,`emp_id` int(11)
,`payroll_period_start` date
,`payroll_period_end` date
,`payroll_type` varchar(12)
,`basic_pay` decimal(15,2)
,`overtime_pay` decimal(15,2)
,`holiday_pay` decimal(15,2)
,`night_differential` int(1)
,`allowances` decimal(10,2)
,`bonuses` int(1)
,`incentives` int(1)
,`gross_pay` decimal(15,2)
,`sss_deduction` decimal(10,2)
,`phic_deduction` decimal(10,2)
,`hdmf_deduction` decimal(10,2)
,`tax_deduction` decimal(10,2)
,`sss_loan_deduction` decimal(10,2)
,`hdmf_loan_deduction` decimal(10,2)
,`other_deductions` decimal(11,2)
,`cash_advance_deduction` decimal(10,2)
,`total_deductions` decimal(15,2)
,`net_pay` decimal(15,2)
,`regular_hours` decimal(10,2)
,`overtime_hours` decimal(12,2)
,`holiday_hours` decimal(11,2)
,`night_diff_hours` int(1)
,`status` varchar(9)
,`calculated_by` binary(0)
,`approved_by` binary(0)
,`processed_date` timestamp
,`created_at` timestamp
,`updated_at` timestamp
,`firstName` varchar(50)
,`lastName` varchar(50)
,`position` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `late_arrivals_report`
-- (See below for the actual view)
--
CREATE TABLE `late_arrivals_report` (
`emp_id` int(11)
,`employee_name` varchar(101)
,`position` varchar(100)
,`date` date
,`time_in` time
,`late_minutes` int(11)
,`late_category` varchar(11)
,`status` varchar(50)
,`month` int(2)
,`year` int(4)
);

-- --------------------------------------------------------

--
-- Table structure for table `leave_attachments`
--

CREATE TABLE `leave_attachments` (
  `id` int(11) NOT NULL,
  `leave_request_id` int(11) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_comments`
--

CREATE TABLE `leave_comments` (
  `id` int(11) NOT NULL,
  `leave_request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('employee','hr','admin') NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `max_days_per_year` int(11) DEFAULT 0,
  `requires_approval` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `type_name`, `description`, `max_days_per_year`, `requires_approval`, `is_active`, `created_at`) VALUES
(8, 'Vacation Leave', 'Annual vacation leave', 30, 1, 1, '2025-07-12 13:28:15'),
(9, 'Sick Leave', 'Medical sick leave', 15, 1, 1, '2025-07-12 13:28:15');

-- --------------------------------------------------------

--
-- Table structure for table `otp_attempts`
--

CREATE TABLE `otp_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `attempts` int(11) DEFAULT 1,
  `last_attempt` datetime DEFAULT current_timestamp(),
  `blocked_until` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `overtime_report`
-- (See below for the actual view)
--
CREATE TABLE `overtime_report` (
`emp_id` int(11)
,`employee_name` varchar(101)
,`position` varchar(100)
,`date` date
,`time_in` time
,`time_out` time
,`total_hours` decimal(14,2)
,`overtime_hours` decimal(14,2)
,`overtime_pay_multiplier` decimal(17,2)
,`status` varchar(50)
,`month` int(2)
,`year` int(4)
);

-- --------------------------------------------------------

--
-- Table structure for table `payroll_computations`
--

CREATE TABLE `payroll_computations` (
  `id` int(11) NOT NULL,
  `payroll_record_id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `basic_pay_monthly` decimal(15,2) DEFAULT 0.00,
  `basic_pay_semi_monthly` decimal(15,2) DEFAULT 0.00,
  `rate_per_day` decimal(10,2) DEFAULT 0.00,
  `rate_per_hour` decimal(10,2) DEFAULT 0.00,
  `rate_per_minute` decimal(10,2) DEFAULT 0.00,
  `site_allowance` decimal(10,2) DEFAULT 0.00,
  `transportation_allowance` decimal(10,2) DEFAULT 0.00,
  `total_allowances` decimal(10,2) DEFAULT 0.00,
  `regular_hours` decimal(10,2) DEFAULT 0.00,
  `regular_hours_amount` decimal(15,2) DEFAULT 0.00,
  `regular_ot_hours` decimal(10,2) DEFAULT 0.00,
  `regular_ot_amount` decimal(15,2) DEFAULT 0.00,
  `regular_ot_rate` decimal(10,2) DEFAULT 0.00,
  `regular_ot_nd_hours` decimal(10,2) DEFAULT 0.00,
  `regular_ot_nd_amount` decimal(15,2) DEFAULT 0.00,
  `regular_ot_nd_rate` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot_hours` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot_amount` decimal(15,2) DEFAULT 0.00,
  `rest_day_ot_rate` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot_plus_ot_hours` decimal(10,2) DEFAULT 0.00,
  `rest_day_ot_plus_ot_amount` decimal(15,2) DEFAULT 0.00,
  `rest_day_ot_plus_ot_rate` decimal(10,2) DEFAULT 0.00,
  `rest_day_nd_hours` decimal(10,2) DEFAULT 0.00,
  `rest_day_nd_amount` decimal(15,2) DEFAULT 0.00,
  `rest_day_nd_rate` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_hours` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_amount` decimal(15,2) DEFAULT 0.00,
  `regular_holiday_rate` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_ot_hours` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_ot_amount` decimal(15,2) DEFAULT 0.00,
  `regular_holiday_ot_rate` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_nd_hours` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_nd_amount` decimal(15,2) DEFAULT 0.00,
  `regular_holiday_nd_rate` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_rot_hours` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_rot_amount` decimal(15,2) DEFAULT 0.00,
  `regular_holiday_rot_rate` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_rot_ot_hours` decimal(10,2) DEFAULT 0.00,
  `regular_holiday_rot_ot_amount` decimal(15,2) DEFAULT 0.00,
  `regular_holiday_rot_ot_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_ot_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_ot_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_ot_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_nd_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_nd_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_nd_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_rot_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_ot_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_ot_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_rot_ot_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_nd_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_nd_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_rot_nd_rate` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_ot_plus_ot_hours` decimal(10,2) DEFAULT 0.00,
  `special_holiday_rot_ot_plus_ot_amount` decimal(15,2) DEFAULT 0.00,
  `special_holiday_rot_ot_plus_ot_rate` decimal(10,2) DEFAULT 0.00,
  `total_holiday_pay` decimal(15,2) DEFAULT 0.00,
  `total_overtime_pay` decimal(15,2) DEFAULT 0.00,
  `gross_pay` decimal(15,2) DEFAULT 0.00,
  `late_undertime_minutes` int(11) DEFAULT 0,
  `late_undertime_rate` decimal(10,2) DEFAULT 0.00,
  `late_undertime_amount` decimal(15,2) DEFAULT 0.00,
  `absences_days` decimal(5,2) DEFAULT 0.00,
  `absences_rate` decimal(10,2) DEFAULT 0.00,
  `absences_amount` decimal(15,2) DEFAULT 0.00,
  `sss_contribution` decimal(10,2) DEFAULT 0.00,
  `phic_contribution` decimal(10,2) DEFAULT 0.00,
  `hdmf_contribution` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `sss_loan` decimal(10,2) DEFAULT 0.00,
  `hdmf_loan` decimal(10,2) DEFAULT 0.00,
  `teed` decimal(10,2) DEFAULT 0.00,
  `staff_house` decimal(10,2) DEFAULT 0.00,
  `cash_advance` decimal(10,2) DEFAULT 0.00,
  `total_deductions` decimal(15,2) DEFAULT 0.00,
  `net_pay` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_generation_history`
--

CREATE TABLE `payroll_generation_history` (
  `id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `total_employees` int(11) DEFAULT 0,
  `total_amount` decimal(18,2) DEFAULT 0.00 CHECK (`total_amount` >= 0),
  `generation_status` enum('Processing','Completed','Failed') DEFAULT 'Processing',
  `file_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `payroll_generation_summary`
-- (See below for the actual view)
--
CREATE TABLE `payroll_generation_summary` (
`id` int(11)
,`payroll_id` varchar(6)
,`payroll_period_id` int(11)
,`prp_id` varchar(10)
,`payroll_period` varchar(149)
,`date_from` date
,`date_to` date
,`total_employees` int(11)
,`total_amount` decimal(18,2)
,`status` enum('Processing','Completed','Failed')
,`created_at` timestamp
,`completed_at` timestamp
,`actual_employee_count` bigint(21)
,`generated_count` decimal(22,0)
,`released_count` decimal(22,0)
,`actual_total_amount` decimal(37,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `payroll_history`
--

CREATE TABLE `payroll_history` (
  `id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `payroll_period_id` int(11) DEFAULT NULL,
  `field_changed` varchar(100) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_history`
--

INSERT INTO `payroll_history` (`id`, `emp_id`, `payroll_period_id`, `field_changed`, `old_value`, `new_value`, `change_reason`, `effective_from`, `created_at`) VALUES
(749, 128, NULL, 'gross_pay', '25000.00', '26149.44', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(750, 128, NULL, 'net_pay', '25000.00', '26149.44', 'Net Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(751, 129, NULL, 'gross_pay', '14000.00', '15287.36', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(752, 129, NULL, 'net_pay', '14000.00', '15287.36', 'Net Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(753, 130, NULL, 'gross_pay', '14000.00', '15287.36', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(754, 130, NULL, 'net_pay', '14000.00', '15287.36', 'Net Pay Update', '2025-08-22', '2025-08-22 02:20:11'),
(755, 117, NULL, 'gross_pay', '54597.68', '56091.93', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(756, 117, NULL, 'net_pay', '54597.68', '56091.93', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(757, 118, NULL, 'gross_pay', '16379.28', '16827.55', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(758, 118, NULL, 'net_pay', '16379.28', '16827.55', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(759, 119, NULL, 'gross_pay', '32758.64', '34551.76', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(760, 119, NULL, 'net_pay', '32758.64', '34551.76', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:04'),
(761, 122, NULL, 'gross_pay', '19109.20', '20155.18', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(762, 122, NULL, 'net_pay', '19109.20', '20155.18', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(763, 123, NULL, 'gross_pay', '13649.44', '14023.01', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(764, 123, NULL, 'net_pay', '13649.44', '14023.01', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(765, 126, NULL, 'gross_pay', '15287.36', '16542.54', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(766, 126, NULL, 'net_pay', '15287.36', '16542.54', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(767, 129, NULL, 'gross_pay', '15287.36', '15705.75', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(768, 129, NULL, 'net_pay', '15287.36', '15705.75', 'Net Pay Update', '2025-08-22', '2025-08-22 02:21:05'),
(769, 117, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(770, 117, NULL, 'net_pay', '56091.93', '47943.19', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(771, 118, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(772, 118, NULL, 'net_pay', '16827.55', '15077.55', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(773, 119, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(774, 119, NULL, 'net_pay', '34551.76', '32784.51', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(775, 120, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(776, 120, NULL, 'gross_pay', '20747.12', '22658.03', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(777, 120, NULL, 'net_pay', '20747.12', '20908.03', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(778, 121, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(779, 121, NULL, 'gross_pay', '21839.12', '22126.48', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(780, 121, NULL, 'net_pay', '21839.12', '18537.40', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(781, 122, NULL, 'sss', '0.00', '1750.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(782, 122, NULL, 'gross_pay', '20155.18', '20406.62', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(783, 122, NULL, 'net_pay', '20155.18', '17003.87', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(784, 123, NULL, 'sss', '0.00', '1275.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(785, 123, NULL, 'gross_pay', '14023.01', '14382.21', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(786, 123, NULL, 'net_pay', '14023.01', '13107.21', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(787, 124, NULL, 'sss', '0.00', '1275.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(788, 124, NULL, 'net_pay', '13649.44', '12374.44', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(789, 125, NULL, 'sss', '0.00', '1400.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(790, 125, NULL, 'gross_pay', '16487.36', '17493.11', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(791, 125, NULL, 'net_pay', '16487.36', '16093.11', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(792, 126, NULL, 'sss', '0.00', '1400.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(793, 126, NULL, 'gross_pay', '16542.54', '17145.99', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(794, 126, NULL, 'net_pay', '16542.54', '15743.31', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(795, 127, NULL, 'sss', '0.00', '1400.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(796, 127, NULL, 'net_pay', '29287.36', '27884.68', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(797, 128, NULL, 'sss', '0.00', '1275.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(798, 128, NULL, 'gross_pay', '26149.44', '26329.04', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(799, 128, NULL, 'net_pay', '26149.44', '23875.93', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(800, 129, NULL, 'sss', '0.00', '1400.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(801, 129, NULL, 'gross_pay', '15705.75', '15786.21', 'Gross Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(802, 129, NULL, 'net_pay', '15705.75', '14313.85', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(803, 130, NULL, 'sss', '0.00', '1400.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:30:28'),
(804, 130, NULL, 'net_pay', '15287.36', '13847.16', 'Net Pay Update', '2025-08-22', '2025-08-22 02:30:28'),
(805, 117, NULL, 'phic', '0.00', '2500.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(806, 117, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(807, 118, NULL, 'sss', '1750.00', '0.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(808, 119, NULL, 'phic', '0.00', '1500.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(809, 119, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(810, 120, NULL, 'phic', '0.00', '950.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(811, 120, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(812, 121, NULL, 'phic', '0.00', '1000.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(813, 121, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(814, 122, NULL, 'phic', '0.00', '875.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(815, 122, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(816, 123, NULL, 'phic', '0.00', '625.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(817, 123, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(818, 124, NULL, 'phic', '0.00', '625.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(819, 124, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(820, 125, NULL, 'phic', '0.00', '700.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(821, 125, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(822, 126, NULL, 'phic', '0.00', '700.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(823, 126, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(824, 127, NULL, 'phic', '0.00', '700.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(825, 127, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(826, 128, NULL, 'phic', '0.00', '625.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(827, 128, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(828, 129, NULL, 'phic', '0.00', '700.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(829, 129, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(830, 130, NULL, 'phic', '0.00', '700.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(831, 130, NULL, 'hdmf', '0.00', '200.00', 'Benefits Update', '2025-08-22', '2025-08-22 02:32:46'),
(832, 122, NULL, 'net_pay', '17003.87', '15928.87', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:00'),
(833, 124, NULL, 'net_pay', '12374.44', '11549.44', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:00'),
(834, 129, NULL, 'net_pay', '14313.85', '13413.85', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(835, 121, NULL, 'net_pay', '18537.40', '17337.40', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(836, 117, NULL, 'net_pay', '47943.19', '45243.19', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(837, 118, NULL, 'net_pay', '15077.55', '16827.55', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(838, 128, NULL, 'net_pay', '23875.93', '23050.93', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(839, 130, NULL, 'net_pay', '13847.16', '12947.16', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(840, 123, NULL, 'net_pay', '13107.21', '12282.21', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(841, 125, NULL, 'net_pay', '16093.11', '15193.11', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(842, 119, NULL, 'net_pay', '32784.51', '31084.51', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(843, 120, NULL, 'net_pay', '20908.03', '19758.03', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(844, 126, NULL, 'net_pay', '15743.31', '14843.31', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(845, 127, NULL, 'net_pay', '27884.68', '26984.68', 'Net Pay Update', '2025-08-22', '2025-08-22 02:33:01'),
(846, 122, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(847, 124, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(848, 121, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(849, 117, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(850, 118, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(851, 128, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(852, 130, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(853, 123, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(854, 125, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(855, 119, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(856, 120, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(857, 126, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(858, 127, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23'),
(859, 129, NULL, 'status', 'Generated', 'Released', 'Status Change', '2025-08-22', '2025-08-22 02:38:23');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL,
  `prp_id` varchar(10) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_period_holidays`
--

CREATE TABLE `payroll_period_holidays` (
  `id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `holiday_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_records`
--

CREATE TABLE `payroll_records` (
  `id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `status` enum('Pending','Generated','Released') DEFAULT 'Pending',
  `gross_pay` decimal(15,2) DEFAULT 0.00 CHECK (`gross_pay` >= 0),
  `net_pay` decimal(15,2) DEFAULT 0.00 CHECK (`net_pay` >= 0),
  `total_deductions` decimal(15,2) DEFAULT 0.00 CHECK (`total_deductions` >= 0),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `payroll_records`
--
DELIMITER $$
CREATE TRIGGER `payroll_records_audit` AFTER UPDATE ON `payroll_records` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO payroll_history (emp_id, payroll_period_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, NEW.payroll_period_id, 'status', OLD.status, NEW.status, 'Status Change', CURDATE());
    END IF;
    
    IF OLD.gross_pay != NEW.gross_pay THEN
        INSERT INTO payroll_history (emp_id, payroll_period_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, NEW.payroll_period_id, 'gross_pay', OLD.gross_pay, NEW.gross_pay, 'Gross Pay Update', CURDATE());
    END IF;
    
    IF OLD.net_pay != NEW.net_pay THEN
        INSERT INTO payroll_history (emp_id, payroll_period_id, field_changed, old_value, new_value, change_reason, effective_from)
        VALUES (NEW.emp_id, NEW.payroll_period_id, 'net_pay', OLD.net_pay, NEW.net_pay, 'Net Pay Update', CURDATE());
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `payslip_history_view`
-- (See below for the actual view)
--
CREATE TABLE `payslip_history_view` (
`id` int(11)
,`emp_id` int(11)
,`employee_id` varchar(6)
,`employee_name` varchar(255)
,`position` varchar(100)
,`workarrangement` varchar(20)
,`profile_image` varchar(500)
,`payroll_period_id` int(11)
,`prp_id` varchar(10)
,`payroll_period` varchar(149)
,`date_from` date
,`date_to` date
,`status` enum('Pending','Generated','Released')
,`gross_pay` decimal(15,2)
,`net_pay` decimal(15,2)
,`total_deductions` decimal(15,2)
,`created_at` timestamp
,`updated_at` timestamp
,`basic_pay_monthly` decimal(15,2)
,`basic_pay_semi_monthly` decimal(15,2)
,`site_allowance` decimal(10,2)
,`transportation_allowance` decimal(10,2)
,`total_allowances` decimal(10,2)
,`regular_hours` decimal(10,2)
,`regular_hours_amount` decimal(15,2)
,`regular_ot_hours` decimal(10,2)
,`regular_ot_amount` decimal(15,2)
,`regular_holiday_hours` decimal(10,2)
,`regular_holiday_amount` decimal(15,2)
,`regular_holiday_ot_hours` decimal(10,2)
,`regular_holiday_ot_amount` decimal(15,2)
,`special_holiday_hours` decimal(10,2)
,`special_holiday_amount` decimal(15,2)
,`special_holiday_ot_hours` decimal(10,2)
,`special_holiday_ot_amount` decimal(15,2)
,`total_holiday_pay` decimal(15,2)
,`total_overtime_pay` decimal(15,2)
,`late_undertime_minutes` int(11)
,`late_undertime_amount` decimal(15,2)
,`absences_days` decimal(5,2)
,`absences_amount` decimal(15,2)
,`sss_contribution` decimal(10,2)
,`phic_contribution` decimal(10,2)
,`hdmf_contribution` decimal(10,2)
,`tax_amount` decimal(10,2)
,`sss_loan` decimal(10,2)
,`hdmf_loan` decimal(10,2)
,`teed` decimal(10,2)
,`staff_house` decimal(10,2)
,`cash_advance` decimal(10,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `payslip_releases`
--

CREATE TABLE `payslip_releases` (
  `id` int(11) NOT NULL,
  `payroll_record_id` int(11) NOT NULL,
  `emp_id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `release_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `released_by` int(11) DEFAULT NULL,
  `pdf_generated` tinyint(1) DEFAULT 0,
  `excel_generated` tinyint(1) DEFAULT 0,
  `status` enum('Released','Cancelled') DEFAULT 'Released'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pay_components`
--

CREATE TABLE `pay_components` (
  `id` int(11) NOT NULL,
  `ppc_id` varchar(10) NOT NULL,
  `component_name` varchar(255) NOT NULL,
  `base_rate_type` varchar(100) DEFAULT NULL,
  `rate_type` enum('Basic pay-Monthly','Basic Pay-Semi-Monthly','Rate Per Day','Rate Per Hour','Rate Per Min','Regular Overtime','Regular OT+ND','Rest Day OT','Rest Day OT+OT','Rest Day ND','Regular Holiday','Regular Holiday OT','Regular Holiday + Night Diff','Regular Holiday + ROT + OT','Special Holiday','Special Holiday OT','Special Holiday + Night Diff','Special Holiday + ROT','Special Holiday + ROT + OT','Special Holiday + ROT + ND','Undertime/Late','Absences') NOT NULL,
  `formula` text NOT NULL,
  `rate_formula` text DEFAULT NULL,
  `amount_formula` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pay_components`
--

INSERT INTO `pay_components` (`id`, `ppc_id`, `component_name`, `base_rate_type`, `rate_type`, `formula`, `rate_formula`, `amount_formula`, `status`, `created_at`, `updated_at`) VALUES
(3, 'PPC001', 'day', 'Basic Pay Monthly', 'Rate Per Day', 'Basic Pay Monthly / 261 * 12', 'Basic Pay Monthly / 261 * 12', '', 'Active', '2025-07-26 16:25:37', '2025-07-26 16:25:37'),
(4, 'PPC002', 'hour', 'Rate Per Day', 'Rate Per Hour', 'Rate Per Day / 8', 'Rate Per Day / 8', '', 'Active', '2025-07-26 16:25:54', '2025-07-26 16:25:54'),
(5, 'PPC003', 'mins', 'Rate Per Hour', 'Rate Per Min', 'Rate Per Hour / 60', 'Rate Per Hour / 60', '', 'Active', '2025-07-26 16:26:17', '2025-07-26 16:26:17'),
(6, 'PPC004', '', 'Rate Per Hour', 'Regular Holiday', 'Rate Per Hour * 1.0', 'Rate Per Hour * 1.0', 'RATE * HOURS', 'Active', '2025-07-26 16:28:24', '2025-07-26 16:28:24'),
(7, 'PPC005', '', 'Rate Per Hour', 'Regular Holiday OT', 'Rate Per Hour * 2.6', 'Rate Per Hour * 2.6', 'RATE * HOURS', 'Active', '2025-07-26 16:28:48', '2025-07-26 16:28:48'),
(8, 'PPC006', 'asd', 'Rate Per Day', 'Regular Holiday + Night Diff', 'Rate Per Day * 2.6 * 0.1', 'Rate Per Day * 2.6 * 0.1', 'RATE * HOURS', 'Active', '2025-07-26 16:33:04', '2025-07-26 16:33:04'),
(9, 'PPC007', '', 'Rate Per Day', 'Regular Holiday + ROT + OT', 'Rate Per Day / 8 * 3.38', 'Rate Per Day / 8 * 3.38', 'RATE * HOURS', 'Active', '2025-07-26 16:34:37', '2025-07-26 16:34:37'),
(10, 'PPC008', '', 'Rate Per Day', 'Special Holiday', 'Rate Per Day / 8 * 1.3', 'Rate Per Day / 8 * 1.3', 'RATE * HOURS', 'Active', '2025-07-26 16:35:05', '2025-07-26 16:35:05'),
(11, 'PPC009', '', 'Rate Per Hour', 'Special Holiday OT', 'Rate Per Hour * 1.69', 'Rate Per Hour * 1.69', 'RATE * HOURS', 'Active', '2025-07-26 16:36:21', '2025-07-26 16:36:21'),
(12, 'PPC010', '', 'Rate Per Day', 'Special Holiday + Night Diff', 'Rate Per Day / 8 * 1.5', 'Rate Per Day * 1.5 / 8', 'RATE * HOURS', 'Active', '2025-07-26 16:37:01', '2025-07-26 16:37:32'),
(13, 'PPC011', '', 'Rate Per Day', 'Special Holiday + ROT', 'Rate Per Day / 8 * 1.5', 'Rate Per Day / 8 * 1.5', 'RATE * HOURS', 'Active', '2025-07-26 17:11:18', '2025-07-26 17:11:18'),
(14, 'PPC012', '', 'Rate Per Hour', 'Special Holiday + ROT + OT', 'Rate Per Hour * 1.95', 'Rate Per Hour * 1.95', 'RATE * HOURS', 'Active', '2025-07-26 17:15:34', '2025-07-26 17:15:34'),
(15, 'PPC013', '', 'Special Holiday + ROT', 'Special Holiday + ROT + ND', 'Special Holiday + ROT * 1.5 * 0.1', 'Special Holiday + ROT * 1.5 * 0.1', 'RATE * HOURS', 'Active', '2025-07-26 17:16:36', '2025-07-26 17:16:36'),
(16, 'PPC014', '', 'Rate Per Hour', 'Regular Overtime', 'Rate Per Hour * 1.25', 'Rate Per Hour * 1.25', 'RATE * HOURS', 'Active', '2025-07-26 18:36:36', '2025-07-26 18:36:36'),
(17, 'PPC015', '', 'Rate Per Hour', 'Regular OT+ND', 'Rate Per Hour * 0.1', 'Rate Per Hour * 0.1', 'RATE * HOURS', 'Active', '2025-07-26 18:37:14', '2025-07-26 18:37:14'),
(18, 'PPC016', '', 'Rate Per Hour', 'Rest Day OT', 'Rate Per Hour * 1.30', 'Rate Per Hour * 1.30', 'RATE * HOURS', 'Active', '2025-07-26 18:37:49', '2025-07-26 18:37:49'),
(38, 'PPC017', '', 'Rate Per Hour', 'Rest Day ND', 'Rate Per Hour * 1.3 * 0.1 * 8', 'Rate Per Hour * 1.3 * 0.1 * 8', 'RATE * HOURS', 'Active', '2025-07-26 19:25:49', '2025-07-26 19:26:14'),
(40, 'PPC018', '', 'Rate Per Hour', 'Rest Day OT+OT', 'Rate Per Hour * 1.69', 'Rate Per Hour * 1.69', 'RATE * HOURS', 'Active', '2025-07-26 19:26:41', '2025-07-26 19:26:41'),
(41, 'PPC019', '', 'Rate Per Minute', 'Undertime/Late', 'Rate Per Minute', 'Rate Per Minute', 'RATE * MINUTES', 'Active', '2025-07-26 19:28:24', '2025-07-27 06:34:46'),
(42, 'PPC020', '', 'Rate Per Day', 'Absences', 'Rate Per Day ', 'Rate Per Day', 'RATE * DAYS', 'Active', '2025-07-26 19:28:39', '2025-07-27 06:33:45'),
(44, 'PPC021', '', 'Basic Pay Monthly', 'Basic Pay-Semi-Monthly', 'Basic Pay Monthly * 5', 'Basic Pay Monthly * 5', '', 'Active', '2025-08-21 10:40:28', '2025-08-21 10:40:28');

-- --------------------------------------------------------

--
-- Table structure for table `supervisorlist`
--

CREATE TABLE `supervisorlist` (
  `id` int(11) NOT NULL,
  `sup_id` int(11) NOT NULL,
  `firstName` varchar(255) NOT NULL,
  `lastName` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'supervisor',
  `position` varchar(255) DEFAULT 'Supervisor',
  `department` varchar(255) DEFAULT '',
  `work_days` varchar(100) DEFAULT 'Monday-Friday',
  `status` varchar(50) DEFAULT 'active',
  `address` text DEFAULT '',
  `number` varchar(20) DEFAULT NULL,
  `workarrangement` varchar(50) DEFAULT 'office',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `useraccounts`
--

CREATE TABLE `useraccounts` (
  `id` int(11) NOT NULL,
  `firstName` varchar(50) NOT NULL,
  `lastName` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `password` varchar(50) NOT NULL,
  `role` varchar(50) NOT NULL,
  `last_login` varchar(20) DEFAULT NULL,
  `last_logout` datetime DEFAULT NULL,
  `fingerprint_uid` int(10) DEFAULT NULL,
  `fingerprint_status` varchar(20) DEFAULT 'Not Registered',
  `registered_date` datetime DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `profile_image` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `auth_status` varchar(20) DEFAULT 'New Account',
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `cover_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='User accounts table with profile image support for all user roles';

--
-- Dumping data for table `useraccounts`
--

INSERT INTO `useraccounts` (`id`, `firstName`, `lastName`, `email`, `password`, `role`, `last_login`, `last_logout`, `fingerprint_uid`, `fingerprint_status`, `registered_date`, `token`, `token_expires`, `profile_image`, `status`, `created_at`, `updated_at`, `auth_status`, `otp_code`, `otp_expiry`, `cover_photo`) VALUES
(101, 'Admin', 'Account', 'admin@gmail.com', '123', 'Admin', '2025-08-24 05:15:21', '2025-08-16 17:30:39', NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-04 09:21:10', '2025-08-24 03:15:21', 'Verified', '054272', '2025-08-04 11:43:35', NULL),
(102, 'HR', 'Account', 'hr@gmail.com', '123', 'HR', '2025-08-24 05:16:40', '2025-08-17 07:23:48', NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-04 09:34:39', '2025-08-24 03:16:40', 'Verified', NULL, NULL, NULL),
(103, 'Accountant', 'Account', 'accountant@gmail.com', '123', 'Accountant', '2025-08-23 15:30:12', '2025-08-17 07:16:06', NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-04 09:35:56', '2025-08-23 13:30:12', 'Verified', NULL, NULL, NULL),
(117, 'Emil', 'Santos', 'emil.santos@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(118, 'Emilio', 'Rodriguez', 'emilio.rodriguez@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(119, 'Melissa', 'Cruz', 'melissa.cruz@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(120, 'Mikka', 'Dela Rosa', 'mikka.delarosa@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(121, 'Dominic', 'Garcia', 'dominic.garcia@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(122, 'Aldrin', 'Reyes', 'aldrin.reyes@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(123, 'JM', 'Villanueva', 'jm.villanueva@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(124, 'Analyn', 'Mendoza', 'analyn.mendoza@gmail.com', '123', 'employee', '2025-08-22 04:13:19', NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 02:13:35', 'Verified', NULL, NULL, NULL),
(125, 'Jonathan', 'Torres', 'jonathan.torres@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(126, 'Paolo', 'Morales', 'paolo.morales@gmail.com', '123', 'employee', '2025-08-22 03:55:09', NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(127, 'Tan', 'Lim', 'tan.lim@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(128, 'Ericka', 'Flores', 'ericka.flores@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(129, 'Bryan', 'Aquino', 'bryan.aquino@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(130, 'Jelvin', 'Castillo', 'jelvin.castillo@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-22 01:54:40', '2025-08-22 01:58:18', 'Verified', NULL, NULL, NULL),
(131, 'Employee', 'WFH', 'emp1@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-24 03:16:02', '2025-08-24 03:17:13', 'Verified', NULL, NULL, NULL),
(132, 'Employee', 'OnSite', 'emp2@gmail.com', '123', 'employee', NULL, NULL, NULL, 'Not Registered', NULL, NULL, NULL, NULL, 'active', '2025-08-24 03:16:18', '2025-08-24 03:17:20', 'Verified', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_profiles`
--

CREATE TABLE `user_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_rel` varchar(50) DEFAULT NULL,
  `emergency_number` varchar(20) DEFAULT NULL,
  `education_level` varchar(50) DEFAULT NULL,
  `school_name` varchar(100) DEFAULT NULL,
  `school_address` varchar(255) DEFAULT NULL,
  `field_study` varchar(100) DEFAULT NULL,
  `start_year` varchar(4) DEFAULT NULL,
  `end_year` varchar(4) DEFAULT NULL,
  `family_type` varchar(50) DEFAULT NULL,
  `family_name` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `civil_status` enum('Single','Married','Divorced','Widowed') DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `place_birth` varchar(100) DEFAULT NULL,
  `marital_status` enum('Single','Married','Divorced','Widowed') DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `citizenship` varchar(50) DEFAULT NULL,
  `height` varchar(20) DEFAULT NULL,
  `weight` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `background_position` text DEFAULT NULL,
  `background_company` text DEFAULT NULL,
  `background_year` text DEFAULT NULL,
  `background_description` text DEFAULT NULL,
  `skills_traits` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_profiles`
--

INSERT INTO `user_profiles` (`id`, `user_id`, `middle_name`, `address`, `permanent_address`, `emergency_name`, `emergency_rel`, `emergency_number`, `education_level`, `school_name`, `school_address`, `field_study`, `start_year`, `end_year`, `family_type`, `family_name`, `contact_number`, `date_of_birth`, `civil_status`, `gender`, `place_birth`, `marital_status`, `religion`, `citizenship`, `height`, `weight`, `created_at`, `updated_at`, `background_position`, `background_company`, `background_year`, `background_description`, `skills_traits`) VALUES
(11, 124, '', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', '0000-00-00', '', '', NULL, NULL, NULL, '', '', '', '2025-08-22 02:13:35', '2025-08-22 02:13:35', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `work_schedule`
--

CREATE TABLE `work_schedule` (
  `id` int(11) NOT NULL,
  `schedule_name` varchar(100) NOT NULL,
  `work_start_time` time DEFAULT '08:00:00',
  `work_end_time` time DEFAULT '17:00:00',
  `lunch_start_time` time DEFAULT '12:00:00',
  `lunch_end_time` time DEFAULT '13:00:00',
  `grace_period_minutes` int(11) DEFAULT 10 COMMENT 'Grace period before marking late',
  `total_work_hours` decimal(4,2) DEFAULT 8.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `work_schedule`
--

INSERT INTO `work_schedule` (`id`, `schedule_name`, `work_start_time`, `work_end_time`, `lunch_start_time`, `lunch_end_time`, `grace_period_minutes`, `total_work_hours`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Standard Schedule', '08:00:00', '17:00:00', '12:00:00', '13:00:00', 10, 8.00, 1, '2025-07-12 17:07:03', '2025-07-12 17:07:03'),
(2, 'Standard Schedule', '08:00:00', '17:00:00', '12:00:00', '13:00:00', 10, 8.00, 1, '2025-07-12 17:07:47', '2025-07-12 17:07:47');

-- --------------------------------------------------------

--
-- Structure for view `attendance_report`
--
DROP TABLE IF EXISTS `attendance_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `attendance_report`  AS SELECT `a`.`id` AS `id`, `a`.`emp_id` AS `emp_id`, concat(`a`.`firstName`,' ',`a`.`lastName`) AS `employee_name`, `e`.`position` AS `position`, `e`.`workarrangement` AS `workarrangement`, `a`.`date` AS `date`, `a`.`time_in` AS `time_in`, `a`.`time_out` AS `time_out`, `a`.`total_workhours` AS `total_workhours`, `a`.`overtime` AS `overtime`, `a`.`late` AS `late`, `a`.`late_minutes` AS `late_minutes`, `a`.`status` AS `status`, `a`.`present` AS `present`, `a`.`absent` AS `absent`, `a`.`on_leave` AS `on_leave`, CASE WHEN `a`.`time_in` is null THEN 'Absent' WHEN `a`.`time_out` is null AND `a`.`late` = 1 THEN 'Late' WHEN `a`.`time_out` is null THEN 'Present' WHEN `a`.`overtime` > 0 AND `a`.`late` = 1 THEN 'Late + Overtime' WHEN `a`.`overtime` > 0 THEN 'Overtime' WHEN `a`.`late` = 1 THEN 'Late' ELSE 'Present' END AS `computed_status`, CASE WHEN dayofweek(`a`.`date`) in (1,7) THEN 'Weekend' WHEN `h`.`date_from` is not null THEN 'Holiday' ELSE 'Workday' END AS `day_type`, `h`.`name` AS `holiday_name`, `a`.`created_at` AS `created_at`, `a`.`updated_at` AS `updated_at` FROM ((`attendancelist` `a` left join `employeelist` `e` on(`a`.`emp_id` = `e`.`emp_id`)) left join `holidays` `h` on(`a`.`date` >= `h`.`date_from` and `a`.`date` <= `h`.`date_to`)) ORDER BY `a`.`date` DESC, `a`.`time_in` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `employee_dashboard_stats`
--
DROP TABLE IF EXISTS `employee_dashboard_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `employee_dashboard_stats`  AS SELECT `inquiries`.`employee_id` AS `employee_id`, count(0) AS `total_tickets`, sum(case when `inquiries`.`status` = 'Open' then 1 else 0 end) AS `open_tickets`, sum(case when `inquiries`.`status` = 'In Progress' then 1 else 0 end) AS `in_progress_tickets`, sum(case when `inquiries`.`status` = 'Closed' then 1 else 0 end) AS `closed_tickets`, sum(case when `inquiries`.`status` = 'Cancelled' then 1 else 0 end) AS `cancelled_tickets`, sum(case when `inquiries`.`unread_employee` = 1 then 1 else 0 end) AS `unread_tickets` FROM `inquiries` GROUP BY `inquiries`.`employee_id` ;

-- --------------------------------------------------------

--
-- Structure for view `employee_payroll_summary`
--
DROP TABLE IF EXISTS `employee_payroll_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `employee_payroll_summary`  AS SELECT `e`.`emp_id` AS `emp_id`, `e`.`firstName` AS `firstName`, `e`.`lastName` AS `lastName`, `e`.`position` AS `position`, `e`.`status` AS `status`, `ep`.`basic_pay_monthly` AS `basic_pay_monthly`, `ep`.`basic_pay_semi_monthly` AS `basic_pay_semi_monthly`, `ep`.`rate_per_day` AS `rate_per_day`, `ep`.`rate_per_hour` AS `rate_per_hour`, `ep`.`sss` AS `sss`, `ep`.`phic` AS `phic`, `ep`.`hdmf` AS `hdmf`, `ep`.`tax` AS `tax`, `ep`.`created_at` AS `payroll_created`, `ep`.`updated_at` AS `payroll_updated` FROM (`employeelist` `e` left join `employee_payroll` `ep` on(`e`.`emp_id` = `ep`.`emp_id`)) WHERE `e`.`role` = 'employee' AND `e`.`status` = 'active' ;

-- --------------------------------------------------------

--
-- Structure for view `hr_dashboard_stats`
--
DROP TABLE IF EXISTS `hr_dashboard_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `hr_dashboard_stats`  AS SELECT count(0) AS `total_tickets`, sum(case when `inquiries`.`status` = 'Open' then 1 else 0 end) AS `open_tickets`, sum(case when `inquiries`.`status` = 'In Progress' then 1 else 0 end) AS `in_progress_tickets`, sum(case when `inquiries`.`status` = 'Closed' then 1 else 0 end) AS `closed_tickets`, sum(case when `inquiries`.`status` = 'Cancelled' then 1 else 0 end) AS `cancelled_tickets`, sum(case when `inquiries`.`unread_hr` = 1 then 1 else 0 end) AS `unread_hr_tickets`, sum(case when `inquiries`.`status` in ('Open','In Progress') and `inquiries`.`unread_hr` = 1 then 1 else 0 end) AS `unread_active_tickets` FROM `inquiries` ;

-- --------------------------------------------------------

--
-- Structure for view `inquiry_summary`
--
DROP TABLE IF EXISTS `inquiry_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `inquiry_summary`  AS SELECT `i`.`id` AS `id`, `i`.`employee_id` AS `employee_id`, concat(`u`.`firstName`,' ',`u`.`lastName`) AS `employee_name`, `u`.`email` AS `employee_email`, `i`.`subject` AS `subject`, `i`.`description` AS `description`, `i`.`priority` AS `priority`, `i`.`status` AS `status`, `i`.`date_submitted` AS `date_submitted`, `i`.`date_updated` AS `date_updated`, `i`.`unread_hr` AS `unread_hr`, `i`.`unread_employee` AS `unread_employee`, `i`.`original_ticket_id` AS `original_ticket_id`, count(`im`.`id`) AS `message_count`, max(`im`.`timestamp`) AS `last_message_time` FROM ((`inquiries` `i` left join `useraccounts` `u` on(`i`.`employee_id` = `u`.`id`)) left join `inquiry_messages` `im` on(`i`.`id` = `im`.`ticket_id`)) GROUP BY `i`.`id`, `i`.`employee_id`, `u`.`firstName`, `u`.`lastName`, `u`.`email`, `i`.`subject`, `i`.`description`, `i`.`priority`, `i`.`status`, `i`.`date_submitted`, `i`.`date_updated`, `i`.`unread_hr`, `i`.`unread_employee`, `i`.`original_ticket_id` ;

-- --------------------------------------------------------

--
-- Structure for view `latest_payroll_calculations`
--
DROP TABLE IF EXISTS `latest_payroll_calculations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `latest_payroll_calculations`  AS SELECT `pc`.`id` AS `id`, `pc`.`emp_id` AS `emp_id`, `pp`.`date_from` AS `payroll_period_start`, `pp`.`date_to` AS `payroll_period_end`, 'Semi-Monthly' AS `payroll_type`, `pc`.`basic_pay_semi_monthly` AS `basic_pay`, `pc`.`total_overtime_pay` AS `overtime_pay`, `pc`.`total_holiday_pay` AS `holiday_pay`, 0 AS `night_differential`, `pc`.`total_allowances` AS `allowances`, 0 AS `bonuses`, 0 AS `incentives`, `pc`.`gross_pay` AS `gross_pay`, `pc`.`sss_contribution` AS `sss_deduction`, `pc`.`phic_contribution` AS `phic_deduction`, `pc`.`hdmf_contribution` AS `hdmf_deduction`, `pc`.`tax_amount` AS `tax_deduction`, `pc`.`sss_loan` AS `sss_loan_deduction`, `pc`.`hdmf_loan` AS `hdmf_loan_deduction`, `pc`.`teed`+ `pc`.`staff_house` AS `other_deductions`, `pc`.`cash_advance` AS `cash_advance_deduction`, `pc`.`total_deductions` AS `total_deductions`, `pc`.`net_pay` AS `net_pay`, `pc`.`regular_hours` AS `regular_hours`, `pc`.`regular_ot_hours`+ `pc`.`special_holiday_ot_hours` + `pc`.`regular_holiday_ot_hours` AS `overtime_hours`, `pc`.`regular_holiday_hours`+ `pc`.`special_holiday_hours` AS `holiday_hours`, 0 AS `night_diff_hours`, 'Generated' AS `status`, NULL AS `calculated_by`, NULL AS `approved_by`, `pc`.`created_at` AS `processed_date`, `pc`.`created_at` AS `created_at`, `pc`.`updated_at` AS `updated_at`, `e`.`firstName` AS `firstName`, `e`.`lastName` AS `lastName`, `e`.`position` AS `position` FROM ((`payroll_computations` `pc` join `employeelist` `e` on(`pc`.`emp_id` = `e`.`emp_id`)) left join `payroll_periods` `pp` on(`pc`.`payroll_period_id` = `pp`.`id`)) WHERE `pc`.`id` in (select max(`payroll_computations`.`id`) from `payroll_computations` group by `payroll_computations`.`emp_id`) ;

-- --------------------------------------------------------

--
-- Structure for view `late_arrivals_report`
--
DROP TABLE IF EXISTS `late_arrivals_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `late_arrivals_report`  AS SELECT `a`.`emp_id` AS `emp_id`, concat(`a`.`firstName`,' ',`a`.`lastName`) AS `employee_name`, `e`.`position` AS `position`, `a`.`date` AS `date`, `a`.`time_in` AS `time_in`, `a`.`late_minutes` AS `late_minutes`, CASE WHEN `a`.`late_minutes` <= 15 THEN 'Minor' WHEN `a`.`late_minutes` <= 30 THEN 'Moderate' WHEN `a`.`late_minutes` <= 60 THEN 'Significant' ELSE 'Severe' END AS `late_category`, `a`.`status` AS `status`, month(`a`.`date`) AS `month`, year(`a`.`date`) AS `year` FROM (`attendancelist` `a` left join `employeelist` `e` on(`a`.`emp_id` = `e`.`emp_id`)) WHERE `a`.`late` = 1 AND `a`.`late_minutes` > 0 ORDER BY `a`.`date` DESC, `a`.`late_minutes` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `overtime_report`
--
DROP TABLE IF EXISTS `overtime_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `overtime_report`  AS SELECT `a`.`emp_id` AS `emp_id`, concat(`a`.`firstName`,' ',`a`.`lastName`) AS `employee_name`, `e`.`position` AS `position`, `a`.`date` AS `date`, `a`.`time_in` AS `time_in`, `a`.`time_out` AS `time_out`, round(`a`.`total_workhours` / 60.0,2) AS `total_hours`, round(`a`.`overtime` / 60.0,2) AS `overtime_hours`, round(`a`.`overtime` / 60.0 * ifnull(`e`.`overtime_rate`,1.5),2) AS `overtime_pay_multiplier`, `a`.`status` AS `status`, month(`a`.`date`) AS `month`, year(`a`.`date`) AS `year` FROM (`attendancelist` `a` left join `employeelist` `e` on(`a`.`emp_id` = `e`.`emp_id`)) WHERE `a`.`overtime` > 0 ORDER BY `a`.`date` DESC, `a`.`emp_id` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `payroll_generation_summary`
--
DROP TABLE IF EXISTS `payroll_generation_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `payroll_generation_summary`  AS SELECT `pgh`.`id` AS `id`, concat('PAY',lpad(`pgh`.`id`,3,'0')) AS `payroll_id`, `pgh`.`payroll_period_id` AS `payroll_period_id`, `pp`.`prp_id` AS `prp_id`, concat(date_format(`pp`.`date_from`,'%M %d, %Y'),' - ',date_format(`pp`.`date_to`,'%M %d, %Y')) AS `payroll_period`, `pp`.`date_from` AS `date_from`, `pp`.`date_to` AS `date_to`, `pgh`.`total_employees` AS `total_employees`, `pgh`.`total_amount` AS `total_amount`, `pgh`.`generation_status` AS `status`, `pgh`.`created_at` AS `created_at`, `pgh`.`completed_at` AS `completed_at`, count(`pr`.`id`) AS `actual_employee_count`, sum(case when `pr`.`status` = 'Generated' then 1 else 0 end) AS `generated_count`, sum(case when `pr`.`status` = 'Released' then 1 else 0 end) AS `released_count`, sum(`pr`.`net_pay`) AS `actual_total_amount` FROM ((`payroll_generation_history` `pgh` join `payroll_periods` `pp` on(`pgh`.`payroll_period_id` = `pp`.`id`)) left join `payroll_records` `pr` on(`pgh`.`payroll_period_id` = `pr`.`payroll_period_id`)) WHERE `pgh`.`generation_status` = 'Completed' GROUP BY `pgh`.`id`, `pgh`.`payroll_period_id`, `pp`.`prp_id`, `pp`.`date_from`, `pp`.`date_to`, `pgh`.`total_employees`, `pgh`.`total_amount`, `pgh`.`generation_status`, `pgh`.`created_at`, `pgh`.`completed_at` ORDER BY `pgh`.`created_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `payslip_history_view`
--
DROP TABLE IF EXISTS `payslip_history_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `payslip_history_view`  AS SELECT `pr`.`id` AS `id`, `pr`.`emp_id` AS `emp_id`, concat('DIF',lpad(`pr`.`emp_id`,3,'0')) AS `employee_id`, `pr`.`employee_name` AS `employee_name`, `e`.`position` AS `position`, `e`.`workarrangement` AS `workarrangement`, `u`.`profile_image` AS `profile_image`, `pr`.`payroll_period_id` AS `payroll_period_id`, `pp`.`prp_id` AS `prp_id`, concat(date_format(`pp`.`date_from`,'%M %d, %Y'),' - ',date_format(`pp`.`date_to`,'%M %d, %Y')) AS `payroll_period`, `pp`.`date_from` AS `date_from`, `pp`.`date_to` AS `date_to`, `pr`.`status` AS `status`, `pr`.`gross_pay` AS `gross_pay`, `pr`.`net_pay` AS `net_pay`, `pr`.`total_deductions` AS `total_deductions`, `pr`.`created_at` AS `created_at`, `pr`.`updated_at` AS `updated_at`, `pc`.`basic_pay_monthly` AS `basic_pay_monthly`, `pc`.`basic_pay_semi_monthly` AS `basic_pay_semi_monthly`, `pc`.`site_allowance` AS `site_allowance`, `pc`.`transportation_allowance` AS `transportation_allowance`, `pc`.`total_allowances` AS `total_allowances`, `pc`.`regular_hours` AS `regular_hours`, `pc`.`regular_hours_amount` AS `regular_hours_amount`, `pc`.`regular_ot_hours` AS `regular_ot_hours`, `pc`.`regular_ot_amount` AS `regular_ot_amount`, `pc`.`regular_holiday_hours` AS `regular_holiday_hours`, `pc`.`regular_holiday_amount` AS `regular_holiday_amount`, `pc`.`regular_holiday_ot_hours` AS `regular_holiday_ot_hours`, `pc`.`regular_holiday_ot_amount` AS `regular_holiday_ot_amount`, `pc`.`special_holiday_hours` AS `special_holiday_hours`, `pc`.`special_holiday_amount` AS `special_holiday_amount`, `pc`.`special_holiday_ot_hours` AS `special_holiday_ot_hours`, `pc`.`special_holiday_ot_amount` AS `special_holiday_ot_amount`, `pc`.`total_holiday_pay` AS `total_holiday_pay`, `pc`.`total_overtime_pay` AS `total_overtime_pay`, `pc`.`late_undertime_minutes` AS `late_undertime_minutes`, `pc`.`late_undertime_amount` AS `late_undertime_amount`, `pc`.`absences_days` AS `absences_days`, `pc`.`absences_amount` AS `absences_amount`, `pc`.`sss_contribution` AS `sss_contribution`, `pc`.`phic_contribution` AS `phic_contribution`, `pc`.`hdmf_contribution` AS `hdmf_contribution`, `pc`.`tax_amount` AS `tax_amount`, `pc`.`sss_loan` AS `sss_loan`, `pc`.`hdmf_loan` AS `hdmf_loan`, `pc`.`teed` AS `teed`, `pc`.`staff_house` AS `staff_house`, `pc`.`cash_advance` AS `cash_advance` FROM ((((`payroll_records` `pr` join `payroll_periods` `pp` on(`pr`.`payroll_period_id` = `pp`.`id`)) join `employeelist` `e` on(`pr`.`emp_id` = `e`.`emp_id`)) left join `useraccounts` `u` on(`pr`.`emp_id` = `u`.`id`)) left join `payroll_computations` `pc` on(`pr`.`id` = `pc`.`payroll_record_id`)) WHERE `pr`.`status` = 'Released' ORDER BY `pr`.`updated_at` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `applicantlist`
--
ALTER TABLE `applicantlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`app_id`);

--
-- Indexes for table `applicant_files`
--
ALTER TABLE `applicant_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_file_type` (`app_id`,`file_type`),
  ADD KEY `idx_app_id` (`app_id`),
  ADD KEY `idx_file_type` (`file_type`);

--
-- Indexes for table `applicant_requirements`
--
ALTER TABLE `applicant_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_app_requirements` (`app_id`),
  ADD KEY `idx_app_id` (`app_id`);

--
-- Indexes for table `attendancelist`
--
ALTER TABLE `attendancelist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_emp_date` (`emp_id`,`date`),
  ADD KEY `idx_emp_id` (`emp_id`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_emp_date` (`emp_id`,`date`),
  ADD KEY `idx_date_status` (`date`,`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_late_minutes` (`late_minutes`),
  ADD KEY `idx_overtime` (`overtime`),
  ADD KEY `idx_payroll_period` (`current_payroll_period_id`),
  ADD KEY `idx_holiday_tracking` (`is_holiday`,`holiday_type`),
  ADD KEY `idx_last_time_in` (`last_time_in`),
  ADD KEY `idx_session_tracking` (`session_count`,`accumulated_hours`) USING BTREE;

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_action` (`emp_id`,`action_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `attendance_settings`
--
ALTER TABLE `attendance_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `benefit_files`
--
ALTER TABLE `benefit_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_benefit_id` (`benefit_id`),
  ADD KEY `idx_uploaded_at` (`uploaded_at`),
  ADD KEY `idx_benefit_files_type` (`file_type`);

--
-- Indexes for table `department_list`
--
ALTER TABLE `department_list`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `supervisor_id` (`supervisor_id`);

--
-- Indexes for table `employeelist`
--
ALTER TABLE `employeelist`
  ADD PRIMARY KEY (`emp_id`),
  ADD KEY `user_id` (`emp_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `employee_benefits`
--
ALTER TABLE `employee_benefits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_employee_period` (`employee_id`,`payroll_period_id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_payroll_period_id` (`payroll_period_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_employee_benefits_status_period` (`status`,`payroll_period_id`);

--
-- Indexes for table `employee_files`
--
ALTER TABLE `employee_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_id` (`emp_id`),
  ADD KEY `idx_file_type` (`file_type`),
  ADD KEY `idx_migrated_from_applicant` (`migrated_from_applicant`),
  ADD KEY `idx_original_applicant_id` (`original_applicant_id`);

--
-- Indexes for table `employee_payroll`
--
ALTER TABLE `employee_payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_emp_payroll` (`emp_id`),
  ADD KEY `idx_emp_id` (`emp_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_updated_at` (`updated_at`),
  ADD KEY `idx_basic_pay` (`basic_pay_monthly`,`basic_pay_semi_monthly`),
  ADD KEY `idx_government_contributions` (`sss`,`phic`,`hdmf`,`tax`);

--
-- Indexes for table `employee_payslip_pdfs`
--
ALTER TABLE `employee_payslip_pdfs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_emp_period_pdf` (`emp_id`,`payroll_period_id`),
  ADD KEY `idx_emp_id` (`emp_id`),
  ADD KEY `idx_payroll_period` (`payroll_period_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_date` (`event_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `examinations`
--
ALTER TABLE `examinations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_assignments`
--
ALTER TABLE `exam_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`),
  ADD KEY `idx_exam_assignments_app_id` (`app_id`),
  ADD KEY `idx_exam_assignments_exam_id` (`exam_id`),
  ADD KEY `fk_exam_assignments_assigned_by` (`assigned_by`);

--
-- Indexes for table `exam_attempts`
--
ALTER TABLE `exam_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `exam_id` (`exam_id`),
  ADD KEY `idx_exam_attempts_assignment_status` (`assignment_id`,`status`),
  ADD KEY `idx_exam_attempts_app_status` (`app_id`,`status`),
  ADD KEY `idx_exam_attempts_app_id` (`app_id`),
  ADD KEY `idx_exam_attempts_exam_id` (`exam_id`);

--
-- Indexes for table `hiring_positions`
--
ALTER TABLE `hiring_positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `holiday_id` (`holiday_id`),
  ADD KEY `idx_holidays_dates` (`date_from`,`date_to`),
  ADD KEY `idx_holidays_type` (`holiday_type`);

--
-- Indexes for table `inquiries`
--
ALTER TABLE `inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date_submitted` (`date_submitted`),
  ADD KEY `idx_unread_hr` (`unread_hr`),
  ADD KEY `idx_unread_employee` (`unread_employee`),
  ADD KEY `idx_original_ticket` (`original_ticket_id`),
  ADD KEY `idx_inquiries_composite` (`status`,`date_submitted`,`unread_hr`);

--
-- Indexes for table `inquiry_activities`
--
ALTER TABLE `inquiry_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_activities_composite` (`ticket_id`,`created_at`);

--
-- Indexes for table `inquiry_assignments`
--
ALTER TABLE `inquiry_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_hr_user_id` (`hr_user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `inquiry_messages`
--
ALTER TABLE `inquiry_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_sender` (`sender_id`,`sender_type`),
  ADD KEY `idx_timestamp` (`timestamp`),
  ADD KEY `idx_messages_composite` (`ticket_id`,`timestamp`);

--
-- Indexes for table `leave_attachments`
--
ALTER TABLE `leave_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leave_request_id` (`leave_request_id`);

--
-- Indexes for table `leave_comments`
--
ALTER TABLE `leave_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leave_request_id` (`leave_request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `otp_attempts`
--
ALTER TABLE `otp_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_attempts_user_email` (`user_id`,`email`),
  ADD KEY `idx_otp_attempts_blocked` (`blocked_until`);

--
-- Indexes for table `payroll_computations`
--
ALTER TABLE `payroll_computations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payroll_computation` (`payroll_record_id`,`emp_id`),
  ADD KEY `idx_payroll_record` (`payroll_record_id`),
  ADD KEY `idx_employee_period` (`emp_id`,`payroll_period_id`),
  ADD KEY `fk_payroll_computations_period` (`payroll_period_id`),
  ADD KEY `idx_payroll_computations_record` (`payroll_record_id`),
  ADD KEY `idx_rates` (`regular_ot_rate`,`regular_holiday_rate`,`special_holiday_rate`),
  ADD KEY `idx_deduction_rates` (`late_undertime_rate`,`absences_rate`);

--
-- Indexes for table `payroll_generation_history`
--
ALTER TABLE `payroll_generation_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_period_history` (`payroll_period_id`),
  ADD KEY `idx_generated_by` (`generated_by`),
  ADD KEY `idx_generation_status` (`generation_status`),
  ADD KEY `idx_status_date` (`generation_status`,`created_at`),
  ADD KEY `idx_payroll_generation_period` (`payroll_period_id`);

--
-- Indexes for table `payroll_history`
--
ALTER TABLE `payroll_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_id` (`emp_id`),
  ADD KEY `idx_payroll_period` (`payroll_period_id`),
  ADD KEY `idx_field_changed` (`field_changed`);

--
-- Indexes for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prp_id` (`prp_id`),
  ADD KEY `idx_payroll_periods_dates` (`date_from`,`date_to`);

--
-- Indexes for table `payroll_period_holidays`
--
ALTER TABLE `payroll_period_holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_period_holiday` (`payroll_period_id`,`holiday_id`),
  ADD KEY `idx_payroll_period_holidays_period` (`payroll_period_id`),
  ADD KEY `idx_payroll_period_holidays_holiday` (`holiday_id`);

--
-- Indexes for table `payroll_records`
--
ALTER TABLE `payroll_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payroll_employee` (`payroll_period_id`,`emp_id`),
  ADD KEY `idx_payroll_period` (`payroll_period_id`),
  ADD KEY `idx_employee` (`emp_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_status_period` (`status`,`payroll_period_id`),
  ADD KEY `idx_employee_status` (`emp_id`,`status`),
  ADD KEY `idx_payroll_records_period_status` (`payroll_period_id`,`status`);

--
-- Indexes for table `payslip_releases`
--
ALTER TABLE `payslip_releases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payroll_release` (`payroll_record_id`),
  ADD KEY `idx_emp_period` (`emp_id`,`payroll_period_id`),
  ADD KEY `fk_payslip_rel_period` (`payroll_period_id`);

--
-- Indexes for table `pay_components`
--
ALTER TABLE `pay_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ppc_id` (`ppc_id`),
  ADD KEY `idx_pay_components_status` (`status`),
  ADD KEY `idx_pay_components_rate_formula` (`rate_formula`(100));

--
-- Indexes for table `supervisorlist`
--
ALTER TABLE `supervisorlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sup_id` (`sup_id`);

--
-- Indexes for table `useraccounts`
--
ALTER TABLE `useraccounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_useraccounts_profile` (`id`,`profile_image`),
  ADD KEY `idx_useraccounts_auth_status` (`auth_status`),
  ADD KEY `idx_useraccounts_otp` (`otp_code`,`otp_expiry`);

--
-- Indexes for table `user_profiles`
--
ALTER TABLE `user_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `work_schedule`
--
ALTER TABLE `work_schedule`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `applicantlist`
--
ALTER TABLE `applicantlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `applicant_files`
--
ALTER TABLE `applicant_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `applicant_requirements`
--
ALTER TABLE `applicant_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendancelist`
--
ALTER TABLE `attendancelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=367;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_settings`
--
ALTER TABLE `attendance_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `benefit_files`
--
ALTER TABLE `benefit_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `department_list`
--
ALTER TABLE `department_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `employee_benefits`
--
ALTER TABLE `employee_benefits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `employee_files`
--
ALTER TABLE `employee_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `employee_payroll`
--
ALTER TABLE `employee_payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `employee_payslip_pdfs`
--
ALTER TABLE `employee_payslip_pdfs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1087;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `examinations`
--
ALTER TABLE `examinations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `exam_assignments`
--
ALTER TABLE `exam_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `exam_attempts`
--
ALTER TABLE `exam_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `hiring_positions`
--
ALTER TABLE `hiring_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `inquiry_activities`
--
ALTER TABLE `inquiry_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `inquiry_assignments`
--
ALTER TABLE `inquiry_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inquiry_messages`
--
ALTER TABLE `inquiry_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `leave_attachments`
--
ALTER TABLE `leave_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_comments`
--
ALTER TABLE `leave_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `otp_attempts`
--
ALTER TABLE `otp_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_computations`
--
ALTER TABLE `payroll_computations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2769;

--
-- AUTO_INCREMENT for table `payroll_generation_history`
--
ALTER TABLE `payroll_generation_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=324;

--
-- AUTO_INCREMENT for table `payroll_history`
--
ALTER TABLE `payroll_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=860;

--
-- AUTO_INCREMENT for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payroll_period_holidays`
--
ALTER TABLE `payroll_period_holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `payroll_records`
--
ALTER TABLE `payroll_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2667;

--
-- AUTO_INCREMENT for table `payslip_releases`
--
ALTER TABLE `payslip_releases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1378;

--
-- AUTO_INCREMENT for table `pay_components`
--
ALTER TABLE `pay_components`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `supervisorlist`
--
ALTER TABLE `supervisorlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_profiles`
--
ALTER TABLE `user_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `work_schedule`
--
ALTER TABLE `work_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applicantlist`
--
ALTER TABLE `applicantlist`
  ADD CONSTRAINT `applicantlist_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `useraccounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `applicant_files`
--
ALTER TABLE `applicant_files`
  ADD CONSTRAINT `applicant_files_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `useraccounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `applicant_requirements`
--
ALTER TABLE `applicant_requirements`
  ADD CONSTRAINT `applicant_requirements_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `useraccounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendancelist`
--
ALTER TABLE `attendancelist`
  ADD CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`emp_id`) REFERENCES `employeelist` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attendance_payroll_period` FOREIGN KEY (`current_payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `benefit_files`
--
ALTER TABLE `benefit_files`
  ADD CONSTRAINT `fk_benefit_files_benefit` FOREIGN KEY (`benefit_id`) REFERENCES `employee_benefits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `department_list`
--
ALTER TABLE `department_list`
  ADD CONSTRAINT `department_list_ibfk_1` FOREIGN KEY (`supervisor_id`) REFERENCES `supervisorlist` (`sup_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `employeelist`
--
ALTER TABLE `employeelist`
  ADD CONSTRAINT `employeelist_ibfk_1` FOREIGN KEY (`emp_id`) REFERENCES `useraccounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `employeelist_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `department_list` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `employee_benefits`
--
ALTER TABLE `employee_benefits`
  ADD CONSTRAINT `fk_employee_benefits_employee` FOREIGN KEY (`employee_id`) REFERENCES `employeelist` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employee_benefits_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
