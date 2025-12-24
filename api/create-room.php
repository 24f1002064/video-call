<?php
require_once '../db_connect.php';

$conn = getDatabaseConnection();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$expirationLimit = date('Y-m-d H:i:s', strtotime('-24 hours'));
$cleanupSql = "DELETE FROM rooms WHERE created_at < '$expirationLimit'";
$conn->query($cleanupSql);

$maxRetries = 5;
$roomId = '';
$inserted = false;

for ($i = 0; $i < $maxRetries; $i++) {
    $roomId = bin2hex(random_bytes(8));
    
    $stmt = $conn->prepare("INSERT INTO rooms (room_id) VALUES (?)");
    $stmt->bind_param("s", $roomId);
    
    try {
        if ($stmt->execute()) {
            $inserted = true;
            break;
        }
    } catch (mysqli_sql_exception $e) {
        continue;
    }
}

if ($inserted) {
    echo json_encode(['roomId' => $roomId]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate unique room ID']);
}

$conn->close();