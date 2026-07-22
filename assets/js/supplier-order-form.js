/**
 * Vanilla JS for the Supplier Order create/edit page: the "+ Add Product" picker modal
 * (11.2) and the dynamic order-items table (11.3) it feeds into. No framework, no build
 * step - reads its data from a <script type="application/json" id="supplier-order-form-data">
 * tag (see modules/supplier-orders/create.php / edit.php), same convention as
 * assets/js/product-form.js.
 *
 * Every item row keeps real name="unit_key[]"/"quantity[]"/"supplier_price[]" attributes,
 * so the whole table still posts as a plain array-of-rows form on submit - this file only
 * builds/removes rows and computes subtotals, it never talks to the server itself (no
 * inventory reservation/stock logic lives here at all).
 */
(function () {
    'use strict';

    var configEl = document.getElementById('supplier-order-form-data');
    if (!configEl) {
        return;
    }
    var config = JSON.parse(configEl.textContent || '{}');
    var products = config.products || [];

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function formatMoney(value) {
        return (Math.round((value + Number.EPSILON) * 100) / 100).toFixed(2);
    }

    // ---------------------------------------------------------------------------------
    // Order items table.
    // ---------------------------------------------------------------------------------
    var tbody = document.querySelector('#supplier-order-items-table tbody');
    var totalEl = document.getElementById('supplier-order-total');

    function existingUnitKeys() {
        var keys = [];
        if (!tbody) {
            return keys;
        }
        tbody.querySelectorAll('tr[data-unit-key]').forEach(function (row) {
            keys.push(row.getAttribute('data-unit-key'));
        });
        return keys;
    }

    function recalcRow(row) {
        var qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
        var cost = parseFloat(row.querySelector('.item-cost').value) || 0;
        row.querySelector('.item-subtotal').textContent = formatMoney(qty * cost);

        // Below-MOQ is a non-blocking warning only (per spec: "Do not block saving") -
        // just an inline hint, never disables the Save button or the input itself.
        var moqWarning = row.querySelector('.item-moq-warning');
        if (moqWarning) {
            var moq = parseInt(row.getAttribute('data-moq') || '', 10);
            if (moq > 0 && qty > 0 && qty < moq) {
                moqWarning.textContent = 'Quantity is below MOQ (Minimum Order Quantity: ' + moq + '). Continue?';
                moqWarning.classList.remove('d-none');
            } else {
                moqWarning.classList.add('d-none');
            }
        }
    }

    function recalcTotal() {
        if (!tbody || !totalEl) {
            return;
        }
        var total = 0;
        tbody.querySelectorAll('tr[data-unit-key]').forEach(function (row) {
            var qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
            var cost = parseFloat(row.querySelector('.item-cost').value) || 0;
            total += qty * cost;
        });
        totalEl.textContent = formatMoney(total);
    }

    function addRow(unitKey, label, sku, quantity, cost, receivedQuantity, moq) {
        if (!tbody || existingUnitKeys().indexOf(unitKey) !== -1) {
            return;
        }

        // A line that already has received quantity (edit mode only) can be increased but
        // never removed or reduced below what's already been received - the quantity
        // input's min enforces the floor client-side, and the server re-validates the same
        // rule via supplier_order_apply_edit() regardless.
        var received = parseInt(receivedQuantity, 10) || 0;
        var qtyMin = Math.max(1, received);
        var actionCell = received > 0
            ? '<span class="badge bg-secondary" title="Already received ' + received + ' unit(s) - cannot be removed">Received ' + received + '</span>'
            : '<button type="button" class="btn btn-sm btn-outline-danger remove-item-row">Remove</button>';

        var moqValue = (moq === null || moq === undefined || moq === '') ? null : parseInt(moq, 10);
        // Quantity defaults to the MOQ when a product is first added (11.2's "Auto fill:
        // Quantity: 50" example) - only when no explicit quantity was passed in, so
        // pre-existing rows (edit mode / re-displayed after a validation error) keep
        // whatever was actually saved/typed, never silently reset to the MOQ.
        var defaultQuantity = quantity !== undefined && quantity !== null && quantity !== '' ? quantity : (moqValue || 1);

        var row = document.createElement('tr');
        row.setAttribute('data-unit-key', unitKey);
        if (moqValue) {
            row.setAttribute('data-moq', String(moqValue));
        }
        row.innerHTML =
            '<td>' + escapeHtml(label) +
            '<input type="hidden" name="unit_key[]" value="' + escapeHtml(unitKey) + '"></td>' +
            '<td>' + escapeHtml(sku) + '</td>' +
            '<td class="text-muted">' + (moqValue ? escapeHtml(moqValue) : '&mdash;') + '</td>' +
            '<td>' +
            '<input type="number" class="form-control form-control-sm item-quantity" name="quantity[]" min="' + qtyMin + '" style="width:90px;" value="' + escapeHtml(defaultQuantity) + '">' +
            '<div class="text-warning small item-moq-warning d-none"></div>' +
            '</td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-cost" name="supplier_price[]" style="width:110px;" value="' + escapeHtml(cost || '0.00') + '"></td>' +
            '<td class="item-subtotal">0.00</td>' +
            '<td class="text-end">' + actionCell + '</td>';

        row.querySelector('.item-quantity').addEventListener('input', function () {
            recalcRow(row);
            recalcTotal();
        });
        row.querySelector('.item-cost').addEventListener('input', function () {
            recalcRow(row);
            recalcTotal();
        });
        var removeBtn = row.querySelector('.remove-item-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                row.remove();
                recalcTotal();
            });
        }

        tbody.appendChild(row);
        recalcRow(row);
        recalcTotal();
    }

    // Pre-existing rows (edit mode, or a re-displayed form after a validation error).
    (config.existingItems || []).forEach(function (item) {
        addRow(item.unit_key, item.label, item.sku, item.quantity, item.supplier_price, item.received_quantity, item.moq);
    });

    // ---------------------------------------------------------------------------------
    // Product Picker modal.
    // ---------------------------------------------------------------------------------
    function unitMatchesSearch(product, unit, needle) {
        if (!needle) {
            return true;
        }
        needle = needle.toLowerCase();
        return product.name.toLowerCase().indexOf(needle) !== -1 ||
            product.sku.toLowerCase().indexOf(needle) !== -1 ||
            unit.sku.toLowerCase().indexOf(needle) !== -1;
    }

    function renderPicker() {
        var results = document.getElementById('picker-results');
        if (!results) {
            return;
        }

        var search = (document.getElementById('picker-search').value || '').trim();
        var supplierFilter = document.getElementById('picker-supplier-filter').value;
        var categoryFilter = document.getElementById('picker-category-filter').value;
        var typeFilter = document.getElementById('picker-type-filter').value;
        var added = existingUnitKeys();

        var html = '';
        products.forEach(function (product) {
            if (supplierFilter && String(product.supplier_id) !== supplierFilter) {
                return;
            }
            if (categoryFilter && String(product.category_id) !== categoryFilter) {
                return;
            }
            if (typeFilter && product.product_type !== typeFilter) {
                return;
            }

            var matchingUnits = product.units.filter(function (unit) {
                return unitMatchesSearch(product, unit, search);
            });
            if (matchingUnits.length === 0) {
                return;
            }

            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="fw-semibold">' + escapeHtml(product.sku) + ' &mdash; ' + escapeHtml(product.name) + '</div>';

            if (product.catalog_type === 'variable') {
                html += '<div class="ms-3 mt-1">';
                matchingUnits.forEach(function (unit) {
                    var checked = added.indexOf(unit.key) !== -1;
                    var keyParts = unit.key.split(':');
                    html += '<label class="d-block checkbox-item">' +
                        '<input type="checkbox" class="picker-unit-checkbox" value="' + escapeHtml(unit.key) +
                        '" data-product-id="' + escapeHtml(keyParts[0]) +
                        '" data-variation-id="' + escapeHtml(keyParts[1] || '0') +
                        '" data-label="' + escapeHtml(product.name + ' - ' + (unit.label || '')) +
                        '" data-sku="' + escapeHtml(unit.sku) +
                        '" data-cost="' + escapeHtml(formatMoney(unit.cost_price || 0)) +
                        '" data-moq="' + escapeHtml(unit.moq || '') + '"' + (checked ? ' checked disabled' : '') + '> ' +
                        escapeHtml(unit.label || '(no attributes)') + ' <span class="text-muted small">' + escapeHtml(unit.sku) + '</span>' +
                        (checked ? ' <span class="badge bg-secondary">Added</span>' : '') +
                        '</label>';
                });
                html += '</div>';
            } else {
                var unit = matchingUnits[0];
                var isAdded = added.indexOf(unit.key) !== -1;
                var keyParts = unit.key.split(':');
                html += '<label class="d-block checkbox-item">' +
                    '<input type="checkbox" class="picker-unit-checkbox" value="' + escapeHtml(unit.key) +
                    '" data-product-id="' + escapeHtml(keyParts[0]) +
                    '" data-variation-id="' + escapeHtml(keyParts[1] || '0') +
                    '" data-label="' + escapeHtml(product.name) +
                    '" data-sku="' + escapeHtml(unit.sku) +
                    '" data-cost="' + escapeHtml(formatMoney(unit.cost_price || 0)) +
                    '" data-moq="' + escapeHtml(unit.moq || '') + '"' + (isAdded ? ' checked disabled' : '') + '> Add this product' +
                    (isAdded ? ' <span class="badge bg-secondary">Added</span>' : '') +
                    '</label>';
            }

            html += '</div>';
        });

        results.innerHTML = html || '<p class="text-muted small mb-0">No products match.</p>';
    }

    function initProductPicker() {
        var modalEl = document.getElementById('productPickerModal');
        var addBtn = document.getElementById('add-product-btn');
        if (!modalEl || !addBtn) {
            return;
        }

        addBtn.addEventListener('click', function () {
            if (window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            renderPicker();
        });

        ['picker-search', 'picker-supplier-filter', 'picker-category-filter', 'picker-type-filter'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', renderPicker);
            }
        });

        document.getElementById('picker-add-selected-btn').addEventListener('click', function () {
            document.querySelectorAll('.picker-unit-checkbox:checked:not(:disabled)').forEach(function (checkbox) {
                // Unit Cost is pre-filled from the product's current cost_price but stays a
                // plain editable input from here on - editing it only affects this one
                // supplier order line, never products.cost_price itself. Quantity defaults
                // to the MOQ (addRow() falls back to it when quantity is left undefined).
                addRow(checkbox.value, checkbox.dataset.label, checkbox.dataset.sku, undefined, checkbox.dataset.cost || '0.00', 0, checkbox.dataset.moq);
            });
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });
    }

    initProductPicker();
})();
