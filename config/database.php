<?php

$host = "localhost";
$dbname = "u924285025_mewmii_os";
$username = "u924285025_mewmii_admin";
$password = "MewmiiTassama27!";


try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
} catch (PDOException $e) {

    die("Database connection failed: " . $e->getMessage());
}
