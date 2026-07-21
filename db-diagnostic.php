<?php
if (!is_file(__DIR__ . '/.env')) {
    echo "ENV_MISSING\n";
    exit(1);
}

$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$env = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }

    [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($name)] = trim($value);
}

$host = $env['DB_HOST'] ?? '';
$dbname = $env['DB_DATABASE'] ?? '';
$username = $env['DB_USERNAME'] ?? '';

echo "DB_HOST=$host\n";
echo "DB_DATABASE=$dbname\n";
echo "DB_USERNAME=$username\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $env['DB_PASSWORD'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "PDO_OK\n";
} catch (PDOException $e) {
    echo "PDO_ERROR=" . $e->getMessage() . "\n";
    exit(1);
}
