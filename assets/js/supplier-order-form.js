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
    // Exchange rate suggestion only (see includes/currency_rates.php's 'supplier' rate type,
    // batched server-side in modules/supplier-orders/create.php/edit.php) - a pre-fill hint,
    // never the value actually saved. The invoice's real rate always wins: applySupplierRate-
    // Suggestion() below only ever writes into an EMPTY exchange rate field, so a value the
    // admin already typed, or an already-saved rate on an existing order, is never overwritten.
    var supplierRateSuggestions = config.supplierRateSuggestions || {};

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function formatMoney(value) {
        return (Math.round((value + Number.EPSILON) * 100) / 100).toFixed(2);
    }

    // ---------------------------------------------------------------------------------
    // Guards against a fast double-click (or an impatient repeat click while the first
    // request is still in flight) firing two POSTs and creating a genuine duplicate order -
    // disabling happens in the submit handler itself, after the browser has already read
    // the button's value for this submission, so the in-flight request is unaffected.
    // ---------------------------------------------------------------------------------
    var orderForm = document.querySelector('#supplier-order-items-table');
    orderForm = orderForm ? orderForm.closest('form') : null;
    if (orderForm) {
        orderForm.addEventListener('submit', function () {
            // V3 Phase 3.5: was a bare `button.disabled = true`, which prevents the
            // double-submit but gives no sign anything is happening, and lets the button
            // keep its full width while looking inert. LoadingUI.formPending() applies the
            // same spinner drawer.js already uses and pins the width first, so the toolbar
            // does not reflow. Identical guarantee against double submission.
            window.LoadingUI.formPending(orderForm, true);
        });
    }

    // ---------------------------------------------------------------------------------
    // Order items table.
    // ---------------------------------------------------------------------------------
    var tbody = document.querySelector('#supplier-order-items-table tbody');
    var productSubtotalEl = document.getElementById('supplier-order-product-subtotal');
    var totalEl = document.getElementById('supplier-order-total');
    var shippingFeeInput = document.getElementById('supplier-order-shipping-fee');
    if (shippingFeeInput) {
        shippingFeeInput.addEventListener('input', recalcTotal);
    }

    // ---------------------------------------------------------------------------------
    // Phase 6B (Supplier Order currency): "Unit Cost"/"Subtotal" are entered/shown in the
    // order's own currency, converted to MYR here purely for display (the same foreign x
    // rate = MYR formula includes/supplier_orders.php's supplier_order_convert_to_myr()
    // computes server-side on submit - this is only a live preview, never the value actually
    // saved, which the server always re-derives itself).
    // ---------------------------------------------------------------------------------
    var currencySelect = document.getElementById('supplier-order-currency');
    var currencyOtherInput = document.getElementById('supplier-order-currency-other');
    var exchangeRateWrapper = document.getElementById('supplier-order-exchange-rate-wrapper');
    var exchangeRateLabel = document.getElementById('supplier-order-exchange-rate-label');
    var exchangeRateInput = document.getElementById('supplier-order-exchange-rate');
    var unitCostHeader = document.getElementById('supplier-order-unit-cost-header');
    var subtotalHeader = document.getElementById('supplier-order-subtotal-header');
    var foreignSubtotalRow = document.getElementById('supplier-order-foreign-subtotal-row');
    var foreignSubtotalCurrencyEl = document.getElementById('supplier-order-foreign-subtotal-currency');
    var foreignSubtotalEl = document.getElementById('supplier-order-foreign-subtotal');

    function currentCurrencyCode() {
        if (!currencySelect) {
            return 'MYR';
        }
        if (currencySelect.value === 'OTHER') {
            return (currencyOtherInput && currencyOtherInput.value.trim().toUpperCase()) || 'OTHER';
        }
        return currencySelect.value;
    }

    function currentExchangeRate() {
        var isForeign = currencySelect && currencySelect.value !== 'MYR';
        var rate = isForeign && exchangeRateInput ? parseFloat(exchangeRateInput.value) : NaN;
        return (!isNaN(rate) && rate > 0) ? rate : 1;
    }

    function applySupplierRateSuggestion(code, isForeign) {
        if (!exchangeRateInput || !isForeign || exchangeRateInput.value !== '') {
            return;
        }
        var suggestion = supplierRateSuggestions[code];
        if (suggestion) {
            exchangeRateInput.value = suggestion;
        }
    }

    function applyCurrencyUi() {
        var isForeign = !!currencySelect && currencySelect.value !== 'MYR';
        var code = currentCurrencyCode();
        applySupplierRateSuggestion(code, isForeign);

        if (currencyOtherInput) {
            currencyOtherInput.classList.toggle('d-none', currencySelect.value !== 'OTHER');
        }
        if (exchangeRateWrapper) {
            exchangeRateWrapper.classList.toggle('d-none', !isForeign);
        }
        if (exchangeRateLabel) {
            exchangeRateLabel.textContent = 'Exchange Rate (1 ' + code + ' = ? MYR)';
        }
        if (unitCostHeader) {
            unitCostHeader.textContent = isForeign ? ('Unit Cost (' + code + ')') : 'Unit Cost (RM)';
        }
        if (subtotalHeader) {
            subtotalHeader.textContent = isForeign ? ('Subtotal (' + code + ')') : 'Subtotal (RM)';
        }
        if (foreignSubtotalRow) {
            foreignSubtotalRow.classList.toggle('d-none', !isForeign);
        }
        if (foreignSubtotalCurrencyEl) {
            foreignSubtotalCurrencyEl.textContent = code;
        }

        recalcTotal();
    }

    if (currencySelect) {
        currencySelect.addEventListener('change', applyCurrencyUi);
    }
    if (currencyOtherInput) {
        currencyOtherInput.addEventListener('input', applyCurrencyUi);
    }
    if (exchangeRateInput) {
        exchangeRateInput.addEventListener('input', recalcTotal);
    }

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
        if (!tbody) {
            return;
        }
        // Raw sum of qty x unit cost, in the order's own currency (the exact values every
        // row's own name="supplier_price[]" input carries and posts as-is).
        var foreignSubtotal = 0;
        tbody.querySelectorAll('tr[data-unit-key]').forEach(function (row) {
            var qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
            var cost = parseFloat(row.querySelector('.item-cost').value) || 0;
            foreignSubtotal += qty * cost;
        });

        var rate = currentExchangeRate();
        var productSubtotal = foreignSubtotal * rate;

        if (foreignSubtotalEl) {
            foreignSubtotalEl.textContent = formatMoney(foreignSubtotal);
        }
        if (productSubtotalEl) {
            productSubtotalEl.textContent = formatMoney(productSubtotal);
        }
        if (totalEl) {
            var shippingFee = shippingFeeInput ? (parseFloat(shippingFeeInput.value) || 0) : 0;
            totalEl.textContent = formatMoney(productSubtotal + shippingFee);
        }
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
            // P7a - a line that already has received stock has its unit cost locked: that value is
            // the authoritative landed-cost input (SO-A1) and product_cost_history froze a
            // snapshot from it at receiving, so changing it would retroactively restate what the
            // received stock cost. readonly (not disabled) so the field still submits its
            // unchanged value; supplier_order_apply_edit() enforces the same rule server-side.
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-cost" name="supplier_price[]" style="width:110px;" value="' + escapeHtml(cost || '0.00') + '"' + (received > 0 ? ' readonly title="Locked - stock already received against this line"' : '') + '></td>' +
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

    // Sets header labels/row visibility to match whatever currency the page already loaded
    // with (e.g. editing an existing foreign-currency order) - addRow() above already ran
    // recalcTotal() per row, so this is just the one-time label/visibility pass.
    applyCurrencyUi();

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

        // BUGFIX - price the picker against the supplier this purchase order is actually for.
        //
        // unit.cost_price is the product's master cost, which is the PREFERRED supplier's price -
        // using it on a PO raised against a different supplier loaded the wrong figure (e.g. RM20
        // from Supplier A on a Supplier B order that should be RM16). unit.supplier_costs carries
        // every supplier's saved price from supplier_products (see
        // supplier_order_picker_products()); this picks the selected supplier's entry and falls
        // back to the existing master cost only when that supplier has nothing on file.
        //
        // Read live on every render so changing the supplier re-prices the picker before anything
        // is added. Rows already added keep the value they were added with - re-pricing them
        // silently would overwrite figures the operator may have typed deliberately.
        var selectedSupplierId = (function () {
            var select = document.querySelector('select[name="supplier_id"]');
            return select && select.value ? String(select.value) : '';
        })();

        function unitCostFor(unit) {
            if (selectedSupplierId && unit.supplier_costs) {
                var entry = unit.supplier_costs[selectedSupplierId];
                if (entry && entry.unit_cost !== null && entry.unit_cost !== undefined && entry.unit_cost !== '') {
                    return entry.unit_cost;
                }
            }
            return unit.cost_price || 0;
        }

        function supplierSkuFor(unit) {
            if (selectedSupplierId && unit.supplier_costs) {
                var entry = unit.supplier_costs[selectedSupplierId];
                if (entry && entry.supplier_sku) {
                    return entry.supplier_sku;
                }
            }
            return unit.supplier_sku;
        }

        // True when the price shown came from the selected supplier's own saved entry, so the
        // picker can say so instead of silently presenting a fallback as if it were theirs.
        function hasSupplierPrice(unit) {
            if (!selectedSupplierId || !unit.supplier_costs) { return false; }
            var entry = unit.supplier_costs[selectedSupplierId];
            return !!(entry && entry.unit_cost !== null && entry.unit_cost !== undefined && entry.unit_cost !== '');
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

            var productUnit = product.units[0] || {};
            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="fw-semibold">' + escapeHtml(product.sku) + ' &mdash; ' + escapeHtml(product.name) +
                (product.catalog_type !== 'variable' && productUnit.supplier_sku ? ' <span class="text-muted small fw-normal">(Supplier SKU: ' + escapeHtml(productUnit.supplier_sku) + ')</span>' : '') +
                '</div>';

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
                        '" data-cost="' + escapeHtml(formatMoney(unitCostFor(unit))) +
                        '" data-moq="' + escapeHtml(unit.moq || '') + '"' + (checked ? ' checked disabled' : '') + '> ' +
                        escapeHtml(unit.label || '(no attributes)') + ' <span class="text-muted small">' + escapeHtml(unit.sku) +
                        (supplierSkuFor(unit) ? ' &middot; Supplier SKU: ' + escapeHtml(supplierSkuFor(unit)) : '') +
                        (hasSupplierPrice(unit) ? ' &middot; <span class="text-success">supplier price</span>' : '') + '</span>' +
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
                    '" data-cost="' + escapeHtml(formatMoney(unitCostFor(unit))) +
                    '" data-moq="' + escapeHtml(unit.moq || '') + '"' + (isAdded ? ' checked disabled' : '') + '> Add this product' +
                    (hasSupplierPrice(unit) ? ' <span class="text-success small">supplier price</span>' : '') +
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

        // Changing the order's supplier re-prices the picker, so the costs shown always belong to
        // the supplier the PO is actually for. Only affects what the picker offers - lines already
        // added keep the values they were added with.
        var orderSupplierSelect = document.querySelector('select[name="supplier_id"]');
        if (orderSupplierSelect) {
            orderSupplierSelect.addEventListener('change', renderPicker);
        }

        document.getElementById('picker-add-selected-btn').addEventListener('click', function () {
            document.querySelectorAll('.picker-unit-checkbox:checked:not(:disabled)').forEach(function (checkbox) {
                // Unit Cost is pre-filled from the selected supplier's saved price when they have
                // one on file, otherwise from the product's cost_price - see unitCostFor(). It
                // stays a plain editable input from here on: editing it only affects this one
                // supplier order line, never products.cost_price or supplier_products. Quantity
                // defaults to the MOQ (addRow() falls back to it when quantity is undefined).
                addRow(checkbox.value, checkbox.dataset.label, checkbox.dataset.sku, undefined, checkbox.dataset.cost || '0.00', 0, checkbox.dataset.moq);
            });
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });
    }

    initProductPicker();
})();
