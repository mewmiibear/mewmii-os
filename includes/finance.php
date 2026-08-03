<?php

require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/receipt_storage.php';

/**
 * Finance & Accounting Phase A/B (docs/FINANCE_DATABASE_DESIGN.md, docs/FINANCE_WORKFLOW.md).
 * This file holds Finance-owned domain logic (expenses, bank accounts, manual income,
 * attachments). Existing business data from Orders/Inventory/Supplier Orders is read by reports
 * layers later, never duplicated or rewritten here.
 */

const EXPENSE_STATUSES = ['draft', 'paid', 'archived'];
const BANK_ACCOUNT_TYPES = ['bank', 'cash', 'e-wallet'];
const MANUAL_INCOME_CATEGORIES = ['Asset Sale', 'Grant', 'Other'];

function bank_account_type_labels(): array
{
    return [
        'bank' => 'Bank',
        'cash' => 'Cash',
        'e-wallet' => 'E-Wallet',
    ];
}

function bank_account_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, account_type, currency, notes, is_active FROM bank_accounts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function bank_accounts_list(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT id, name, account_type, currency, notes, is_active, created_at FROM bank_accounts';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, name ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function bank_account_validate_form(array $input): array
{
    $errors = [];

    $data = [
        'name' => trim((string) ($input['name'] ?? '')),
        'account_type' => trim((string) ($input['account_type'] ?? '')),
        'currency' => strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR',
        'notes' => trim((string) ($input['notes'] ?? '')),
    ];

    if ($data['name'] === '' || strlen($data['name']) > 120) {
        $errors[] = 'Account name is required and must be 120 characters or fewer.';
    }

    if (!in_array($data['account_type'], BANK_ACCOUNT_TYPES, true)) {
        $errors[] = 'Choose a valid account type.';
    }

    if (strlen($data['currency']) > 10) {
        $errors[] = 'Currency code must be 10 characters or fewer.';
    }

    if (strlen($data['notes']) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => $data];
}

function bank_account_create(PDO $pdo, array $data): int
{
    $pdo->prepare('
        INSERT INTO bank_accounts (name, account_type, currency, notes, is_active)
        VALUES (?, ?, ?, ?, 1)
    ')->execute([
        $data['name'],
        $data['account_type'],
        $data['currency'],
        $data['notes'] !== '' ? $data['notes'] : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    activity_log($pdo, 'finance', 'bank_account_created', $id, 'Bank account created: ' . $data['name'] . '.');

    return $id;
}

function bank_account_update(PDO $pdo, int $id, array $data): void
{
    $pdo->prepare('
        UPDATE bank_accounts
        SET name = ?, account_type = ?, currency = ?, notes = ?
        WHERE id = ?
    ')->execute([
        $data['name'],
        $data['account_type'],
        $data['currency'],
        $data['notes'] !== '' ? $data['notes'] : null,
        $id,
    ]);

    activity_log($pdo, 'finance', 'bank_account_updated', $id, 'Bank account updated: ' . $data['name'] . '.');
}

function bank_account_set_active(PDO $pdo, int $id, bool $isActive): void
{
    $pdo->prepare('UPDATE bank_accounts SET is_active = ? WHERE id = ?')->execute([$isActive ? 1 : 0, $id]);
    activity_log($pdo, 'finance', 'bank_account_status_changed', $id, 'Bank account ' . ($isActive ? 'activated' : 'deactivated') . '.');
}

/**
 * Every active category, parent-first then its children immediately after (matches how the
 * picker should render - a two-level indented list, not a flat alphabetical one). Each row
 * carries 'depth' (0 for a parent/top-level category, 1 for a child) so the caller can indent
 * without re-deriving the hierarchy itself.
 */
function expense_categories_flat(PDO $pdo, bool $activeOnly = true): array
{
    $whereSql = $activeOnly ? 'WHERE is_active = 1' : '';
    $rows = $pdo->query("SELECT id, name, parent_id FROM expense_categories {$whereSql} ORDER BY parent_id IS NOT NULL, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $byParent = [];
    foreach ($rows as $row) {
        $byParent[$row['parent_id'] === null ? 0 : (int) $row['parent_id']][] = $row;
    }

    $flat = [];
    foreach ($byParent[0] ?? [] as $parent) {
        $flat[] = ['id' => (int) $parent['id'], 'name' => $parent['name'], 'depth' => 0];
        foreach ($byParent[(int) $parent['id']] ?? [] as $child) {
            $flat[] = ['id' => (int) $child['id'], 'name' => $child['name'], 'depth' => 1];
        }
    }

    return $flat;
}

function expense_category_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, parent_id, is_active FROM expense_categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Shared by modules/finance/create.php and edit.php, same "one validator, both callers" reuse
 * discipline already established for supplier orders (supplier_order_validate_form(),
 * includes/supplier_orders.php). Returns ['errors' => string[], 'data' => array] - $data is
 * always fully populated (trimmed/cast) even when $errors isn't empty, so the form can be
 * re-rendered with whatever the user typed.
 */
function expense_validate_form(PDO $pdo, array $input): array
{
    $errors = [];

    $data = [
        'category_id' => (int) ($input['category_id'] ?? 0),
        'supplier_id' => isset($input['supplier_id']) && (int) $input['supplier_id'] > 0 ? (int) $input['supplier_id'] : null,
        'bank_account_id' => isset($input['bank_account_id']) && (int) $input['bank_account_id'] > 0 ? (int) $input['bank_account_id'] : null,
        'expense_date' => trim((string) ($input['expense_date'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'amount' => (string) ($input['amount'] ?? ''),
        'currency' => strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR',
        'exchange_rate' => trim((string) ($input['exchange_rate'] ?? '')) !== '' ? (float) $input['exchange_rate'] : null,
        'payment_method' => trim((string) ($input['payment_method'] ?? '')),
        'reference_number' => trim((string) ($input['reference_number'] ?? '')),
        'tax_deductible' => !empty($input['tax_deductible']) ? 1 : 0,
    ];

    if ($data['category_id'] < 1 || expense_category_get($pdo, $data['category_id']) === null) {
        $errors[] = 'Choose a valid category.';
    }

    if ($data['bank_account_id'] !== null && bank_account_get($pdo, $data['bank_account_id']) === null) {
        $errors[] = 'Choose a valid bank account.';
    }

    if ($data['expense_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['expense_date'])) {
        $errors[] = 'Enter a valid expense date.';
    }

    if ($data['description'] === '' || strlen($data['description']) > 255) {
        $errors[] = 'Description is required and must be 255 characters or fewer.';
    }

    if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
        $errors[] = 'Amount must be a positive number.';
    } else {
        $data['amount'] = round((float) $data['amount'], 2);
    }

    if (strlen($data['currency']) > 10) {
        $errors[] = 'Currency code must be 10 characters or fewer.';
    }

    if (strlen($data['payment_method']) > 50) {
        $errors[] = 'Payment method must be 50 characters or fewer.';
    }

    if (strlen($data['reference_number']) > 100) {
        $errors[] = 'Reference number must be 100 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => $data];
}

/**
 * Every new expense starts at 'draft' - not a caller-supplied value, by design
 * (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §15: every expense is real the moment it's saved,
 * "draft" describes its payment state, not whether it's a placeholder).
 */
function expense_create(PDO $pdo, array $data, ?int $userId): int
{
    $stmt = $pdo->prepare('
        INSERT INTO expenses (category_id, supplier_id, bank_account_id, expense_date, description, amount, currency, exchange_rate, payment_method, reference_number, tax_deductible, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['category_id'],
        $data['supplier_id'],
        $data['bank_account_id'],
        $data['expense_date'],
        $data['description'],
        $data['amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['payment_method'] !== '' ? $data['payment_method'] : null,
        $data['reference_number'] !== '' ? $data['reference_number'] : null,
        $data['tax_deductible'],
        'draft',
        $userId,
    ]);

    $expenseId = (int) $pdo->lastInsertId();

    activity_log($pdo, 'finance', 'expense_created', $expenseId, 'Expense recorded: ' . $data['description'] . ' (' . $data['currency'] . ' ' . number_format($data['amount'], 2) . ')');

    return $expenseId;
}

function expense_update(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare('
        UPDATE expenses
        SET category_id = ?, supplier_id = ?, bank_account_id = ?, expense_date = ?, description = ?, amount = ?, currency = ?, exchange_rate = ?, payment_method = ?, reference_number = ?, tax_deductible = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $data['category_id'],
        $data['supplier_id'],
        $data['bank_account_id'],
        $data['expense_date'],
        $data['description'],
        $data['amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['payment_method'] !== '' ? $data['payment_method'] : null,
        $data['reference_number'] !== '' ? $data['reference_number'] : null,
        $data['tax_deductible'],
        $id,
    ]);

    activity_log($pdo, 'finance', 'expense_updated', $id, 'Expense updated: ' . $data['description']);
}

function expense_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT e.*, ec.name AS category_name, ec.parent_id AS category_parent_id, s.name AS supplier_name,
               ba.name AS bank_account_name, ba.account_type AS bank_account_type
        FROM expenses e
        INNER JOIN expense_categories ec ON ec.id = e.category_id
        LEFT JOIN suppliers s ON s.id = e.supplier_id
        LEFT JOIN bank_accounts ba ON ba.id = e.bank_account_id
        WHERE e.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function manual_income_validate_form(PDO $pdo, array $input): array
{
    $errors = [];

    $data = [
        'income_date' => trim((string) ($input['income_date'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'amount' => (string) ($input['amount'] ?? ''),
        'currency' => strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR',
        'exchange_rate' => trim((string) ($input['exchange_rate'] ?? '')) !== '' ? (float) $input['exchange_rate'] : null,
        'category' => trim((string) ($input['category'] ?? '')),
        'bank_account_id' => isset($input['bank_account_id']) && (int) $input['bank_account_id'] > 0 ? (int) $input['bank_account_id'] : null,
        'reference_number' => trim((string) ($input['reference_number'] ?? '')),
    ];

    if ($data['income_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['income_date'])) {
        $errors[] = 'Enter a valid income date.';
    }

    if ($data['description'] === '' || strlen($data['description']) > 255) {
        $errors[] = 'Description is required and must be 255 characters or fewer.';
    }

    if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
        $errors[] = 'Amount must be a positive number.';
    } else {
        $data['amount'] = round((float) $data['amount'], 2);
    }

    if (strlen($data['currency']) > 10) {
        $errors[] = 'Currency code must be 10 characters or fewer.';
    }

    if (!in_array($data['category'], MANUAL_INCOME_CATEGORIES, true)) {
        $errors[] = 'Choose a valid income category.';
    }

    if ($data['bank_account_id'] !== null && bank_account_get($pdo, $data['bank_account_id']) === null) {
        $errors[] = 'Choose a valid bank account.';
    }

    if (strlen($data['reference_number']) > 100) {
        $errors[] = 'Reference number must be 100 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => $data];
}

function manual_income_create(PDO $pdo, array $data, ?int $userId): int
{
    $pdo->prepare('
        INSERT INTO manual_income (income_date, description, amount, currency, exchange_rate, category, bank_account_id, reference_number, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $data['income_date'],
        $data['description'],
        $data['amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['category'],
        $data['bank_account_id'],
        $data['reference_number'] !== '' ? $data['reference_number'] : null,
        $userId,
    ]);

    $id = (int) $pdo->lastInsertId();
    activity_log($pdo, 'finance', 'manual_income_created', $id, 'Manual income recorded: ' . $data['description'] . ' (' . $data['currency'] . ' ' . number_format($data['amount'], 2) . ')');

    return $id;
}

function manual_income_update(PDO $pdo, int $id, array $data): void
{
    $pdo->prepare('
        UPDATE manual_income
        SET income_date = ?, description = ?, amount = ?, currency = ?, exchange_rate = ?, category = ?, bank_account_id = ?, reference_number = ?
        WHERE id = ?
    ')->execute([
        $data['income_date'],
        $data['description'],
        $data['amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['category'],
        $data['bank_account_id'],
        $data['reference_number'] !== '' ? $data['reference_number'] : null,
        $id,
    ]);

    activity_log($pdo, 'finance', 'manual_income_updated', $id, 'Manual income updated: ' . $data['description'] . '.');
}

function manual_income_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT mi.*, ba.name AS bank_account_name, ba.account_type AS bank_account_type
        FROM manual_income mi
        LEFT JOIN bank_accounts ba ON ba.id = mi.bank_account_id
        WHERE mi.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function manual_income_list(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    $searchTerm = trim((string) ($filters['q'] ?? ''));
    if ($searchTerm !== '') {
        $where[] = '(mi.description LIKE ? OR mi.reference_number LIKE ?)';
        $like = '%' . $searchTerm . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, MANUAL_INCOME_CATEGORIES, true)) {
        $where[] = 'mi.category = ?';
        $params[] = $category;
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'mi.income_date >= ?';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'mi.income_date <= ?';
        $params[] = $dateTo;
    }

    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT mi.*, ba.name AS bank_account_name, ba.account_type AS bank_account_type
        FROM manual_income mi
        LEFT JOIN bank_accounts ba ON ba.id = mi.bank_account_id
        {$whereSql}
        ORDER BY mi.income_date DESC, mi.id DESC
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Lifecycle transition only - draft -> paid -> archived, archived reachable from either
 * (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §15). No validation of "legal" transitions beyond
 * the fixed EXPENSE_STATUSES list - the UI only ever offers the two real forward actions
 * (Mark Paid, Archive), so an out-of-order transition isn't reachable through the app itself.
 */
function expense_set_status(PDO $pdo, int $id, string $status): void
{
    if (!in_array($status, EXPENSE_STATUSES, true)) {
        throw new InvalidArgumentException('Invalid expense status.');
    }

    $pdo->prepare('UPDATE expenses SET status = ? WHERE id = ?')->execute([$status, $id]);
    activity_log($pdo, 'finance', 'expense_status_changed', $id, 'Expense status changed to ' . $status);
}

/**
 * Reuses includes/receipt_storage.php's existing validate/store function unchanged - same
 * private, .htaccess-denied, finfo-validated storage already proven for customer payment
 * receipts (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §1). One expense can have multiple
 * attachments - this only ever inserts, never replaces a prior row.
 */
function expense_attachment_store(PDO $pdo, int $expenseId, array $file, ?int $userId): void
{
    $stored = receipt_upload_validate_and_store($file);

    $pdo->prepare('
        INSERT INTO expense_attachments (expense_id, file_path, original_filename, file_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $expenseId,
        $stored['file_path'],
        $stored['file_name'],
        pathinfo($stored['file_path'], PATHINFO_EXTENSION),
        $userId,
    ]);
}

function expense_attachments_list(PDO $pdo, int $expenseId): array
{
    $stmt = $pdo->prepare('SELECT id, file_path, original_filename, file_type, uploaded_at FROM expense_attachments WHERE expense_id = ? ORDER BY uploaded_at ASC');
    $stmt->execute([$expenseId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function expense_attachment_get(PDO $pdo, int $attachmentId): ?array
{
    $stmt = $pdo->prepare('SELECT id, expense_id, file_path, original_filename FROM expense_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}
