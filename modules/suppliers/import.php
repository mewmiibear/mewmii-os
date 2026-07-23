<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
app_require_permission('suppliers.manage');

/**
 * CSV import for Suppliers - same all-or-nothing shape as modules/customers/import.php.
 */

$appTitle = 'Import Suppliers';
$error = '';
$rowErrors = [];
$imported = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '' && (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Choose a CSV file to upload.';
    }

    if ($error === '') {
        $pdo = app_db();

        try {
            $parsed = csv_import_read_rows($_FILES['csv_file']['tmp_name']);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '') {
            $rows = $parsed['rows'];
            if ($rows === []) {
                $error = 'The CSV file has no data rows.';
            }
        }

        if ($error === '') {
            $existingNamesStmt = $pdo->query('SELECT LOWER(name) FROM suppliers');
            $existingNames = array_flip($existingNamesStmt->fetchAll(PDO::FETCH_COLUMN));
            $seenNames = [];

            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $name = trim((string) ($row['name'] ?? ''));
                $email = trim((string) ($row['email'] ?? ''));

                if ($name === '' || strlen($name) > 120) {
                    $rowErrors[] = "Row {$rowNum}: name is required and must be 120 characters or fewer.";
                } elseif (isset($existingNames[strtolower($name)])) {
                    $rowErrors[] = "Row {$rowNum}: a supplier named \"{$name}\" already exists.";
                } elseif (isset($seenNames[strtolower($name)])) {
                    $rowErrors[] = "Row {$rowNum}: \"{$name}\" is duplicated elsewhere in this file.";
                } elseif ($email !== '' && !app_validate_email($email)) {
                    $rowErrors[] = "Row {$rowNum}: email must be a valid address.";
                }

                $seenNames[strtolower($name)] = true;
            }

            if ($rowErrors === []) {
                $pdo->beginTransaction();

                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO suppliers (name, contact, country, contact_person, phone, email, currency, payment_terms, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    foreach ($rows as $row) {
                        $stmt->execute([
                            trim((string) ($row['name'] ?? '')),
                            ($row['contact'] ?? '') !== '' ? trim($row['contact']) : null,
                            ($row['country'] ?? '') !== '' ? trim($row['country']) : null,
                            ($row['contact_person'] ?? '') !== '' ? trim($row['contact_person']) : null,
                            ($row['phone'] ?? '') !== '' ? trim($row['phone']) : null,
                            ($row['email'] ?? '') !== '' ? trim($row['email']) : null,
                            ($row['currency'] ?? '') !== '' ? trim($row['currency']) : null,
                            ($row['payment_terms'] ?? '') !== '' ? trim($row['payment_terms']) : null,
                            ($row['notes'] ?? '') !== '' ? trim($row['notes']) : null,
                        ]);
                        $imported++;
                    }

                    $pdo->commit();
                } catch (Exception $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed: ' . $exception->getMessage();
                    $imported = 0;
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Import Suppliers</h2>
        <p class="text-muted mb-0">CSV only. Every row is validated first - the whole file is rejected if any row fails.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/suppliers/index.php">Back to Suppliers</a>
</div>

<?php if ($imported > 0): ?>
    <div class="alert alert-success"><?php echo $imported; ?> supplier(s) imported successfully.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
<?php endif; ?>
<?php if ($rowErrors !== []): ?>
    <div class="alert alert-danger">
        <p class="mb-2">The file was <strong>not</strong> imported - fix these rows and re-upload:</p>
        <ul class="mb-0">
            <?php foreach ($rowErrors as $rowError): ?>
                <li><?php echo app_escape($rowError); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card p-4">
    <h5 class="mb-3">Upload CSV</h5>
    <p class="text-muted small">Columns (header row required): <code>name</code> (required), <code>contact</code>, <code>country</code>, <code>contact_person</code>, <code>phone</code>, <code>email</code>, <code>currency</code>, <code>payment_terms</code>, <code>notes</code>.</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <div class="mb-3">
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Validate &amp; Import</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
