<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/csv_import.php';
app_require_permission('customers.manage');

/**
 * CSV import for Customers - all-or-nothing (per the confirmed import behavior): every row
 * is validated first, and the whole file is rejected if ANY row fails. Only when every row
 * passes does this insert anything, all inside one transaction.
 */

$appTitle = 'Import Customers';
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
            $existingEmailsStmt = $pdo->query('SELECT LOWER(email) FROM customers WHERE email IS NOT NULL');
            $existingEmails = array_flip($existingEmailsStmt->fetchAll(PDO::FETCH_COLUMN));
            $seenEmails = [];

            foreach ($rows as $i => $row) {
                $rowNum = $i + 2; // +1 for header, +1 for 1-indexed display
                $name = trim((string) ($row['name'] ?? ''));
                $email = trim((string) ($row['email'] ?? ''));

                if ($name === '' || strlen($name) > 120) {
                    $rowErrors[] = "Row {$rowNum}: name is required and must be 120 characters or fewer.";
                } elseif ($email !== '' && (strlen($email) > 190 || !app_validate_email($email))) {
                    $rowErrors[] = "Row {$rowNum}: email must be a valid address of 190 characters or fewer.";
                } elseif ($email !== '' && isset($existingEmails[strtolower($email)])) {
                    $rowErrors[] = "Row {$rowNum}: a customer with email {$email} already exists.";
                } elseif ($email !== '' && isset($seenEmails[strtolower($email)])) {
                    $rowErrors[] = "Row {$rowNum}: email {$email} is duplicated elsewhere in this file.";
                }

                if ($email !== '') {
                    $seenEmails[strtolower($email)] = true;
                }
            }

            if ($rowErrors === []) {
                $pdo->beginTransaction();

                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO customers (name, email, phone, instagram_username, address, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    foreach ($rows as $row) {
                        $stmt->execute([
                            trim((string) ($row['name'] ?? '')),
                            ($row['email'] ?? '') !== '' ? trim($row['email']) : null,
                            ($row['phone'] ?? '') !== '' ? trim($row['phone']) : null,
                            ($row['instagram_username'] ?? '') !== '' ? trim($row['instagram_username']) : null,
                            ($row['address'] ?? '') !== '' ? trim($row['address']) : null,
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
        <h2 class="mb-1">Import Customers</h2>
        <p class="text-muted mb-0">CSV only. Every row is validated first - the whole file is rejected if any row fails.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/modules/customers/index.php">Back to Customers</a>
</div>

<?php if ($imported > 0): ?>
    <div class="alert alert-success"><?php echo $imported; ?> customer(s) imported successfully.</div>
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
    <p class="text-muted small">Columns (header row required): <code>name</code> (required), <code>email</code>, <code>phone</code>, <code>instagram_username</code>, <code>address</code>, <code>notes</code>.</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
        <div class="mb-3">
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Validate &amp; Import</button>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
