<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// This endpoint now redirects to the main fingerprint management API
// to handle both registration and regular fingerprint scans

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Forward the request to fingerprint_management.php
    $raw_data = file_get_contents('php://input');
    $data = json_decode($raw_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data: ' . json_last_error_msg()
        ]);
        exit();
    }
    
    // Add action for fingerprint scan processing
    $data['action'] = 'receive_fingerprint_scan';
    
    // Initialize cURL to forward to main API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://difsysinc.com/difsysapi/fingerprint_management.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($data))
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        // If cURL fails, include the fingerprint_management.php directly
        require_once 'fingerprint_management.php';
    } else {
        http_response_code($http_code);
        echo $response;
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // File storage configuration for backward compatibility
    $fingerprint_file = 'fingerprint_scans.json';
    
    function loadFingerprintData() {
        global $fingerprint_file;
        if (file_exists($fingerprint_file)) {
            $json_data = file_get_contents($fingerprint_file);
            $data = json_decode($json_data, true);
            return $data ?: [];
        }
        return [];
    }
    
    // Handle GET request to retrieve fingerprint scan records
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    // Load fingerprint data from file
    $fingerprint_records = loadFingerprintData();
    
    // Sort by datetime descending (newest first)
    usort($fingerprint_records, function($a, $b) {
        return strtotime($b['datetime']) - strtotime($a['datetime']);
    });
    
    // Apply pagination
    $total_records = count($fingerprint_records);
    $records = array_slice($fingerprint_records, $offset, $limit);
    
    echo json_encode([
        'success' => true,
        'count' => count($records),
        'total' => $total_records,
        'data' => $records
    ]);
    
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
}
?>