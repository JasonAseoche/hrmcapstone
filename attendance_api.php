<?php

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set the correct timezone for the entire script
date_default_timezone_set('Asia/Manila');


require_once 'db_connection.php';

// Convert mysqli connection to PDO for compatibility with existing code
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Close the mysqli connection since we're using PDO
    $conn->close();
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGetRequest($pdo);
} elseif ($method === 'POST') {
    handlePostRequest($pdo);
} else {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function handleGetRequest($pdo) {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_attendance_records':
            getAttendanceRecords($pdo);
            break;
        case 'get_employee_info':
            getEmployeeInfo($pdo);
            break;
        case 'get_today_status':
            getTodayStatus($pdo);
            break;
        case 'check_timer_status':
            checkTimerStatus($pdo);
            break;
        case 'process_absent_employees':
            processAbsentEmployees($pdo);
            break;
        case 'fix_existing_data':
            fixExistingLateUndertimeData($pdo);
            break;
        case 'debug_info':
            debugInfo($pdo);
            break;
        case 'debug_timezone':
            debugTimezoneInfo($pdo);
            break;
        case 'debug_current_time':
            debugCurrentTime();
            break;
        case 'get_current_payroll_period':
            getCurrentPayrollPeriod($pdo);
            break;
        case 'get_break_status':
            getBreakStatus($pdo);
            break;
        case 'get_test_settings':
            getTestSettings($pdo);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

function handlePostRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'time_in':
            timeIn($pdo, $input);
            break;
        case 'time_out':
            timeOut($pdo, $input);
            break;
        case 'biometric_push':
            handleBiometricPush($pdo, $input);
            break;
        case 'update_test_time':
            updateTestTime($pdo, $input);
            break;
        case 'update_test_settings':
            updateTestSettings($pdo, $input);
            break;
        default:
            // Check if it's a biometric push without action parameter
            if (isset($input['employee_id']) && isset($input['status']) && isset($input['datetime'])) {
                handleBiometricPush($pdo, $input);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid action or missing parameters']);
            }
    }
}



function getCurrentTimeUTC8($testTime = null) {
    // Check if test mode is active from database
    $testSettings = getTestTimeFromDB();
    
    if ($testSettings && $testSettings['test_mode']) {
        // Use test time
        $testDateTime = new DateTime($testSettings['test_date'] . ' ' . $testSettings['test_time'], new DateTimeZone('Asia/Manila'));
        return $testDateTime;
    } else {
        // Use real time
        $manila = new DateTimeZone('Asia/Manila');
        return new DateTime('now', $manila);
    }
}

function updateTestTime($pdo, $input) {
    try {
        $testMode = $input['testMode'] ?? false;
        $testTime = $input['testTime'] ?? null;
        $testDate = $input['testDate'] ?? null;
        
        $testTimeFile = __DIR__ . '/test_time.json';
        
        $testData = [
            'testMode' => $testMode,
            'testTime' => $testTime,
            'testDate' => $testDate,
            'updatedAt' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($testTimeFile, json_encode($testData));
        
        echo json_encode([
            'success' => true,
            'message' => 'Test time updated successfully',
            'testData' => $testData
        ]);
        
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error updating test time: ' . $e->getMessage()]);
    }
}

// Quick debug function to see what time is being used
function debugCurrentTime() {
    global $TEMP_TEST_MODE, $TEMP_TEST_TIME;
    
    $currentTime = getCurrentTimeUTC8();
    
    echo json_encode([
        'success' => true,
        'debug' => [
            'test_mode' => $TEMP_TEST_MODE,
            'test_time_setting' => $TEMP_TEST_TIME,
            'actual_time_used' => $currentTime->format('Y-m-d H:i:s'),
            'time_only' => $currentTime->format('H:i:s'),
            'date_only' => $currentTime->format('Y-m-d'),
            'detected_shift' => detectShiftType($currentTime->format('H:i:s'))
        ]
    ]);
}

function getEmployeeRestDay($pdo, $emp_id) {
    try {
        $sql = "SELECT rest_day FROM employeelist WHERE emp_id = :emp_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['rest_day'] : null;
    } catch(PDOException $e) {
        error_log("Error getting employee rest day: " . $e->getMessage());
        return null;
    }
}

// Helper function to check if current date is employee's rest day
function isRestDay($date, $emp_id, $pdo) {
    $restDay = getEmployeeRestDay($pdo, $emp_id);
    if (!$restDay) return false;
    
    $dateObj = new DateTime($date . ' 00:00:00', new DateTimeZone('Asia/Manila'));
    $dayOfWeek = $dateObj->format('l'); // Full day name (Monday, Tuesday, etc.)
    
    // Handle range formats like "Tuesday-Friday" (means Tuesday AND Friday, not Tuesday TO Friday)
    if (strpos($restDay, '-') !== false) {
        $days = explode('-', $restDay);
        foreach ($days as $day) {
            $day = trim($day);
            if (strtolower($dayOfWeek) === strtolower($day)) {
                return true;
            }
        }
        return false;
    }
    
    // Handle single day format
    return strtolower($dayOfWeek) === strtolower($restDay);
}

// Helper function to check if date is weekday (Monday-Friday)
function isWeekday($date) {
    // Ensure we're using Manila timezone
    $dateObj = new DateTime($date . ' 00:00:00', new DateTimeZone('Asia/Manila'));
    $dayOfWeek = (int)$dateObj->format('N'); // 1 (Monday) to 7 (Sunday)
    return $dayOfWeek >= 1 && $dayOfWeek <= 5;
}

function isValidAttendanceTime($time, $action, $detectedShift = null) {
    $currentHour = (int)date('H', strtotime($time));
    
    // If no shift detected yet, detect it based on time
    if ($detectedShift === null) {
        $detectedShift = detectShiftType($time);
    }
    
    $isValid = false;
    if ($detectedShift === 'day') {
        // Day shift: Allow time in/out from 6:00 AM to 5:00 PM
        $isValid = $currentHour >= 6 && $currentHour < 17;
    } else {
        // Night shift: different rules for time in vs time out
        if (in_array($action, ['Time Out', 'Check Out', 'time_out'])) {
            // Time out: 6:00 PM onwards OR up to 8:00 AM (inclusive)
            $isValid = $currentHour >= 18 || $currentHour <= 8;
        } else {
            // Time in: 6:00 PM onwards OR early morning hours (0-5 AM for next day arrival)
            $isValid = $currentHour >= 18 || $currentHour <= 6;
        }
    } 
    
    // DEBUG LOGGING
    error_log("=== isValidAttendanceTime DEBUG ===");
    error_log("Time: " . $time);
    error_log("Current Hour: " . $currentHour);
    error_log("Action: " . $action);
    error_log("Detected Shift: " . $detectedShift);
    error_log("Is Valid: " . ($isValid ? 'YES' : 'NO'));
    
    return $isValid;
}

function calculateLateMinutes($timeIn, $detectedShift = null, $date = null) {
    $timeInObj = new DateTime($timeIn, new DateTimeZone('Asia/Manila'));
    
    // Detect shift if not provided
    if ($detectedShift === null) {
        $detectedShift = detectShiftType($timeIn);
    }
    
    if ($detectedShift === 'day') {
        $workStartTime = new DateTime('08:00:00', new DateTimeZone('Asia/Manila'));
        $graceTime = new DateTime('08:10:00', new DateTimeZone('Asia/Manila'));
        
        if ($timeInObj <= $graceTime) {
            return 0;
        }
        
        $interval = $workStartTime->diff($timeInObj);
        return ($interval->h * 60) + $interval->i;
    } else {
        // Night shift logic
        $currentHour = (int)$timeInObj->format('H');
        
        if ($currentHour >= 22) {
            // Same day (10:00 PM - 11:59 PM)
            $workStartTime = new DateTime('22:00:00', new DateTimeZone('Asia/Manila'));
            $graceTime = new DateTime('22:10:00', new DateTimeZone('Asia/Manila'));
            
            if ($timeInObj <= $graceTime) {
                return 0;
            }
            
            $interval = $workStartTime->diff($timeInObj);
            return ($interval->h * 60) + $interval->i;
        } else if ($currentHour >= 18 && $currentHour < 22) {
            // Early arrival (6:00 PM - 9:59 PM) - no late minutes
            return 0;
        } else {
            // Next day (12:00 AM - 6:00 AM)
            $workStartTime = new DateTime('22:00:00', new DateTimeZone('Asia/Manila'));
            $workStartTime->modify('-1 day');
            $graceTime = new DateTime('22:10:00', new DateTimeZone('Asia/Manila'));
            $graceTime->modify('-1 day');
            
            // If within grace period, return 0
            if ($timeInObj <= $graceTime) {
                return 0;
            }
            
            // Calculate late minutes from the official start time (10:00 PM)
            $interval = $workStartTime->diff($timeInObj);
            return ($interval->h * 60) + $interval->i;
        }
    }
}

// Helper function to calculate undertime minutes (if time out is before 5:00 PM)
function calculateUndertimeMinutes($timeOut, $shiftType = null) {
    if (!$timeOut) return 0;
    
    $timeOutObj = new DateTime($timeOut, new DateTimeZone('Asia/Manila'));
    
    // If shift type is not provided, detect it (but this should be avoided)
    if ($shiftType === null) {
        $hour = (int)$timeOutObj->format('H');
        if ($hour >= 0 && $hour < 6) {
            $shiftType = 'night'; // Early morning hours are likely night shift
        } else {
            $shiftType = detectShiftType($timeOut); // Fallback to existing detection
        }
    }
    
    if ($shiftType === 'day') {
        // Day shift: undertime if time out before 5:00 PM
        $workEndTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila')); // 5:00 PM
        
        if ($timeOutObj >= $workEndTime) {
            return 0;
        }
        
        $diffMs = $workEndTime->getTimestamp() - $timeOutObj->getTimestamp();
        $undertimeMinutes = max(0, floor($diffMs / 60));
        
        return $undertimeMinutes;
    } else {
        // Night shift: undertime if time out before 6:00 AM
        $workEndTime = new DateTime('06:00:00', new DateTimeZone('Asia/Manila')); // 6:00 AM
        
        if ($timeOutObj >= $workEndTime) {
            return 0;
        }
        
        $diffMs = $workEndTime->getTimestamp() - $timeOutObj->getTimestamp();
        $undertimeMinutes = max(0, floor($diffMs / 60));
        
        return $undertimeMinutes;
    }
}


// Helper function to check if a date is a holiday and get holiday details
function checkHolidayForDate($pdo, $date) {
    try {
        // First check if this date falls within any payroll period
        $periodSql = "SELECT pp.id as payroll_period_id 
                      FROM payroll_periods pp 
                      WHERE DATE(:date) >= DATE(pp.date_from) AND DATE(:date) <= DATE(pp.date_to)
                      LIMIT 1";
        $periodStmt = $pdo->prepare($periodSql);
        $periodStmt->bindParam(':date', $date);
        $periodStmt->execute();
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$period) {
            return ['is_holiday' => false, 'holiday_type' => null, 'holiday_name' => null];
        }
        
        // Check if this date is a holiday within the payroll period
        $holidaySql = "SELECT h.holiday_type, h.name as holiday_name
                       FROM holidays h
                       INNER JOIN payroll_period_holidays pph ON h.id = pph.holiday_id
                       WHERE pph.payroll_period_id = :payroll_period_id
                       AND DATE(:date) >= DATE(h.date_from) AND DATE(:date) <= DATE(h.date_to)
                       LIMIT 1";
                       
        $holidayStmt = $pdo->prepare($holidaySql);
        $holidayStmt->bindParam(':payroll_period_id', $period['payroll_period_id'], PDO::PARAM_INT);
        $holidayStmt->bindParam(':date', $date);
        $holidayStmt->execute();
        $holiday = $holidayStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($holiday) {
            return [
                'is_holiday' => true,
                'holiday_type' => $holiday['holiday_type'],
                'holiday_name' => $holiday['holiday_name']
            ];
        }
        
        return ['is_holiday' => false, 'holiday_type' => null, 'holiday_name' => null];
        
    } catch(PDOException $e) {
        error_log("Error checking holiday for date: " . $e->getMessage());
        return ['is_holiday' => false, 'holiday_type' => null, 'holiday_name' => null];
    }
}

// Modified calculateWorkHoursWithHolidays function to handle holidays
function calculateWorkHoursWithHolidays($timeIn, $timeOut, $date, $pdo, $emp_id) {
    $timeInFormatted = date('H:i:s', strtotime($timeIn));
    $timeOutFormatted = date('H:i:s', strtotime($timeOut));
    
    // Detect shift type first
    $shiftType = detectShiftType($timeInFormatted);
    
    // Check if this date is a holiday
    $holidayInfo = checkHolidayForDate($pdo, $date);
    
    // Calculate work hours and overtime based on shift type
    $overtimeMinutes = 0;
    $regularMinutes = 0;
    
    if ($shiftType === 'day') {
        // Day shift logic remains the same
        $timeInObj = new DateTime($date . ' ' . $timeInFormatted, new DateTimeZone('Asia/Manila'));
        $timeInHour = (int)$timeInObj->format('H');
        $timeOutObj = new DateTime($date . ' ' . $timeOutFormatted, new DateTimeZone('Asia/Manila'));
        $timeOutHour = (int)$timeOutObj->format('H');
        
        $workEndTime = new DateTime($date . ' 17:00:00', new DateTimeZone('Asia/Manila'));
        
        if ($timeInHour < 8 && $timeOutHour < 8) {
            $regularMinutes = 0;
        } else {
            if ($timeInHour < 8) {
                $timeInObj = new DateTime($date . ' 08:00:00', new DateTimeZone('Asia/Manila'));
            }
            
            $workStart = new DateTime($date . ' 08:00:00', new DateTimeZone('Asia/Manila'));
            $regularEndTime = $timeOutObj <= $workEndTime ? $timeOutObj : $workEndTime;
            
            $workInterval = $timeInObj->diff($regularEndTime);
            $totalMinutesWorked = ($workInterval->h * 60) + $workInterval->i;
 
            $lunchStart = new DateTime($date . ' 12:00:00', new DateTimeZone('Asia/Manila'));
            $lunchEnd = new DateTime($date . ' 13:00:00', new DateTimeZone('Asia/Manila'));
 
            if ($timeInObj <= $lunchStart && $regularEndTime > $lunchEnd) {
                $regularMinutes = max(0, $totalMinutesWorked - 60);
            } else if ($timeInObj < $lunchEnd && $regularEndTime > $lunchStart) {
                if ($regularEndTime > $lunchEnd) {
                    $lunchOverlapStart = max($timeInObj, $lunchStart);
                    $lunchOverlapEnd = min($regularEndTime, $lunchEnd);
                    
                    $lunchInterval = $lunchOverlapStart->diff($lunchOverlapEnd);
                    $lunchOverlapMinutes = ($lunchInterval->h * 60) + $lunchInterval->i;
                    
                    $regularMinutes = max(0, $totalMinutesWorked - $lunchOverlapMinutes);
                } else {
                    $regularMinutes = $totalMinutesWorked;
                }
            } else {
                $regularMinutes = $totalMinutesWorked;
            }
        }

        // Day shift overtime: after 5:00 PM
        if ($timeOutObj > $workEndTime) {
            $overtimeInterval = $workEndTime->diff($timeOutObj);
            $overtimeRaw = ($overtimeInterval->h * 60) + $overtimeInterval->i;
            
            if ($overtimeRaw >= 60) {
                $overtimeHours = floor($overtimeRaw / 60);
                $overtimeMinutes = $overtimeHours * 60;
            } else {
                $overtimeMinutes = 0;
            }
        }
 
    } else {
        // MODIFIED: Night shift (10:00 PM to 6:00 AM) - ALL time is night differential overtime, NO regular minutes
        $timeInObj = new DateTime($date . ' ' . $timeInFormatted, new DateTimeZone('Asia/Manila'));
        $timeOutObj = new DateTime($date . ' ' . $timeOutFormatted, new DateTimeZone('Asia/Manila'));
        $timeInHour = (int)$timeInObj->format('H');
        $timeOutHour = (int)$timeOutObj->format('H');
        
        // Handle date crossing for night shift
        if ($timeInHour >= 22) {
            $workStart = new DateTime($date . ' 22:00:00', new DateTimeZone('Asia/Manila'));
            $workEnd = new DateTime($date . ' 06:00:00', new DateTimeZone('Asia/Manila'));
            $workEnd->modify('+1 day');
            
            if ($timeOutHour <= 12) {
                $timeOutObj->modify('+1 day');
            }
        } else if ($timeInHour >= 18 && $timeInHour < 22) {
            $workStart = new DateTime($date . ' 22:00:00', new DateTimeZone('Asia/Manila'));
            $workEnd = new DateTime($date . ' 06:00:00', new DateTimeZone('Asia/Manila'));
            $workEnd->modify('+1 day');
            $timeInObj = $workStart;
            
            if ($timeOutHour <= 12) {
                $timeOutObj->modify('+1 day');
            }
        } else {
            $workStart = new DateTime($date . ' 22:00:00', new DateTimeZone('Asia/Manila'));
            $workStart->modify('-1 day');
            $workEnd = new DateTime($date . ' 06:00:00', new DateTimeZone('Asia/Manila'));
        }
        
        // Calculate work hours (cap at 6:00 AM)
        $endTime = $timeOutObj <= $workEnd ? $timeOutObj : $workEnd;
        
        // Check for session data first
        $sessionSql = "SELECT session_data FROM attendancelist WHERE emp_id = :emp_id AND date = :date LIMIT 1";
        $sessionStmt = $pdo->prepare($sessionSql);
        $sessionStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $sessionStmt->bindParam(':date', $date);
        $sessionStmt->execute();
        $sessionRecord = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    
        if ($sessionRecord && $sessionRecord['session_data']) {
            $sessionData = json_decode($sessionRecord['session_data'], true);
            $totalNightMinutes = 0;
            foreach ($sessionData as $session) {
                $totalNightMinutes += $session['adjusted_minutes'] ?? $session['duration_minutes'];
            }
        } else {
            $workInterval = $workStart->diff($endTime);
            
            if ($endTime < $workStart) {
                $totalNightMinutes = 0;
            } else {
                $totalMinutes = ($workInterval->days * 24 * 60) + ($workInterval->h * 60) + $workInterval->i;
                $totalNightMinutes = $totalMinutes;
            }
        }
    
        $totalNightMinutes = max(0, $totalNightMinutes);
        
        // MODIFIED: For night shift, NO regular minutes, ALL work time is night differential
        $regularMinutes = 0;
        $overtimeMinutes = 0; // No overtime after 6AM, just stop the work
    }
    
    // Initialize all hour tracking variables
    $regularHolidayMinutes = 0;
    $regularHolidayOTMinutes = 0;
    $specialHolidayMinutes = 0;
    $specialHolidayOTMinutes = 0;
    $restDayMinutes = 0;
    $restDayOTMinutes = 0;
    $restDayNDMinutes = 0;
    $regularHolidayROTMinutes = 0;
    $regularHolidayROTOTMinutes = 0;
    $specialHolidayROTMinutes = 0;
    $specialHolidayROTOTMinutes = 0;
    $specialHolidayROTNDMinutes = 0;
    
    // MODIFIED: New night differential variables
    $regularOTNDMinutes = 0;
    $regularHolidayNDMinutes = 0;
    $specialHolidayNDMinutes = 0;
 
    // Store total regular work hours in total_workhours (ALWAYS)
    $totalMinutes = min(max(0, $regularMinutes), 480);
    
    // Check if it's employee's rest day
    $isRestDay = isRestDay($date, $emp_id, $pdo);
 
    // MODIFIED: Night differential allocation logic
    if ($shiftType === 'night') {
        if ($isRestDay) {
            if ($holidayInfo['is_holiday']) {
                if ($holidayInfo['holiday_type'] === 'Special') {
                    // Special Holiday + Rest Day + Night Diff = special_holiday_rot_nd
                    $specialHolidayROTNDMinutes = $totalNightMinutes;
                }
                // Regular Holiday + Rest Day + Night Diff would go to regular_holiday_rot_nd but this column doesn't exist
                // So we'll use rest_day_nd for regular holidays on rest day
                else {
                    $restDayNDMinutes = $totalNightMinutes;
                }
            } else {
                // Rest Day + Night Diff = rest_day_nd
                $restDayNDMinutes = $totalNightMinutes;
            }
        } else {
            // Not rest day
            if ($holidayInfo['is_holiday']) {
                if ($holidayInfo['holiday_type'] === 'Regular') {
                    // Regular Holiday + Night Diff = regular_holiday_nd
                    $regularHolidayNDMinutes = $totalNightMinutes;
                } else {
                    // Special Holiday + Night Diff = special_holiday_nd
                    $specialHolidayNDMinutes = $totalNightMinutes;
                }
            } else {
                // Regular Night Diff = regular_ot_nd
                $regularOTNDMinutes = $totalNightMinutes;
            }
        }
    } else {
        // Day shift - existing logic for holiday allocation
        if ($holidayInfo['is_holiday']) {
            if ($holidayInfo['holiday_type'] === 'Regular') {
                $regularHolidayMinutes = max(0, $regularMinutes);
                $regularHolidayOTMinutes = $overtimeMinutes;
            } elseif ($holidayInfo['holiday_type'] === 'Special') {
                $specialHolidayMinutes = max(0, $regularMinutes);
                $specialHolidayOTMinutes = $overtimeMinutes;
            }
        }
        
        if ($isRestDay) {
            if ($holidayInfo['is_holiday']) {
                if ($holidayInfo['holiday_type'] === 'Regular') {
                    $regularHolidayROTMinutes = $totalMinutes;
                    $regularHolidayROTOTMinutes = $overtimeMinutes;
                } else {
                    $specialHolidayROTMinutes = $totalMinutes;
                    $specialHolidayROTOTMinutes = $overtimeMinutes;
                }
            } else {
                $restDayMinutes = min($totalMinutes, 480);
                if ($overtimeMinutes > 0) {
                    $restDayOTMinutes = $overtimeMinutes;
                }
            }
        }
    }
    
    return [
        'totalMinutes' => $totalMinutes,
        'overtimeMinutes' => $overtimeMinutes,
        'regularHolidayMinutes' => $regularHolidayMinutes,
        'regularHolidayOTMinutes' => $regularHolidayOTMinutes,
        'specialHolidayMinutes' => $specialHolidayMinutes,
        'specialHolidayOTMinutes' => $specialHolidayOTMinutes,
        'restDayMinutes' => $restDayMinutes,
        'restDayOTMinutes' => $restDayOTMinutes,
        'restDayNDMinutes' => $restDayNDMinutes,
        'regularHolidayROTMinutes' => $regularHolidayROTMinutes,
        'regularHolidayROTOTMinutes' => $regularHolidayROTOTMinutes,
        'specialHolidayROTMinutes' => $specialHolidayROTMinutes,
        'specialHolidayROTOTMinutes' => $specialHolidayROTOTMinutes,
        'specialHolidayROTNDMinutes' => $specialHolidayROTNDMinutes,
        // MODIFIED: New night differential columns
        'regularOTNDMinutes' => $regularOTNDMinutes,
        'regularHolidayNDMinutes' => $regularHolidayNDMinutes,
        'specialHolidayNDMinutes' => $specialHolidayNDMinutes,
        'isHoliday' => $holidayInfo['is_holiday'],
        'holidayType' => $holidayInfo['holiday_type'],
        'isRestDay' => $isRestDay,
        'debug' => [
            'originalTimeIn' => $timeIn,
            'originalTimeOut' => $timeOut,
            'shiftType' => $shiftType,
            'regularMinutes' => $regularMinutes,
            'overtimeMinutes' => $overtimeMinutes,
            'totalNightMinutes' => $totalNightMinutes ?? 0,
            'holidayInfo' => $holidayInfo,
            'isRestDay' => $isRestDay
        ]
    ];
}



function detectShiftType($timeIn) {
    $timeInObj = new DateTime($timeIn, new DateTimeZone('Asia/Manila'));
    $hour = (int)$timeInObj->format('H');
    
    $shiftType = '';
    // Detect shift based on time in
    if ($hour >= 6 && $hour < 18) {
        // Time in between 6:00 AM and 6:00 PM = Day Shift
        $shiftType = 'day';
    } else {
        // Time in between 6:00 PM and 6:00 AM = Night Shift
        $shiftType = 'night';
    }
    
    // DEBUG LOGGING
    error_log("=== detectShiftType DEBUG ===");
    error_log("Input Time: " . $timeIn);
    error_log("Parsed Hour: " . $hour);
    error_log("Detected Shift: " . $shiftType);
    error_log("Day shift check (6-18): " . (($hour >= 6 && $hour < 18) ? 'YES' : 'NO'));
    error_log("Night shift check (else): " . (($hour < 6 || $hour >= 18) ? 'YES' : 'NO'));
    
    return $shiftType;
}

function getBreakStatus($pdo) {
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }
        
        $currentTime = getCurrentTimeUTC8();
        $currentHour = (int)$currentTime->format('H');
        $currentMinute = (int)$currentTime->format('i');
        
        // FIXED: Determine which date to check based on current time
        $searchDate = $currentTime->format('Y-m-d');
        
        // If current time is 00:00-05:59, check previous day for night shift records
        if ($currentHour >= 0 && $currentHour < 8) {
            $prevDay = clone $currentTime;
            $prevDay->modify('-1 day');
            $searchDate = $prevDay->format('Y-m-d');
        }
        
        // Get attendance record for the correct date
        $sql = "SELECT break_start_time, break_end_time, break_duration, time_in, time_out, shift_type 
                FROM attendancelist 
                WHERE emp_id = :emp_id AND date = :search_date 
                AND time_in IS NOT NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $stmt->bindParam(':search_date', $searchDate);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $isOnBreak = false;
        $isAutoBreak = false;
        $breakStartTime = null;
        $breakEndTime = null;
        
        // Only check breaks for day shift and if employee is still timed in
        if ($result && !$result['time_out'] && ($result['shift_type'] === 'day' || detectShiftType($result['time_in']) === 'day')) {
            // Check if it's currently lunch time (12:00-13:00) and should be on break
            if ($currentHour == 12) {
                // During 12:00 PM hour - should be on break
                if (!$result['break_start_time']) {
                    $updateSql = "UPDATE attendancelist 
                                SET break_start_time = '12:00:00' 
                                WHERE emp_id = :emp_id AND date = :search_date 
                                AND time_in IS NOT NULL AND time_out IS NULL";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
                    $updateStmt->bindParam(':search_date', $searchDate);
                    $updateStmt->execute();
                    
                    $breakStartTime = '12:00:00';
                } else {
                    $breakStartTime = $result['break_start_time'];
                }
                
                $breakEndTime = $result['break_end_time'];
                $isOnBreak = true;
                $isAutoBreak = true;
                
            } elseif ($currentHour >= 13) {
                // Past 1:00 PM - break should be ended
                if (!$result['break_end_time']) {
                    $updateEndSql = "UPDATE attendancelist 
                                    SET break_end_time = '13:00:00', 
                                        break_duration = 60 
                                    WHERE emp_id = :emp_id AND date = :search_date 
                                    AND time_in IS NOT NULL AND time_out IS NULL";
                    $updateEndStmt = $pdo->prepare($updateEndSql);
                    $updateEndStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
                    $updateEndStmt->bindParam(':search_date', $searchDate);
                    $updateEndStmt->execute();
                    
                    $breakEndTime = '13:00:00';
                } else {
                    $breakEndTime = $result['break_end_time'];
                }
                
                $breakStartTime = $result['break_start_time'];
                $isOnBreak = false;  // ALWAYS false when past 1:00 PM
                $isAutoBreak = false;
                
            } else {
                // Outside lunch hours (before 12:00 PM) - check existing break status
                if ($result['break_start_time'] && !$result['break_end_time']) {
                    $isOnBreak = true;
                    $isAutoBreak = false;
                    $breakStartTime = $result['break_start_time'];
                    $breakEndTime = null;
                } else {
                    $isOnBreak = false;
                    $isAutoBreak = false;
                    $breakStartTime = $result['break_start_time'];
                    $breakEndTime = $result['break_end_time'];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'is_on_break' => $isOnBreak,
            'is_auto_break' => $isAutoBreak,
            'break_start_time' => $breakStartTime,
            'break_end_time' => $breakEndTime,
            'break_duration' => $result['break_duration'] ?? 0,
            'debug' => [
                'emp_id' => $emp_id,
                'search_date' => $searchDate,
                'currentTime' => $currentTime->format('H:i:s'),
                'currentHour' => $currentHour,
                'currentMinute' => $currentMinute,
                'result' => $result,
                'logic' => [
                    'is_lunch_time' => ($currentHour == 12 || ($currentHour == 13 && $currentMinute == 0)),
                    'has_time_in' => !empty($result['time_in'] ?? ''),
                    'has_time_out' => !empty($result['time_out'] ?? ''),
                    'is_day_shift' => ($result['shift_type'] ?? detectShiftType($result['time_in'] ?? '')) === 'day',
                    'has_break_start' => !empty($result['break_start_time'] ?? ''),
                    'has_break_end' => !empty($result['break_end_time'] ?? '')
                ]
            ]
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// Function to fix existing data with proper late_undertime calculation
function fixExistingLateUndertimeData($pdo) {
    try {
        // Get all records that need fixing WITH shift_type
        $sql = "SELECT id, late_minutes, time_out, date, shift_type, time_in FROM attendancelist WHERE time_out IS NOT NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $fixedCount = 0;
        
        foreach ($records as $record) {
            // Get the shift type for this record
            $shiftType = $record['shift_type'];
            
            // If shift_type is null, detect it from time_in
            if (!$shiftType && $record['time_in']) {
                $shiftType = detectShiftType($record['time_in']);
            }
            
            // Calculate undertime for this record with proper shift type
            $undertimeMinutes = 0;
            if ($record['time_out']) {
                $undertimeMinutes = calculateUndertimeMinutes($record['time_out'], $shiftType);
            }
            
            // Calculate combined late_undertime
            $lateUndertimeTotal = $record['late_minutes'] + $undertimeMinutes;
            
            // Update the record
            $updateSql = "UPDATE attendancelist SET 
                          undertime_minutes = :undertime_minutes,
                          late_undertime = :late_undertime
                          WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':undertime_minutes', $undertimeMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':late_undertime', $lateUndertimeTotal, PDO::PARAM_INT);
            $updateStmt->bindParam(':id', $record['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            
            $fixedCount++;
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Fixed $fixedCount attendance records with proper late/undertime calculations",
            'fixed_count' => $fixedCount
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getCurrentPayrollPeriod($pdo) {
    try {
        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        
        $sql = "SELECT pp.*,
                       COUNT(CASE WHEN h.holiday_type = 'Regular' THEN 1 END) as regular_holidays,
                       COUNT(CASE WHEN h.holiday_type = 'Special' THEN 1 END) as special_holidays,
                       CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display
                FROM payroll_periods pp
                LEFT JOIN payroll_period_holidays pph ON pp.id = pph.payroll_period_id
                LEFT JOIN holidays h ON pph.holiday_id = h.id
                WHERE DATE(pp.date_from) <= DATE(:today) AND DATE(pp.date_to) >= DATE(:today)
                GROUP BY pp.id
                LIMIT 1";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':today', $today);
        $stmt->execute();
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($period) {
            echo json_encode([
                'success' => true,
                'payroll_period' => $period
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'No active payroll period found for today',
                'payroll_period' => null
            ]);
        }
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAttendanceRecords($pdo) {
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        $limit = (int)($_GET['limit'] ?? 50);
        
        if ($emp_id) {
            $sql = "SELECT a.*, e.position, u.profile_image 
                    FROM attendancelist a 
                    LEFT JOIN employeelist e ON a.emp_id = e.emp_id 
                    LEFT JOIN useraccounts u ON a.emp_id = u.id
                    WHERE a.emp_id = :emp_id 
                    ORDER BY a.date DESC, a.time_in DESC 
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $sql = "SELECT a.*, e.position, u.profile_image 
                    FROM attendancelist a 
                    LEFT JOIN employeelist e ON a.emp_id = e.emp_id 
                    LEFT JOIN useraccounts u ON a.emp_id = u.id
                    ORDER BY a.date DESC, a.time_in DESC 
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'records' => $records]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getEmployeeInfo($pdo) {
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }
        
        // FIXED: Removed e.shift_type from the SELECT query
        $sql = "SELECT u.firstName, u.lastName, u.fingerprint_uid, u.profile_image, 
                       e.position, e.workarrangement 
                FROM useraccounts u 
                LEFT JOIN employeelist e ON u.id = e.emp_id 
                WHERE u.id = :emp_id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            // Handle null/empty workarrangement with a default value
            if (empty($employee['workarrangement']) || is_null($employee['workarrangement'])) {
                $employee['workarrangement'] = 'Office'; // Default value
            }
            
            echo json_encode(['success' => true, 'employee' => $employee]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getTodayStatus($pdo) {
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }
        
        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        $currentHour = (int)$currentTime->format('H');
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));
        
        // FIXED: Check employee work arrangement first
        $empSql = "SELECT e.workarrangement FROM employeelist e WHERE e.emp_id = :emp_id";
        $empStmt = $pdo->prepare($empSql);
        $empStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empInfo = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        $workArrangement = $empInfo['workarrangement'] ?? 'Office';
        
        // First check today's record
        $todaySql = "SELECT * FROM attendancelist WHERE emp_id = :emp_id AND date = :today ORDER BY time_in DESC LIMIT 1";
        $todayStmt = $pdo->prepare($todaySql);
        $todayStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $todayStmt->bindParam(':today', $today);
        $todayStmt->execute();
        $todayRecord = $todayStmt->fetch(PDO::FETCH_ASSOC);
        
        // If we found today's record, use it
        if ($todayRecord) {
            echo json_encode(['success' => true, 'record' => $todayRecord]);
            return;
        }
        
        // If current hour is between 0-6 AM, also check yesterday's night shift record
        if ($currentHour >= 0 && $currentHour < 8) {
            $yesterdaySql = "SELECT * FROM attendancelist 
                            WHERE emp_id = :emp_id 
                            AND date = :yesterday 
                            AND shift_type = 'night'
                            AND (time_out IS NULL OR DATE(time_out) = :today)
                            ORDER BY time_in DESC LIMIT 1";
            $yesterdayStmt = $pdo->prepare($yesterdaySql);
            $yesterdayStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $yesterdayStmt->bindParam(':yesterday', $yesterday);
            $yesterdayStmt->bindParam(':today', $today);
            $yesterdayStmt->execute();
            $yesterdayRecord = $yesterdayStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($yesterdayRecord) {
                echo json_encode(['success' => true, 'record' => $yesterdayRecord]);
                return;
            }
        }
        
        // No active record found
        echo json_encode(['success' => true, 'record' => null]);
        
    } catch(PDOException $e) {
        error_log("Database error in getTodayStatus: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function checkTimerStatus($pdo) {
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }
        
        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        $currentHour = (int)$currentTime->format('H');
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));
        
        // Get employee work arrangement
        $empSql = "SELECT e.workarrangement FROM employeelist e WHERE e.emp_id = :emp_id";
        $empStmt = $pdo->prepare($empSql);
        $empStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empInfo = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        $workArrangement = $empInfo['workarrangement'] ?? 'Office';
        
        // First check today's record
        $todaySql = "SELECT * FROM attendancelist WHERE emp_id = :emp_id AND date = :today ORDER BY id DESC LIMIT 1";
        $todayStmt = $pdo->prepare($todaySql);
        $todayStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $todayStmt->bindParam(':today', $today);
        $todayStmt->execute();
        $todayRecord = $todayStmt->fetch(PDO::FETCH_ASSOC);
        
        $record = $todayRecord;
        
        // If no today's record and current hour is between 0-6 AM, check yesterday's night shift record
        if (!$record && $currentHour >= 0 && $currentHour < 8) {
            $yesterdaySql = "SELECT * FROM attendancelist 
                            WHERE emp_id = :emp_id 
                            AND date = :yesterday 
                            AND shift_type = 'night'
                            AND (time_out IS NULL OR DATE(time_out) = :today)
                            ORDER BY id DESC LIMIT 1";
            $yesterdayStmt = $pdo->prepare($yesterdaySql);
            $yesterdayStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $yesterdayStmt->bindParam(':yesterday', $yesterday);
            $yesterdayStmt->bindParam(':today', $today);
            $yesterdayStmt->execute();
            $yesterdayRecord = $yesterdayStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($yesterdayRecord) {
                $record = $yesterdayRecord;
            }
        }
        
        $shouldStartTimer = false;
        $isBreakTime = false;
        
        // Check if it's break time (12:00 PM to 1:00 PM) - only for day shift
        if ($currentHour >= 12 && $currentHour < 13) {
            $isBreakTime = true;
        }
        
        if ($record) {
            // Rest of the function remains the same...
            // [Keep existing logic for timer status]
        }
        
        // Return the timer status
        echo json_encode([
            'success' => true, 
            'shouldStartTimer' => $shouldStartTimer, 
            'isBreakTime' => $isBreakTime,
            'record' => $record,
            'debug' => [
                // [Keep existing debug info]
            ]
        ]);
    } catch(PDOException $e) {
        error_log("Database error in checkTimerStatus: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// Replace your timeIn function with this updated version

function timeIn($pdo, $input) {
    try {
        $emp_id = $input['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }

        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        $timeStr = $currentTime->format('H:i:s');
        
        $isEmployeeRestDay = isRestDay($today, $emp_id, $pdo);
        $shiftType = detectShiftType($timeStr);
        
        if (!isValidAttendanceTime($timeStr, 'Time In', $shiftType)) {
            $errorMsg = $shiftType === 'day' 
              ? 'Day shift time in: 6:00 AM - 5:00 PM only!' 
              : 'Night shift time in: 6:00 PM onwards only!';
            echo json_encode(['success' => false, 'error' => $errorMsg]);
            return;
        }
        
        // MODIFIED: Check for active session of THE SAME SHIFT TYPE
        $checkSql = "SELECT id, shift_type FROM attendancelist WHERE emp_id = :emp_id AND date = :today AND time_in IS NOT NULL AND time_out IS NULL";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $checkStmt->bindParam(':today', $today);
        $checkStmt->execute();
        $activeSession = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($activeSession) {
            if ($activeSession['shift_type'] === $shiftType) {
                echo json_encode(['success' => false, 'error' => 'Already timed in for this shift type today']);
                return;
            }
            // If different shift type, allow new record (continue below)
        }

        // MODIFIED: Re-entry check only for SAME shift type on SAME day
        $reEntryCheckSql = "SELECT id, time_in, time_out, late_minutes, session_type, accumulated_hours, shift_type, date 
        FROM attendancelist 
        WHERE emp_id = :emp_id 
        AND date = :today
        AND shift_type = :shift_type
        AND time_in IS NOT NULL AND time_out IS NOT NULL 
        ORDER BY id DESC LIMIT 1";
        $reEntryStmt = $pdo->prepare($reEntryCheckSql);
        $reEntryStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $reEntryStmt->bindParam(':today', $today);
        $reEntryStmt->bindParam(':shift_type', $shiftType);
        $reEntryStmt->execute();
        $existingRecord = $reEntryStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord) {
            // Re-time in for same shift type
            $recordShiftType = $existingRecord['shift_type'];
            $currentHour = (int)$currentTime->format('H');
            
            // Validate re-time in hours
            $canReTimeIn = false;
            if ($recordShiftType === 'day') {
                $canReTimeIn = $currentHour >= 8 && $currentHour < 17;
            } else {
                $canReTimeIn = ($currentHour >= 18 && $currentHour < 22) || $currentHour >= 22 || $currentHour < 6;
            }
            
            if (!$canReTimeIn) {
                $errorMsg = $recordShiftType === 'day' 
                    ? 'Re-time in not allowed after 5:00 PM (overtime period).' 
                    : 'Re-time in not allowed after 6:00 AM (overtime period).';
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                return;
            }
            
            // Update existing record for re-time in
            $sessionType = ($existingRecord['session_type'] ?? 0) + 1;
            
            // MODIFIED: For night shift, no late minutes carried over
            $lateCarryOver = $recordShiftType === 'night' ? 0 : $existingRecord['late_minutes'];
            
            $updateSql = "UPDATE attendancelist SET 
                        time_out = NULL,
                        last_time_in = :last_time_in,
                        session_type = :session_type,
                        total_workhours = 0,
                        overtime = 0,
                        regular_ot = 0,
                        regular_holiday = 0,
                        regular_holiday_ot = 0,
                        special_holiday = 0,
                        special_holiday_ot = 0,
                        undertime_minutes = 0,
                        late_undertime = :late_undertime,
                        status = 'Present'
                        WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':last_time_in', $timeStr);
            $updateStmt->bindParam(':session_type', $sessionType, PDO::PARAM_INT);
            $updateStmt->bindParam(':late_undertime', $lateCarryOver, PDO::PARAM_INT);
            $updateStmt->bindParam(':id', $existingRecord['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Re-time in recorded successfully (Session ' . $sessionType . ')',
                'time_in' => $timeStr,
                'date' => $today,
                'late_minutes' => 0,
                'session_type' => $sessionType,
                'is_re_entry' => true,
                'original_time_in' => $existingRecord['time_in'],
                'last_time_in' => $timeStr,
                'accumulated_hours' => $existingRecord['accumulated_hours'] ?? 0,
                'shift_type' => $recordShiftType
            ]);
            return;
        }
        
        // New time in record (could be different shift type on same day)
        $empSql = "SELECT firstName, lastName FROM useraccounts WHERE id = :emp_id";
        $empStmt = $pdo->prepare($empSql);
        $empStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empInfo = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$empInfo) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            return;
        }
        
        // MODIFIED: Night shift has no late minutes
        $lateMinutes = $shiftType === 'night' ? 0 : calculateLateMinutes($timeStr, $shiftType, $today);
        $isLate = $lateMinutes > 0 ? 1 : 0;
        $holidayInfo = checkHolidayForDate($pdo, $today);

        $sql = "INSERT INTO attendancelist (emp_id, firstName, lastName, date, time_in, last_time_in, late, late_minutes, present, is_holiday, holiday_type, is_rest_day, shift_type, session_type, accumulated_hours)
        VALUES (:emp_id, :firstName, :lastName, :date, :time_in, :last_time_in, :late, :late_minutes, 1, :is_holiday, :holiday_type, :is_rest_day, :shift_type, 1, 0)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $stmt->bindParam(':firstName', $empInfo['firstName']);
        $stmt->bindParam(':lastName', $empInfo['lastName']);
        $stmt->bindParam(':date', $today);
        $stmt->bindParam(':time_in', $timeStr);
        $stmt->bindParam(':last_time_in', $timeStr);
        $isRestDayFlag = $isEmployeeRestDay ? 1 : 0;
        $stmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
        $stmt->bindParam(':late', $isLate, PDO::PARAM_INT);
        $stmt->bindParam(':late_minutes', $lateMinutes, PDO::PARAM_INT);
        $isHolidayFlag = $holidayInfo['is_holiday'] ? 1 : 0;
        $stmt->bindParam(':is_holiday', $isHolidayFlag, PDO::PARAM_INT);
        $stmt->bindParam(':holiday_type', $holidayInfo['holiday_type']);
        $stmt->bindParam(':shift_type', $shiftType);
        $stmt->execute();

        // Get the inserted record ID
        $insertedId = $pdo->lastInsertId();

        // Check for approved OT and update auto_time_out
        $autoTimeOut = checkAndUpdateAutoTimeOut($pdo, $emp_id, $today);

        // Update the record with auto_time_out
        $updateAutoSql = "UPDATE attendancelist SET auto_time_out = :auto_time_out WHERE id = :id";
        $updateAutoStmt = $pdo->prepare($updateAutoSql);
        $updateAutoStmt->bindParam(':auto_time_out', $autoTimeOut);
        $updateAutoStmt->bindParam(':id', $insertedId, PDO::PARAM_INT);
        $updateAutoStmt->execute();

        
        
        $message = 'Time in recorded successfully';
        if ($shiftType === 'night') {
            $message .= ' (Night Differential)';
        } elseif ($lateMinutes > 0) {
            $message .= ' (Late by ' . $lateMinutes . ' minutes)';
        }
        if ($holidayInfo['is_holiday']) {
            $message .= ' - ' . $holidayInfo['holiday_type'] . ' Holiday';
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'time_in' => $timeStr,
            'date' => $today,
            'late_minutes' => $lateMinutes,
            'is_holiday' => $holidayInfo['is_holiday'],
            'holiday_type' => $holidayInfo['holiday_type'],
            'is_re_entry' => false,
            'session_type' => 1,
            'original_time_in' => $timeStr,
            'last_time_in' => $timeStr,
            'accumulated_hours' => 0,
            'shift_type' => $shiftType
        ]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function timeOut($pdo, $input) {
    try {
        $emp_id = $input['emp_id'] ?? null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'error' => 'Employee ID required']);
            return;
        }
        
        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        $timeStr = $currentTime->format('H:i:s');
        
        // Find today's time in record
        $findSql = "SELECT id, time_in, last_time_in, late, late_minutes, session_type, session_data, accumulated_hours, shift_type, date FROM attendancelist WHERE emp_id = :emp_id AND (date = :date OR (date = DATE_SUB(:date, INTERVAL 1 DAY) AND shift_type = 'night')) AND time_in IS NOT NULL AND time_out IS NULL ORDER BY time_in DESC LIMIT 1";
        $findStmt = $pdo->prepare($findSql);
        $findStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $findStmt->bindParam(':date', $today);
        $findStmt->execute();
        
        $record = $findStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            echo json_encode(['success' => false, 'error' => 'No active time in record found']);
            return;
        }

        // Calculate current session duration properly
        $sessionTimeIn = $record['last_time_in'] ?? $record['time_in'];
        $timeInObj = new DateTime($today . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
        $timeOutObj = new DateTime($today . ' ' . $timeStr, new DateTimeZone('Asia/Manila'));
        
        // Handle overnight sessions for night shift
        if ($timeOutObj < $timeInObj) {
            $timeOutObj->modify('+1 day');
        }
        
        $sessionInterval = $timeInObj->diff($timeOutObj);
        $currentSessionMinutes = ($sessionInterval->h * 60) + $sessionInterval->i + ($sessionInterval->s / 60);
        
        // Get accumulated hours from previous sessions (already in minutes)
        $accumulatedMinutes = $record['accumulated_hours'] ?? 0;
        $sessionData = $record['session_data'] ? json_decode($record['session_data'], true) : [];
        $sessionType = $record['session_type'] ?? 1;

        error_log("=== TIME OUT CALCULATION DEBUG ===");
        error_log("Session Time In: " . $sessionTimeIn);
        error_log("Time Out: " . $timeStr);
        error_log("Current Session Minutes: " . $currentSessionMinutes);
        error_log("Previous Accumulated Minutes: " . $accumulatedMinutes);

        $shiftType = $record['shift_type'] ?? detectShiftType($record['time_in']);
        $timeInHour = (int)date('H', strtotime($sessionTimeIn));
        $timeOutHour = (int)date('H', strtotime($timeStr));
        $isEarlyNightShiftArrival = ($shiftType === 'night' && $timeInHour >= 18 && $timeInHour < 22);
        $isEarlyDayShiftArrival = ($shiftType === 'day' && $timeInHour < 8);
        
        // Calculate adjusted session minutes for early arrivals
        $adjustedSessionMinutes = $currentSessionMinutes;
        
        if ($isEarlyNightShiftArrival) {
            // For early night shift arrivals, calculate time only from 10:00 PM
            $effectiveTimeIn = $today . ' 22:00:00';
            $effectiveTimeOut = $today . ' ' . $timeStr;
            
            // If time out is before 10:00 PM, the session is entirely during early arrival period
            if ($timeOutHour < 22 && $timeOutHour >= 18) {
                $adjustedSessionMinutes = 0;
            } else {
                // Calculate only the time after 10:00 PM
                $effectiveTimeInObj = new DateTime($effectiveTimeIn, new DateTimeZone('Asia/Manila'));
                $effectiveTimeOutObj = new DateTime($effectiveTimeOut, new DateTimeZone('Asia/Manila'));
                
                // If time out is after midnight, adjust the date
                if ($timeOutHour < 18) {
                    $effectiveTimeOutObj->modify('+1 day');
                }
                
                $adjustedInterval = $effectiveTimeInObj->diff($effectiveTimeOutObj);
                $adjustedSessionMinutes = ($adjustedInterval->h * 60) + $adjustedInterval->i;
            }
            
            error_log("EARLY NIGHT SHIFT ADJUSTMENT:");
            error_log("Original session minutes: " . $currentSessionMinutes);
            error_log("Adjusted session minutes: " . $adjustedSessionMinutes);
            
        } elseif ($isEarlyDayShiftArrival) {
            // For early day shift arrivals, calculate time only from 8:00 AM
            if ($timeOutHour <= 8) {
                // Time out before 8:00 AM - no work time
                $adjustedSessionMinutes = 0;
            } else {
                // Calculate only the time after 8:00 AM
                $effectiveTimeIn = $today . ' 08:00:00';
                $effectiveTimeOut = $today . ' ' . $timeStr;
                
                $effectiveTimeInObj = new DateTime($effectiveTimeIn, new DateTimeZone('Asia/Manila'));
                $effectiveTimeOutObj = new DateTime($effectiveTimeOut, new DateTimeZone('Asia/Manila'));
                
                $adjustedInterval = $effectiveTimeInObj->diff($effectiveTimeOutObj);
                $adjustedSessionMinutes = ($adjustedInterval->h * 60) + $adjustedInterval->i;
            }
            
            error_log("EARLY DAY SHIFT ADJUSTMENT:");
            error_log("Original session minutes: " . $currentSessionMinutes);
            error_log("Adjusted session minutes: " . $adjustedSessionMinutes);
        }

        // Apply lunch break deduction for day shift sessions that cross lunch time
        $lunchAdjustedMinutes = $adjustedSessionMinutes;
        if ($shiftType === 'day') {
            $sessionStart = new DateTime($today . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
            $sessionEnd = new DateTime($today . ' ' . $timeStr, new DateTimeZone('Asia/Manila'));
            $lunchStart = new DateTime($today . ' 12:00:00', new DateTimeZone('Asia/Manila'));
            $lunchEnd = new DateTime($today . ' 13:00:00', new DateTimeZone('Asia/Manila'));
            
            // If session crosses lunch period (12:00-13:00), subtract 60 minutes
            if ($sessionStart <= $lunchStart && $sessionEnd > $lunchEnd) {
                $lunchAdjustedMinutes = max(0, $adjustedSessionMinutes - 60);
                error_log("LUNCH DEDUCTION: Session crosses lunch period. Before: $adjustedSessionMinutes, After: $lunchAdjustedMinutes");
            } else if ($sessionStart < $lunchEnd && $sessionEnd > $lunchStart && $sessionEnd->format('H:i') >= '12:00') {
                // Partial overlap with lunch, but only if time out is at or after 12:00 PM
                $lunchOverlapStart = max($sessionStart, $lunchStart);
                $lunchOverlapEnd = min($sessionEnd, $lunchEnd);
                $lunchInterval = $lunchOverlapStart->diff($lunchOverlapEnd);
                $lunchOverlapMinutes = ($lunchInterval->h * 60) + $lunchInterval->i;
                $lunchAdjustedMinutes = max(0, $adjustedSessionMinutes - $lunchOverlapMinutes);
                error_log("LUNCH DEDUCTION: Partial overlap after 12PM. Overlap: $lunchOverlapMinutes, Before: $adjustedSessionMinutes, After: $lunchAdjustedMinutes");
            }
        }

        // Add current session to session data with lunch-adjusted minutes
        $sessionData[] = [
            'session' => $sessionType,
            'date' => $today,
            'time_in' => $sessionTimeIn,
            'time_out' => $timeStr,
            'duration_minutes' => $currentSessionMinutes,
            'adjusted_minutes' => $lunchAdjustedMinutes  // Use lunch-adjusted minutes
        ];

        $totalWorkMinutes = $accumulatedMinutes + $lunchAdjustedMinutes;  // Use lunch-adjusted minutes
                
        // Update accumulated hours for potential next session
        $newAccumulatedMinutes = $totalWorkMinutes;

        error_log("Total Work Minutes: " . $totalWorkMinutes);
        error_log("New Accumulated Minutes: " . $newAccumulatedMinutes);

        // FIXED: Initialize variables first
        $totalMinutes = 0;
        $overtimeMinutes = 0;
        $regularHolidayMinutes = 0;
        $regularHolidayOTMinutes = 0;
        $specialHolidayMinutes = 0;
        $specialHolidayOTMinutes = 0;
        $restDayMinutes = 0;
        $restDayOTMinutes = 0;
        $restDayNDMinutes = 0;
        $regularHolidayROTMinutes = 0;
        $regularHolidayROTOTMinutes = 0;
        $specialHolidayROTMinutes = 0;
        $specialHolidayROTOTMinutes = 0;
        $specialHolidayROTNDMinutes = 0;
        $regularOTNDMinutes = 0;
        $regularHolidayNDMinutes = 0;
        $specialHolidayNDMinutes = 0;

        // Get holiday and rest day info
        $holidayInfo = checkHolidayForDate($pdo, $today);
        $isHoliday = $holidayInfo['is_holiday'];
        $holidayType = $holidayInfo['holiday_type'];
        $isRestDay = isRestDay($today, $emp_id, $pdo);

        // FIXED: Proper calculation for both single and multiple sessions
        if ($shiftType === 'night') {
            // For night shift, ALL work time is night differential
            // No regular minutes, no overtime after 8 hours rule
            $totalMinutes = $totalWorkMinutes; // FIXED: Set total_workhours to actual work time
            $overtimeMinutes = 0; // Night shift doesn't have traditional overtime
            
            // Allocate ALL minutes to appropriate night differential column
            if ($isRestDay) {
                if ($isHoliday && $holidayType === 'Special') {
                    $specialHolidayROTNDMinutes = $totalWorkMinutes;
                } else {
                    $restDayNDMinutes = $totalWorkMinutes;
                }
            } else {
                if ($isHoliday) {
                    if ($holidayType === 'Regular') {
                        $regularHolidayNDMinutes = $totalWorkMinutes;
                    } else {
                        $specialHolidayNDMinutes = $totalWorkMinutes;
                    }
                } else {
                    $regularOTNDMinutes = $totalWorkMinutes;
                }
            }
            
            error_log("NIGHT SHIFT ALLOCATION:");
            error_log("Total Work Minutes: " . $totalWorkMinutes);
            error_log("Total Minutes (for total_workhours): " . $totalMinutes);
            error_log("Regular OT ND: " . $regularOTNDMinutes);
            error_log("Regular Holiday ND: " . $regularHolidayNDMinutes);
            error_log("Special Holiday ND: " . $specialHolidayNDMinutes);
            error_log("Rest Day ND: " . $restDayNDMinutes);
            error_log("Special Holiday ROT ND: " . $specialHolidayROTNDMinutes);
            
        } else {
            // Day shift logic - Calculate total from ALL sessions
            $totalRegularMinutes = 0;
            $totalOvertimeMinutes = 0;
            
            // Calculate minutes from ALL sessions (including current)
            foreach ($sessionData as $session) {
                $sessionInParts = explode(':', $session['time_in']);
                $sessionOutParts = explode(':', $session['time_out']);
                
                $sessionInMinutes = ((int)$sessionInParts[0] * 60) + (int)$sessionInParts[1];
                $sessionOutMinutes = ((int)$sessionOutParts[0] * 60) + (int)$sessionOutParts[1];
                
                $workEndMinutes = 17 * 60; // 5:00 PM = 1020 minutes
                
                // Regular time: before 5 PM
                if ($sessionOutMinutes <= $workEndMinutes) {
                    // Entire session is regular time
                    $totalRegularMinutes += $session['adjusted_minutes'];
                } else if ($sessionInMinutes < $workEndMinutes) {
                    // Session spans regular and overtime
                    $regularPortion = $workEndMinutes - $sessionInMinutes;
                    $overtimePortion = $sessionOutMinutes - $workEndMinutes;
                    
                    // Apply lunch deduction if needed for regular portion
                    if ($sessionInMinutes < 780 && $workEndMinutes > 780) { // 780 = 1 PM
                        $regularPortion -= 60; // Lunch deduction
                    }
                    
                    $totalRegularMinutes += $regularPortion;
                    $totalOvertimeMinutes += $overtimePortion;
                } else {
                    // Entire session is overtime (after 5 PM)
                    $totalOvertimeMinutes += $session['adjusted_minutes'];
                }
            }
            
            // Apply overtime capping logic: fill total_workhours to 480 first
            if ($totalRegularMinutes < 480 && $totalOvertimeMinutes > 0) {
                // Need to fill the gap to reach 8 hours
                $gapToFill = 480 - $totalRegularMinutes;
                $fillAmount = min($gapToFill, $totalOvertimeMinutes);
                
                $totalMinutes = $totalRegularMinutes + $fillAmount;
                $remainingOvertimeMinutes = $totalOvertimeMinutes - $fillAmount;
                
                // Only count overtime if remaining is at least 1 hour
                if ($remainingOvertimeMinutes >= 60) {
                    $overtimeHours = floor($remainingOvertimeMinutes / 60);
                    $overtimeMinutes = $overtimeHours * 60;
                } else {
                    $overtimeMinutes = 0;
                }
            } else {
                // Already have 8+ hours of regular time, or no overtime
                $totalMinutes = min($totalRegularMinutes, 480);
                
                // Only count overtime if it's at least 1 hour
                $overtimeMinutes = 0;
                if ($totalOvertimeMinutes >= 60) {
                    $overtimeHours = floor($totalOvertimeMinutes / 60);
                    $overtimeMinutes = $overtimeHours * 60;
                }
            }
        
            error_log("BIOMETRIC DAY SHIFT CALCULATION:");
            error_log("Session Time In: " . $sessionTimeIn);
            error_log("Time Out: " . $timeStr);
            error_log("Total Regular Minutes: " . $totalMinutes);
            error_log("Overtime Minutes: " . $overtimeMinutes);
        
            // Allocate hours based on holiday/rest day status
            if ($isRestDay) {
                if ($isHoliday) {
                    if ($holidayType === 'Regular') {
                        $regularHolidayROTMinutes = $totalMinutes;
                        $regularHolidayROTOTMinutes = $overtimeMinutes;
                        $regularHolidayMinutes = 0;
                        $regularHolidayOTMinutes = 0;
                    } else {
                        $specialHolidayROTMinutes = $totalMinutes;
                        $specialHolidayROTOTMinutes = $overtimeMinutes;
                        $specialHolidayMinutes = 0;
                        $specialHolidayOTMinutes = 0;
                    }
                } else {
                    $restDayMinutes = $totalMinutes;
                    if ($overtimeMinutes > 0) {
                        $restDayOTMinutes = $overtimeMinutes;
                    }
                }
            } else {
                // Not rest day - regular holiday allocation
                if ($isHoliday) {
                    if ($holidayType === 'Regular') {
                        $regularHolidayMinutes = $totalMinutes;
                        $regularHolidayOTMinutes = $overtimeMinutes;
                    } else {
                        $specialHolidayMinutes = $totalMinutes;
                        $specialHolidayOTMinutes = $overtimeMinutes;
                    }
                }
                // All _rot columns remain 0 for non-rest day scenarios
            }
        } // <-- Move the closing brace HERE
        
        // Calculate undertime minutes
        $undertimeMinutes = $shiftType === 'night' ? 0 : calculateUndertimeMinutes($timeStr, $shiftType);
        
        // Calculate combined late_undertime (late_minutes + undertime_minutes)
        $lateUndertimeTotal = $record['late_minutes'] + $undertimeMinutes;
        
        // Determine status
        $status = 'Present';
        if ($isHoliday) {
            if ($overtimeMinutes > 0 || $regularHolidayOTMinutes > 0 || $specialHolidayOTMinutes > 0) {
                $status = $holidayType . ' Holiday + OT';
            } else {
                $status = $holidayType . ' Holiday';
            }
        } elseif ($overtimeMinutes > 0) {
            $status = 'Overtime';
        }
        
        // Determine regular_ot column value based on holiday status
        $regularOtMinutes = 0;
        if (!$isHoliday && $overtimeMinutes > 0 && $shiftType === 'day') {
            $regularOtMinutes = $overtimeMinutes;
        }
        
        // Update record with corrected values
        $updateSql = "UPDATE attendancelist SET 
              time_out = :time_out, 
              total_workhours = :total_hours, 
              session_data = :session_data,
              accumulated_hours = :accumulated_hours,
              overtime = :overtime,
              regular_ot = :regular_ot,
              regular_holiday = :regular_holiday,
              regular_holiday_ot = :regular_holiday_ot,
              special_holiday = :special_holiday,
              special_holiday_ot = :special_holiday_ot,
              regular_ot_nd = :regular_ot_nd,
              regular_holiday_nd = :regular_holiday_nd,
              special_holiday_nd = :special_holiday_nd,
              rest_day_ot = :rest_day_ot,                         
              rest_day_ot_plus_ot = :rest_day_ot_plus_ot,          
              rest_day_nd = :rest_day_nd,                          
              regular_holiday_rot = :regular_holiday_rot,          
              regular_holiday_rot_ot = :regular_holiday_rot_ot,    
              special_holiday_rot = :special_holiday_rot,          
              special_holiday_rot_ot = :special_holiday_rot_ot,   
              special_holiday_rot_nd = :special_holiday_rot_nd,    
              undertime_minutes = :undertime_minutes,
              late_undertime = :late_undertime,
              is_holiday = :is_holiday,
              holiday_type = :holiday_type,
              is_rest_day = :is_rest_day,
              status = :status 
              WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':time_out', $timeStr);
        $updateStmt->bindParam(':total_hours', $totalMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':overtime', $overtimeMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_ot', $regularOtMinutes, PDO::PARAM_INT);
        $sessionDataJson = json_encode($sessionData);
        $updateStmt->bindParam(':session_data', $sessionDataJson);
        $isRestDayFlag = $isRestDay ? 1 : 0;
        $updateStmt->bindParam(':rest_day_ot', $restDayMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':rest_day_ot_plus_ot', $restDayOTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':rest_day_nd', $restDayNDMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_holiday_rot', $regularHolidayROTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_ot_nd', $regularOTNDMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_holiday_nd', $regularHolidayNDMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday_nd', $specialHolidayNDMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_holiday_rot_ot', $regularHolidayROTOTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday_rot', $specialHolidayROTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday_rot_ot', $specialHolidayROTOTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday_rot_nd', $specialHolidayROTNDMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
        $updateStmt->bindParam(':accumulated_hours', $newAccumulatedMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_holiday', $regularHolidayMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':regular_holiday_ot', $regularHolidayOTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday', $specialHolidayMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':special_holiday_ot', $specialHolidayOTMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':undertime_minutes', $undertimeMinutes, PDO::PARAM_INT);
        $updateStmt->bindParam(':late_undertime', $lateUndertimeTotal, PDO::PARAM_INT);
        $updateStmt->bindParam(':is_holiday', $isHoliday, PDO::PARAM_INT);
        $updateStmt->bindParam(':holiday_type', $holidayType);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->bindParam(':id', $record['id'], PDO::PARAM_INT);
        $updateStmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Time out recorded successfully',
            'time_out' => $timeStr,
            'total_hours' => number_format($totalMinutes / 60, 2),
            'overtime_hours' => number_format($overtimeMinutes / 60, 2),
            'regular_ot_hours' => number_format($regularOtMinutes / 60, 2),
            'regular_holiday_hours' => number_format($regularHolidayMinutes / 60, 2),
            'regular_holiday_ot_hours' => number_format($regularHolidayOTMinutes / 60, 2),
            'special_holiday_hours' => number_format($specialHolidayMinutes / 60, 2),
            'special_holiday_ot_hours' => number_format($specialHolidayOTMinutes / 60, 2),
            'rest_day_hours' => number_format($restDayMinutes / 60, 2),
            'rest_day_ot_hours' => number_format($restDayOTMinutes / 60, 2),
            'rest_day_nd_hours' => number_format($restDayNDMinutes / 60, 2),
            'regular_holiday_rot_hours' => number_format($regularHolidayROTMinutes / 60, 2),
            'regular_holiday_rot_ot_hours' => number_format($regularHolidayROTOTMinutes / 60, 2),
            'special_holiday_rot_hours' => number_format($specialHolidayROTMinutes / 60, 2),
            'special_holiday_rot_ot_hours' => number_format($specialHolidayROTOTMinutes / 60, 2),
            'special_holiday_rot_nd_hours' => number_format($specialHolidayROTNDMinutes / 60, 2),
            'regular_ot_nd_hours' => number_format($regularOTNDMinutes / 60, 2),
            'regular_holiday_nd_hours' => number_format($regularHolidayNDMinutes / 60, 2),
            'special_holiday_nd_hours' => number_format($specialHolidayNDMinutes / 60, 2),
            'undertime_minutes' => $undertimeMinutes,
            'late_undertime_total' => $lateUndertimeTotal,
            'display_time' => $currentTime->format('g:i A'),
            'status' => $status,
            'is_holiday' => $isHoliday,
            'holiday_type' => $holidayType,
            'is_rest_day' => $isRestDay,
            'debug' => [
                'shift_type' => $shiftType,
                'session_minutes' => $currentSessionMinutes,
                'accumulated_minutes' => $accumulatedMinutes,
                'total_work_minutes' => $totalWorkMinutes,
                'total_minutes_for_db' => $totalMinutes,
                'session_data' => $sessionData
            ]
        ]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleBiometricPush($pdo, $input) {
    global $pdo;
    global $TEMP_TEST_MODE;
    
    try {
        $employee_id = $input['employee_id'] ?? null;
        $status = $input['status'] ?? null;
        $datetime = $input['datetime'] ?? null;
        
        if (!$employee_id || !$status || !$datetime) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            return;
        }
        
        try {
            // MODIFIED: Use test mode if enabled, otherwise use the biometric datetime
            $testSettings = getTestTimeFromDB();
            if ($testSettings && $testSettings['test_mode'] == 1) {
                // Use test mode - get current time with test settings
                $datetimeObj = getCurrentTimeUTC8();
                error_log("🚨 BIOMETRIC TEST MODE ACTIVE");
                error_log("🚨 Original biometric datetime: " . $datetime);
                error_log("🚨 Using test datetime: " . $datetimeObj->format('Y-m-d H:i:s'));
                $isTestMode = true;
            } else {
                // Normal mode - use the actual biometric datetime
                if (strpos($datetime, '+') === false && strpos($datetime, 'Z') === false) {
                    $datetimeObj = new DateTime($datetime, new DateTimeZone('Asia/Manila'));
                } else {
                    $datetimeObj = new DateTime($datetime);
                    $datetimeObj->setTimezone(new DateTimeZone('Asia/Manila'));
                }
                $isTestMode = false;
            }
            
            $time = $datetimeObj->format('H:i:s');
            $hour = (int)$datetimeObj->format('H');
            
            $originalDate = $datetimeObj->format('Y-m-d');
            // Only adjust date for night shift workers (6 PM to 6 AM window)
            if ($hour >= 0 && $hour < 8) {
                $shiftType = detectShiftType($time);
                if ($shiftType === 'night') {
                    $datetimeObj->modify('-1 day');
                }
            }
            $date = $datetimeObj->format('Y-m-d');
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Invalid datetime format: ' . $e->getMessage()]);
            return;
        }

        // Find employee by fingerprint
        $findEmpSql = "SELECT u.id as user_id, u.firstName, u.lastName, e.workarrangement 
                       FROM useraccounts u 
                       LEFT JOIN employeelist e ON u.id = e.emp_id 
                       WHERE u.fingerprint_uid = :fingerprint_uid OR e.fingerprint_uid = :fingerprint_uid2";
        $findEmpStmt = $pdo->prepare($findEmpSql);
        $findEmpStmt->bindParam(':fingerprint_uid', $employee_id);
        $findEmpStmt->bindParam(':fingerprint_uid2', $employee_id);
        $findEmpStmt->execute();
        
        $employee = $findEmpStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found with fingerprint ID: ' . $employee_id]);
            return;
        }
        
        if ($employee['workarrangement'] === 'Work From Home') {
            echo json_encode(['success' => false, 'error' => 'Work from home employees should use web interface']);
            return;
        }

        $emp_id = $employee['user_id'];              
        $isEmployeeRestDay = isRestDay($date, $emp_id, $pdo);  
        error_log("=== BIOMETRIC TIME IN REST DAY CHECK ===");
        error_log("Employee ID: " . $emp_id . ", Date: " . $date . ", Is Rest Day: " . ($isEmployeeRestDay ? 'YES' : 'NO'));
        
        // Handle Time In
        if (in_array($status, ['Check In', 'Time In'])) {
            $shiftType = detectShiftType($time);
            
            if (!isValidAttendanceTime($time, $status, $shiftType)) {
                if ($shiftType === 'day') {
                    $errorMsg = in_array($status, ['Check In', 'Time In']) 
                        ? 'Day shift time in: 6:00 AM - 5:00 PM only!' 
                        : 'Day shift time out: 6:00 AM - 5:00 PM only!';
                } else {
                    $errorMsg = in_array($status, ['Check In', 'Time In']) 
                        ? 'Night shift time in: 6:00 PM onwards only!' 
                        : 'Night shift time out: 6:00 PM - 8:00 AM only!';
                }
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                return;
            }
            
            // Check for active session
            $checkSql = "SELECT id, shift_type FROM attendancelist WHERE emp_id = :emp_id AND date = :date AND time_in IS NOT NULL AND time_out IS NULL";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $checkStmt->bindParam(':date', $date);
            $checkStmt->execute();
            $activeSession = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($activeSession) {
                if ($activeSession['shift_type'] === $shiftType) {
                    echo json_encode(['success' => false, 'error' => 'Already timed in for this shift type today']);
                    return;
                }
                // If different shift type, allow new record (continue below)
            }
                        
            // Check for re-time in scenario
            $reEntryCheckSql = "SELECT id, time_in, time_out, late_minutes, session_type, accumulated_hours, shift_type, date 
FROM attendancelist 
WHERE emp_id = :emp_id 
AND date = :date
AND shift_type = :shift_type
AND time_in IS NOT NULL AND time_out IS NOT NULL 
ORDER BY id DESC LIMIT 1";
$reEntryStmt = $pdo->prepare($reEntryCheckSql);
$reEntryStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
$reEntryStmt->bindParam(':date', $date);
$reEntryStmt->bindParam(':shift_type', $shiftType);
$reEntryStmt->execute();
$existingRecord = $reEntryStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                // Validate re-time in hours
                $recordShiftType = $existingRecord['shift_type'] ?? detectShiftType($existingRecord['time_in']);
                $currentHour = (int)date('H', strtotime($time));
                
                $canReTimeIn = false;
                if ($recordShiftType === 'day') {
                    $canReTimeIn = $currentHour >= 8 && $currentHour < 17;
                } else {
                    $canReTimeIn = ($currentHour >= 18 && $currentHour < 22) || $currentHour >= 22 || $currentHour < 6;
                }
                
                if (!$canReTimeIn) {
                    $errorMsg = $recordShiftType === 'day' 
                        ? 'Biometric re-time in not allowed after 5:00 PM (overtime period).' 
                        : 'Biometric re-time in not allowed after 6:00 AM (overtime period).';
                    echo json_encode(['success' => false, 'error' => $errorMsg]);
                    return;
                }
                
                // Update for re-time in
                $sessionType = ($existingRecord['session_type'] ?? 0) + 1;
                
                $updateSql = "UPDATE attendancelist SET 
                            time_out = NULL,
                            last_time_in = :last_time_in,
                            session_type = :session_type,
                            total_workhours = 0,
                            overtime = 0,
                            regular_ot = 0,
                            regular_holiday = 0,
                            regular_holiday_ot = 0,
                            special_holiday = 0,
                            special_holiday_ot = 0,
                            undertime_minutes = 0,
                            late_undertime = :late_undertime,
                            status = 'Present'
                            WHERE id = :id";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->bindParam(':last_time_in', $time);
                $updateStmt->bindParam(':session_type', $sessionType, PDO::PARAM_INT);
                $updateStmt->bindParam(':late_undertime', $existingRecord['late_minutes'], PDO::PARAM_INT);
                $updateStmt->bindParam(':id', $existingRecord['id'], PDO::PARAM_INT);
                $updateStmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Biometric re-time in recorded successfully (Session ' . $sessionType . ')',
                    'employee_name' => $employee['firstName'] . ' ' . $employee['lastName'],
                    'time_in' => $time,
                    'date' => $date,
                    'session_type' => $sessionType,
                    'is_re_entry' => true,
                    'action' => 'time_in',
                    'test_mode' => $TEMP_TEST_MODE // Add this for debugging
                ]);
                return;
            }
            
            // New time in
            $lateMinutes = calculateLateMinutes($time, $shiftType, $date);
            $holidayInfo = checkHolidayForDate($pdo, $date);

            $sql = "INSERT INTO attendancelist (emp_id, firstName, lastName, date, time_in, last_time_in, late, late_minutes, present, is_holiday, holiday_type, is_rest_day, shift_type, session_type, accumulated_hours)
                    VALUES (:emp_id, :firstName, :lastName, :date, :time_in, :last_time_in, :late, :late_minutes, 1, :is_holiday, :holiday_type, :is_rest_day, :shift_type, 1, 0)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $stmt->bindParam(':firstName', $employee['firstName']);
            $stmt->bindParam(':lastName', $employee['lastName']);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':time_in', $time);
            $stmt->bindParam(':last_time_in', $time);
            $isLate = $lateMinutes > 0 ? 1 : 0;
            $isHolidayFlag = $holidayInfo['is_holiday'] ? 1 : 0;
            $isRestDayFlag = $isEmployeeRestDay ? 1 : 0;
            $stmt->bindParam(':late', $isLate, PDO::PARAM_INT);
            $stmt->bindParam(':late_minutes', $lateMinutes, PDO::PARAM_INT);
            $stmt->bindParam(':is_holiday', $isHolidayFlag, PDO::PARAM_INT);
            $stmt->bindParam(':holiday_type', $holidayInfo['holiday_type']);
            $stmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
            $stmt->bindParam(':shift_type', $shiftType);
            $stmt->execute();

            // Get the inserted record ID
            $insertedId = $pdo->lastInsertId();

            // Check for approved OT and update auto_time_out
            $autoTimeOut = checkAndUpdateAutoTimeOut($pdo, $emp_id, $date);

            // Update the record with auto_time_out
            $updateAutoSql = "UPDATE attendancelist SET auto_time_out = :auto_time_out WHERE id = :id";
            $updateAutoStmt = $pdo->prepare($updateAutoSql);
            $updateAutoStmt->bindParam(':auto_time_out', $autoTimeOut);
            $updateAutoStmt->bindParam(':id', $insertedId, PDO::PARAM_INT);
            $updateAutoStmt->execute();
            
            $message = 'Biometric time in recorded successfully';
            if ($lateMinutes > 0) {
                $message .= ' (Late by ' . $lateMinutes . ' minutes)';
            }
            if ($holidayInfo['is_holiday']) {
                $message .= ' - ' . $holidayInfo['holiday_type'] . ' Holiday';
            }
            if ($TEMP_TEST_MODE) {
                $message .= ' [TEST MODE]';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'employee_name' => $employee['firstName'] . ' ' . $employee['lastName'],
                'time_in' => $time,
                'date' => $date,
                'late_minutes' => $lateMinutes,
                'is_holiday' => $holidayInfo['is_holiday'],
                'holiday_type' => $holidayInfo['holiday_type'],
                'is_rest_day' => $isEmployeeRestDay,
                'action' => 'time_in',
                'test_mode' => $isTestMode,
                'original_biometric_time' => $isTestMode ? $datetime : null
            ]);
            
        } elseif (in_array($status, ['Check Out', 'Time Out'])) {
            // Handle Time Out - FIXED VERSION
            $findSql = "SELECT id, time_in, last_time_in, late_minutes, session_type, session_data, accumulated_hours, shift_type FROM attendancelist WHERE emp_id = :emp_id AND (date = :date OR (date = DATE_SUB(:date, INTERVAL 1 DAY) AND shift_type = 'night')) AND time_in IS NOT NULL AND time_out IS NULL ORDER BY time_in DESC LIMIT 1";
            $findStmt = $pdo->prepare($findSql);
            $findStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $findStmt->bindParam(':date', $date);
            $findStmt->execute();
            
            $record = $findStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode(['success' => false, 'error' => 'No active time in record found']);
                return;
            }
            
            // Calculate current session duration properly
            $sessionTimeIn = $record['last_time_in'] ?? $record['time_in'];
            $timeInObj = new DateTime($date . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
            $timeOutObj = new DateTime($date . ' ' . $time, new DateTimeZone('Asia/Manila'));
            
            // Handle overnight sessions for night shift
            if ($timeOutObj < $timeInObj) {
                $timeOutObj->modify('+1 day');
            }
            
            $sessionInterval = $timeInObj->diff($timeOutObj);
            $currentSessionMinutes = ($sessionInterval->h * 60) + $sessionInterval->i + ($sessionInterval->s / 60);
            
            // Get accumulated hours from previous sessions (already in minutes)
            $accumulatedMinutes = $record['accumulated_hours'] ?? 0;
            $sessionData = $record['session_data'] ? json_decode($record['session_data'], true) : [];
            $sessionType = $record['session_type'] ?? 1;

            error_log("=== BIOMETRIC TIME OUT CALCULATION DEBUG ===");
            error_log("Session Time In: " . $sessionTimeIn);
            error_log("Time Out: " . $time);
            error_log("Current Session Minutes: " . $currentSessionMinutes);
            error_log("Previous Accumulated Minutes: " . $accumulatedMinutes);

            $shiftType = $record['shift_type'] ?? detectShiftType($record['time_in']);
            $timeInHour = (int)date('H', strtotime($sessionTimeIn));
            $timeOutHour = (int)date('H', strtotime($time));
            $isEarlyNightShiftArrival = ($shiftType === 'night' && $timeInHour >= 18 && $timeInHour < 22);
            $isEarlyDayShiftArrival = ($shiftType === 'day' && $timeInHour < 8);

            // Calculate adjusted session minutes for early arrivals
            $adjustedSessionMinutes = $currentSessionMinutes;

            if ($isEarlyNightShiftArrival) {
                // For early night shift arrivals, calculate time only from 10:00 PM
                $effectiveTimeIn = $date . ' 22:00:00';
                $effectiveTimeOut = $date . ' ' . $time;
                
                // If time out is before 10:00 PM, the session is entirely during early arrival period
                if ($timeOutHour < 22 && $timeOutHour >= 18) {
                    $adjustedSessionMinutes = 0;
                } else {
                    // Calculate only the time after 10:00 PM
                    $effectiveTimeInObj = new DateTime($effectiveTimeIn, new DateTimeZone('Asia/Manila'));
                    $effectiveTimeOutObj = new DateTime($effectiveTimeOut, new DateTimeZone('Asia/Manila'));
                    
                    // If time out is after midnight, adjust the date
                    if ($timeOutHour < 18) {
                        $effectiveTimeOutObj->modify('+1 day');
                    }
                    
                    $adjustedInterval = $effectiveTimeInObj->diff($effectiveTimeOutObj);
                    $adjustedSessionMinutes = ($adjustedInterval->h * 60) + $adjustedInterval->i;
                }
                
                error_log("EARLY NIGHT SHIFT ADJUSTMENT:");
                error_log("Original session minutes: " . $currentSessionMinutes);
                error_log("Adjusted session minutes: " . $adjustedSessionMinutes);
                
            } elseif ($isEarlyDayShiftArrival) {
                // For early day shift arrivals, calculate time only from 8:00 AM
                if ($timeOutHour <= 8) {
                    // Time out before 8:00 AM - no work time
                    $adjustedSessionMinutes = 0;
                } else {
                    // Calculate only the time after 8:00 AM
                    $effectiveTimeIn = $date . ' 08:00:00';
                    $effectiveTimeOut = $date . ' ' . $time;
                    
                    $effectiveTimeInObj = new DateTime($effectiveTimeIn, new DateTimeZone('Asia/Manila'));
                    $effectiveTimeOutObj = new DateTime($effectiveTimeOut, new DateTimeZone('Asia/Manila'));
                    
                    $adjustedInterval = $effectiveTimeInObj->diff($effectiveTimeOutObj);
                    $adjustedSessionMinutes = ($adjustedInterval->h * 60) + $adjustedInterval->i;
                }
                
                error_log("EARLY DAY SHIFT ADJUSTMENT:");
                error_log("Original session minutes: " . $currentSessionMinutes);
                error_log("Adjusted session minutes: " . $adjustedSessionMinutes);
            }

            // Apply lunch break deduction for day shift sessions that cross lunch time
            $lunchAdjustedMinutes = $adjustedSessionMinutes;
            if ($shiftType === 'day') {
                $sessionStart = new DateTime($date . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
                $sessionEnd = new DateTime($date . ' ' . $time, new DateTimeZone('Asia/Manila'));
                $lunchStart = new DateTime($date . ' 12:00:00', new DateTimeZone('Asia/Manila'));
                $lunchEnd = new DateTime($date . ' 13:00:00', new DateTimeZone('Asia/Manila'));
                
                // Only subtract lunch if session ends AFTER 1:00 PM and started before lunch
                if ($sessionStart <= $lunchStart && $sessionEnd > $lunchEnd) {
                    $lunchAdjustedMinutes = max(0, $adjustedSessionMinutes - 60);
                    error_log("LUNCH DEDUCTION: Session crosses full lunch period. Before: $adjustedSessionMinutes, After: $lunchAdjustedMinutes");
                }
                // Sessions ending at 11:59 AM or earlier should have NO lunch deduction
            }

            // Add current session to session data with lunch-adjusted minutes
            $sessionData[] = [
                'session' => $sessionType,
                'date' => $date,
                'time_in' => $sessionTimeIn,
                'time_out' => $time,
                'duration_minutes' => $currentSessionMinutes,
                'adjusted_minutes' => $lunchAdjustedMinutes
            ];

            // Calculate total work minutes = accumulated + adjusted session minutes
            $totalWorkMinutes = $accumulatedMinutes + $lunchAdjustedMinutes;
            
            // Update accumulated hours for potential next session
            $newAccumulatedMinutes = $totalWorkMinutes;

            error_log("Total Work Minutes: " . $totalWorkMinutes);
            error_log("New Accumulated Minutes: " . $newAccumulatedMinutes);

            // FIXED: Initialize variables first
            $totalMinutes = 0;
            $overtimeMinutes = 0;
            $regularHolidayMinutes = 0;
            $regularHolidayOTMinutes = 0;
            $specialHolidayMinutes = 0;
            $specialHolidayOTMinutes = 0;
            $restDayMinutes = 0;
            $restDayOTMinutes = 0;
            $restDayNDMinutes = 0;
            $regularHolidayROTMinutes = 0;
            $regularHolidayROTOTMinutes = 0;
            $specialHolidayROTMinutes = 0;
            $specialHolidayROTOTMinutes = 0;
            $specialHolidayROTNDMinutes = 0;
            $regularOTNDMinutes = 0;
            $regularHolidayNDMinutes = 0;
            $specialHolidayNDMinutes = 0;

            // Get holiday and rest day info
            $holidayInfo = checkHolidayForDate($pdo, $date);
            $isHoliday = $holidayInfo['is_holiday'];
            $holidayType = $holidayInfo['holiday_type'];
            $isRestDay = isRestDay($date, $emp_id, $pdo);

            // FIXED: Proper calculation for both single and multiple sessions
            if ($shiftType === 'night') {
                // For night shift, ALL work time is night differential
                // No regular minutes, no overtime after 8 hours rule
                $totalMinutes = $totalWorkMinutes; // FIXED: Set total_workhours to actual work time
                $overtimeMinutes = 0; // Night shift doesn't have traditional overtime
                
                // Allocate ALL minutes to appropriate night differential column
                if ($isRestDay) {
                    if ($isHoliday && $holidayType === 'Special') {
                        $specialHolidayROTNDMinutes = $totalWorkMinutes;
                    } else {
                        $restDayNDMinutes = $totalWorkMinutes;
                    }
                } else {
                    if ($isHoliday) {
                        if ($holidayType === 'Regular') {
                            $regularHolidayNDMinutes = $totalWorkMinutes;
                        } else {
                            $specialHolidayNDMinutes = $totalWorkMinutes;
                        }
                    } else {
                        $regularOTNDMinutes = $totalWorkMinutes;
                    }
                }
                
                error_log("BIOMETRIC NIGHT SHIFT ALLOCATION:");
                error_log("Total Work Minutes: " . $totalWorkMinutes);
                error_log("Total Minutes (for total_workhours): " . $totalMinutes);
                error_log("Regular OT ND: " . $regularOTNDMinutes);
                error_log("Regular Holiday ND: " . $regularHolidayNDMinutes);
                error_log("Special Holiday ND: " . $specialHolidayNDMinutes);
                error_log("Rest Day ND: " . $restDayNDMinutes);
                error_log("Special Holiday ROT ND: " . $specialHolidayROTNDMinutes);
                
            } else {
                // Day shift logic - Calculate total from ALL sessions
                $totalRegularMinutes = 0;
                $totalOvertimeMinutes = 0;
                
                // Calculate minutes from ALL sessions (including current)
                foreach ($sessionData as $session) {
                    $sessionInParts = explode(':', $session['time_in']);
                    $sessionOutParts = explode(':', $session['time_out']);
                    
                    $sessionInMinutes = ((int)$sessionInParts[0] * 60) + (int)$sessionInParts[1];
                    $sessionOutMinutes = ((int)$sessionOutParts[0] * 60) + (int)$sessionOutParts[1];
                    
                    $workEndMinutes = 17 * 60; // 5:00 PM = 1020 minutes
                    
                    // Regular time: before 5 PM
                    if ($sessionOutMinutes <= $workEndMinutes) {
                        // Entire session is regular time
                        $totalRegularMinutes += $session['adjusted_minutes'];
                    } else if ($sessionInMinutes < $workEndMinutes) {
                        // Session spans regular and overtime
                        $regularPortion = $workEndMinutes - $sessionInMinutes;
                        $overtimePortion = $sessionOutMinutes - $workEndMinutes;
                        
                        // Apply lunch deduction if needed for regular portion
                        if ($sessionInMinutes < 780 && $workEndMinutes > 780) { // 780 = 1 PM
                            $regularPortion -= 60; // Lunch deduction
                        }
                        
                        $totalRegularMinutes += $regularPortion;
                        $totalOvertimeMinutes += $overtimePortion;
                    } else {
                        // Entire session is overtime (after 5 PM)
                        $totalOvertimeMinutes += $session['adjusted_minutes'];
                    }
                }
                
                // Apply overtime capping logic: fill total_workhours to 480 first
                if ($totalRegularMinutes < 480 && $totalOvertimeMinutes > 0) {
                    // Need to fill the gap to reach 8 hours
                    $gapToFill = 480 - $totalRegularMinutes;
                    $fillAmount = min($gapToFill, $totalOvertimeMinutes);
                    
                    $totalMinutes = $totalRegularMinutes + $fillAmount;
                    $remainingOvertimeMinutes = $totalOvertimeMinutes - $fillAmount;
                    
                    // Only count overtime if remaining is at least 1 hour
                    if ($remainingOvertimeMinutes >= 60) {
                        $overtimeHours = floor($remainingOvertimeMinutes / 60);
                        $overtimeMinutes = $overtimeHours * 60;
                    } else {
                        $overtimeMinutes = 0;
                    }
                } else {
                    // Already have 8+ hours of regular time, or no overtime
                    $totalMinutes = min($totalRegularMinutes, 480);
                    
                    // Only count overtime if it's at least 1 hour
                    $overtimeMinutes = 0;
                    if ($totalOvertimeMinutes >= 60) {
                        $overtimeHours = floor($totalOvertimeMinutes / 60);
                        $overtimeMinutes = $overtimeHours * 60;
                    }
                }
                
                error_log("BIOMETRIC DAY SHIFT CALCULATION:");
                error_log("Total Regular Minutes (before 5PM): " . $totalRegularMinutes);
                error_log("Total Overtime Minutes (after 5PM): " . $totalOvertimeMinutes);
                error_log("Final Total Minutes (capped at 480): " . $totalMinutes);
                error_log("Final Overtime Minutes: " . $overtimeMinutes);
            
                // Allocate hours based on holiday/rest day status
                if ($isRestDay) {
                    if ($isHoliday) {
                        if ($holidayType === 'Regular') {
                            $regularHolidayROTMinutes = $totalMinutes;
                            $regularHolidayROTOTMinutes = $overtimeMinutes;
                            $regularHolidayMinutes = 0;
                            $regularHolidayOTMinutes = 0;
                        } else {
                            $specialHolidayROTMinutes = $totalMinutes;
                            $specialHolidayROTOTMinutes = $overtimeMinutes;
                            $specialHolidayMinutes = 0;
                            $specialHolidayOTMinutes = 0;
                        }
                    } else {
                        $restDayMinutes = $totalMinutes;
                        if ($overtimeMinutes > 0) {
                            $restDayOTMinutes = $overtimeMinutes;
                        }
                    }
                } else {
                    // Not rest day - regular holiday allocation
                    if ($isHoliday) {
                        if ($holidayType === 'Regular') {
                            $regularHolidayMinutes = $totalMinutes;
                            $regularHolidayOTMinutes = $overtimeMinutes;
                        } else {
                            $specialHolidayMinutes = $totalMinutes;
                            $specialHolidayOTMinutes = $overtimeMinutes;
                        }
                    }
                    // All _rot columns remain 0 for non-rest day scenarios
                }
            } // <-- Move the closing brace HERE
            
            // Calculate undertime minutes
            $undertimeMinutes = $shiftType === 'night' ? 0 : calculateUndertimeMinutes($time, $shiftType);
            
            // Calculate combined late_undertime (late_minutes + undertime_minutes)
            $lateUndertimeTotal = $record['late_minutes'] + $undertimeMinutes;
            
            // Determine status
            $status = 'Present';
            if ($isHoliday) {
                if ($overtimeMinutes > 0 || $regularHolidayOTMinutes > 0 || $specialHolidayOTMinutes > 0) {
                    $status = $holidayType . ' Holiday + OT';
                } else {
                    $status = $holidayType . ' Holiday';
                }
            } elseif ($overtimeMinutes > 0) {
                $status = 'Overtime';
            }
            
            // Determine regular_ot column value based on holiday status
            $regularOtMinutes = 0;
            if (!$isHoliday && $overtimeMinutes > 0 && $shiftType === 'day') {
                $regularOtMinutes = $overtimeMinutes;
            }
            
            // Update record with corrected values (SAME AS WFH FUNCTION)
            $updateSql = "UPDATE attendancelist SET 
                          time_out = :time_out, 
                          total_workhours = :total_hours, 
                          session_data = :session_data,
                          accumulated_hours = :accumulated_hours,
                          overtime = :overtime,
                          regular_ot = :regular_ot,
                          regular_holiday = :regular_holiday,
                          regular_holiday_ot = :regular_holiday_ot,
                          special_holiday = :special_holiday,
                          special_holiday_ot = :special_holiday_ot,
                          rest_day_ot = :rest_day_ot,                          
                          rest_day_ot_plus_ot = :rest_day_ot_plus_ot,         
                          rest_day_nd = :rest_day_nd,  
                          regular_ot_nd = :regular_ot_nd,
                          regular_holiday_nd = :regular_holiday_nd,
                          special_holiday_nd = :special_holiday_nd,                        
                          regular_holiday_rot = :regular_holiday_rot,         
                          regular_holiday_rot_ot = :regular_holiday_rot_ot,    
                          special_holiday_rot = :special_holiday_rot,         
                          special_holiday_rot_ot = :special_holiday_rot_ot,    
                          special_holiday_rot_nd = :special_holiday_rot_nd,    
                          undertime_minutes = :undertime_minutes,
                          late_undertime = :late_undertime,
                          is_holiday = :is_holiday,
                          holiday_type = :holiday_type,
                          is_rest_day = :is_rest_day,
                          status = :status 
                          WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':time_out', $time);
            $updateStmt->bindParam(':total_hours', $totalMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':overtime', $overtimeMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_ot', $regularOtMinutes, PDO::PARAM_INT);
            $sessionDataJson = json_encode($sessionData);
            $updateStmt->bindParam(':session_data', $sessionDataJson);
            $isRestDayFlag = isRestDay($date, $emp_id, $pdo) ? 1 : 0;
            $updateStmt->bindParam(':rest_day_ot', $restDayMinutes, PDO::PARAM_INT);             
            $updateStmt->bindParam(':rest_day_ot_plus_ot', $restDayOTMinutes, PDO::PARAM_INT); 
            $updateStmt->bindParam(':rest_day_nd', $restDayNDMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_ot_nd', $regularOTNDMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_holiday_nd', $regularHolidayNDMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday_nd', $specialHolidayNDMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_holiday_rot', $regularHolidayROTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_holiday_rot_ot', $regularHolidayROTOTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday_rot', $specialHolidayROTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday_rot_ot', $specialHolidayROTOTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday_rot_nd', $specialHolidayROTNDMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
            $updateStmt->bindParam(':accumulated_hours', $newAccumulatedMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_holiday', $regularHolidayMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':regular_holiday_ot', $regularHolidayOTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday', $specialHolidayMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':special_holiday_ot', $specialHolidayOTMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':undertime_minutes', $undertimeMinutes, PDO::PARAM_INT);
            $updateStmt->bindParam(':late_undertime', $lateUndertimeTotal, PDO::PARAM_INT);
            $updateStmt->bindParam(':is_holiday', $isHoliday, PDO::PARAM_INT);
            $updateStmt->bindParam(':holiday_type', $holidayType);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':id', $record['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Biometric time out recorded successfully',
                'employee_name' => $employee['firstName'] . ' ' . $employee['lastName'],
                'time_out' => $time,
                'total_hours' => number_format($totalMinutes / 60, 2),
                'overtime_hours' => number_format($overtimeMinutes / 60, 2),
                'regular_ot_hours' => number_format($regularOtMinutes / 60, 2),
                'regular_holiday_hours' => number_format($regularHolidayMinutes / 60, 2),
                'regular_holiday_ot_hours' => number_format($regularHolidayOTMinutes / 60, 2),
                'special_holiday_hours' => number_format($specialHolidayMinutes / 60, 2),
                'special_holiday_ot_hours' => number_format($specialHolidayOTMinutes / 60, 2),
                'rest_day_hours' => number_format($restDayMinutes / 60, 2),
                'rest_day_ot_hours' => number_format($restDayOTMinutes / 60, 2),
                'rest_day_nd_hours' => number_format($restDayNDMinutes / 60, 2),
                'regular_holiday_rot_hours' => number_format($regularHolidayROTMinutes / 60, 2),
                'regular_holiday_rot_ot_hours' => number_format($regularHolidayROTOTMinutes / 60, 2),
                'special_holiday_rot_hours' => number_format($specialHolidayROTMinutes / 60, 2),
                'special_holiday_rot_ot_hours' => number_format($specialHolidayROTOTMinutes / 60, 2),
                'special_holiday_rot_nd_hours' => number_format($specialHolidayROTNDMinutes / 60, 2),
                'regular_ot_nd_hours' => number_format($regularOTNDMinutes / 60, 2),
                'regular_holiday_nd_hours' => number_format($regularHolidayNDMinutes / 60, 2),
                'special_holiday_nd_hours' => number_format($specialHolidayNDMinutes / 60, 2),
                'undertime_minutes' => $undertimeMinutes,
                'late_undertime_total' => $lateUndertimeTotal,
                'display_time' => $datetimeObj->format('g:i A'),
                'status' => $status,
                'is_holiday' => $isHoliday,
                'holiday_type' => $holidayType,
                'is_rest_day' => $isRestDay,
                'test_mode' => $isTestMode,
                'original_biometric_time' => $isTestMode ? $datetime : null,
                'debug' => [
                    'shift_type' => $shiftType,
                    'session_minutes' => $currentSessionMinutes,
                    'accumulated_minutes' => $accumulatedMinutes,
                    'total_work_minutes' => $totalWorkMinutes,
                    'total_minutes_for_db' => $totalMinutes,
                    'session_data' => $sessionData
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid status: ' . $status]);
        }
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

function processAbsentEmployees($pdo) {
    try {
        $currentTime = getCurrentTimeUTC8();
        $today = $currentTime->format('Y-m-d');
        $currentHour = (int)$currentTime->format('H');
        
        // Only run this after 5:00 PM on weekdays
        if ($currentHour < 17) {
            echo json_encode(['success' => false, 'message' => 'Not time to process absent employees yet (must be after 5:00 PM)']);
            return;
        }
        
        // Get all employees who should be working today
        $empSql = "SELECT u.id, u.firstName, u.lastName 
                   FROM useraccounts u 
                   INNER JOIN employeelist e ON u.id = e.emp_id 
                   WHERE u.role = 'employee' 
                   AND u.status = 'active'
                   AND u.id NOT IN (
                       SELECT DISTINCT emp_id 
                       FROM attendancelist 
                       WHERE date = :today
                   )";
        $empStmt = $pdo->prepare($empSql);
        $empStmt->bindParam(':today', $today);
        $empStmt->execute();
        $absentEmployees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $absentCount = 0;
        
        foreach ($absentEmployees as $employee) {
            // Mark employee as absent
            $insertSql = "INSERT INTO attendancelist (emp_id, firstName, lastName, date, status, present, absent, late, late_minutes, total_workhours, overtime, undertime_minutes, late_undertime, regular_holiday, regular_holiday_ot, special_holiday, special_holiday_ot, is_holiday, holiday_type) 
                         VALUES (:emp_id, :firstName, :lastName, :date, 'Absent', 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL)";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->bindParam(':emp_id', $employee['id'], PDO::PARAM_INT);
            $insertStmt->bindParam(':firstName', $employee['firstName']);
            $insertStmt->bindParam(':lastName', $employee['lastName']);
            $insertStmt->bindParam(':date', $today);
            $insertStmt->execute();
            
            $absentCount++;
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Processed attendance for $today: $absentCount employees marked as absent",
            'absent_count' => $absentCount,
            'processed_at' => $currentTime->format('Y-m-d H:i:s')
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateSessionData($pdo, $recordId, $timeIn, $timeOut, $sessionNumber) {
    try {
        // Get existing sessions data
        $sql = "SELECT sessions_data, accumulated_hours FROM attendancelist WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sessionsData = $record['sessions_data'] ? json_decode($record['sessions_data'], true) : [];
        $accumulatedHours = $record['accumulated_hours'] ?: 0;
        
        // Add new session
        $newSession = [
            'session' => $sessionNumber,
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'duration_minutes' => 0
        ];
        
        if ($timeOut) {
            $timeInObj = new DateTime($timeIn, new DateTimeZone('Asia/Manila'));
            $timeOutObj = new DateTime($timeOut, new DateTimeZone('Asia/Manila'));
            $duration = $timeOutObj->diff($timeInObj);
            $newSession['duration_minutes'] = ($duration->h * 60) + $duration->i;
            $accumulatedHours += $newSession['duration_minutes'];
        }
        
        $sessionsData[] = $newSession;
        
        // Update database
        $updateSql = "UPDATE attendancelist SET 
                      sessions_data = :sessions_data,
                      accumulated_hours = :accumulated_hours,
                      session_count = :session_count
                      WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':sessions_data', json_encode($sessionsData));
        $updateStmt->bindParam(':accumulated_hours', $accumulatedHours, PDO::PARAM_INT);
        $updateStmt->bindParam(':session_count', count($sessionsData), PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $recordId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        return true;
    } catch(PDOException $e) {
        error_log('Error updating session data: ' . $e->getMessage());
        return false;
    }
}

function getTestTimeFromDB() {
    global $pdo;
    try {
        $sql = "SELECT test_mode, test_time, test_date FROM test_system ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error getting test time: " . $e->getMessage());
        return null;
    }
}

function getTestSettings($pdo) {
    try {
        $sql = "SELECT * FROM test_system ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo json_encode(['success' => true, 'data' => $result]);
        } else {
            echo json_encode(['success' => true, 'data' => null]);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateTestSettings($pdo, $input) {
    try {
        $testMode = $input['test_mode'] ? 1 : 0;
        $testTime = $input['test_time'] ?? null;
        $testDate = $input['test_date'] ?? null;
        
        // Get current real time for tracking
        $realDateTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $realDate = $realDateTime->format('Y-m-d');
        $realTime = $realDateTime->format('H:i:s');
        
        // Check if record exists
        $checkSql = "SELECT id FROM test_system ORDER BY id DESC LIMIT 1";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute();
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            $sql = "UPDATE test_system SET 
                    test_mode = :test_mode, 
                    test_time = :test_time, 
                    test_date = :test_date,
                    real_date = :real_date,
                    real_time = :real_time,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $existing['id'], PDO::PARAM_INT);
        } else {
            // Insert new record
            $sql = "INSERT INTO test_system (test_mode, test_time, test_date, real_date, real_time) 
                    VALUES (:test_mode, :test_time, :test_date, :real_date, :real_time)";
            $stmt = $pdo->prepare($sql);
        }
        
        $stmt->bindParam(':test_mode', $testMode, PDO::PARAM_INT);
        $stmt->bindParam(':test_time', $testTime);
        $stmt->bindParam(':test_date', $testDate);
        $stmt->bindParam(':real_date', $realDate);
        $stmt->bindParam(':real_time', $realTime);
        $stmt->execute();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Test settings updated successfully',
            'data' => [
                'test_mode' => $testMode,
                'test_time' => $testTime,
                'test_date' => $testDate,
                'real_date' => $realDate,
                'real_time' => $realTime
            ]
        ]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

// Debug functions
function debugInfo($pdo) {
    $currentTime = getCurrentTimeUTC8();
    $today = $currentTime->format('Y-m-d');
    
    echo json_encode([
        'success' => true,
        'debug' => [
            'current_utc8_time' => $currentTime->format('Y-m-d H:i:s'),
            'current_date' => $today,
            'is_weekday' => isWeekday($today),
            'current_hour' => (int)$currentTime->format('H'),
            'php_timezone' => date_default_timezone_get(),
            'server_time' => date('Y-m-d H:i:s')
        ]
    ]);
}

function debugTimezoneInfo($pdo) {
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $berlinTime = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    
    echo json_encode([
        'success' => true,
        'timezone_debug' => [
            'php_default_timezone' => date_default_timezone_get(),
            'manila_time' => $now->format('Y-m-d H:i:s T'),
            'berlin_time' => $berlinTime->format('Y-m-d H:i:s T'),
            'current_date_manila' => $now->format('Y-m-d'),
            'is_weekday_manila' => isWeekday($now->format('Y-m-d')),
            'current_hour_manila' => (int)$now->format('H')
        ]
    ]);
}

// Check if employee has approved OT for today and update auto_time_out
function checkAndUpdateAutoTimeOut($pdo, $emp_id, $date) {
    try {
        $otSql = "SELECT end_time FROM overtime_requests 
                  WHERE emp_id = :emp_id 
                  AND date_filed = :date 
                  AND status = 'Approved' 
                  ORDER BY created_at DESC LIMIT 1";
        $otStmt = $pdo->prepare($otSql);
        $otStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $otStmt->bindParam(':date', $date);
        $otStmt->execute();
        $otResult = $otStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($otResult) {
            $updateSql = "UPDATE attendancelist 
                         SET auto_time_out = :end_time 
                         WHERE emp_id = :emp_id 
                         AND date = :date 
                         AND time_in IS NOT NULL 
                         AND time_out IS NULL";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':end_time', $otResult['end_time']);
            $updateStmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
            $updateStmt->bindParam(':date', $date);
            $updateStmt->execute();
            
            return $otResult['end_time'];
        }
        
        return '17:00:00'; // Default 5:00 PM
    } catch(PDOException $e) {
        error_log("Error checking OT approval: " . $e->getMessage());
        return '17:00:00';
    }
}

?>