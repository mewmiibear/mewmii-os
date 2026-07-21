<?php

$configPath = __DIR__ . '/../config.php';
$config = [];
if (is_file($configPath)) {
    $config = require $configPath;
}

$host = $config['db']['host'] ?? 'localhost';
$dbname = $config['db']['database'] ?? '';
$username = $config['db']['username'] ?? '';
$password = $config['db']['password'] ?? '';

if (!$dbname || !$username) {
    die('Database configuration is incomplete. Please configure the database settings in config.php.');
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
