
<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection parameters
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'difsysdb';

// Create a new connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log successful connection to a file for debugging
file_put_contents('connection_log.txt', date('Y-m-d H:i:s') . ": Database connected successfully\n", FILE_APPEND);
?>