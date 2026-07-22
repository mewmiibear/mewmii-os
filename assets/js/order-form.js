/**
 * Vanilla JS for the Customer Order create/edit page: the "+ Add Product" picker modal,
 * the dynamic order-items table it feeds into (Quantity/Unit Price/Discount/Subtotal), and
 * the inline "+ New Customer" modal. No framework, no build step - mirrors
 * assets/js/supplier-order-form.js's structure exactly.
 *
 * Every item row keeps real name="unit_key[]"/"quantity[]"/"unit_price[]"/"discount[]"
 * attributes, so the whole table still posts as a plain array-of-rows form on submit -
 * this file only builds/removes rows and computes subtotals; it never reserves/releases
 * stock itself (see includes/orders.php's order_apply_edit() for that).
 */
(function () {
    'use strict';

    var configEl = document.getElementById('order-form-data');
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
    var tbody = document.querySelector('#order-items-table tbody');
    var subtotalEl = document.getElementById('order-items-subtotal');
    var grandTotalEl = document.getElementById('order-grand-total');
    var shippingFeeInput = document.getElementById('order-shipping-fee');

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
        var price = parseFloat(row.querySelector('.item-price').value) || 0;
        var discount = parseFloat(row.querySelector('.item-discount').value) || 0;
        var subtotal = Math.max(0, (qty * price) - discount);
        row.querySelector('.item-subtotal').textContent = formatMoney(subtotal);
    }

    function recalcTotals() {
        if (!tbody) {
            return;
        }
        var itemsSubtotal = 0;
        tbody.querySelectorAll('tr[data-unit-key]').forEach(function (row) {
            var qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
            var price = parseFloat(row.querySelector('.item-price').value) || 0;
            var discount = parseFloat(row.querySelector('.item-discount').value) || 0;
            itemsSubtotal += Math.max(0, (qty * price) - discount);
        });

        if (subtotalEl) {
            subtotalEl.textContent = formatMoney(itemsSubtotal);
        }
        if (grandTotalEl) {
            var shippingFee = shippingFeeInput ? (parseFloat(shippingFeeInput.value) || 0) : 0;
            grandTotalEl.textContent = formatMoney(itemsSubtotal + shippingFee);
        }
    }

    if (shippingFeeInput) {
        shippingFeeInput.addEventListener('input', recalcTotals);
    }

    function addRow(unitKey, label, sku, quantity, price, discount, allocatedQuantity) {
        if (!tbody || existingUnitKeys().indexOf(unitKey) !== -1) {
            return;
        }

        // A line already allocated to Customer Storage (edit mode only) can be increased
        // but never removed or reduced below what's already allocated - the quantity
        // input's min enforces the floor client-side, and order_apply_edit() re-validates
        // the same rule server-side regardless.
        var allocated = parseInt(allocatedQuantity, 10) || 0;
        var qtyMin = Math.max(1, allocated);
        var actionCell = allocated > 0
            ? '<span class="badge bg-secondary" title="Already allocated ' + allocated + ' unit(s) to Customer Storage - cannot be removed">Allocated ' + allocated + '</span>'
            : '<button type="button" class="btn btn-sm btn-outline-danger remove-item-row">Remove</button>';

        var row = document.createElement('tr');
        row.setAttribute('data-unit-key', unitKey);
        row.innerHTML =
            '<td>' + escapeHtml(label) +
            '<input type="hidden" name="unit_key[]" value="' + escapeHtml(unitKey) + '"></td>' +
            '<td>' + escapeHtml(sku) + '</td>' +
            '<td><input type="number" class="form-control form-control-sm item-quantity" name="quantity[]" min="' + qtyMin + '" style="width:80px;" value="' + escapeHtml(quantity || 1) + '"></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-price" name="unit_price[]" style="width:100px;" value="' + escapeHtml(price || '0.00') + '"></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-discount" name="discount[]" style="width:100px;" value="' + escapeHtml(discount || '0.00') + '"></td>' +
            '<td class="item-subtotal">0.00</td>' +
            '<td class="text-end">' + actionCell + '</td>';

        ['item-quantity', 'item-price', 'item-discount'].forEach(function (cls) {
            row.querySelector('.' + cls).addEventListener('input', function () {
                recalcRow(row);
                recalcTotals();
            });
        });
        var removeBtn = row.querySelector('.remove-item-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                row.remove();
                recalcTotals();
            });
        }

        tbody.appendChild(row);
        recalcRow(row);
        recalcTotals();
    }

    // Pre-existing rows (edit mode, or a re-displayed form after a validation error).
    (config.existingItems || []).forEach(function (item) {
        addRow(item.unit_key, item.label, item.sku, item.quantity, item.unit_price, item.discount, item.allocated_quantity);
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
        var categoryFilter = document.getElementById('picker-category-filter').value;
        var brandFilter = document.getElementById('picker-brand-filter').value;
        var typeFilter = document.getElementById('picker-type-filter').value;
        var availabilityFilter = document.getElementById('picker-availability-filter').value;
        var supplierFilter = document.getElementById('picker-supplier-filter').value;
        var added = existingUnitKeys();

        var html = '';
        products.forEach(function (product) {
            if (categoryFilter && String(product.category_id) !== categoryFilter) {
                return;
            }
            if (brandFilter && String(product.brand_id) !== brandFilter) {
                return;
            }
            if (typeFilter && product.product_type !== typeFilter) {
                return;
            }
            if (supplierFilter && String(product.supplier_id) !== supplierFilter) {
                return;
            }

            var matchingUnits = product.units.filter(function (unit) {
                if (!unitMatchesSearch(product, unit, search)) {
                    return false;
                }
                if (availabilityFilter === 'available' && !unit.is_available) {
                    return false;
                }
                if (availabilityFilter === 'unavailable' && unit.is_available) {
                    return false;
                }
                return true;
            });
            if (matchingUnits.length === 0) {
                return;
            }

            var thumb = product.thumb_path
                ? '<img src="/' + escapeHtml(product.thumb_path) + '" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px;" class="me-2">'
                : '';

            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="fw-semibold d-flex align-items-center">' + thumb + escapeHtml(product.sku) + ' &mdash; ' + escapeHtml(product.name) + '</div>';

            if (product.catalog_type === 'variable') {
                html += '<div class="ms-3 mt-1">';
                matchingUnits.forEach(function (unit) {
                    html += renderUnitOption(product, unit, added, unit.label || '(no attributes)');
                });
                html += '</div>';
            } else {
                html += renderUnitOption(product, matchingUnits[0], added, 'Add this product');
            }

            html += '</div>';
        });

        results.innerHTML = html || '<p class="text-muted small mb-0">No products match.</p>';
    }

    function renderUnitOption(product, unit, added, text) {
        var isAdded = added.indexOf(unit.key) !== -1;
        var availabilityBadge = unit.is_available
            ? '<span class="badge bg-success">Available</span>'
            : '<span class="badge bg-danger">Unavailable</span>';

        return '<label class="d-block checkbox-item">' +
            '<input type="checkbox" class="picker-unit-checkbox" value="' + escapeHtml(unit.key) +
            '" data-label="' + escapeHtml(product.catalog_type === 'variable' ? (product.name + ' - ' + (unit.label || '')) : product.name) +
            '" data-sku="' + escapeHtml(unit.sku) +
            '" data-price="' + escapeHtml(formatMoney(unit.price || 0)) + '"' + (isAdded ? ' checked disabled' : '') + '> ' +
            escapeHtml(text) + ' <span class="text-muted small">' + escapeHtml(unit.sku) + '</span> ' +
            '<span class="small">RM' + escapeHtml(formatMoney(unit.price || 0)) + '</span> ' +
            availabilityBadge +
            (isAdded ? ' <span class="badge bg-secondary">Added</span>' : '') +
            '</label>';
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

        ['picker-search', 'picker-category-filter', 'picker-brand-filter', 'picker-type-filter', 'picker-availability-filter', 'picker-supplier-filter'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', renderPicker);
            }
        });

        document.getElementById('picker-add-selected-btn').addEventListener('click', function () {
            document.querySelectorAll('.picker-unit-checkbox:checked:not(:disabled)').forEach(function (checkbox) {
                addRow(checkbox.value, checkbox.dataset.label, checkbox.dataset.sku, 1, checkbox.dataset.price || '0.00', '0.00', 0);
            });
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });
    }

    // ---------------------------------------------------------------------------------
    // Inline "+ New Customer" modal.
    // ---------------------------------------------------------------------------------
    function postJson(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(Object.assign({ csrf_token: config.csrfToken }, data)).toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || json.error) {
                    throw new Error(json.error || 'Request failed.');
                }
                return json;
            });
        });
    }

    function initNewCustomer() {
        var modalEl = document.getElementById('newCustomerModal');
        var addBtn = document.getElementById('add-customer-btn');
        var customerSelect = document.getElementById('customer-select');
        if (!modalEl || !addBtn || !customerSelect) {
            return;
        }

        addBtn.addEventListener('click', function () {
            document.getElementById('new-customer-error').classList.add('d-none');
            if (window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        document.getElementById('new-customer-save-btn').addEventListener('click', function () {
            var errorBox = document.getElementById('new-customer-error');
            errorBox.classList.add('d-none');

            postJson(config.urls.createCustomer, {
                name: document.getElementById('new-customer-name').value,
                phone: document.getElementById('new-customer-phone').value,
                email: document.getElementById('new-customer-email').value,
                address: document.getElementById('new-customer-address').value
            }).then(function (result) {
                var option = document.createElement('option');
                option.value = result.id;
                option.textContent = result.name + (result.email ? ' (' + result.email + ')' : '');
                option.selected = true;
                customerSelect.appendChild(option);

                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }).catch(function (error) {
                errorBox.textContent = error.message;
                errorBox.classList.remove('d-none');
            });
        });
    }

    initProductPicker();
    initNewCustomer();
})();
