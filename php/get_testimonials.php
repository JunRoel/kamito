<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

$conn = get_db_connection();
$result = mysqli_query($conn, 'SELECT athlete_name, athlete_title, quote FROM testimonials ORDER BY sort_order ASC');

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}

echo json_encode(['success' => true, 'testimonials' => $rows]);
mysqli_close($conn);
