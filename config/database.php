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
$charset = $config['db']['charset'] ?? 'utf8mb4';

if (!$dbname || !$username) {
    // A bare die('string') always exits 0, which under CLI (see cli/wc_order_sync.php) would
    // make a cron run that failed to even connect look like a success to Hostinger's cron
    // job status - harmless for a web request either way, since browsers don't inspect PHP
    // exit codes, but this is the one path that needs a real non-zero code to be trustworthy
    // for both callers.
    echo 'Database configuration is incomplete. Please configure the database settings in config.php.' . PHP_EOL;
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=$charset",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Database connection failed.' . PHP_EOL;
    exit(1);
}
