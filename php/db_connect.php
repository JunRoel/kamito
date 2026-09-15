<?php
/**
 * KAMITO — Database connection
 * Default XAMPP credentials: host=localhost, user=root, password=""
 * Edit these constants if your local MySQL setup differs.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'kamito_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function get_db_connection(): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF); // we handle errors manually for clean JSON responses
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if (!$conn) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed. Make sure XAMPP MySQL is running and kamito_db has been imported from database/kamito_db.sql.'
        ]);
        exit;
    }

    mysqli_set_charset($conn, 'utf8mb4');
    return $conn;
}
