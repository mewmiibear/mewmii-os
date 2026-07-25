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
$address = trim((string) ($_POST['address'] ?? ''));

if ($name === '') {
    ajax_json(['error' => 'Customer name is required.'], 400);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ajax_json(['error' => 'Enter a valid email address, or leave it blank.'], 400);
}

// Production Hardening Phase 2: this endpoint used to insert unconditionally, unlike
// every other customer-creation path in the app (modules/customers/create.php,
// modules/customers/import.php) - matching an email typed here to an existing customer
// with different casing (or a second Quick Add for someone already in the system) would
// silently create a duplicate customer record. Reuse the existing customer instead,
// same case-insensitive match already used elsewhere.
if ($email !== '') {
    $existingStmt = $pdo->prepare('SELECT id, name, email FROM customers WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $existingStmt->execute([$email]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        ajax_json(['id' => (int) $existing['id'], 'name' => $existing['name'], 'email' => (string) $existing['email'], 'reused' => true]);
    }
}

try {
    $stmt = $pdo->prepare('INSERT INTO customers (name, email, phone, address) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $address !== '' ? $address : null,
    ]);

    $customerId = (int) $pdo->lastInsertId();

    ajax_json(['id' => $customerId, 'name' => $name, 'email' => $email]);
} catch (Exception $exception) {
    ajax_json(['error' => 'Failed to create customer.'], 500);
}
