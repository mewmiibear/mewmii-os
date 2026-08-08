/**
 * Vanilla JS for the unified product create/edit page. No framework, no build step.
 * Reads its configuration (CSRF token, mode, lookup lists, existing data, endpoint URLs)
 * from a <script type="application/json" id="product-form-data"> tag the PHP template
 * embeds, and progressively enhances plain HTML that still works (mostly) without JS -
 * every field keeps its normal `name` attribute so a full-page submit still carries
 * everything the server needs.
 *
 * Edit mode: attributes/variations/images are persisted immediately via the AJAX
 * endpoints in config.urls. Create mode: the product doesn't exist yet, so attribute
 * selection and "Generate Variations" build a client-side preview table only - nothing
 * variation-related is persisted until the single main form submit, which the server
 * re-derives authoritatively via the existing variation_generate_combinations().
 */
(function () {
    'use strict';

    var configEl = document.getElementById('product-form-data');
    if (!configEl) {
        return;
    }
    var config = JSON.parse(configEl.textContent || '{}');
    var attributesById = {};
    (config.attributes || []).forEach(function (attr) {
        attributesById[attr.id] = attr;
    });

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function csrfField() {
        return config.csrfToken || '';
    }

    function postJson(url, data) {
        var body = new FormData();
        body.append('csrf_token', csrfField());
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    body.append(key + '[]', v);
                });
            } else if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) {
                        throw new Error(json.error || 'Request failed.');
                    }
                    return json;
                });
            });
    }

    function postFormData(url, formData) {
        formData.append('csrf_token', csrfField());

        return fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) {
                        throw new Error(json.error || 'Request failed.');
                    }
                    return json;
                });
            });
    }

    function showError(message) {
        window.alert(message);
    }

    // ---------------------------------------------------------------------------------
    // Product Type (Simple / Variable) and Availability Type (Ready Stock / Preorder /
    // Early Bird) together control which fields show. Both toggles are driven by ONE
    // shared apply function reading both controls fresh each time - two independent
    // functions each unconditionally forcing the same "d-none" class on a field that
    // depends on both conditions (e.g. the simple product's Available Stock field) would
    // fight each other and undo one another's decision depending on call order.
    // ---------------------------------------------------------------------------------
    function applyFieldVisibility() {
        var catalogChecked = document.querySelector('input[name="catalog_type"]:checked');
        var isVariable = !!catalogChecked && catalogChecked.value === 'variable';

        var availabilitySelect = document.getElementById('availability-type');
        var isReadyStock = !availabilitySelect || availabilitySelect.value === 'ready_stock';

        document.querySelectorAll('.js-variable-section').forEach(function (el) {
            el.classList.toggle('d-none', !isVariable);
        });
        document.querySelectorAll('.js-simple-section').forEach(function (el) {
            el.classList.toggle('d-none', isVariable || !isReadyStock);
        });
        document.querySelectorAll('.js-stock-ready').forEach(function (el) {
            el.classList.toggle('d-none', !isReadyStock || isVariable);
        });
        document.querySelectorAll('.js-stock-preorder').forEach(function (el) {
            el.classList.toggle('d-none', isReadyStock);
        });
    }

    function initProductTypeToggle() {
        var radios = document.querySelectorAll('input[name="catalog_type"]');
        radios.forEach(function (radio) {
            radio.addEventListener('change', applyFieldVisibility);
        });
        applyFieldVisibility();
    }

    function initAvailabilityToggle() {
        var select = document.getElementById('availability-type');
        if (!select) {
            applyFieldVisibility();
            return;
        }

        function apply() {
            applyFieldVisibility();
        }

        // Switching TO Early Bird auto-enables Sale (Early Bird pricing is meaningless
        // without it) - but switching away never auto-disables it, so an admin can still
        // leave a sale event running independently. Only reacts to an actual change, never
        // forced on page load, so an existing product's current sale_enabled value is
        // never silently overridden just by opening the edit form.
        select.addEventListener('change', function () {
            if (select.value === 'early_bird') {
                var enableSale = document.getElementById('enable-sale');
                if (enableSale && !enableSale.checked) {
                    enableSale.checked = true;
                    enableSale.dispatchEvent(new Event('change'));
                }
            }
        });

        select.addEventListener('change', apply);
        apply();
    }

    // ---------------------------------------------------------------------------------
    // Enable Sale toggle (Early Bird pricing fields) + "Product has expiry date" toggle -
    // two independent checkboxes, since expiry is a separate concept from sale pricing and
    // must never be merged with it.
    // ---------------------------------------------------------------------------------
    function initSaleFields() {
        var enableSale = document.getElementById('enable-sale');
        if (enableSale) {
            var applySale = function () {
                document.querySelectorAll('.js-sale-fields').forEach(function (el) {
                    el.classList.toggle('d-none', !enableSale.checked);
                });
            };
            enableSale.addEventListener('change', applySale);
            applySale();
        }

        var hasExpiry = document.getElementById('has-expiry-checkbox');
        if (hasExpiry) {
            var applyExpiry = function () {
                document.querySelectorAll('.js-expiry-fields').forEach(function (el) {
                    el.classList.toggle('d-none', !hasExpiry.checked);
                });
            };
            hasExpiry.addEventListener('change', applyExpiry);
            applyExpiry();
        }
    }

    // ---------------------------------------------------------------------------------
    // Searchable select: overlays a filter text input on an existing <select>, which
    // stays in the DOM under its original name - no server-side change needed.
    // ---------------------------------------------------------------------------------
    function makeSearchableSelect(select) {
        if (!select || select.dataset.searchableInit) {
            return;
        }
        select.dataset.searchableInit = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'searchable-select position-relative';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('d-none');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.className.replace('d-none', '').trim() || 'form-control';
        input.placeholder = 'Search...';
        wrapper.appendChild(input);

        var list = document.createElement('div');
        list.className = 'searchable-select-list list-group position-absolute w-100 d-none';
        list.style.zIndex = '20';
        list.style.maxHeight = '220px';
        list.style.overflowY = 'auto';
        wrapper.appendChild(list);

        function optionLabel(option) {
            // Trimmed because this label is written into input.value, and an <input> preserves
            // whitespace verbatim where a native <select> trims it for display. The Category
            // options are the only ones authored across multiple lines - _form.php formats them
            // that way for the str_repeat('&mdash; ') depth prefix - so their textContent carries
            // ~41 characters of leading indentation. Untrimmed, that pushed the selected category
            // far to the right in the field and read as centred, while Brand and Collection
            // (single-line options, zero leading whitespace) looked correct.
            //
            // Trimming here rather than reflowing the markup keeps the widget's behaviour
            // equivalent to a native <select> for ANY option, and is a no-op for options that
            // were already clean. Every consumer - currentLabel(), the filter comparison, the
            // list item text and input.value - goes through this one function.
            return (option.textContent || '').trim();
        }

        function currentLabel() {
            var option = select.options[select.selectedIndex];
            return option ? optionLabel(option) : '';
        }

        function renderList(filterText) {
            list.innerHTML = '';
            var needle = (filterText || '').toLowerCase();
            var any = false;
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.value === '' || optionLabel(option).toLowerCase().indexOf(needle) === -1) {
                    return;
                }
                any = true;
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action py-1';
                item.textContent = optionLabel(option);
                item.style.paddingLeft = (12 + (parseInt(option.dataset.depth || '0', 10) * 16)) + 'px';
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    select.value = option.value;
                    select.dispatchEvent(new Event('change'));
                    input.value = optionLabel(option);
                    list.classList.add('d-none');
                });
                list.appendChild(item);
            });
            list.classList.toggle('d-none', !any);
        }

        input.value = currentLabel();
        input.addEventListener('focus', function () {
            renderList(input.value === currentLabel() ? '' : input.value);
        });
        input.addEventListener('input', function () {
            renderList(input.value);
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                input.value = currentLabel();
                list.classList.add('d-none');
            }, 150);
        });

        select.addEventListener('optionsChanged', function () {
            input.value = currentLabel();
        });
    }

    function initSearchableSelects(root) {
        (root || document).querySelectorAll('select[data-searchable="1"]').forEach(makeSearchableSelect);
    }

    // ---------------------------------------------------------------------------------
    // Filterable checkbox list: a type-to-filter box above a long checkbox list.
    // ---------------------------------------------------------------------------------
    function makeFilterableCheckboxList(container) {
        if (!container || container.dataset.filterInit) {
            return;
        }
        container.dataset.filterInit = '1';

        var labels = container.querySelectorAll('label.checkbox-item');
        if (labels.length < 8) {
            return;
        }

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm mb-2';
        input.placeholder = 'Filter...';
        container.insertBefore(input, container.firstChild);

        input.addEventListener('input', function () {
            var needle = input.value.toLowerCase();
            labels.forEach(function (label) {
                label.classList.toggle('d-none', label.textContent.toLowerCase().indexOf(needle) === -1);
            });
        });
    }

    function initFilterableCheckboxLists(root) {
        (root || document).querySelectorAll('[data-filterable-checkboxes="1"]').forEach(makeFilterableCheckboxList);
    }

    // ---------------------------------------------------------------------------------
    // Catalog Management (modules/attributes, modules/{categories,brands,collections,tags})
    // is now the single source of truth for brands/categories/collections/tags/attributes/
    // attribute values - this form only ever SELECTS from what's already there via the
    // searchable <select>s below, it never creates a new one. (Previously this section held
    // an inline "+ Add" modal that POSTed to modules/products/ajax/create_*.php; both the
    // modal and those endpoints are gone - manage catalog metadata from its own page instead.)
    // ---------------------------------------------------------------------------------

    function appendOption(select, value, label, extra) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        if (extra && extra.depth) {
            option.dataset.depth = String(extra.depth);
        }
        select.appendChild(option);
        select.value = value;
        select.dispatchEvent(new Event('optionsChanged'));
        select.dispatchEvent(new Event('change'));
    }

    // ---------------------------------------------------------------------------------
    // Attribute Builder (Variable products): choose attribute -> check values -> repeat.
    // ---------------------------------------------------------------------------------
    var attributeBlockIndex = 0;

    // Each value gets its own editable SKU prefix (product_attribute_values.code) right
    // next to its checkbox - see catalog_attribute_value_sku_code() for how it's used to
    // build variation SKUs. Saved on blur via a dedicated endpoint, not the main form
    // submit, since the value is global/shared across every product using this attribute.
    function addValueCheckbox(container, attributeId, valueId, valueLabel, checked, code) {
        var wrap = document.createElement('span');
        wrap.className = 'd-inline-flex align-items-center gap-1 me-3 mb-1';

        var label = document.createElement('label');
        label.className = 'checkbox-item mb-0';
        label.innerHTML = '<input type="checkbox" class="attribute-value-checkbox" data-attribute-id="' + attributeId + '" value="' + valueId + '"' + (checked ? ' checked' : '') + '> ' + escapeHtml(valueLabel);
        wrap.appendChild(label);

        var codeInput = document.createElement('input');
        codeInput.type = 'text';
        codeInput.className = 'form-control form-control-sm';
        codeInput.style.width = '56px';
        codeInput.maxLength = 5;
        codeInput.placeholder = 'Prefix';
        codeInput.title = 'SKU prefix (e.g. CN)';
        codeInput.value = code || '';
        codeInput.addEventListener('input', function () {
            codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5);
        });
        codeInput.addEventListener('blur', function () {
            var newCode = codeInput.value;
            if (newCode === (code || '')) {
                return;
            }
            postJson(config.urls.updateAttributeValue, { value_id: valueId, code: newCode }).then(function (result) {
                code = result.code;
                var attr = attributesById[attributeId];
                var found = attr && (attr.values || []).filter(function (v) { return v.id === valueId; })[0];
                if (found) {
                    found.code = result.code;
                }
            }).catch(function (error) {
                codeInput.value = code || '';
                showError(error.message);
            });
        });
        wrap.appendChild(codeInput);

        container.appendChild(wrap);
    }

    function renderAttributeValues(block, attributeId, checkedValueIds) {
        var container = block.querySelector('.attribute-values-container');
        container.innerHTML = '';
        container.dataset.attributeId = String(attributeId);
        var attr = attributesById[attributeId];
        if (!attr) {
            return;
        }
        (attr.values || []).forEach(function (value) {
            addValueCheckbox(container, attributeId, value.id, value.value, (checkedValueIds || []).indexOf(value.id) !== -1, value.code);
        });
        var addValueBtn = block.querySelector('.add-value-btn');
        if (addValueBtn) {
            addValueBtn.dataset.attributeId = String(attributeId);
        }
    }

    function addAttributeBlock(preselectAttributeId, checkedValueIds, isVariationFlag) {
        var container = document.getElementById('attribute-builder-blocks');
        if (!container) {
            return null;
        }

        var blockId = 'attr-block-' + (attributeBlockIndex++);
        var block = document.createElement('div');
        block.className = 'attribute-block border rounded p-3 mb-3';
        block.id = blockId;
        block.innerHTML =
            '<div class="d-flex justify-content-between align-items-start mb-2">' +
            '<div class="flex-grow-1 me-3">' +
            '<label class="form-label small mb-1">Attribute</label>' +
            '<select class="form-select form-select-sm attribute-picker" data-searchable="1"></select>' +
            '</div>' +
            '<div class="form-check mt-4">' +
            '<input type="checkbox" class="form-check-input attribute-is-variation" checked>' +
            '<label class="form-check-label small">Defines variations</label>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger ms-2 remove-attribute-block">&times;</button>' +
            '</div>' +
            '<label class="form-label small mb-1">Values</label>' +
            '<div class="attribute-values-container" data-filterable-checkboxes="1"></div>';
        container.appendChild(block);

        var picker = block.querySelector('.attribute-picker');
        appendOption(picker, '', 'Choose attribute…');
        picker.value = '';
        (config.attributes || []).forEach(function (attr) {
            var option = document.createElement('option');
            option.value = attr.id;
            option.textContent = attr.name;
            picker.appendChild(option);
        });

        if (preselectAttributeId) {
            picker.value = String(preselectAttributeId);
            renderAttributeValues(block, preselectAttributeId, checkedValueIds || []);
        }

        picker.addEventListener('change', function () {
            renderAttributeValues(block, parseInt(picker.value, 10) || 0, []);
        });

        if (isVariationFlag === false) {
            block.querySelector('.attribute-is-variation').checked = false;
        }

        block.querySelector('.remove-attribute-block').addEventListener('click', function () {
            block.remove();
        });

        makeSearchableSelect(picker);
        initFilterableCheckboxLists(block);

        return block;
    }

    function collectAttributeSelections() {
        var selections = [];
        document.querySelectorAll('.attribute-block').forEach(function (block) {
            var picker = block.querySelector('.attribute-picker');
            var attributeId = parseInt(picker.value, 10);
            if (!attributeId) {
                return;
            }
            var valueIds = [];
            block.querySelectorAll('.attribute-value-checkbox:checked').forEach(function (checkbox) {
                valueIds.push(parseInt(checkbox.value, 10));
            });
            if (valueIds.length === 0) {
                return;
            }
            selections.push({
                attributeId: attributeId,
                attributeName: attributesById[attributeId] ? attributesById[attributeId].name : '',
                isVariation: block.querySelector('.attribute-is-variation').checked,
                valueIds: valueIds,
                values: (attributesById[attributeId].values || []).filter(function (v) {
                    return valueIds.indexOf(v.id) !== -1;
                })
            });
        });
        return selections;
    }

    function initAttributeBuilder() {
        var addBtn = document.getElementById('add-attribute-block-btn');
        if (!addBtn) {
            return;
        }
        addBtn.addEventListener('click', function () {
            addAttributeBlock();
        });

        (config.existingAssignments || []).forEach(function (assignment) {
            addAttributeBlock(assignment.attributeId, assignment.valueIds, assignment.isVariation);
        });

        if ((config.existingAssignments || []).length === 0) {
            addAttributeBlock();
        }
    }

    // ---------------------------------------------------------------------------------
    // Variation combination signature - must match includes/product_variations.php's
    // own signature format exactly (sorted "attributeId:valueId" pairs joined by "|").
    // ---------------------------------------------------------------------------------
    // ---------------------------------------------------------------------------------
    // Unsaved variation-row edits (EDIT mode).
    //
    // A variation row's inputs carry no name attribute - fieldName() only emits one when
    // namePrefix is set, which is the CREATE preview path. So these fields are not part of the
    // product form and the page's own "Save Changes" cannot and does not submit them; each row
    // persists only through its own Save button, which posts to save_variation.php.
    //
    // Nothing was ever silently discarded by the server, but nothing told the operator either:
    // typing a new SKU and pressing the page's Save looked like a save and wasn't. This marks the
    // row instead, so the state is visible rather than assumed. The architecture is unchanged -
    // the row's Save button remains the only thing that persists a row.
    // ---------------------------------------------------------------------------------
    // Set immediately before a save-triggered reload so the unload guard does not challenge our
    // own navigation - the edits are being persisted, not abandoned.
    //
    // Dirtiness itself is deliberately NOT mirrored in a counter: the row's own .is-dirty class is
    // the single source of truth, so a re-render or a deleted row cannot leave a tally out of step
    // with what is actually on screen.
    var suppressUnloadGuard = false;

    function markVariationRowDirty(row) {
        if (!row || row.classList.contains('is-dirty')) {
            return;
        }
        row.classList.add('is-dirty');

        var actions = row.lastElementChild;
        if (actions && !actions.querySelector('.variation-dirty-flag')) {
            var flag = document.createElement('span');
            flag.className = 'variation-dirty-flag badge bg-warning-subtle text-warning-emphasis border border-warning-subtle me-1';
            flag.textContent = 'Unsaved';
            flag.title = 'This row has unsaved changes. Use its Save button.';
            actions.insertBefore(flag, actions.firstChild);
        }
    }

    function clearVariationRowDirty(row) {
        if (!row || !row.classList.contains('is-dirty')) {
            return;
        }
        row.classList.remove('is-dirty');
        var flag = row.querySelector('.variation-dirty-flag');
        if (flag) {
            flag.remove();
        }
    }

    function hasDirtyVariationRows() {
        return document.querySelectorAll('.variation-row.is-dirty').length > 0;
    }

    /**
     * Delegated so it survives the table being re-rendered, and bound once. Only edit-mode rows
     * qualify: a create-mode preview row has no variationId and its inputs DO submit with the
     * form, so it is not "unsaved" in this sense.
     */
    function initVariationRowDirtyTracking() {
        if (!config.isEdit) {
            return;
        }
        var table = document.getElementById('variation-table');
        if (!table) {
            return;
        }
        var onEdit = function (event) {
            var row = event.target.closest('.variation-row');
            if (!row || !row.dataset.variationId) {
                return;
            }
            // The row's own action buttons are not edits.
            if (event.target.closest('button')) {
                return;
            }
            markVariationRowDirty(row);
        };
        table.addEventListener('input', onEdit);
        table.addEventListener('change', onEdit);

        // Leaving the page with unsaved row edits.
        window.addEventListener('beforeunload', function (event) {
            if (suppressUnloadGuard || !hasDirtyVariationRows()) {
                return;
            }
            event.preventDefault();
            // Browsers show their own wording; returnValue is still required by some.
            event.returnValue = '';
        });
    }

    /**
     * The page's Save Changes must not read as though it saved the rows too.
     *
     * It never did save them, and it still doesn't - this only makes that explicit at the moment
     * it matters, because submitting reloads the page and would discard the typed values. The
     * product's own fields save exactly as before if the operator continues.
     */
    function initVariationDirtySubmitGuard() {
        if (!config.isEdit) {
            return;
        }
        var form = document.getElementById('product-form');
        if (!form) {
            return;
        }
        var RESUBMIT = 'variationDirtyAcknowledged';

        form.addEventListener('submit', function (event) {
            if (form.dataset[RESUBMIT] === '1' || !hasDirtyVariationRows()) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();

            var count = document.querySelectorAll('.variation-row.is-dirty').length;
            var message = count === 1
                ? 'One variation row has unsaved changes. Saving the product will not save that row - use the row\'s own Save button first.'
                : count + ' variation rows have unsaved changes. Saving the product will not save them - use each row\'s own Save button first.';

            // Option names match confirm-dialog.js's contract exactly: body (not message) and
            // label (not confirmLabel). It renders bodyEl.hidden = !options.body, so a wrong key
            // yields a dialog with no explanation at all.
            var ask = (window.ConfirmUI && window.ConfirmUI.confirm)
                ? window.ConfirmUI.confirm({
                    title: 'Unsaved variation changes',
                    body: message,
                    label: 'Save product anyway',
                    cancelLabel: 'Go back',
                    tone: 'warning'
                })
                : Promise.resolve(window.confirm(message));

            ask.then(function (proceed) {
                if (!proceed) {
                    return;
                }
                form.dataset[RESUBMIT] = '1';
                suppressUnloadGuard = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    }

    // Combinations the operator removed from the CREATE preview table, by signature.
    //
    // Kept as module state rather than read back off the DOM because the preview table is
    // re-rendered from scratch every time the attribute selection changes - reading the DOM would
    // lose every removal on the next render, which is exactly the "it came back" bug this is
    // meant to prevent. Signatures survive re-renders; rows do not.
    //
    // Create mode only. Edit mode never populates this and never sends the field.
    var removedPreviewSignatures = {};

    function isPreviewCombinationRemoved(signature) {
        return removedPreviewSignatures[signature] === true;
    }

    function syncExcludedCombinationsField() {
        var field = document.getElementById('excluded-combinations-field');
        if (!field) {
            return;
        }
        field.value = JSON.stringify(Object.keys(removedPreviewSignatures).filter(isPreviewCombinationRemoved));
    }

    function comboSignature(comboParts) {
        var parts = comboParts.map(function (part) {
            return part.attributeId + ':' + part.valueId;
        });
        parts.sort();
        return parts.join('|');
    }

    function cartesianCombinations(selections) {
        var variationSelections = selections.filter(function (s) {
            return s.isVariation;
        });
        var combos = [[]];
        variationSelections.forEach(function (selection) {
            var next = [];
            combos.forEach(function (combo) {
                selection.values.forEach(function (value) {
                    next.push(combo.concat([{
                        attributeId: selection.attributeId,
                        attributeName: selection.attributeName,
                        valueId: value.id,
                        value: value.value,
                        code: value.code
                    }]));
                });
            });
            combos = next;
        });
        return combos;
    }

    function comboLabel(combo) {
        return combo.map(function (part) {
            return part.value;
        }).join(' / ');
    }

    function slugForSku(text) {
        return (text || '').toUpperCase().replace(/[^A-Z0-9]+/g, '') || 'X';
    }

    // Mirrors includes/product_variations.php's catalog_attribute_value_sku_code(): a
    // value's explicit code if set, else a short 3-char prefix auto-derived from its name -
    // never the full customer-facing value name.
    function skuCodeForValue(part) {
        if (part.code && String(part.code).trim() !== '') {
            return slugForSku(part.code);
        }
        return slugForSku(part.value).substring(0, 3) || 'X';
    }

    function buildPreviewSku(combo) {
        var parts = combo.map(function (part) {
            return skuCodeForValue(part);
        });
        return (config.parentSku || 'SKU') + '-' + parts.join('-');
    }

    // ---------------------------------------------------------------------------------
    // Variation table rendering.
    // ---------------------------------------------------------------------------------
    /**
     * options.namePrefix, when set (create mode only), gives every input a real `name`
     * attribute keyed by the combination signature - e.g. name="variation_sku[3:7|4:9]" -
     * so the preview table's values are actually submitted with the main form (it has no
     * server round-trip of its own during creation, unlike the edit-mode table whose
     * inputs are read directly by JS and never need a `name`). The signature format must
     * match comboSignature() exactly, since the server re-derives the same signature from
     * variation_generate_combinations() to match posted edits back onto the rows it creates.
     */
    function variationRowHtml(options) {
        var readonlyAttr = options.readonly ? ' readonly' : '';
        var disabledAttr = options.readonly ? ' disabled' : '';
        var imagePreview = options.imagePath
            ? '<img src="/' + options.imagePath + '" alt="" style="max-width:50px;max-height:50px;" class="border rounded d-block mb-1">'
            : '<div class="text-muted small mb-1">' + (options.fallbackNote || 'no image') + '</div>';

        function fieldName(field) {
            return options.namePrefix ? (' name="' + field + '[' + options.namePrefix + ']"') : '';
        }

        return '' +
            '<td>' + (options.canManage ? '<input type="checkbox" class="form-check-input variation-select">' : '') + '</td>' +
            '<td>' + options.label + '</td>' +
            '<td><input type="text" class="form-control form-control-sm variation-sku"' + fieldName('variation_sku') + ' value="' + options.sku + '"' + readonlyAttr + '></td>' +
            '<td><input type="text" class="form-control form-control-sm variation-barcode"' + fieldName('variation_barcode') + ' value="' + (options.barcode || '') + '"' + readonlyAttr + '></td>' +
            '<td><input type="text" class="form-control form-control-sm variation-supplier-sku" placeholder="Parent SKU"' + fieldName('variation_supplier_sku') + ' style="width:110px;" value="' + (options.supplierSku || '') + '"' + readonlyAttr + '>' +
            '<div class="text-muted small">Blank = use parent SKU</div>' +
            '</td>' +
            '<td>' +
            '<select class="form-select form-select-sm variation-weight-mode"' + fieldName('variation_weight_mode') + disabledAttr + '>' +
            '<option value="inherit"' + (options.weightMode !== 'custom' ? ' selected' : '') + '>Follow Product Weight</option>' +
            '<option value="custom"' + (options.weightMode === 'custom' ? ' selected' : '') + '>Custom Weight</option>' +
            '</select>' +
            '<input type="number" step="0.001" min="0" class="form-control form-control-sm variation-weight mt-1' + (options.weightMode === 'custom' ? '' : ' d-none') + '" placeholder="grams"' + fieldName('variation_weight') + ' style="width:90px;" value="' + (options.weight || '') + '"' + readonlyAttr + '>' +
            '</td>' +
            '<td>' +
            '<select class="form-select form-select-sm variation-price-mode"' + fieldName('variation_price_mode') + disabledAttr + '>' +
            '<option value="inherit"' + (options.priceMode !== 'custom' ? ' selected' : '') + '>Follow Product Price</option>' +
            '<option value="custom"' + (options.priceMode === 'custom' ? ' selected' : '') + '>Custom Price</option>' +
            '</select>' +
            '<input type="number" step="0.01" min="0" class="form-control form-control-sm variation-custom-price mt-1' + (options.priceMode === 'custom' ? '' : ' d-none') + '"' + fieldName('variation_custom_price') + ' value="' + (options.customPrice || '') + '"' + readonlyAttr + '>' +
            '</td>' +
            '<td>' +
            '<input type="number" step="0.01" min="0" class="form-control form-control-sm variation-cost-price" placeholder="Parent cost"' + fieldName('variation_cost_price') + ' style="width:100px;" value="' + (options.costPrice !== null && options.costPrice !== undefined ? options.costPrice : '') + '"' + readonlyAttr + '>' +
            '<div class="text-muted small">Blank = use parent cost</div>' +
            '</td>' +
            '<td class="variation-image-cell">' + imagePreview +
            (options.canManage ? '<input type="file" class="form-control form-control-sm variation-image-input image-file-input"' + fieldName('variation_image') + ' accept="image/*">' +
                (options.hasOwnImage ? '<label class="small d-block mt-1"><input type="checkbox" class="variation-image-remove"' + fieldName('variation_remove_image') + ' value="1"> Use parent image</label>' : '') : '') +
            (options.canManage && options.showRowActions ? '<button type="button" class="btn btn-sm btn-outline-secondary variation-gallery-btn mt-1" data-variation-id="' + options.variationId + '">Gallery</button>' : '') +
            '</td>' +
            // Inventory quantities are managed in the Inventory module, not here - this is a
            // read-only at-a-glance indicator only (see options.stockStatus, computed from
            // available_quantity by the caller).
            '<td class="variation-stock-cell">' + (options.stockStatus === 'in_stock' ? '<span class="text-success">&#128994; In Stock</span>' : '<span class="text-danger">&#128308; Out of Stock</span>') + '</td>' +
            '<td>' +
            '<select class="form-select form-select-sm variation-status"' + fieldName('variation_status') + disabledAttr + '>' +
            ['draft', 'active', 'inactive'].map(function (statusValue) {
                return '<option value="' + statusValue + '"' + (options.status === statusValue ? ' selected' : '') + '>' + statusValue + '</option>';
            }).join('') +
            '</select>' +
            '</td>' +
            // Row actions differ by mode because the rows are different things. On EDIT these are
            // persisted variations, so the actions hit the server (save / edit attrs / delete or
            // archive). On CREATE they are unsaved preview combinations with no variation_id, so
            // the only action is to drop the combination before it is ever generated - handled
            // entirely in the browser plus one hidden field. See removePreviewCombination().
            (options.canManage && options.showRowActions
                ? '<td class="text-end">' + (options.archived ? '<span class="badge bg-secondary">Archived</span>' : '<button type="button" class="btn btn-sm btn-outline-primary save-variation-row me-1">Save</button><button type="button" class="btn btn-sm btn-outline-secondary edit-variation-attributes-btn me-1" data-variation-id="' + options.variationId + '">Edit Attrs</button><button type="button" class="btn btn-sm btn-outline-danger delete-variation-row">Delete</button>') + '</td>'
                : (options.canManage && options.showPreviewRemove
                    ? '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-preview-combination" title="Remove this combination" aria-label="Remove this combination">Remove</button></td>'
                    : '<td></td>'));
    }

    function priceModeChangeHandler(row) {
        var select = row.querySelector('.variation-price-mode');
        var customPriceInput = row.querySelector('.variation-custom-price');
        if (select && customPriceInput) {
            select.addEventListener('change', function () {
                customPriceInput.classList.toggle('d-none', select.value !== 'custom');
            });
        }
    }

    // Phase 9E (Product Weight & Variation SKU Logic) - same shape as priceModeChangeHandler
    // above, for the new "Follow Product Weight" / "Custom Weight" toggle.
    function weightModeChangeHandler(row) {
        var select = row.querySelector('.variation-weight-mode');
        var weightInput = row.querySelector('.variation-weight');
        if (select && weightInput) {
            select.addEventListener('change', function () {
                weightInput.classList.toggle('d-none', select.value !== 'custom');
            });
        }
    }

    // ---------------------------------------------------------------------------------
    // UI redesign pass: purely cosmetic per-row coloring on the existing .variation-status
    // <select> (still the same field, same name/options, still fully editable) - matches
    // assets/css/product-form.css's .is-status-* rules. Never touches the select's value.
    // ---------------------------------------------------------------------------------
    function statusBadgeHandler(row) {
        var select = row.querySelector('.variation-status');
        if (!select) {
            return;
        }
        function applyStatusClass() {
            select.classList.remove('is-status-active', 'is-status-inactive');
            if (select.value === 'active') {
                select.classList.add('is-status-active');
            } else if (select.value === 'inactive') {
                select.classList.add('is-status-inactive');
            }
        }
        select.addEventListener('change', applyStatusClass);
        applyStatusClass();
    }

    function imagePreviewHandler(row) {
        var fileInput = row.querySelector('.variation-image-input');
        if (!fileInput) {
            return;
        }
        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) {
                return;
            }
            var cell = row.querySelector('.variation-image-cell');
            var existingImg = cell.querySelector('img');
            var url = URL.createObjectURL(fileInput.files[0]);
            if (existingImg) {
                existingImg.src = url;
            } else {
                var img = document.createElement('img');
                img.src = url;
                img.style.maxWidth = '50px';
                img.style.maxHeight = '50px';
                img.className = 'border rounded d-block mb-1';
                cell.insertBefore(img, cell.firstChild);
            }
        });
    }

    // --- Create mode: build the preview table entirely client-side ---------------------
    /**
     * options.restoreOnly (Sprint 11): when true, an empty result set is not treated as a
     * user error - used for the automatic post-error restore call below, where the
     * attribute blocks were just rebuilt from existingAssignments and haven't necessarily
     * been touched by the user yet on this exact page load.
     */
    function renderPreviewTable(options) {
        var restoreOnly = !!(options && options.restoreOnly);
        var tbody = document.querySelector('#variation-table tbody');
        if (!tbody) {
            return;
        }

        var selections = collectAttributeSelections();
        var combos = cartesianCombinations(selections);

        if (combos.length === 0) {
            if (!restoreOnly) {
                showError('Select at least one value for a variation-defining attribute before generating.');
            }
            return;
        }

        // Sprint 11: restores the previously-POSTed preview-table edits (SKU/barcode/price/
        // etc, keyed by combination signature) after a failed product-creation submit,
        // instead of resetting every row back to its auto-generated default - see
        // modules/products/create.php's restore block and the config.previewFieldOverrides
        // it feeds into _form.php's JSON config.
        var overrides = config.previewFieldOverrides || {};
        function overrideFor(field, signature) {
            var bucket = overrides[field];
            return bucket && bucket[signature] !== undefined ? bucket[signature] : null;
        }

        tbody.innerHTML = '';
        combos.forEach(function (combo) {
            var signature = comboSignature(combo.map(function (p) { return { attributeId: p.attributeId, valueId: p.valueId }; }));

            // A removed combination stays removed across re-renders. Skipping it here (rather
            // than deleting the row afterwards) is what makes the removal survive the operator
            // touching the attribute selection again.
            if (isPreviewCombinationRemoved(signature)) {
                return;
            }

            var row = document.createElement('tr');
            row.className = 'variation-row';
            row.dataset.signature = signature;
            row.innerHTML = variationRowHtml({
                canManage: true,
                showRowActions: false,
                showPreviewRemove: true,
                namePrefix: signature,
                label: comboLabel(combo),
                sku: overrideFor('variation_sku', signature) || buildPreviewSku(combo),
                barcode: overrideFor('variation_barcode', signature) || '',
                supplierSku: overrideFor('variation_supplier_sku', signature) || '',
                weight: overrideFor('variation_weight', signature) || '',
                weightMode: overrideFor('variation_weight_mode', signature) || 'inherit',
                priceMode: overrideFor('variation_price_mode', signature) || 'inherit',
                customPrice: overrideFor('variation_custom_price', signature) || '',
                costPrice: overrideFor('variation_cost_price', signature),
                // UX pass: new variations default to Active - matches
                // variation_generate_combinations()'s new default in includes/product_
                // variations.php. Only affects the client-side preview for a row that
                // hasn't been saved yet; an existing variation's own status is always
                // read from the server (see renderServerVariationRow() below), never
                // defaulted here.
                status: overrideFor('variation_status', signature) || 'active',
                stockStatus: 'out_of_stock',
                fallbackNote: 'uses parent main image'
            });
            priceModeChangeHandler(row);
            weightModeChangeHandler(row);
            imagePreviewHandler(row);
            statusBadgeHandler(row);
            tbody.appendChild(row);
        });

        // How many of the CURRENT selection's combinations are being withheld. Counted against
        // combos rather than the whole removed set, because a signature for a value the operator
        // has since deselected is not relevant to what is on screen now.
        var hiddenNow = combos.filter(function (combo) {
            return isPreviewCombinationRemoved(comboSignature(combo.map(function (p) {
                return { attributeId: p.attributeId, valueId: p.valueId };
            })));
        }).length;

        renderRemovedCombinationsNote(hiddenNow);
        syncExcludedCombinationsField();

        var variationTableWrapper = document.getElementById('variation-table-wrapper');
        if (variationTableWrapper) {
            variationTableWrapper.classList.remove('d-none');
        }
        initAvailabilityToggle();
    }

    /**
     * Footer note under the preview table saying how many combinations are being withheld, with a
     * way to put them back.
     *
     * Without this, removing a row by mistake is unrecoverable short of reloading the page and
     * losing every other preview edit - the removal deliberately survives re-renders, so nothing
     * else would bring it back.
     */
    function renderRemovedCombinationsNote(hiddenCount) {
        var wrapper = document.getElementById('variation-table-wrapper');
        if (!wrapper) {
            return;
        }
        var note = document.getElementById('removed-combinations-note');

        if (hiddenCount === 0) {
            if (note) {
                note.remove();
            }
            return;
        }

        if (!note) {
            note = document.createElement('div');
            note.id = 'removed-combinations-note';
            note.className = 'form-text mt-2';
            wrapper.appendChild(note);
        }
        note.textContent = hiddenCount + (hiddenCount === 1 ? ' combination is' : ' combinations are')
            + ' removed and will not be created. ';

        var restore = document.createElement('button');
        restore.type = 'button';
        restore.className = 'btn btn-link btn-sm p-0 align-baseline';
        restore.id = 'restore-removed-combinations';
        restore.textContent = 'Restore all';
        restore.addEventListener('click', function () {
            removedPreviewSignatures = {};
            renderPreviewTable({ restoreOnly: true });
        });
        note.appendChild(restore);
    }

    // --- Edit mode: ask the server to generate, then re-render the real table ----------
    function renderServerVariationRow(variation) {
        var row = document.createElement('tr');
        row.className = 'variation-row';
        row.dataset.variationId = variation.id;
        // Phase 9I (Manual Variation Management) - stashed here so the Edit Attrs modal can
        // pre-fill without a second server round-trip; variation.attribute_values comes from
        // includes/product_variations.php's variation_list_for_product() (one batched query
        // for the whole table, not one per row).
        row.dataset.attributeValues = JSON.stringify(variation.attribute_values || {});
        var archived = variation.status === 'archived';
        row.innerHTML = variationRowHtml({
            canManage: true,
            showRowActions: true,
            archived: archived,
            variationId: variation.id,
            label: variation.label || '(no attributes)',
            sku: variation.sku,
            barcode: variation.barcode,
            supplierSku: variation.supplier_sku,
            weight: variation.weight,
            weightMode: variation.weight_mode,
            priceMode: variation.price_mode,
            customPrice: variation.custom_price,
            costPrice: variation.cost_price,
            imagePath: variation.image_path,
            hasOwnImage: !!variation.image_path,
            // Server-computed (see modules/products/edit.php) - never derived from
            // available_quantity here, since Preorder/Early Bird availability depends on
            // the parent product's lifecycle state/override, not raw quantity.
            stockStatus: variation.is_available ? 'in_stock' : 'out_of_stock',
            status: variation.status,
            readonly: archived
        });
        priceModeChangeHandler(row);
        weightModeChangeHandler(row);
        imagePreviewHandler(row);
        statusBadgeHandler(row);
        return row;
    }

    function renderServerVariationTable(variations) {
        var tbody = document.querySelector('#variation-table tbody');
        if (!tbody) {
            return;
        }
        tbody.innerHTML = '';
        variations.forEach(function (variation) {
            tbody.appendChild(renderServerVariationRow(variation));
        });
        var variationTableWrapper = document.getElementById('variation-table-wrapper');
        if (variationTableWrapper) {
            variationTableWrapper.classList.remove('d-none');
        }
        initAvailabilityToggle();
        attachRowActionHandlers();
    }

    function attachRowActionHandlers() {
        document.querySelectorAll('.save-variation-row').forEach(function (button) {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                var row = button.closest('.variation-row');
                var variationId = row.dataset.variationId;
                var formData = new FormData();
                formData.append('product_id', config.productId);
                formData.append('variation_id', variationId);
                formData.append('sku', row.querySelector('.variation-sku').value);
                formData.append('barcode', row.querySelector('.variation-barcode').value);
                formData.append('supplier_sku', row.querySelector('.variation-supplier-sku').value);
                formData.append('weight', row.querySelector('.variation-weight').value);
                formData.append('weight_mode', row.querySelector('.variation-weight-mode').value);
                formData.append('price_mode', row.querySelector('.variation-price-mode').value);
                formData.append('custom_price', row.querySelector('.variation-custom-price').value);
                formData.append('cost_price', row.querySelector('.variation-cost-price').value);
                formData.append('status', row.querySelector('.variation-status').value);
                var imageInput = row.querySelector('.variation-image-input');
                if (imageInput && imageInput.files && imageInput.files[0]) {
                    formData.append('variation_image', imageInput.files[0]);
                }
                var removeCheckbox = row.querySelector('.variation-image-remove');
                if (removeCheckbox && removeCheckbox.checked) {
                    formData.append('remove_image', '1');
                }

                postFormData(config.urls.saveVariation, formData).then(function () {
                    // Persisted, so no longer dirty. Cleared before the reload so the unload
                    // guard does not challenge our own navigation.
                    clearVariationRowDirty(row);
                    suppressUnloadGuard = true;
                    window.location.reload();
                }).catch(function (error) {
                    // Deliberately left dirty - the edit never reached the server.
                    showError(error.message);
                });
            });
        });

        document.querySelectorAll('.delete-variation-row').forEach(function (button) {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                // Phase 9I (Manual Variation Management) - the server decides deleted vs
                // archived depending on whether real history exists (see
                // variation_delete_or_archive()) - this is no longer a "might get blocked"
                // action, so the confirm just describes both possible outcomes upfront.
                // Programmatic path: this posts JSON rather than submitting a form, so there is
                // no submit event for the declarative path to intercept. Danger tone because the
                // outcome may be a permanent delete - the body states both possible outcomes,
                // since the server decides which one applies.
                var row = button.closest('.variation-row');
                window.ConfirmUI.confirm({
                    title: 'Delete this variation?',
                    body: 'With no order, inventory or supplier history it is removed permanently. If it has history it is archived instead, so those records are preserved.',
                    label: 'Delete variation',
                    tone: 'danger'
                }).then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    return postJson(config.urls.deleteVariation, { variation_id: row.dataset.variationId }).then(function () {
                        window.location.reload();
                    }).catch(function (error) {
                        showError(error.message);
                    });
                });
            });
        });

        document.querySelectorAll('.variation-gallery-btn').forEach(function (button) {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                openVariationGalleryModal(parseInt(button.dataset.variationId, 10));
            });
        });

        document.querySelectorAll('.edit-variation-attributes-btn').forEach(function (button) {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                var row = button.closest('.variation-row');
                var currentValues = {};
                try {
                    currentValues = JSON.parse(row.dataset.attributeValues || '{}');
                } catch (e) {
                    currentValues = {};
                }
                openEditVariationAttributesModal(parseInt(row.dataset.variationId, 10), currentValues);
            });
        });
    }

    function initGenerateVariations() {
        var button = document.getElementById('generate-variations-btn');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var selections = collectAttributeSelections();
            if (selections.filter(function (s) { return s.isVariation; }).length === 0) {
                showError('Choose at least one attribute (with values) marked "Defines variations" first.');
                return;
            }

            if (!config.isEdit) {
                renderPreviewTable();
                return;
            }

            postJson(config.urls.saveAttributes, {
                product_id: config.productId,
                selections: JSON.stringify(selections.map(function (s) {
                    return { attribute_id: s.attributeId, is_variation_attribute: s.isVariation, value_ids: s.valueIds };
                }))
            }).then(function () {
                return postJson(config.urls.generateVariations, { product_id: config.productId });
            }).then(function (result) {
                renderServerVariationTable(result.variations || []);
            }).catch(function (error) {
                showError(error.message);
            });
        });
    }

    /**
     * Sprint 11 root-cause fix: create mode builds its variation preview entirely
     * client-side (no server round-trip - see renderPreviewTable() above) and the only
     * thing that ever needs to reach the server is the final main-form submit. That submit
     * previously carried every variation_* field (they have real `name`s) but never the
     * attribute/value selections themselves - modules/products/create.php expects a JSON
     * blob in `attribute_selections`, which nothing ever populated, so creating a new
     * variable product failed 100% of the time with "Select at least one attribute..." and
     * wiped the whole Attribute Builder + preview table on the reload that followed. This
     * fills that hidden field immediately before the browser's native submit proceeds.
     * Edit mode persists attribute selections immediately via the saveAttributes AJAX call
     * in initGenerateVariations() instead, and never reads this field.
     */
    /**
     * Removing a preview combination on the CREATE page.
     *
     * Delegated from the table because the preview tbody is rebuilt on every attribute change -
     * per-row listeners would be discarded with the rows they were bound to. Purely local: no
     * request is made, delete_variation.php is never called, and there is no variation_id to send
     * because nothing has been persisted yet.
     */
    function initPreviewCombinationRemoval() {
        if (config.isEdit) {
            return;
        }
        var table = document.getElementById('variation-table');
        if (!table) {
            return;
        }
        table.addEventListener('click', function (event) {
            var button = event.target.closest('.remove-preview-combination');
            if (!button) {
                return;
            }
            var row = button.closest('.variation-row');
            if (!row || !row.dataset.signature) {
                return;
            }
            removedPreviewSignatures[row.dataset.signature] = true;
            // Re-render rather than just dropping the node, so the withheld-count note and the
            // hidden field are both recalculated from the same single source of truth.
            renderPreviewTable({ restoreOnly: true });
        });
    }

    function initFormSubmitSync() {
        if (config.isEdit) {
            return;
        }
        var form = document.getElementById('product-form');
        var field = document.getElementById('attribute-selections-field');
        if (!form || !field) {
            return;
        }

        form.addEventListener('submit', function () {
            var catalogChecked = document.querySelector('input[name="catalog_type"]:checked');
            if (!catalogChecked || catalogChecked.value !== 'variable') {
                return;
            }

            var selections = collectAttributeSelections();
            field.value = JSON.stringify(selections.map(function (s) {
                return { attribute_id: s.attributeId, is_variation_attribute: s.isVariation, value_ids: s.valueIds };
            }));

            // Sent alongside the selections so the server generates every combination EXCEPT
            // these. Written here as well as on render because the form can be submitted without
            // a re-render happening in between.
            syncExcludedCombinationsField();
        });
    }

    // ---------------------------------------------------------------------------------
    // Bulk actions.
    // ---------------------------------------------------------------------------------
    function initBulkActions() {
        var applyBtn = document.getElementById('bulk-apply-btn');
        if (!applyBtn) {
            return;
        }

        applyBtn.addEventListener('click', function () {
            var selectedRows = Array.prototype.filter.call(document.querySelectorAll('.variation-row'), function (row) {
                var checkbox = row.querySelector('.variation-select');
                return checkbox && checkbox.checked;
            });
            if (selectedRows.length === 0) {
                showError('Select at least one variation first.');
                return;
            }

            var priceMode = document.getElementById('bulk-price-mode').value;
            var customPrice = document.getElementById('bulk-custom-price').value;
            var weight = document.getElementById('bulk-weight').value;
            var status = document.getElementById('bulk-status').value;
            var clearBarcode = document.getElementById('bulk-clear-barcode').checked;
            var imageFile = document.getElementById('bulk-image').files[0];

            if (!config.isEdit) {
                selectedRows.forEach(function (row) {
                    if (priceMode) {
                        row.querySelector('.variation-price-mode').value = priceMode;
                        row.querySelector('.variation-price-mode').dispatchEvent(new Event('change'));
                    }
                    if (priceMode === 'custom' && customPrice !== '') {
                        row.querySelector('.variation-custom-price').value = customPrice;
                    }
                    if (weight !== '') {
                        // A bulk-typed weight is always an explicit override - matches
                        // variation_bulk_apply()'s server-side behavior for edit mode below.
                        row.querySelector('.variation-weight').value = weight;
                        row.querySelector('.variation-weight-mode').value = 'custom';
                        row.querySelector('.variation-weight-mode').dispatchEvent(new Event('change'));
                    }
                    if (status) {
                        row.querySelector('.variation-status').value = status;
                        row.querySelector('.variation-status').dispatchEvent(new Event('change'));
                    }
                    if (clearBarcode) {
                        row.querySelector('.variation-barcode').value = '';
                    }
                    if (imageFile) {
                        var cell = row.querySelector('.variation-image-cell');
                        var img = cell.querySelector('img');
                        var url = URL.createObjectURL(imageFile);
                        if (img) {
                            img.src = url;
                        }
                    }
                });
                return;
            }

            var variationIds = selectedRows.map(function (row) {
                return row.dataset.variationId;
            });

            var formData = new FormData();
            variationIds.forEach(function (id) {
                formData.append('variation_ids[]', id);
            });
            formData.append('product_id', config.productId);
            if (priceMode) {
                formData.append('price_mode', priceMode);
                formData.append('custom_price', customPrice);
            }
            if (weight !== '') {
                formData.append('weight', weight);
            }
            if (status) {
                formData.append('status', status);
            }
            if (clearBarcode) {
                formData.append('clear_barcode', '1');
            }
            if (imageFile) {
                formData.append('image', imageFile);
            }

            postFormData(config.urls.bulkVariationAction, formData).then(function () {
                window.location.reload();
            }).catch(function (error) {
                showError(error.message);
            });
        });
    }

    // ---------------------------------------------------------------------------------
    // Gallery: drag-and-drop reorder + delete (edit mode; AJAX). Preview-only in create
    // mode (files just sit in the native multi-file input until the main form submits).
    // ---------------------------------------------------------------------------------
    function initGallery() {
        var container = document.getElementById('gallery-container');
        if (!container) {
            return;
        }

        var dragged = null;
        container.querySelectorAll('.gallery-item').forEach(function (item) {
            item.addEventListener('dragstart', function () {
                dragged = item;
                item.classList.add('opacity-50');
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('opacity-50');
                if (config.isEdit) {
                    persistGalleryOrder();
                }
            });
            item.addEventListener('dragover', function (event) {
                event.preventDefault();
            });
            item.addEventListener('drop', function (event) {
                event.preventDefault();
                if (dragged && dragged !== item) {
                    var items = Array.prototype.slice.call(container.querySelectorAll('.gallery-item'));
                    var draggedIndex = items.indexOf(dragged);
                    var targetIndex = items.indexOf(item);
                    if (draggedIndex < targetIndex) {
                        item.parentNode.insertBefore(dragged, item.nextSibling);
                    } else {
                        item.parentNode.insertBefore(dragged, item);
                    }
                }
            });
        });

        function persistGalleryOrder() {
            var formData = new FormData();
            formData.append('product_id', config.productId);
            Array.prototype.forEach.call(container.querySelectorAll('.gallery-item'), function (item, index) {
                formData.append('sort_order[' + item.dataset.imageId + ']', index);
            });
            postFormData(config.urls.updateGallery, formData).catch(function (error) {
                showError(error.message);
            });
        }

        container.querySelectorAll('.gallery-delete').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (!checkbox.checked) {
                    return;
                }
                if (!config.isEdit) {
                    checkbox.closest('.gallery-item').classList.add('d-none');
                    return;
                }
                var formData = new FormData();
                formData.append('product_id', config.productId);
                formData.append('delete_ids[]', checkbox.value);
                postFormData(config.urls.updateGallery, formData).then(function () {
                    checkbox.closest('.gallery-item').remove();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        });

        var addGalleryInput = document.getElementById('gallery-add-input');
        if (addGalleryInput && config.isEdit) {
            addGalleryInput.addEventListener('change', function () {
                if (!addGalleryInput.files || addGalleryInput.files.length === 0) {
                    return;
                }
                var formData = new FormData();
                formData.append('product_id', config.productId);
                Array.prototype.forEach.call(addGalleryInput.files, function (file) {
                    formData.append('gallery_images[]', file);
                });
                postFormData(config.urls.addGalleryImages, formData).then(function () {
                    window.location.reload();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        }

        var mainImageInput = document.getElementById('main-image-input');
        if (mainImageInput && config.isEdit) {
            mainImageInput.addEventListener('change', function () {
                if (!mainImageInput.files || !mainImageInput.files[0]) {
                    return;
                }
                var formData = new FormData();
                formData.append('product_id', config.productId);
                formData.append('main_image', mainImageInput.files[0]);
                postFormData(config.urls.uploadMainImage, formData).then(function () {
                    window.location.reload();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        }

        document.querySelectorAll('.image-file-input').forEach(function (input) {
            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) {
                    return;
                }
                var preview = input.parentElement.querySelector('img');
                var url = URL.createObjectURL(input.files[0]);
                if (preview) {
                    preview.src = url;
                }
            });
        });
    }

    // ---------------------------------------------------------------------------------
    // UI redesign pass: modern "drop files here or click to upload" presentation for the
    // Main Image / Gallery inputs. In every case the real <input type="file"> from the
    // markup above is reused unchanged (same name/id/accept) and simply stretched to cover
    // the styled box (see .pf-dropzone in assets/css/product-form.css), so native OS
    // drag-and-drop and click-to-browse both work on the real input with zero new upload
    // logic - these functions only add the visual drag-over highlight and, for fields that
    // don't already have a live preview (a brand-new product's Main Image, and Gallery in
    // create mode where files just sit in the input until the real multipart submit), a
    // local object-URL preview of what's about to be uploaded.
    // ---------------------------------------------------------------------------------
    function initDropzoneHighlight() {
        document.querySelectorAll('.pf-dropzone').forEach(function (zone) {
            ['dragenter', 'dragover'].forEach(function (evt) {
                zone.addEventListener(evt, function () {
                    zone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (evt) {
                zone.addEventListener(evt, function () {
                    zone.classList.remove('is-dragover');
                });
            });
        });
    }

    function initMainImageDropzonePreview() {
        var zone = document.getElementById('pf-main-image-dropzone');
        var input = document.getElementById('main-image-input');
        if (!zone || !input) {
            return;
        }
        // Additive - does not replace the existing generic `.image-file-input` listener
        // above (which already sets the <img>'s src on change). This only toggles the
        // has-image class so the dropzone hint disappears once a preview is showing.
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                zone.classList.add('has-image');
            }
        });
    }

    function initGalleryDropzonePreview() {
        // Edit mode already uploads via AJAX on change and reloads the page (see
        // initGallery() above), which then shows the real, server-rendered thumbnail - a
        // local preview here would only flash briefly before that reload. Create mode has
        // no such upload-on-select step (the files just sit in the input until the main
        // form submits), so this is the only place a create-mode gallery pick gets any
        // preview at all.
        if (config.isEdit) {
            return;
        }
        var input = document.getElementById('gallery-add-input');
        var container = document.getElementById('gallery-container');
        if (!input || !container) {
            return;
        }

        function render() {
            Array.prototype.slice.call(container.querySelectorAll('.pf-gallery-pending')).forEach(function (el) {
                el.remove();
            });

            Array.prototype.forEach.call(input.files || [], function (file, index) {
                var item = document.createElement('div');
                item.className = 'gallery-item pf-gallery-pending border rounded p-2 text-center';

                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = '';
                item.appendChild(img);

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-outline-danger d-block w-100';
                removeBtn.textContent = 'Remove';
                removeBtn.addEventListener('click', function () {
                    // A native file input's FileList can't be edited in place - rebuild it
                    // via DataTransfer with every file except this one, so the picked-but-
                    // not-yet-submitted selection can still be trimmed before Create Product.
                    var dt = new DataTransfer();
                    Array.prototype.forEach.call(input.files, function (f, i) {
                        if (i !== index) {
                            dt.items.add(f);
                        }
                    });
                    input.files = dt.files;
                    render();
                });
                item.appendChild(removeBtn);

                container.appendChild(item);
            });
        }

        input.addEventListener('change', render);
    }

    // ---------------------------------------------------------------------------------
    // UI redesign pass: the compact Publish card's Availability readout is a plain <span>,
    // not a form field (the real, validated Product Availability Type <select> stays put in
    // Basic Information) - this just mirrors its current label so the summary card doesn't
    // go stale if the admin changes it further up the page before saving.
    // ---------------------------------------------------------------------------------
    function initPublishCardSync() {
        var availabilitySelect = document.getElementById('availability-type');
        var readout = document.getElementById('pf-availability-readout');
        if (!availabilitySelect || !readout) {
            return;
        }
        availabilitySelect.addEventListener('change', function () {
            var selected = availabilitySelect.options[availabilitySelect.selectedIndex];
            readout.textContent = selected ? selected.textContent : readout.textContent;
        });
    }

    // ---------------------------------------------------------------------------------
    // Variation Gallery modal: lazy-loaded (fetched only when opened, mirroring the
    // Inventory page's History modal), lets staff add/remove close-up/angle/packaging
    // photos for one variation - separate from its single Main Image, which is still
    // managed inline in the variation row via the existing file input.
    // ---------------------------------------------------------------------------------
    var galleryModalState = { variationId: null };

    function renderVariationGalleryImages(images) {
        var container = document.getElementById('variation-gallery-modal-images');
        if (!container) {
            return;
        }
        if (!images || images.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">No gallery images yet.</p>';
            return;
        }
        container.innerHTML = images.map(function (image) {
            return '<div class="gallery-item border rounded p-2 text-center" style="width:110px;" data-image-id="' + image.id + '">' +
                '<img src="/' + escapeHtml(image.image_path) + '" alt="" style="max-width:90px;max-height:90px;" class="mb-1">' +
                '<button type="button" class="btn btn-sm btn-outline-danger d-block w-100 variation-gallery-delete-btn" data-image-id="' + image.id + '">Delete</button>' +
                '</div>';
        }).join('');

        container.querySelectorAll('.variation-gallery-delete-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var formData = new FormData();
                formData.append('variation_id', galleryModalState.variationId);
                formData.append('delete_ids[]', button.dataset.imageId);
                postFormData(config.urls.updateVariationGallery, formData).then(function () {
                    loadVariationGalleryImages();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        });
    }

    function loadVariationGalleryImages() {
        var container = document.getElementById('variation-gallery-modal-images');
        if (container) {
            window.LoadingUI.placeholder(container);
        }
        fetch(config.urls.getVariationImages + '?variation_id=' + galleryModalState.variationId, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                renderVariationGalleryImages(json.gallery || []);
            })
            .catch(function () {
                if (container) {
                    container.innerHTML = '<p class="text-danger small mb-0">Failed to load images.</p>';
                }
            });
    }

    function openVariationGalleryModal(variationId) {
        galleryModalState.variationId = variationId;
        var modalEl = document.getElementById('variationGalleryModal');
        if (modalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        loadVariationGalleryImages();
    }

    function initVariationGalleryModal() {
        var addInput = document.getElementById('variation-gallery-add-input');
        if (!addInput) {
            return;
        }
        addInput.addEventListener('change', function () {
            if (!addInput.files || addInput.files.length === 0) {
                return;
            }
            var formData = new FormData();
            formData.append('product_id', config.productId);
            formData.append('variation_id', galleryModalState.variationId);
            Array.prototype.forEach.call(addInput.files, function (file) {
                formData.append('gallery_images[]', file);
            });
            postFormData(config.urls.addVariationGalleryImages, formData).then(function () {
                addInput.value = '';
                loadVariationGalleryImages();
            }).catch(function (error) {
                showError(error.message);
            });
        });
    }

    // ---------------------------------------------------------------------------------
    // Phase 9I (Manual Variation Management) - "Add Variation Manually" and "Edit
    // Attributes" both need the same one-select-per-attribute picker (pick exactly one
    // value per variation-defining attribute, or "— None —" to leave that attribute unset),
    // so it's built once here and reused by both modals rather than duplicated.
    // ---------------------------------------------------------------------------------
    /**
     * Attributes the Edit Attributes modal should offer for a variation.
     *
     * The product's assigned variation attributes, PLUS any attribute the variation itself
     * actually carries. The union matters: a variation's attribute values live in
     * product_variation_attribute_values and are independent of the product's current
     * assignments, so the two can legitimately diverge - e.g. an attribute was un-marked as
     * "defines variations" after variations already existed.
     *
     * Before the union, such an attribute still appeared in the row's label (built by
     * variation_build_label() straight from the variation's own rows) but had no select in the
     * modal - visible, uneditable, and silently preserved on every save. Including it is what
     * makes it manageable, and setting it to "None" is what removes it.
     *
     * currentValues is optional; without it this returns the product-assigned set exactly as
     * before, which is what the "Add Variation Manually" modal wants - a brand-new variation has
     * no attributes of its own to union in.
     */
    function variationDefiningAttributes(currentValues) {
        var wantedIds = {};
        (config.existingAssignments || []).forEach(function (assignment) {
            if (assignment.isVariation) {
                wantedIds[assignment.attributeId] = true;
            }
        });

        var assignedIds = Object.assign({}, wantedIds);
        Object.keys(currentValues || {}).forEach(function (attributeId) {
            wantedIds[attributeId] = true;
        });

        return (config.attributes || [])
            .filter(function (attr) {
                return !!wantedIds[attr.id];
            })
            .map(function (attr) {
                // Flagged so the modal can say why an attribute is offered even though the
                // product no longer treats it as variation-defining.
                return Object.assign({}, attr, { isUnassigned: !assignedIds[attr.id] });
            });
    }

    function buildAttributeValueSelects(container, currentValues) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        var attrs = variationDefiningAttributes(currentValues);
        if (attrs.length === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">Add at least one attribute (marked "Defines variations") above first.</p>';
            return;
        }
        attrs.forEach(function (attr) {
            var wrapper = document.createElement('div');
            wrapper.className = 'mb-2';
            var label = document.createElement('label');
            label.className = 'form-label small mb-1';
            label.textContent = attr.name;
            if (attr.isUnassigned) {
                var hint = document.createElement('span');
                hint.className = 'text-muted fw-normal';
                hint.textContent = ' - no longer a variation attribute on this product';
                label.appendChild(hint);
            }
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm manual-variation-attribute-select';
            select.dataset.attributeId = attr.id;
            var noneOption = document.createElement('option');
            noneOption.value = '';
            noneOption.textContent = '— None —';
            select.appendChild(noneOption);
            (attr.values || []).forEach(function (value) {
                var option = document.createElement('option');
                option.value = value.id;
                option.textContent = value.value;
                if (currentValues && currentValues[attr.id] !== undefined && String(currentValues[attr.id]) === String(value.id)) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
            wrapper.appendChild(label);
            wrapper.appendChild(select);
            container.appendChild(wrapper);
        });
    }

    function collectAttributeValueSelects(container) {
        var map = {};
        if (!container) {
            return map;
        }
        container.querySelectorAll('.manual-variation-attribute-select').forEach(function (select) {
            if (select.value) {
                map[select.dataset.attributeId] = select.value;
            }
        });
        return map;
    }

    function initAddVariationManual() {
        var openBtn = document.getElementById('add-variation-manual-btn');
        var modalEl = document.getElementById('addVariationManualModal');
        if (!openBtn || !modalEl) {
            return;
        }
        var container = document.getElementById('add-variation-attribute-selects');
        var weightModeSelect = document.getElementById('add-variation-weight-mode');
        var weightInput = document.getElementById('add-variation-weight');
        if (weightModeSelect && weightInput) {
            weightModeSelect.addEventListener('change', function () {
                weightInput.classList.toggle('d-none', weightModeSelect.value !== 'custom');
            });
        }

        openBtn.addEventListener('click', function () {
            buildAttributeValueSelects(container, {});
            document.getElementById('add-variation-barcode').value = '';
            document.getElementById('add-variation-supplier-sku').value = '';
            if (weightModeSelect) {
                weightModeSelect.value = 'inherit';
            }
            if (weightInput) {
                weightInput.value = '';
                weightInput.classList.add('d-none');
            }
            document.getElementById('add-variation-status').value = 'active';
            if (window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        var submitBtn = document.getElementById('add-variation-manual-submit-btn');
        if (submitBtn) {
            submitBtn.addEventListener('click', function () {
                var attributeValues = collectAttributeValueSelects(container);
                if (Object.keys(attributeValues).length === 0) {
                    showError('Select at least one attribute value for the new variation.');
                    return;
                }
                postJson(config.urls.addVariationManual, {
                    product_id: config.productId,
                    attribute_values: JSON.stringify(attributeValues),
                    barcode: document.getElementById('add-variation-barcode').value,
                    supplier_sku: document.getElementById('add-variation-supplier-sku').value,
                    weight_mode: weightModeSelect ? weightModeSelect.value : 'inherit',
                    weight: weightInput ? weightInput.value : '',
                    status: document.getElementById('add-variation-status').value
                }).then(function () {
                    window.location.reload();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        }
    }

    var editAttributesModalState = { variationId: null };

    function openEditVariationAttributesModal(variationId, currentValues) {
        editAttributesModalState.variationId = variationId;
        var container = document.getElementById('edit-variation-attribute-selects');
        buildAttributeValueSelects(container, currentValues || {});
        var modalEl = document.getElementById('editVariationAttributesModal');
        if (modalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    function initEditVariationAttributesModal() {
        var submitBtn = document.getElementById('edit-variation-attributes-submit-btn');
        if (!submitBtn) {
            return;
        }
        submitBtn.addEventListener('click', function () {
            var container = document.getElementById('edit-variation-attribute-selects');
            var attributeValues = collectAttributeValueSelects(container);
            postJson(config.urls.updateVariationAttributes, {
                product_id: config.productId,
                variation_id: editAttributesModalState.variationId,
                attribute_values: JSON.stringify(attributeValues)
            }).then(function () {
                window.location.reload();
            }).catch(function (error) {
                showError(error.message);
            });
        });
    }

    // ---------------------------------------------------------------------------------
    // Boot.
    // ---------------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initProductTypeToggle();
        initAvailabilityToggle();
        initSaleFields();
        initSearchableSelects();
        initFilterableCheckboxLists();
        initAttributeBuilder();
        initGenerateVariations();
        initFormSubmitSync();
        initPreviewCombinationRemoval();
        initVariationRowDirtyTracking();
        initVariationDirtySubmitGuard();
        initBulkActions();
        initGallery();
        initDropzoneHighlight();
        initMainImageDropzonePreview();
        initGalleryDropzonePreview();
        initPublishCardSync();
        initVariationGalleryModal();
        initAddVariationManual();
        initEditVariationAttributesModal();

        if (config.isEdit && (config.variations || []).length > 0) {
            renderServerVariationTable(config.variations);
        }

        // Sprint 11: create mode, right after a failed submit for a variable product -
        // initAttributeBuilder() above already rebuilt the attribute blocks from
        // config.existingAssignments, so re-run the same preview build the user triggered
        // before submitting, restoring their edited SKU/barcode/price/etc via
        // config.previewFieldOverrides instead of leaving the table empty.
        if (!config.isEdit && config.previewFieldOverrides && Object.keys(config.previewFieldOverrides).length > 0) {
            renderPreviewTable({ restoreOnly: true });
        }
    });
})();
