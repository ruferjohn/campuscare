
<?php

$host = '127.0.0.1';
$port = '3307';
$dbname = 'campuscare_db';
$username = 'root';
$password = 'Harold@1112';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('CampusCare database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the database. Check the server configuration.');
}
