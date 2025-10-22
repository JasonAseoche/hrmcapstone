<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db_connection.php';

// Simple file storage
$pending_file = 'pending_registrations.json';
$log_file = 'debug.log';

function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

function loadJSON($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return $data ?: [];
    }
    return [];
}

function saveJSON($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
        
        if ($action === 'fetch_accounts') {
            // Fetch user accounts from database
            $query = "SELECT id, firstName, lastName, email, fingerprint_uid, fingerprint_status FROM useraccounts ORDER BY firstName";
            $result = mysqli_query($conn, $query);
            
            if (!$result) {
                throw new Exception('Database query failed: ' . mysqli_error($conn));
            }
            
            $accounts = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $accounts[] = [
                    'id' => $row['id'],
                    'firstName' => $row['firstName'],
                    'lastName' => $row['lastName'],
                    'email' => $row['email'],
                    'fingerprint_uid' => $row['fingerprint_uid'],
                    'fingerprint_status' => $row['fingerprint_status'] ?: 'Not Registered'
                ];
            }
            
            writeLog("Fetched " . count($accounts) . " accounts");
            echo json_encode(['success' => true, 'data' => $accounts]);
            
        } elseif ($action === 'check_registration') {
            // Check if registration is complete by looking for matching scan
            $user_id = $_GET['user_id'] ?? '';
            $fingerprint_id = $_GET['fingerprint_id'] ?? '';
            
            writeLog("Checking registration for user_id: $user_id, fingerprint_id: $fingerprint_id");
            
            $pending = loadJSON($pending_file);
            
            // Find the pending registration
            $registration = null;
            foreach ($pending as $key => $p) {
                if ($p['user_id'] == $user_id && $p['fingerprint_id'] == $fingerprint_id) {
                    $registration = $p;
                    $registration['key'] = $key;
                    break;
                }
            }
            
            if (!$registration) {
                echo json_encode(['success' => true, 'status' => 'not_found']);
                exit();
            }
            
            // Check if registration completed (fingerprint_scan_received flag)
            if (isset($registration['completed']) && $registration['completed']) {
                writeLog("Registration already completed for user_id: $user_id");
                echo json_encode(['success' => true, 'status' => 'completed']);
                exit();
            }
            
            // Check if registration failed due to wrong fingerprint
            if (isset($registration['failed']) && $registration['failed']) {
                writeLog("Registration failed for user_id: $user_id - wrong fingerprint scanned");
                // Remove failed registration
                unset($pending[$registration['key']]);
                saveJSON($pending_file, array_values($pending));
                echo json_encode(['success' => true, 'status' => 'failed', 'message' => 'Wrong fingerprint scanned']);
                exit();
            }
            
            // Check if registration has expired (5 minutes)
            if ((time() - strtotime($registration['created_at'])) > 300) {
                writeLog("Registration expired for user_id: $user_id");
                // Remove expired registration
                unset($pending[$registration['key']]);
                saveJSON($pending_file, array_values($pending));
                echo json_encode(['success' => true, 'status' => 'expired']);
                exit();
            }
            
            // Still waiting
            echo json_encode(['success' => true, 'status' => 'waiting']);
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        writeLog("POST request received: " . $input);
        
        if (!$data) {
            throw new Exception('Invalid JSON data');
        }
        
        $action = $data['action'] ?? '';
        
        if ($action === 'start_registration') {
            // Start fingerprint registration
            $user_id = $data['user_id'] ?? '';
            $fingerprint_id = $data['fingerprint_id'] ?? '';
            
            if (empty($user_id) || empty($fingerprint_id)) {
                throw new Exception('User ID and Fingerprint ID are required');
            }
            
            writeLog("Starting registration for user_id: $user_id, fingerprint_id: $fingerprint_id");
            
            // Check if fingerprint ID is already in use in useraccounts
            $check_query = "SELECT id, firstName, lastName FROM useraccounts WHERE fingerprint_uid = ? AND id != ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "si", $fingerprint_id, $user_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                $existing_user = mysqli_fetch_assoc($check_result);
                throw new Exception("Fingerprint ID '{$fingerprint_id}' is already registered to {$existing_user['firstName']} {$existing_user['lastName']}");
            }
            
            // Check if fingerprint ID is already in use in employeelist
            $check_query_emp = "SELECT emp_id, firstName, lastName FROM employeelist WHERE fingerprint_uid = ?";
            $check_stmt_emp = mysqli_prepare($conn, $check_query_emp);
            mysqli_stmt_bind_param($check_stmt_emp, "s", $fingerprint_id);
            mysqli_stmt_execute($check_stmt_emp);
            $check_result_emp = mysqli_stmt_get_result($check_stmt_emp);
            
            if (mysqli_num_rows($check_result_emp) > 0) {
                $existing_emp = mysqli_fetch_assoc($check_result_emp);
                throw new Exception("Fingerprint ID '{$fingerprint_id}' is already registered to {$existing_emp['firstName']} {$existing_emp['lastName']} in employee list");
            }
            
            $pending = loadJSON($pending_file);
            
            // Remove any existing registration for this user
            $pending = array_filter($pending, function($p) use ($user_id) {
                return $p['user_id'] != $user_id;
            });
            
            // Add new registration
            $pending[] = [
                'user_id' => $user_id,
                'fingerprint_id' => $fingerprint_id,
                'created_at' => date('Y-m-d H:i:s'),
                'completed' => false,
                'failed' => false
            ];
            
            saveJSON($pending_file, $pending);
            writeLog("Registration started successfully for user_id: $user_id, fingerprint_id: $fingerprint_id");
            
            echo json_encode(['success' => true, 'message' => 'Registration started']);
            
        } elseif ($action === 'unregister_fingerprint') {
            // Unregister fingerprint
            $user_id = $data['user_id'] ?? '';
            
            if (empty($user_id)) {
                throw new Exception('User ID is required');
            }
            
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Update useraccounts table
                $update_query1 = "UPDATE useraccounts SET fingerprint_uid = NULL, fingerprint_status = 'Not Registered' WHERE id = ?";
                $stmt1 = mysqli_prepare($conn, $update_query1);
                mysqli_stmt_bind_param($stmt1, "i", $user_id);
                
                if (!mysqli_stmt_execute($stmt1)) {
                    throw new Exception('Failed to update useraccounts table: ' . mysqli_error($conn));
                }
                
                // Get user details from useraccounts to match with employeelist
                $user_query = "SELECT firstName, lastName, email FROM useraccounts WHERE id = ?";
                $user_stmt = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($user_stmt, "i", $user_id);
                mysqli_stmt_execute($user_stmt);
                $user_result = mysqli_stmt_get_result($user_stmt);
                $user_data = mysqli_fetch_assoc($user_result);
                
                if (!$user_data) {
                    throw new Exception('User not found in useraccounts table');
                }
                
                // Update employeelist table by matching email or firstName+lastName
                $update_query2 = "UPDATE employeelist SET fingerprint_uid = NULL WHERE email = ? OR (firstName = ? AND lastName = ?)";
                $stmt2 = mysqli_prepare($conn, $update_query2);
                mysqli_stmt_bind_param($stmt2, "sss", $user_data['email'], $user_data['firstName'], $user_data['lastName']);
                
                if (!mysqli_stmt_execute($stmt2)) {
                    throw new Exception('Failed to update employeelist table: ' . mysqli_error($conn));
                }
                
                // Commit transaction
                mysqli_commit($conn);
                
                writeLog("Fingerprint unregistered for user_id: $user_id (both tables updated)");
                echo json_encode(['success' => true, 'message' => 'Fingerprint unregistered successfully']);
                
            } catch (Exception $e) {
                // Rollback transaction on error
                mysqli_rollback($conn);
                throw $e;
            }
            
        } elseif (isset($data['employee_id']) && isset($data['datetime'])) {
            // This is a fingerprint scan from C# application (attendance function)
            $employee_id = trim($data['employee_id']);
            $datetime = trim($data['datetime']);
            
            writeLog("Attendance scan received - employee_id: '$employee_id', datetime: '$datetime'");
            
            $pending = loadJSON($pending_file);
            
            // Look for matching pending registration
            $matched = false;
            foreach ($pending as $key => &$registration) {
                if ($registration['fingerprint_id'] == $employee_id && !$registration['completed'] && !$registration['failed']) {
                    writeLog("MATCH FOUND! Fingerprint ID '$employee_id' matches pending registration for user_id: {$registration['user_id']}");
                    
                    // Start transaction
                    mysqli_begin_transaction($conn);
                    
                    try {
                        // Update useraccounts table
                        $update_query1 = "UPDATE useraccounts SET fingerprint_uid = ?, fingerprint_status = 'Registered' WHERE id = ?";
                        $stmt1 = mysqli_prepare($conn, $update_query1);
                        mysqli_stmt_bind_param($stmt1, "si", $employee_id, $registration['user_id']);
                        
                        if (!mysqli_stmt_execute($stmt1)) {
                            throw new Exception('Failed to update useraccounts table: ' . mysqli_error($conn));
                        }
                        
                        // Get user details from useraccounts to match with employeelist
                        $user_query = "SELECT firstName, lastName, email FROM useraccounts WHERE id = ?";
                        $user_stmt = mysqli_prepare($conn, $user_query);
                        mysqli_stmt_bind_param($user_stmt, "i", $registration['user_id']);
                        mysqli_stmt_execute($user_stmt);
                        $user_result = mysqli_stmt_get_result($user_stmt);
                        $user_data = mysqli_fetch_assoc($user_result);
                        
                        if (!$user_data) {
                            throw new Exception('User not found in useraccounts table');
                        }
                        
                        // Update employeelist table by matching email or firstName+lastName
                        $update_query2 = "UPDATE employeelist SET fingerprint_uid = ? WHERE email = ? OR (firstName = ? AND lastName = ?)";
                        $stmt2 = mysqli_prepare($conn, $update_query2);
                        mysqli_stmt_bind_param($stmt2, "ssss", $employee_id, $user_data['email'], $user_data['firstName'], $user_data['lastName']);
                        
                        if (!mysqli_stmt_execute($stmt2)) {
                            throw new Exception('Failed to update employeelist table: ' . mysqli_error($conn));
                        }
                        
                        // Commit transaction
                        mysqli_commit($conn);
                        
                        // Mark registration as completed
                        $registration['completed'] = true;
                        $registration['completed_at'] = date('Y-m-d H:i:s');
                        $registration['scan_datetime'] = $datetime;
                        
                        saveJSON($pending_file, $pending);
                        
                        writeLog("Registration COMPLETED successfully for user_id: {$registration['user_id']}, fingerprint_id: '$employee_id' (both tables updated)");
                        
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Fingerprint registered successfully',
                            'registration_completed' => true,
                            'user_id' => $registration['user_id'],
                            'fingerprint_id' => $employee_id
                        ]);
                        $matched = true;
                        break;
                        
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        mysqli_rollback($conn);
                        writeLog("Database update failed: " . $e->getMessage());
                        throw new Exception('Failed to update database: ' . $e->getMessage());
                    }
                }
            }
            
            if (!$matched) {
                writeLog("No matching pending registration found for fingerprint_id: '$employee_id'");
                
                // Check if there are any pending registrations and mark them as failed
                $pendingFound = false;
                foreach ($pending as $key => &$registration) {
                    if (!$registration['completed'] && !$registration['failed']) {
                        writeLog("Marking pending registration as failed for user_id: {$registration['user_id']} - wrong fingerprint scanned");
                        $registration['failed'] = true;
                        $registration['failed_at'] = date('Y-m-d H:i:s');
                        $registration['wrong_fingerprint_id'] = $employee_id;
                        $pendingFound = true;
                    }
                }
                
                if ($pendingFound) {
                    saveJSON($pending_file, $pending);
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Attendance scan recorded (no matching registration)',
                    'registration_completed' => false
                ]);
            }
            
        } else {
            throw new Exception('Invalid request data');
        }
    }
    
} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>