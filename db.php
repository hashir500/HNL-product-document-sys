<?php
$host = '127.0.0.1';
$user = 'root';
$pass = 'root123'; 
$db   = 'pharmacy_db';
$port = 3306; 

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db, $port);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("<div style='font-family: sans-serif; padding: 20px; background: #ffebee; color: #c62828; border-radius: 8px; margin: 20px;'>
            <strong>Database Connection Error:</strong> " . $e.getMessage() . "
         </div>");
}
?>