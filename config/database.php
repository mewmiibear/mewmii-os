<?php

function mewmii_load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (!getenv($name) && !isset($_ENV[$name]) && !isset($_SERVER[$name])) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$envCandidates = [
    __DIR__ . '/../.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/.env',
    getcwd() . '/.env',
];
foreach (array_unique(array_filter($envCandidates)) as $envCandidate) {
    mewmii_load_env_file($envCandidate);
}

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
