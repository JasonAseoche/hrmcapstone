<?php
require_once 'db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $app_id = $_GET['app_id'] ?? null;
    
    if (!$app_id) {
        echo json_encode([
            'success' => false,
            'error' => 'App ID is required'
        ]);
        exit;
    }
    
    // Get requirements for this specific applicant
    $sql = "SELECT requirements FROM applicant_requirements WHERE app_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $requirements = json_decode($row['requirements'], true);
        
        echo json_encode([
            'success' => true,
            'requirements' => $requirements ?: []
        ]);
    } else {
        // No specific requirements set, return empty array
        echo json_encode([
            'success' => true,
            'requirements' => []
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>