<?php
require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function getDatabaseConnection() {
    $servername = $_ENV['DB_HOST'];
    $username = $_ENV['DB_USERNAME'];
    $password = $_ENV['DB_PASSWORD'];
    $dbname = $_ENV['DB_NAME'];

    $conn = @new mysqli($servername, $username, $password, $dbname);

    if (!$conn->connect_error) {
        $sql = "CREATE TABLE IF NOT EXISTS rooms (
            id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id VARCHAR(30) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($sql);
    }

    return $conn;
}