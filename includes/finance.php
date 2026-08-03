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

/**
 * Phase C. A fixed list held in PHP rather than a lookup table, matching how
 * MANUAL_INCOME_CATEGORIES/BANK_ACCOUNT_TYPES already handle small closed sets in this same
 * file - expense_categories is a real table only because that list is user-editable, which
 * this one deliberately is not.
 *
 * Only two statuses: 'sold' is deliberately absent until an Asset Sale accounting flow exists
 * to give it meaning (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §15). Disposal is terminal -
 * there is no path back to 'in_use', matching how this codebase treats every other terminal
 * transition (a returned asset is a new record, not a reversed status).
 */
const ASSET_STATUSES = ['in_use', 'disposed'];
const ASSET_CATEGORIES = [
    'Computing',
    'Photography & Content',
    'Warehouse Equipment',
    'Packaging Equipment',
    'Display & Furniture',
    'Office Equipment',
    'Other',
];

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

/**
 * ---------------------------------------------------------------------------------------
 * Finance & Accounting Phase C - Assets.
 *
 * An asset register, NOT an accounting module: no depreciation, no capital allowance, no
 * ledger entries, no balance sheet (docs/FINANCE_ACCOUNTING_ARCHITECTURE.md §6/§17). These
 * functions track what the business owns, where it is, and who holds it. An asset purchase is
 * never also an `expenses` row - the two are siblings, and the Expense-vs-Asset fork decides
 * which table a purchase belongs in.
 * ---------------------------------------------------------------------------------------
 */

function asset_status_labels(): array
{
    return [
        'in_use' => 'In Use',
        'disposed' => 'Disposed',
    ];
}

/**
 * Normalises a user-entered asset code: trimmed, uppercased, and empty-to-NULL. No numbering
 * engine and no auto-generation, by explicit decision - the code is an optional internal
 * reference the user types, so the only rules are the ones needed to keep the UNIQUE index
 * meaningful (' ast-1 ' and 'AST-1' must not become two different codes).
 */
function asset_code_normalise(?string $rawCode): ?string
{
    $code = strtoupper(trim((string) $rawCode));

    return $code !== '' ? $code : null;
}

/**
 * True when $code is already used by a different asset. The DB's UNIQUE index is the actual
 * guarantee (and the race-condition backstop); this exists so the normal case produces a
 * friendly form error instead of a duplicate-key exception. Same belt-and-braces approach
 * already used for supplier_orders.purchase_number.
 */
function asset_code_in_use(PDO $pdo, string $code, ?int $excludeAssetId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM assets WHERE asset_code = ?';
    $params = [$code];

    if ($excludeAssetId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeAssetId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Shared by modules/finance/asset_create.php and asset_edit.php, same "one validator, both
 * callers" discipline as expense_validate_form() above. $excludeAssetId is the id being edited
 * (so an asset's own code doesn't collide with itself); null when creating.
 *
 * Note what is NOT validated here: `status` and `disposal_date`. Neither is settable from the
 * create/edit forms at all - disposal is its own separate action (asset_dispose()), so there
 * is no path by which a POST to these forms could change an asset's lifecycle state.
 *
 * @return array{errors: string[], data: array}
 */
function asset_validate_form(PDO $pdo, array $input, ?int $excludeAssetId = null): array
{
    $errors = [];

    $data = [
        'asset_code' => asset_code_normalise($input['asset_code'] ?? null),
        'name' => trim((string) ($input['name'] ?? '')),
        'category' => trim((string) ($input['category'] ?? '')),
        'supplier_id' => isset($input['supplier_id']) && (int) $input['supplier_id'] > 0 ? (int) $input['supplier_id'] : null,
        'bank_account_id' => isset($input['bank_account_id']) && (int) $input['bank_account_id'] > 0 ? (int) $input['bank_account_id'] : null,
        'assigned_to' => isset($input['assigned_to']) && (int) $input['assigned_to'] > 0 ? (int) $input['assigned_to'] : null,
        'location' => trim((string) ($input['location'] ?? '')),
        'purchase_date' => trim((string) ($input['purchase_date'] ?? '')),
        'purchase_amount' => (string) ($input['purchase_amount'] ?? ''),
        'currency' => strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR',
        'exchange_rate' => trim((string) ($input['exchange_rate'] ?? '')) !== '' ? (float) $input['exchange_rate'] : null,
        'warranty_expiry' => trim((string) ($input['warranty_expiry'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'notes' => trim((string) ($input['notes'] ?? '')),
    ];

    if ($data['asset_code'] !== null) {
        if (strlen($data['asset_code']) > 30) {
            $errors[] = 'Asset code must be 30 characters or fewer.';
        } elseif (asset_code_in_use($pdo, $data['asset_code'], $excludeAssetId)) {
            $errors[] = 'Asset code "' . $data['asset_code'] . '" is already used by another asset.';
        }
    }

    if ($data['name'] === '' || strlen($data['name']) > 120) {
        $errors[] = 'Asset name is required and must be 120 characters or fewer.';
    }

    if (!in_array($data['category'], ASSET_CATEGORIES, true)) {
        $errors[] = 'Choose a valid category.';
    }

    if ($data['supplier_id'] !== null) {
        $supplierStmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = ?');
        $supplierStmt->execute([$data['supplier_id']]);
        if ((int) $supplierStmt->fetchColumn() === 0) {
            $errors[] = 'Choose a valid supplier.';
        }
    }

    if ($data['bank_account_id'] !== null && bank_account_get($pdo, $data['bank_account_id']) === null) {
        $errors[] = 'Choose a valid bank account.';
    }

    if ($data['assigned_to'] !== null) {
        $userStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $userStmt->execute([$data['assigned_to']]);
        if ((int) $userStmt->fetchColumn() === 0) {
            $errors[] = 'Choose a valid user to assign this asset to.';
        }
    }

    if (strlen($data['location']) > 100) {
        $errors[] = 'Location must be 100 characters or fewer.';
    }

    if ($data['purchase_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['purchase_date'])) {
        $errors[] = 'Enter a valid purchase date.';
    }

    if (!is_numeric($data['purchase_amount']) || (float) $data['purchase_amount'] <= 0) {
        $errors[] = 'Purchase amount must be a positive number.';
    } else {
        $data['purchase_amount'] = round((float) $data['purchase_amount'], 2);
    }

    if (strlen($data['currency']) > 10) {
        $errors[] = 'Currency code must be 10 characters or fewer.';
    }

    if ($data['warranty_expiry'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['warranty_expiry'])) {
        $errors[] = 'Enter a valid warranty expiry date, or leave it blank.';
    }

    if ($data['description'] === '' || strlen($data['description']) > 255) {
        $errors[] = 'Description is required and must be 255 characters or fewer.';
    }

    return ['errors' => $errors, 'data' => $data];
}

/**
 * Every new asset starts 'in_use' with a NULL disposal_date - not caller-supplied, exactly like
 * expense_create() fixes 'draft'. Disposal is a separate, deliberate action later.
 */
function asset_create(PDO $pdo, array $data, ?int $userId): int
{
    $stmt = $pdo->prepare('
        INSERT INTO assets (asset_code, name, category, supplier_id, bank_account_id, assigned_to, location, purchase_date, purchase_amount, currency, exchange_rate, warranty_expiry, description, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['asset_code'],
        $data['name'],
        $data['category'],
        $data['supplier_id'],
        $data['bank_account_id'],
        $data['assigned_to'],
        $data['location'] !== '' ? $data['location'] : null,
        $data['purchase_date'],
        $data['purchase_amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['warranty_expiry'] !== '' ? $data['warranty_expiry'] : null,
        $data['description'],
        $data['notes'] !== '' ? $data['notes'] : null,
        'in_use',
        $userId,
    ]);

    $assetId = (int) $pdo->lastInsertId();

    activity_log($pdo, 'finance', 'asset_created', $assetId, 'Asset recorded: ' . $data['name'] . ' (' . $data['currency'] . ' ' . number_format((float) $data['purchase_amount'], 2) . ')');

    return $assetId;
}

/**
 * Deliberately does not touch status or disposal_date - see asset_validate_form()'s docblock.
 */
function asset_update(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare('
        UPDATE assets
        SET asset_code = ?, name = ?, category = ?, supplier_id = ?, bank_account_id = ?, assigned_to = ?, location = ?, purchase_date = ?, purchase_amount = ?, currency = ?, exchange_rate = ?, warranty_expiry = ?, description = ?, notes = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $data['asset_code'],
        $data['name'],
        $data['category'],
        $data['supplier_id'],
        $data['bank_account_id'],
        $data['assigned_to'],
        $data['location'] !== '' ? $data['location'] : null,
        $data['purchase_date'],
        $data['purchase_amount'],
        $data['currency'],
        $data['exchange_rate'],
        $data['warranty_expiry'] !== '' ? $data['warranty_expiry'] : null,
        $data['description'],
        $data['notes'] !== '' ? $data['notes'] : null,
        $id,
    ]);

    activity_log($pdo, 'finance', 'asset_updated', $id, 'Asset updated: ' . $data['name']);
}

function asset_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT a.*, s.name AS supplier_name,
               ba.name AS bank_account_name, ba.account_type AS bank_account_type,
               u.name AS assigned_to_name
        FROM assets a
        LEFT JOIN suppliers s ON s.id = a.supplier_id
        LEFT JOIN bank_accounts ba ON ba.id = a.bank_account_id
        LEFT JOIN users u ON u.id = a.assigned_to
        WHERE a.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * @return array{rows: array, total_count: int, total_amount: float}
 */
function assets_list(PDO $pdo, array $filters = [], int $limit = 50, int $offset = 0): array
{
    $where = [];
    $params = [];

    $searchTerm = trim((string) ($filters['q'] ?? ''));
    if ($searchTerm !== '') {
        $where[] = '(a.name LIKE ? OR a.description LIKE ? OR a.asset_code LIKE ?)';
        $like = '%' . $searchTerm . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, ASSET_CATEGORIES, true)) {
        $where[] = 'a.category = ?';
        $params[] = $category;
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && in_array($status, ASSET_STATUSES, true)) {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }

    $location = trim((string) ($filters['location'] ?? ''));
    if ($location !== '') {
        $where[] = 'a.location LIKE ?';
        $params[] = '%' . $location . '%';
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'a.purchase_date >= ?';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'a.purchase_date <= ?';
        $params[] = $dateTo;
    }

    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(a.purchase_amount), 0) FROM assets a{$whereSql}");
    $countStmt->execute($params);
    $totals = $countStmt->fetch(PDO::FETCH_NUM);

    // Cast to int, never interpolated from raw input - same approach as the expenses list.
    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $stmt = $pdo->prepare("
        SELECT a.id, a.asset_code, a.name, a.category, a.location, a.purchase_date, a.purchase_amount,
               a.currency, a.status, s.name AS supplier_name, u.name AS assigned_to_name
        FROM assets a
        LEFT JOIN suppliers s ON s.id = a.supplier_id
        LEFT JOIN users u ON u.id = a.assigned_to
        {$whereSql}
        ORDER BY a.purchase_date DESC, a.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);

    return [
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total_count' => (int) ($totals[0] ?? 0),
        'total_amount' => (float) ($totals[1] ?? 0),
    ];
}

/**
 * Terminal transition: in_use -> disposed. There is no reverse function on purpose - an asset
 * that returns to service is a new record, not an un-disposal. Callers must confirm the asset
 * is still 'in_use' before calling (the UI hides the action once disposed); this re-checks
 * anyway so a replayed/forged POST can't overwrite an existing disposal date.
 */
function asset_dispose(PDO $pdo, int $id, string $disposalDate): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $disposalDate)) {
        throw new InvalidArgumentException('Enter a valid disposal date.');
    }

    $stmt = $pdo->prepare("UPDATE assets SET status = 'disposed', disposal_date = ? WHERE id = ? AND status = 'in_use'");
    $stmt->execute([$disposalDate, $id]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('This asset has already been disposed.');
    }

    activity_log($pdo, 'finance', 'asset_disposed', $id, 'Asset disposed on ' . $disposalDate . '.');
}

/**
 * Mirrors expense_attachment_store() exactly, against asset_attachments. Kept as a separate
 * function rather than generalising both into one: sharing would mean a table name assembled
 * at runtime inside SQL, which is worse for safety and against this codebase's plain,
 * single-purpose style (docs/FINANCE_DATABASE_DESIGN.md §8 makes the same call at table level).
 */
function asset_attachment_store(PDO $pdo, int $assetId, array $file, ?int $userId): void
{
    $stored = receipt_upload_validate_and_store($file);

    $pdo->prepare('
        INSERT INTO asset_attachments (asset_id, file_path, original_filename, file_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $assetId,
        $stored['file_path'],
        $stored['file_name'],
        pathinfo($stored['file_path'], PATHINFO_EXTENSION),
        $userId,
    ]);
}

function asset_attachments_list(PDO $pdo, int $assetId): array
{
    $stmt = $pdo->prepare('SELECT id, file_path, original_filename, file_type, uploaded_at FROM asset_attachments WHERE asset_id = ? ORDER BY uploaded_at ASC');
    $stmt->execute([$assetId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function asset_attachment_get(PDO $pdo, int $attachmentId): ?array
{
    $stmt = $pdo->prepare('SELECT id, asset_id, file_path, original_filename FROM asset_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}
