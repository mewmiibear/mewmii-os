<?php

/**
 * Catalogue Manager > Attributes tab. Two views sharing one tab, switched by ?manage=ID:
 * - list view (no ?manage): every attribute, Add/Delete, matches the old
 *   modules/attributes/index.php exactly.
 * - manage view (?tab=attributes&manage=ID): one attribute's name plus its full value list
 *   (Add/Edit/Delete, including SKU prefix), matches the old modules/attributes/edit.php
 *   exactly. Unlike the other four tabs, this isn't folded into an in-place Add/Edit form
 *   swap - managing an attribute's values is a genuinely different, larger view (its own
 *   sub-table), so it fully replaces the list rather than sitting above it.
 *
 * The manage view requires products.manage outright (no read-only view), same as the
 * original edit.php - a products.view-only user can see the attribute list but not open
 * "Manage Values" for one, preserving that permission split exactly.
 */

function catalog_tab_attributes_validate_code(PDO $pdo, int $attributeId, string $code, ?int $excludeValueId = null): ?string
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        return 'Enter a SKU prefix (e.g. CN for Cinnamoroll).';
    }
    if (!preg_match('/^[A-Z0-9]{1,5}$/', $code)) {
        return 'Prefix must be 1-5 letters/numbers only (e.g. CN).';
    }

    $sql = 'SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND code = ?';
    $params = [$attributeId, $code];
    if ($excludeValueId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeValueId;
    }
    $check = $pdo->prepare($sql);
    $check->execute($params);
    if ((int) $check->fetchColumn() > 0) {
        return 'That prefix is already used by another value for this attribute.';
    }

    return null;
}

function catalog_tab_attributes_boot(PDO $pdo, bool $canManage): array
{
    $error = '';
    $manageId = (int) ($_GET['manage'] ?? 0);

    if ($manageId > 0) {
        app_require_permission('products.manage');

        $attribute = catalog_get_attribute($pdo, $manageId);
        if ($attribute === null) {
            http_response_code(404);
            require_once __DIR__ . '/../../../includes/header.php';
            echo '<div class="alert alert-danger">Attribute not found.</div>';
            require_once __DIR__ . '/../../../includes/footer.php';
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                app_require_csrf();
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }

            if ($error === '') {
                $action = (string) ($_POST['action'] ?? '');

                if ($action === 'rename') {
                    $name = trim((string) ($_POST['name'] ?? ''));

                    if ($name === '' || strlen($name) > 100) {
                        $error = 'Name is required and must be 100 characters or fewer.';
                    } else {
                        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attributes WHERE LOWER(name) = LOWER(?) AND id != ?');
                        $dupCheck->execute([$name, $manageId]);
                        if ((int) $dupCheck->fetchColumn() > 0) {
                            $error = 'An attribute with this name already exists.';
                        }
                    }

                    if ($error === '') {
                        $pdo->beginTransaction();

                        try {
                            $pdo->prepare('UPDATE product_attributes SET name = ? WHERE id = ?')->execute([$name, $manageId]);
                            $pdo->commit();

                            app_redirect('/modules/catalog/index.php?tab=attributes&manage=' . $manageId . '&renamed=1');
                        } catch (Exception $exception) {
                            $pdo->rollBack();
                            $error = 'Failed to rename attribute.';
                        }
                    }
                } elseif ($action === 'add_value') {
                    $value = trim((string) ($_POST['value'] ?? ''));
                    $code = (string) ($_POST['code'] ?? '');

                    if ($value === '' || strlen($value) > 150) {
                        $error = 'Enter a value (150 characters or fewer).';
                    } else {
                        $codeError = catalog_tab_attributes_validate_code($pdo, $manageId, $code);
                        if ($codeError !== null) {
                            $error = $codeError;
                        }
                    }

                    if ($error === '') {
                        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND value = ?');
                        $dupCheck->execute([$manageId, $value]);
                        if ((int) $dupCheck->fetchColumn() > 0) {
                            $error = 'This attribute already has that value.';
                        }
                    }

                    if ($error === '') {
                        $pdo->beginTransaction();

                        try {
                            catalog_get_or_create_attribute_value($pdo, $manageId, $value, strtoupper(trim($code)));
                            $pdo->commit();

                            app_redirect('/modules/catalog/index.php?tab=attributes&manage=' . $manageId . '&value_added=1');
                        } catch (Exception $exception) {
                            $pdo->rollBack();
                            $error = 'Failed to add value.';
                        }
                    }
                } elseif ($action === 'update_value') {
                    $valueId = (int) ($_POST['value_id'] ?? 0);
                    $value = trim((string) ($_POST['value'] ?? ''));
                    $code = (string) ($_POST['code'] ?? '');
                    $existingValue = $valueId > 0 ? catalog_get_attribute_value($pdo, $valueId) : null;

                    if ($existingValue === null || (int) $existingValue['attribute_id'] !== $manageId) {
                        $error = 'Invalid value.';
                    } elseif ($value === '' || strlen($value) > 150) {
                        $error = 'Enter a value (150 characters or fewer).';
                    } else {
                        $codeError = catalog_tab_attributes_validate_code($pdo, $manageId, $code, $valueId);
                        if ($codeError !== null) {
                            $error = $codeError;
                        }
                    }

                    if ($error === '') {
                        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ? AND value = ? AND id != ?');
                        $dupCheck->execute([$manageId, $value, $valueId]);
                        if ((int) $dupCheck->fetchColumn() > 0) {
                            $error = 'This attribute already has that value.';
                        }
                    }

                    if ($error === '') {
                        $pdo->beginTransaction();

                        try {
                            $pdo->prepare('UPDATE product_attribute_values SET value = ?, code = ? WHERE id = ?')
                                ->execute([$value, strtoupper(trim($code)), $valueId]);
                            $pdo->commit();

                            app_redirect('/modules/catalog/index.php?tab=attributes&manage=' . $manageId . '&value_updated=1');
                        } catch (Exception $exception) {
                            $pdo->rollBack();
                            $error = 'Failed to update value.';
                        }
                    }
                } elseif ($action === 'delete_value') {
                    $valueId = (int) ($_POST['value_id'] ?? 0);
                    $existingValue = $valueId > 0 ? catalog_get_attribute_value($pdo, $valueId) : null;

                    if ($existingValue === null || (int) $existingValue['attribute_id'] !== $manageId) {
                        $error = 'Invalid value.';
                    } else {
                        $pdo->beginTransaction();

                        try {
                            catalog_attribute_value_delete_if_unused($pdo, $valueId);
                            $pdo->commit();

                            app_redirect('/modules/catalog/index.php?tab=attributes&manage=' . $manageId . '&value_deleted=1');
                        } catch (RuntimeException $exception) {
                            $pdo->rollBack();
                            $error = $exception->getMessage();
                        } catch (Exception $exception) {
                            $pdo->rollBack();
                            $error = 'Failed to delete value.';
                        }
                    }
                } else {
                    $error = 'Unknown action.';
                }
            }

            // Re-read after a POST that ended in an error (redirects already returned above on success).
            $attribute = catalog_get_attribute($pdo, $manageId);
        }

        $productCount = catalog_attribute_product_count($pdo, $manageId);
        $values = catalog_list_attribute_values($pdo, $manageId);
        foreach ($values as &$value) {
            $value['product_count'] = catalog_attribute_value_product_count($pdo, (int) $value['id']);
        }
        unset($value);

        return [
            'mode' => 'manage',
            'error' => $error,
            'canManage' => $canManage,
            'attribute' => $attribute,
            'productCount' => $productCount,
            'values' => $values,
        ];
    }

    // --- List view ----------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            app_require_csrf();
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '' && !$canManage) {
            http_response_code(403);
            $error = 'You do not have permission to manage attributes.';
        }

        if ($error === '') {
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $name = trim((string) ($_POST['name'] ?? ''));

                if ($name === '') {
                    $error = 'Enter an attribute name.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        $newId = catalog_get_or_create_attribute($pdo, $name);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=attributes&manage=' . $newId . '&created=1');
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to create attribute.';
                    }
                }
            } elseif ($action === 'delete') {
                $attributeId = (int) ($_POST['attribute_id'] ?? 0);

                if ($attributeId < 1) {
                    $error = 'Invalid attribute.';
                } else {
                    $pdo->beginTransaction();

                    try {
                        catalog_attribute_delete_if_unused($pdo, $attributeId);
                        $pdo->commit();

                        app_redirect('/modules/catalog/index.php?tab=attributes&deleted=1');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $error = $exception->getMessage();
                    } catch (Exception $exception) {
                        $pdo->rollBack();
                        $error = 'Failed to delete attribute.';
                    }
                }
            } else {
                $error = 'Unknown action.';
            }
        }
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $attributes = catalog_list_attributes_with_counts($pdo);
    if ($search !== '') {
        $needle = strtolower($search);
        $attributes = array_values(array_filter($attributes, static function (array $attribute) use ($needle): bool {
            return strpos(strtolower($attribute['name']), $needle) !== false;
        }));
    }

    return [
        'mode' => 'list',
        'error' => $error,
        'canManage' => $canManage,
        'search' => $search,
        'attributes' => $attributes,
    ];
}

function catalog_tab_attributes_render(array $ctx): void
{
    if ($ctx['mode'] === 'manage') {
        catalog_tab_attributes_render_manage($ctx);

        return;
    }

    catalog_tab_attributes_render_list($ctx);
}

function catalog_tab_attributes_render_list(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var string $search
     * @var array $attributes
     */
    ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Attribute deleted.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo nl2br(app_escape($error)); ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Add Attribute</h5>
            <form method="post" class="row g-2 align-items-end" action="/modules/catalog/index.php?tab=attributes">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="add">
                <div class="col-md-10">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="name" maxlength="100" placeholder="e.g. Character, Color, Size" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Add</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4 filter-card">
        <form method="get" class="row g-2 align-items-end" action="/modules/catalog/index.php">
            <input type="hidden" name="tab" value="attributes">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo app_escape($search); ?>" placeholder="Search attributes by name&hellip;">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary w-100">Search</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary w-100" href="/modules/catalog/index.php?tab=attributes">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card p-4">
        <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Values</th>
                    <th>Products</th>
                    <th>Date Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attributes as $attribute): ?>
                    <tr>
                        <td><a href="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>"><?php echo app_escape($attribute['name']); ?></a></td>
                        <td><?php echo (int) $attribute['value_count']; ?></td>
                        <td><?php echo (int) $attribute['product_count']; ?></td>
                        <td><?php echo $attribute['created_at'] !== null ? app_escape($attribute['created_at']) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>">Manage Values</a>
                                    <form method="post" class="d-inline" action="/modules/catalog/index.php?tab=attributes" onsubmit="return confirm('Delete this attribute? This only works if no product currently has it assigned.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="attribute_id" value="<?php echo (int) $attribute['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($attributes === []): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <?php if ($search !== ''): ?>
                                    <div class="empty-state-title">No Attributes Match "<?php echo app_escape($search); ?>"</div>
                                <?php else: ?>
                                    <div class="empty-state-title">No Attributes Yet</div>
                                    <p class="empty-state-text">Attributes (Character, Color, Size, ...) let variable products define variations - add one to get started.</p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}

function catalog_tab_attributes_render_manage(array $ctx): void
{
    extract($ctx);
    /**
     * @var string $error
     * @var bool $canManage
     * @var array $attribute
     * @var int $productCount
     * @var array $values
     */
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Manage Attribute: <?php echo app_escape($attribute['name']); ?> &middot; <span class="text-muted"><?php echo (int) $productCount; ?> product(s) &middot; <?php echo count($values); ?> value(s)</span></h5>
        <a class="btn btn-sm btn-outline-secondary" href="/modules/catalog/index.php?tab=attributes">Back to Attributes</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Attribute created.</div>
    <?php endif; ?>
    <?php if (isset($_GET['renamed'])): ?>
        <div class="alert alert-success">Attribute renamed.</div>
    <?php endif; ?>
    <?php if (isset($_GET['value_added'])): ?>
        <div class="alert alert-success">Value added.</div>
    <?php endif; ?>
    <?php if (isset($_GET['value_updated'])): ?>
        <div class="alert alert-success">Value updated.</div>
    <?php endif; ?>
    <?php if (isset($_GET['value_deleted'])): ?>
        <div class="alert alert-success">Value deleted.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo app_escape($error); ?></div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Attribute Name</h5>
        <form method="post" class="row g-2 align-items-end" action="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="rename">
            <div class="col-md-8">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" value="<?php echo app_escape($attribute['name']); ?>" maxlength="100" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Slug</label>
                <input type="text" class="form-control" value="<?php echo app_escape($attribute['slug']); ?>" disabled>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Save Name</button>
            </div>
        </form>
    </div>

    <?php if ($values !== []): ?>
        <?php foreach ($values as $value): ?>
            <form method="post" action="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>" id="value-form-<?php echo (int) $value['id']; ?>" class="d-none">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="update_value">
                <input type="hidden" name="value_id" value="<?php echo (int) $value['id']; ?>">
            </form>
            <form method="post" action="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>" id="delete-value-form-<?php echo (int) $value['id']; ?>" class="d-none" onsubmit="return confirm('Delete this value? This only works if no product currently has it selected.');">
                <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="delete_value">
                <input type="hidden" name="value_id" value="<?php echo (int) $value['id']; ?>">
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <h5 class="mb-3">Values</h5>
        <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th style="width: 45%;">Value</th>
                    <th style="width: 15%;">SKU Prefix</th>
                    <th>Products</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($values as $value): ?>
                    <tr>
                        <td><input type="text" class="form-control form-control-sm" form="value-form-<?php echo (int) $value['id']; ?>" name="value" value="<?php echo app_escape($value['value']); ?>" maxlength="150" required></td>
                        <td><input type="text" class="form-control form-control-sm" form="value-form-<?php echo (int) $value['id']; ?>" name="code" value="<?php echo app_escape($value['code'] ?? ''); ?>" maxlength="5" style="text-transform: uppercase;" required></td>
                        <td><?php echo (int) $value['product_count']; ?></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary" form="value-form-<?php echo (int) $value['id']; ?>">Save</button>
                                <button type="submit" class="btn btn-sm btn-outline-danger" form="delete-value-form-<?php echo (int) $value['id']; ?>">Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($values === []): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <div class="empty-state-title">No Values Yet</div>
                                <p class="empty-state-text">Add the first value below (e.g. "Hello Kitty" for a Character attribute).</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card p-4">
        <h5 class="mb-3">Add Value</h5>
        <form method="post" class="row g-2 align-items-end" action="/modules/catalog/index.php?tab=attributes&manage=<?php echo (int) $attribute['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo app_escape(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="add_value">
            <div class="col-md-7">
                <label class="form-label">Value</label>
                <input type="text" class="form-control" name="value" maxlength="150" placeholder="e.g. Cinnamoroll" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">SKU Prefix</label>
                <input type="text" class="form-control" name="code" maxlength="5" style="text-transform: uppercase;" placeholder="e.g. CN" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>
    <?php
}
