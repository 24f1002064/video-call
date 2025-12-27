<?php
require 'vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

function getDatabaseConnection() {
    if (isset($_ENV['DATABASE_URL']) && !empty($_ENV['DATABASE_URL'])) {
        $db_url = parse_url($_ENV['DATABASE_URL']);
        
        $servername = $db_url['host'];
        $username = $db_url['user'];
        $password = $db_url['pass'];
        $dbname = ltrim($db_url['path'], '/');
        $port = isset($db_url['port']) ? $db_url['port'] : 3306;
    } else {
        $servername = $_ENV['DB_HOST'] ?? 'localhost';
        $username = $_ENV['DB_USERNAME'] ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? '';
        $dbname = $_ENV['DB_NAME'] ?? 'video_call';
        $port = 3306;
    }

    $conn = new mysqli($servername, $username, $password, $dbname, $port);

    if ($conn->connect_error) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }

    $sql = "CREATE TABLE IF NOT EXISTS rooms (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id VARCHAR(30) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    if (!$conn->query($sql)) {
        error_log("Error creating table: " . $conn->error);
    }

    return $conn;
}