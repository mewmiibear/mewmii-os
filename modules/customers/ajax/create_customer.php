<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ajax_helpers.php';

// Inline "create new customer" for the Order Product Picker's customer field (11) - a
// thin wrapper around a plain INSERT, not a new customer-management flow (the full
// Customers module already covers create/edit elsewhere).
ajax_require_permission('customers.manage');
ajax_require_csrf();

$pdo = app_db();
$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$instagramUsername = trim((string) ($_POST['instagram_username'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));

if ($name === '' && $phone === '' && $email === '' && $instagramUsername === '') {
    ajax_json(['error' => 'Provide at least one of display name, Instagram username, phone, or email.'], 400);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ajax_json(['error' => 'Enter a valid email address, or leave it blank.'], 400);
}

$existing = app_customer_find_existing_by_identity($pdo, [
    'phone' => $phone,
    'email' => $email,
    'instagram_username' => $instagramUsername,
]);
if ($existing !== null) {
    ajax_json(['id' => (int) $existing['id'], 'name' => app_customer_display_name($existing), 'email' => (string) $existing['email'], 'reused' => true]);
}

try {
    $stmt = $pdo->prepare('INSERT INTO customers (name, email, phone, instagram_username, address) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $instagramUsername !== '' ? $instagramUsername : null,
        $address !== '' ? $address : null,
    ]);

    $customerId = (int) $pdo->lastInsertId();

    ajax_json(['id' => $customerId, 'name' => $name !== '' ? $name : app_customer_display_name(['name' => $name, 'email' => $email, 'phone' => $phone, 'instagram_username' => $instagramUsername]), 'email' => $email]);
} catch (Exception $exception) {
    ajax_json(['error' => 'Failed to create customer.'], 500);
}
