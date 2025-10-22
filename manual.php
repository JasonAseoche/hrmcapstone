<?php
// manual_file_migration.php - Script to manually migrate files for existing employees

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Include database connection
include 'db_connection.php';

echo "<h2>Manual File Migration Script</h2>";
echo "<p>This script will migrate files from applicant_files to employee_files for existing employees.</p>";

try {
    // Start transaction
    $conn->autocommit(FALSE);
    
    // Find employees who might have files in applicant_files but not in employee_files
    $sql = "SELECT e.id as employee_list_id, e.emp_id, e.firstName, e.lastName, e.email
            FROM employeelist e
            WHERE e.emp_id IN (
                SELECT DISTINCT af.app_id 
                FROM applicant_files af 
                WHERE af.app_id NOT IN (
                    SELECT DISTINCT ef.original_applicant_id 
                    FROM employee_files ef 
                    WHERE ef.original_applicant_id IS NOT NULL
                )
            )";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo "<h3>Found " . count($employees) . " employees with potential files to migrate:</h3>";
    
    $total_migrated = 0;
    
    foreach ($employees as $employee) {
        echo "<h4>Processing: {$employee['firstName']} {$employee['lastName']} (ID: {$employee['emp_id']})</h4>";
        
        // Get files for this employee from applicant_files
        $file_sql = "SELECT id, file_type, file_content, file_name, file_size, mime_type, uploaded_at 
                     FROM applicant_files 
                     WHERE app_id = ?";
        
        $file_stmt = $conn->prepare($file_sql);
        $file_stmt->bind_param("i", $employee['emp_id']);
        $file_stmt->execute();
        $file_result = $file_stmt->get_result();
        $files = $file_result->fetch_all(MYSQLI_ASSOC);
        $file_stmt->close();
        
        echo "<ul>";
        
        foreach ($files as $file) {
            echo "<li>Migrating: {$file['file_name']} ({$file['file_type']})</li>";
            
            // Insert into employee_files
            $insert_sql = "INSERT INTO employee_files (emp_id, file_type, file_content, file_name, file_size, mime_type, uploaded_at, migrated_from_applicant, original_applicant_id) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("issssssi", 
                $employee['employee_list_id'],  // Use the employee_list_id as emp_id
                $file['file_type'],
                $file['file_content'],
                $file['file_name'],
                $file['file_size'],
                $file['mime_type'],
                $file['uploaded_at'],
                $employee['emp_id']  // Original applicant ID
            );
            
            if ($insert_stmt->execute()) {
                $total_migrated++;
                echo " ✓ Success<br>";
            } else {
                echo " ✗ Failed: " . $insert_stmt->error . "<br>";
            }
            
            $insert_stmt->close();
        }
        
        echo "</ul>";
        
        // Delete files from applicant_files after successful migration
        if (count($files) > 0) {
            $delete_sql = "DELETE FROM applicant_files WHERE app_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $employee['emp_id']);
            
            if ($delete_stmt->execute()) {
                $deleted_count = $delete_stmt->affected_rows;
                echo "<p>✓ Deleted {$deleted_count} files from applicant_files</p>";
            } else {
                echo "<p>✗ Failed to delete files from applicant_files: " . $delete_stmt->error . "</p>";
            }
            
            $delete_stmt->close();
        }
        
        echo "<hr>";
    }
    
    // Commit transaction
    $conn->commit();
    
    echo "<h3>Migration Complete!</h3>";
    echo "<p>Total files migrated: {$total_migrated}</p>";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
} finally {
    $conn->autocommit(TRUE);
    $conn->close();
}
?>