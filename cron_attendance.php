<?php
// Set the correct timezone
date_default_timezone_set('Asia/Manila');

require_once 'db_connection.php';

// Convert mysqli connection to PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->close();
} catch(PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    exit;
}

function getTestTimeFromDB($pdo) {
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

function getCurrentTimeUTC8($pdo) {
    // Check if test mode is active from database
    $testSettings = getTestTimeFromDB($pdo);
    
    if ($testSettings && $testSettings['test_mode'] == 1) {
        // Use test time
        $testDateTime = new DateTime($testSettings['test_date'] . ' ' . $testSettings['test_time'], new DateTimeZone('Asia/Manila'));
        error_log("🧪 CRON TEST MODE ACTIVE - Using: " . $testDateTime->format('Y-m-d H:i:s'));
        return $testDateTime;
    } else {
        // Use real time
        $manila = new DateTimeZone('Asia/Manila');
        return new DateTime('now', $manila);
    }
}

// Helper function to check if date is employee's rest day
function isRestDay($date, $emp_id, $pdo) {
    try {
        $sql = "SELECT rest_day FROM employeelist WHERE emp_id = :emp_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':emp_id', $emp_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['rest_day']) return false;
        
        $restDay = $result['rest_day'];
        $dateObj = new DateTime($date . ' 00:00:00', new DateTimeZone('Asia/Manila'));
        $dayOfWeek = $dateObj->format('l');
        
        // Handle range formats like "Saturday-Sunday"
        if (strpos($restDay, '-') !== false) {
            $days = explode('-', $restDay);
            $startDay = trim($days[0]);
            $endDay = trim($days[1]);
            
            return strtolower($dayOfWeek) === strtolower($startDay) || 
                   strtolower($dayOfWeek) === strtolower($endDay);
        }
        
        // Handle single day format
        return strtolower($dayOfWeek) === strtolower($restDay);
    } catch(PDOException $e) {
        error_log("Error getting employee rest day: " . $e->getMessage());
        return false;
    }
}

function processAbsentEmployees($pdo) {
    try {
        $currentTime = getCurrentTimeUTC8($pdo);
        $today = $currentTime->format('Y-m-d');
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        
        // Only process on weekdays at 6:00 AM
        $dayOfWeek = (int)$currentTime->format('N');
        if ($dayOfWeek < 1 || $dayOfWeek > 5) {
            error_log("Skipping absent processing - Weekend day");
            return;
        }

        // Get all active employees
        $empSql = "SELECT u.id, u.firstName, u.lastName 
                   FROM useraccounts u 
                   INNER JOIN employeelist e ON u.id = e.emp_id 
                   WHERE u.role = 'employee' 
                   AND u.status = 'active'";
        $empStmt = $pdo->prepare($empSql);
        $empStmt->execute();
        $allEmployees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $absentCount = 0;
        
        foreach ($allEmployees as $employee) {
            $empId = $employee['id'];
            
            // Check if employee worked yesterday (day shift) or yesterday night (night shift)
            $workedYesterday = false;
            
            // Check day shift attendance for yesterday
            $dayShiftSql = "SELECT id FROM attendancelist 
                           WHERE emp_id = :emp_id 
                           AND date = :yesterday 
                           AND time_in IS NOT NULL";
            $dayShiftStmt = $pdo->prepare($dayShiftSql);
            $dayShiftStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
            $dayShiftStmt->bindParam(':yesterday', $yesterday);
            $dayShiftStmt->execute();
            
            if ($dayShiftStmt->fetch()) {
                $workedYesterday = true;
            }
            
            // Check night shift attendance for yesterday (would be working into today)
            if (!$workedYesterday) {
                $nightShiftSql = "SELECT id FROM attendancelist 
                                 WHERE emp_id = :emp_id 
                                 AND date = :yesterday 
                                 AND shift_type = 'night' 
                                 AND time_in IS NOT NULL";
                $nightShiftStmt = $pdo->prepare($nightShiftSql);
                $nightShiftStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                $nightShiftStmt->bindParam(':yesterday', $yesterday);
                $nightShiftStmt->execute();
                
                if ($nightShiftStmt->fetch()) {
                    $workedYesterday = true;
                }
            }
            
            // If employee didn't work either shift yesterday, mark as absent
            if (!$workedYesterday) {
                // Check if absent record already exists to avoid duplicates
                $existingAbsentSql = "SELECT id FROM attendancelist 
                                     WHERE emp_id = :emp_id 
                                     AND date = :yesterday 
                                     AND status = 'Absent'";
                $existingAbsentStmt = $pdo->prepare($existingAbsentSql);
                $existingAbsentStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                $existingAbsentStmt->bindParam(':yesterday', $yesterday);
                $existingAbsentStmt->execute();
                
                if (!$existingAbsentStmt->fetch()) {
                    // Mark employee as absent for yesterday
                    $insertSql = "INSERT INTO attendancelist (emp_id, firstName, lastName, date, status, present, absent, late, late_minutes, total_workhours, overtime, undertime_minutes, late_undertime, regular_holiday, regular_holiday_ot, special_holiday, special_holiday_ot, is_holiday, holiday_type) 
                                 VALUES (:emp_id, :firstName, :lastName, :date, 'Absent', 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL)";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                    $insertStmt->bindParam(':firstName', $employee['firstName']);
                    $insertStmt->bindParam(':lastName', $employee['lastName']);
                    $insertStmt->bindParam(':date', $yesterday);
                    $insertStmt->execute();
                    
                    $absentCount++;
                }
            }
        }
        
        if ($absentCount > 0) {
            error_log("Auto process absent completed for $yesterday: $absentCount employees marked as absent");
        } else {
            error_log("Auto process absent completed for $yesterday: No absent employees to process");
        }
        
    } catch(PDOException $e) {
        error_log('Auto process absent employees error: ' . $e->getMessage());
    }
}

function autoTimeOutEmployees($pdo, $currentHour, $currentMinute) {
    try {
        $currentTime = getCurrentTimeUTC8($pdo);
        $today = $currentTime->format('Y-m-d');
        $currentTimeStr = $currentTime->format('H:i:s');
        $currentHour = (int)$currentTime->format('H');
        $currentMinute = (int)$currentTime->format('i');
        
        // Only process on weekdays
        $dayOfWeek = (int)$currentTime->format('N');
        if ($dayOfWeek < 1 || $dayOfWeek > 5) {
            error_log("Skipping auto timeout - Weekend day");
            return;
        }

        $autoTimeOutCount = 0;

        // DYNAMIC AUTO TIMEOUT FOR DAY SHIFT - Check every minute for matching auto_time_out
        if ($currentHour >= 17 && $currentHour <= 23) {
            error_log("Checking for DAY SHIFT dynamic auto timeout at " . $currentTimeStr);
            
            // Find employees with auto_time_out matching current time (within 1 minute window)
            $findDayShiftSql = "SELECT id, emp_id, firstName, lastName, time_in, last_time_in, late_minutes, shift_type, accumulated_hours, session_data, date, auto_time_out
                            FROM attendancelist 
                            WHERE date = :today 
                            AND time_in IS NOT NULL 
                            AND time_out IS NULL
                            AND (shift_type = 'day' OR shift_type IS NULL)
                            AND auto_time_out IS NOT NULL";
            
            $findDayShiftStmt = $pdo->prepare($findDayShiftSql);
            $findDayShiftStmt->bindParam(':today', $today);
            $findDayShiftStmt->execute();
            $allDayShiftRecords = $findDayShiftStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter records by time in PHP (to work with test mode)
            $dayShiftRecords = [];
            
            // Compare only hours and minutes (ignore seconds for test mode compatibility)
            $currentHourMinute = substr($currentTimeStr, 0, 5); // "19:00" from "19:00:00"
            
            error_log("Filtering records: current_time=$currentHourMinute (from $currentTimeStr)");
            
            foreach ($allDayShiftRecords as $record) {
                $autoTimeOutFull = date('H:i:s', strtotime($record['auto_time_out']));
                $autoTimeOutHourMinute = substr($autoTimeOutFull, 0, 5); // "19:00" from "19:00:00"
                
                error_log("Checking employee ID " . $record['emp_id'] . ": auto_time_out=$autoTimeOutHourMinute (from $autoTimeOutFull)");
                
                // Match if hour:minute are equal (ignore seconds)
                if ($autoTimeOutHourMinute === $currentHourMinute) {
                    $dayShiftRecords[] = $record;
                    error_log("✓ EXACT MATCH found for employee ID " . $record['emp_id'] . " (current: $currentHourMinute = auto_timeout: $autoTimeOutHourMinute)");
                }
            }
            
            error_log("Found " . count($dayShiftRecords) . " employees to auto-timeout");

            foreach ($dayShiftRecords as $record) {
                $autoTimeOutTime = $record['auto_time_out'];
                error_log("✅ Auto timing out day shift employee ID: " . $record['emp_id'] . " at " . $autoTimeOutTime . " (Approved OT end time)");
                
                // Calculate current session minutes (same as main API)
                $sessionTimeIn = $record['last_time_in'] ?? $record['time_in'];
                $timeInObj = new DateTime($today . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
                $timeOutObj = new DateTime($today . ' ' . $autoTimeOutTime, new DateTimeZone('Asia/Manila'));

                $sessionInterval = $timeInObj->diff($timeOutObj);
                $currentSessionMinutes = ($sessionInterval->h * 60) + $sessionInterval->i;

                // Apply lunch deduction for day shift
                $shiftType = $record['shift_type'] ?? detectShiftType($record['time_in']);
                $adjustedSessionMinutes = $currentSessionMinutes;

                if ($shiftType === 'day') {
                    $sessionStart = new DateTime($today . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
                    $sessionEnd = new DateTime($today . ' ' . $autoTimeOutTime, new DateTimeZone('Asia/Manila'));
                    $lunchStart = new DateTime($today . ' 12:00:00', new DateTimeZone('Asia/Manila'));
                    $lunchEnd = new DateTime($today . ' 13:00:00', new DateTimeZone('Asia/Manila'));
                    
                    // If session crosses full lunch period, subtract 60 minutes
                    if ($sessionStart <= $lunchStart && $sessionEnd > $lunchEnd) {
                        $adjustedSessionMinutes = max(0, $currentSessionMinutes - 60);
                        error_log("CRON: Lunch deduction. Before: $currentSessionMinutes, After: $adjustedSessionMinutes");
                    }
                }

                // Add adjusted minutes to accumulated time
                $accumulatedMinutes = $record['accumulated_hours'] ?? 0;

                // Update session data with adjusted minutes
                $sessionData = $record['session_data'] ? json_decode($record['session_data'], true) : [];
                $sessionType = count($sessionData) + 1;
                $sessionData[] = [
                    'session' => $sessionType,
                    'date' => $today,
                    'time_in' => $sessionTimeIn,
                    'time_out' => $autoTimeOutTime,
                    'duration_minutes' => $currentSessionMinutes,
                    'adjusted_minutes' => $adjustedSessionMinutes
                ];

                $totalWorkMinutes = $accumulatedMinutes + $adjustedSessionMinutes;

                // === NEW: Use same logic as main API for day shift ===
                $totalMinutes = 0;
                $overtimeMinutes = 0;

                if ($shiftType === 'day') {
                    // Calculate total from ALL sessions
                    $totalRegularMinutes = 0;
                    $totalOvertimeMinutes = 0;
                    
                    foreach ($sessionData as $session) {
                        $sessionInParts = explode(':', $session['time_in']);
                        $sessionOutParts = explode(':', $session['time_out']);
                        
                        $sessionInMinutes = ((int)$sessionInParts[0] * 60) + (int)$sessionInParts[1];
                        $sessionOutMinutes = ((int)$sessionOutParts[0] * 60) + (int)$sessionOutParts[1];
                        
                        $workEndMinutes = 17 * 60; // 5:00 PM
                        
                        // Regular time: before 5 PM
                        if ($sessionOutMinutes <= $workEndMinutes) {
                            $totalRegularMinutes += $session['adjusted_minutes'];
                        } else if ($sessionInMinutes < $workEndMinutes) {
                            // Session spans regular and overtime
                            $regularPortion = $workEndMinutes - $sessionInMinutes;
                            $overtimePortion = $sessionOutMinutes - $workEndMinutes;
                            
                            // Apply lunch deduction if needed for regular portion
                            if ($sessionInMinutes < 780 && $workEndMinutes > 780) { // 780 = 1 PM
                                $regularPortion -= 60;
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
                        $gapToFill = 480 - $totalRegularMinutes;
                        $fillAmount = min($gapToFill, $totalOvertimeMinutes);
                        
                        $totalMinutes = $totalRegularMinutes + $fillAmount;
                        $remainingOvertimeMinutes = $totalOvertimeMinutes - $fillAmount;
                        
                        if ($remainingOvertimeMinutes >= 60) {
                            $overtimeHours = floor($remainingOvertimeMinutes / 60);
                            $overtimeMinutes = $overtimeHours * 60;
                        } else {
                            $overtimeMinutes = 0;
                        }
                    } else {
                        $totalMinutes = min($totalRegularMinutes, 480);
                        
                        if ($totalOvertimeMinutes >= 60) {
                            $overtimeHours = floor($totalOvertimeMinutes / 60);
                            $overtimeMinutes = $overtimeHours * 60;
                        } else {
                            $overtimeMinutes = 0;
                        }
                    }
                } else {
                    // Night shift - keep existing logic
                    $totalMinutes = $totalWorkMinutes;
                    $overtimeMinutes = 0;
                }

                // Get holiday and rest day info
                $holidayInfo = checkHolidayForDate($pdo, $today);
                $isHoliday = $holidayInfo['is_holiday'];
                $holidayType = $holidayInfo['holiday_type'];
                $isRestDay = isRestDay($today, $record['emp_id'], $pdo);

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
                $regularOTNDMinutes = 0;
                $regularHolidayNDMinutes = 0;
                $specialHolidayNDMinutes = 0;

                // Allocate hours based on shift, holiday, and rest day status
                if ($shiftType === 'night') {
                    // Night shift - all time is night differential
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
                } else {
                    // Day shift allocation
                    if ($isRestDay) {
                        if ($isHoliday) {
                            if ($holidayType === 'Regular') {
                                $regularHolidayROTMinutes = $totalMinutes;
                                $regularHolidayROTOTMinutes = $overtimeMinutes;
                            } else {
                                $specialHolidayROTMinutes = $totalMinutes;
                                $specialHolidayROTOTMinutes = $overtimeMinutes;
                            }
                        } else {
                            $restDayMinutes = $totalMinutes;
                            $restDayOTMinutes = $overtimeMinutes;
                        }
                    } else {
                        if ($isHoliday) {
                            if ($holidayType === 'Regular') {
                                $regularHolidayMinutes = $totalMinutes;
                                $regularHolidayOTMinutes = $overtimeMinutes;
                            } else {
                                $specialHolidayMinutes = $totalMinutes;
                                $specialHolidayOTMinutes = $overtimeMinutes;
                            }
                        }
                    }
                }

                // Calculate undertime and late totals
                $undertimeMinutes = calculateUndertimeMinutes($autoTimeOutTime);
                $lateUndertimeTotal = $record['late_minutes'] + $undertimeMinutes;
                
                // Determine status
                $status = 'Present (Auto)';
                if ($isHoliday) {
                    if ($overtimeMinutes > 0 || $regularHolidayOTMinutes > 0 || $specialHolidayOTMinutes > 0) {
                        $status = $holidayType . ' Holiday + OT (Auto)';
                    } else {
                        $status = $holidayType . ' Holiday (Auto)';
                    }
                } elseif ($overtimeMinutes > 0) {
                    $status = 'Overtime (Auto)';
                }
                
                // Regular OT (non-holiday overtime)
                $regularOtMinutes = (!$isHoliday && $overtimeMinutes > 0) ? $overtimeMinutes : 0;
                
                // Update record with all data
                $updateSql = "UPDATE attendancelist SET 
                              time_out = :time_out, 
                              total_workhours = :total_hours, 
                              session_data = :session_data,
                              accumulated_hours = :accumulated_hours,
                              overtime = :overtime,
                              regular_ot = :regular_ot,
                              regular_ot_nd = :regular_ot_nd,
                              regular_holiday = :regular_holiday,
                              regular_holiday_ot = :regular_holiday_ot,
                              regular_holiday_nd = :regular_holiday_nd,
                              special_holiday = :special_holiday,
                              special_holiday_ot = :special_holiday_ot,
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
                $updateStmt->bindParam(':time_out', $autoTimeOutTime);
                $updateStmt->bindParam(':total_hours', $totalMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':session_data', json_encode($sessionData));
                $updateStmt->bindParam(':accumulated_hours', $totalWorkMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':overtime', $overtimeMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_ot', $regularOtMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_ot_nd', $regularOTNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday', $regularHolidayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_ot', $regularHolidayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_nd', $regularHolidayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday', $specialHolidayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_ot', $specialHolidayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_nd', $specialHolidayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_ot', $restDayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_ot_plus_ot', $restDayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_nd', $restDayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_rot', $regularHolidayROTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_rot_ot', $regularHolidayROTOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot', $specialHolidayROTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot_ot', $specialHolidayROTOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot_nd', $specialHolidayROTNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':undertime_minutes', $undertimeMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':late_undertime', $lateUndertimeTotal, PDO::PARAM_INT);
                $isHolidayFlag = $isHoliday ? 1 : 0;
                $isRestDayFlag = $isRestDay ? 1 : 0;
                $updateStmt->bindParam(':is_holiday', $isHolidayFlag, PDO::PARAM_INT);
                $updateStmt->bindParam(':holiday_type', $holidayType);
                $updateStmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
                $updateStmt->bindParam(':status', $status);
                $updateStmt->bindParam(':id', $record['id'], PDO::PARAM_INT);
                $updateStmt->execute();
                
                $autoTimeOutCount++;
            }

            if ($autoTimeOutCount > 0) {
                error_log("✅ Day shift dynamic auto timeout completed: $autoTimeOutCount employees timed out at " . $currentTimeStr);
            }
        }

        // 8:00 AM - Auto timeout NIGHT SHIFT employees from previous day
        if ($currentHour == 8 && $currentMinute == 0) {
            error_log("Processing NIGHT SHIFT auto timeout at 8:00 AM");
            
            $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
            
            $findNightShiftSql = "SELECT id, emp_id, firstName, lastName, time_in, last_time_in, late_minutes, shift_type, accumulated_hours, session_data, date
                                 FROM attendancelist 
                                 WHERE date = :yesterday 
                                 AND time_in IS NOT NULL 
                                 AND time_out IS NULL
                                 AND shift_type = 'night'";
            $findNightShiftStmt = $pdo->prepare($findNightShiftSql);
            $findNightShiftStmt->bindParam(':yesterday', $yesterday);
            $findNightShiftStmt->execute();
            $nightShiftRecords = $findNightShiftStmt->fetchAll(PDO::FETCH_ASSOC);

            $autoTimeOutTime = '08:00:00'; // 8:00 AM

            foreach ($nightShiftRecords as $record) {
                error_log("Auto timing out night shift employee ID: " . $record['emp_id'] . " at 8:00 AM");
                
                // Calculate night shift session time (yesterday to today)
                $sessionTimeIn = $record['last_time_in'] ?? $record['time_in'];
                $timeInObj = new DateTime($yesterday . ' ' . $sessionTimeIn, new DateTimeZone('Asia/Manila'));
                $timeOutObj = new DateTime($today . ' ' . $autoTimeOutTime, new DateTimeZone('Asia/Manila'));

                $sessionInterval = $timeInObj->diff($timeOutObj);
                $currentSessionMinutes = ($sessionInterval->days * 24 * 60) + ($sessionInterval->h * 60) + $sessionInterval->i;

                // Handle early arrival for night shift
                $timeInHour = (int)date('H', strtotime($sessionTimeIn));
                $isEarlyArrival = ($timeInHour >= 18 && $timeInHour < 22);
                $adjustedSessionMinutes = $currentSessionMinutes;

                if ($isEarlyArrival) {
                    // Calculate only work time from 10:00 PM onwards
                    $effectiveTimeIn = new DateTime($yesterday . ' 22:00:00', new DateTimeZone('Asia/Manila'));
                    $adjustedInterval = $effectiveTimeIn->diff($timeOutObj);
                    $adjustedSessionMinutes = ($adjustedInterval->days * 24 * 60) + ($adjustedInterval->h * 60) + $adjustedInterval->i;
                }

                // Add adjusted minutes to accumulated time
                $accumulatedMinutes = $record['accumulated_hours'] ?? 0;
                
                // Update session data
                $sessionData = $record['session_data'] ? json_decode($record['session_data'], true) : [];
                $sessionType = count($sessionData) + 1;
                $sessionData[] = [
                    'session' => $sessionType,
                    'date' => $yesterday,
                    'time_in' => $sessionTimeIn,
                    'time_out' => $autoTimeOutTime,
                    'duration_minutes' => $currentSessionMinutes,
                    'adjusted_minutes' => $adjustedSessionMinutes
                ];

                $totalWorkMinutes = $accumulatedMinutes + $adjustedSessionMinutes;

                // Night shift - all time is night differential, no overtime
                $totalMinutes = $totalWorkMinutes;
                $overtimeMinutes = 0;

                // Get holiday and rest day info
                $holidayInfo = checkHolidayForDate($pdo, $yesterday);
                $isHoliday = $holidayInfo['is_holiday'];
                $holidayType = $holidayInfo['holiday_type'];
                $isRestDay = isRestDay($yesterday, $record['emp_id'], $pdo);

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
                $regularOTNDMinutes = 0;
                $regularHolidayNDMinutes = 0;
                $specialHolidayNDMinutes = 0;

                // Night shift - all time is night differential
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

                // Calculate undertime for night shift (ends at 6:00 AM, so 8:00 AM is 2 hours OT)
                $undertimeMinutes = 0; // No undertime at 8:00 AM for night shift
                $lateUndertimeTotal = $record['late_minutes'] + $undertimeMinutes;
                
                // Determine status
                $status = 'Night Shift + OT (Auto)'; // Always overtime at 8:00 AM
                if ($isHoliday) {
                    $status = $holidayType . ' Holiday + OT (Auto)';
                }
                
                // Regular OT (non-holiday overtime)
                $regularOtMinutes = (!$isHoliday && $overtimeMinutes > 0) ? $overtimeMinutes : 0;
                
                // Update record
                $updateSql = "UPDATE attendancelist SET 
                              time_out = :time_out, 
                              total_workhours = :total_hours, 
                              session_data = :session_data,
                              accumulated_hours = :accumulated_hours,
                              overtime = :overtime,
                              regular_ot = :regular_ot,
                              regular_ot_nd = :regular_ot_nd,
                              regular_holiday = :regular_holiday,
                              regular_holiday_ot = :regular_holiday_ot,
                              regular_holiday_nd = :regular_holiday_nd,
                              special_holiday = :special_holiday,
                              special_holiday_ot = :special_holiday_ot,
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
                $updateStmt->bindParam(':time_out', $autoTimeOutTime);
                $updateStmt->bindParam(':total_hours', $totalMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':session_data', json_encode($sessionData));
                $updateStmt->bindParam(':accumulated_hours', $totalWorkMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':overtime', $overtimeMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_ot', $regularOtMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_ot_nd', $regularOTNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday', $regularHolidayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_ot', $regularHolidayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_nd', $regularHolidayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday', $specialHolidayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_ot', $specialHolidayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_nd', $specialHolidayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_ot', $restDayMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_ot_plus_ot', $restDayOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':rest_day_nd', $restDayNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_rot', $regularHolidayROTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':regular_holiday_rot_ot', $regularHolidayROTOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot', $specialHolidayROTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot_ot', $specialHolidayROTOTMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':special_holiday_rot_nd', $specialHolidayROTNDMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':undertime_minutes', $undertimeMinutes, PDO::PARAM_INT);
                $updateStmt->bindParam(':late_undertime', $lateUndertimeTotal, PDO::PARAM_INT);
                $isHolidayFlag = $isHoliday ? 1 : 0;
                $isRestDayFlag = $isRestDay ? 1 : 0;
                $updateStmt->bindParam(':is_holiday', $isHolidayFlag, PDO::PARAM_INT);
                $updateStmt->bindParam(':holiday_type', $holidayType);
                $updateStmt->bindParam(':is_rest_day', $isRestDayFlag, PDO::PARAM_INT);
                $updateStmt->bindParam(':status', $status);
                $updateStmt->bindParam(':id', $record['id'], PDO::PARAM_INT);
                $updateStmt->execute();
                
                $autoTimeOutCount++;
            }

            if ($autoTimeOutCount > 0) {
                error_log("Night shift auto timeout completed: $autoTimeOutCount employees timed out at 8:00 AM");
            }
        }
        
    } catch(PDOException $e) {
        error_log('Auto timeout error: ' . $e->getMessage());
    }
}

function calculateUndertimeMinutes($timeOut) {
    if (!$timeOut) return 0;
    
    $timeOutObj = new DateTime($timeOut, new DateTimeZone('Asia/Manila'));
    
    // Detect if this is day or night shift based on time out
    $hour = (int)$timeOutObj->format('H');
    
    if ($hour >= 6 && $hour <= 23) {
        // Day shift logic
        $workEndTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila')); // 5:00 PM
        
        if ($timeOutObj >= $workEndTime) {
            return 0;
        }
        
        $diffMs = $workEndTime->getTimestamp() - $timeOutObj->getTimestamp();
        return max(0, floor($diffMs / 60));
    } else {
        // Night shift logic
        $workEndTime = new DateTime('06:00:00', new DateTimeZone('Asia/Manila')); // 6:00 AM
        
        if ($timeOutObj >= $workEndTime) {
            return 0;
        }
        
        $diffMs = $workEndTime->getTimestamp() - $timeOutObj->getTimestamp();
        return max(0, floor($diffMs / 60));
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
    
    return $shiftType;
}

function manageLunchBreaksAutomatically($pdo) {
    try {
        $currentTime = getCurrentTimeUTC8($pdo);
        $today = $currentTime->format('Y-m-d');
        $currentHour = (int)$currentTime->format('H');
        $currentMinute = (int)$currentTime->format('i');
        $currentTimeStr = $currentTime->format('H:i:s');
        
        // Only process on weekdays
        $dayOfWeek = (int)$currentTime->format('N');
        if ($dayOfWeek < 1 || $dayOfWeek > 5) {
            return;
        }
        
        // START LUNCH BREAK - Exactly at 12:00 PM
        if ($currentHour == 12 && $currentMinute == 0) {
            error_log("Starting automatic lunch breaks at 12:00 PM for $today");
            
            // Find all employees who are currently timed in and don't have break start time set today
            $startBreakSql = "UPDATE attendancelist 
                              SET break_start_time = '12:00:00'
                              WHERE date = :today 
                              AND time_in IS NOT NULL 
                              AND time_out IS NULL
                              AND (break_start_time IS NULL OR break_start_time = '')";
            
            $startBreakStmt = $pdo->prepare($startBreakSql);
            $startBreakStmt->bindParam(':today', $today);
            $startBreakStmt->execute();
            
            $affectedRows = $startBreakStmt->rowCount();
            if ($affectedRows > 0) {
                error_log("Started lunch break for $affectedRows employees at 12:00 PM");
            } else {
                error_log("No employees to start lunch break for (already have break_start_time or not timed in)");
            }
        }
        
        // END LUNCH BREAK - Exactly at 1:00 PM
        if ($currentHour == 13 && $currentMinute == 0) {
            error_log("Ending automatic lunch breaks at 1:00 PM for $today");
            
            // Find employees who have break_start_time but no break_end_time
            $endBreakSql = "UPDATE attendancelist 
                            SET break_end_time = '13:00:00',
                                break_duration = 60,
                                break_time = 60
                            WHERE date = :today 
                            AND time_in IS NOT NULL 
                            AND time_out IS NULL
                            AND break_start_time IS NOT NULL
                            AND break_start_time != ''
                            AND (break_end_time IS NULL OR break_end_time = '')";
            
            $endBreakStmt = $pdo->prepare($endBreakSql);
            $endBreakStmt->bindParam(':today', $today);
            $endBreakStmt->execute();
            
            $affectedRows = $endBreakStmt->rowCount();
            if ($affectedRows > 0) {
                error_log("Ended lunch break for $affectedRows employees at 1:00 PM");
            } else {
                error_log("No employees to end lunch break for (no active breaks or already ended)");
            }
        }
        
    } catch(PDOException $e) {
        error_log('Error managing lunch breaks: ' . $e->getMessage());
    }
}

// Get current time
$currentTime = getCurrentTimeUTC8($pdo);
$currentHour = (int)$currentTime->format('H');
$currentMinute = (int)$currentTime->format('i');
$today = $currentTime->format('Y-m-d');

// Log if test mode is active
$testSettings = getTestTimeFromDB($pdo);
if ($testSettings && $testSettings['test_mode'] == 1) {
    error_log("🧪 CRON TEST MODE: " . $currentTime->format('Y-m-d H:i:s') . " (Real time: " . date('Y-m-d H:i:s') . ")");
}

error_log("Cron job running at " . $currentTime->format('Y-m-d H:i:s') . " (UTC+8)");

// CRITICAL: Manage lunch breaks every minute during lunch hours (11:59 to 13:01)
if ($currentHour >= 11 && $currentHour <= 13) {
    error_log("Checking lunch break management at " . $currentTime->format('H:i'));
    manageLunchBreaksAutomatically($pdo);
}

// Process absent employees at 6:00 AM (after both shifts have ended)
if ($currentHour == 6 && $currentMinute == 0) {
    error_log("Running absent employee processing at 6:00 AM");
    processAbsentEmployees($pdo);
}

// Auto timeout employees - runs every minute from 5 PM to 11 PM for dynamic OT checking
if ($currentHour >= 17 && $currentHour <= 23) {
    error_log("Running day shift dynamic auto timeout check at " . $currentTime->format('H:i'));
    autoTimeOutEmployees($pdo, $currentHour, $currentMinute);
}

// Auto timeout at 8:00 AM for night shift
if ($currentHour == 8 && $currentMinute == 0) {
    error_log("Running night shift auto timeout processing at 8:00 AM");
    autoTimeOutEmployees($pdo, $currentHour, $currentMinute);
}

error_log("Cron job completed at " . $currentTime->format('Y-m-d H:i:s'));

?>