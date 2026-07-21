<?php

$host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
$dbname = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: ($_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? '');
$username = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? '');
$password = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');

if (!$dbname || !$username) {
    die('Database configuration is incomplete. Please define DB_DATABASE and DB_USERNAME in the environment.');
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed.');
}
