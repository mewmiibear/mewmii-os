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
        document.querySelectorAll('.variation-stock-cell').forEach(function (cell) {
            var input = cell.querySelector('input');
            cell.classList.toggle('d-none', !isReadyStock);
            if (input) {
                input.disabled = !isReadyStock;
            }
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
            return option.textContent || '';
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
    // Generic "+ Add" modal (brand / category / collection / tag / attribute / value).
    // ---------------------------------------------------------------------------------
    var modalEl = null;
    function ensureModal() {
        if (modalEl) {
            return modalEl;
        }
        modalEl = document.createElement('div');
        modalEl.className = 'add-modal-overlay position-fixed top-0 start-0 w-100 h-100 d-none';
        modalEl.style.background = 'rgba(0,0,0,0.4)';
        modalEl.style.zIndex = '1050';
        modalEl.innerHTML =
            '<div class="add-modal-box bg-white rounded p-4 mx-auto mt-5" style="max-width:420px;">' +
            '<h5 class="add-modal-title mb-3"></h5>' +
            '<div class="add-modal-fields"></div>' +
            '<div class="add-modal-error text-danger small mb-2 d-none"></div>' +
            '<div class="d-flex gap-2 justify-content-end">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm add-modal-cancel">Cancel</button>' +
            '<button type="button" class="btn btn-primary btn-sm add-modal-save">Save</button>' +
            '</div></div>';
        document.body.appendChild(modalEl);
        modalEl.querySelector('.add-modal-cancel').addEventListener('click', closeModal);
        modalEl.addEventListener('click', function (event) {
            if (event.target === modalEl) {
                closeModal();
            }
        });
        return modalEl;
    }

    function closeModal() {
        if (modalEl) {
            modalEl.classList.add('d-none');
        }
    }

    /**
     * config: { title, fields: [{name, label, type: 'text'|'select', options}], onSave: fn(values) }
     * onSave should return a Promise; on success the modal closes, on rejection the
     * error message is shown inline.
     */
    function openAddModal(modalConfig) {
        var modal = ensureModal();
        modal.querySelector('.add-modal-title').textContent = modalConfig.title;
        var fieldsContainer = modal.querySelector('.add-modal-fields');
        fieldsContainer.innerHTML = '';
        var errorBox = modal.querySelector('.add-modal-error');
        errorBox.classList.add('d-none');

        var inputs = {};
        modalConfig.fields.forEach(function (field) {
            var wrap = document.createElement('div');
            wrap.className = 'mb-2';
            var label = document.createElement('label');
            label.className = 'form-label small';
            label.textContent = field.label;
            wrap.appendChild(label);

            var input;
            if (field.type === 'select') {
                input = document.createElement('select');
                input.className = 'form-select form-select-sm';
                var emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = field.emptyLabel || 'None';
                input.appendChild(emptyOption);
                (field.options || []).forEach(function (option) {
                    var opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = option.label;
                    input.appendChild(opt);
                });
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control form-control-sm';
            }
            wrap.appendChild(input);
            fieldsContainer.appendChild(wrap);
            inputs[field.name] = input;
        });

        var saveBtn = modal.querySelector('.add-modal-save');
        var newSaveBtn = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
        newSaveBtn.addEventListener('click', function () {
            var values = {};
            Object.keys(inputs).forEach(function (name) {
                values[name] = inputs[name].value;
            });
            newSaveBtn.disabled = true;
            modalConfig.onSave(values).then(function () {
                newSaveBtn.disabled = false;
                closeModal();
            }).catch(function (error) {
                newSaveBtn.disabled = false;
                errorBox.textContent = error.message || 'Failed to save.';
                errorBox.classList.remove('d-none');
            });
        });

        modal.classList.remove('d-none');
        var firstInput = fieldsContainer.querySelector('input, select');
        if (firstInput) {
            firstInput.focus();
        }
    }

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

    function initAddButtons() {
        document.querySelectorAll('[data-add-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                var kind = button.dataset.addModal;

                if (kind === 'brand') {
                    openAddModal({
                        title: 'Add Brand',
                        fields: [{ name: 'name', label: 'Brand name', type: 'text' }],
                        onSave: function (values) {
                            return postJson(config.urls.createBrand, { name: values.name }).then(function (result) {
                                appendOption(document.getElementById('brand-select'), result.id, result.name);
                            });
                        }
                    });
                } else if (kind === 'collection') {
                    openAddModal({
                        title: 'Add Collection',
                        fields: [{ name: 'name', label: 'Collection name', type: 'text' }],
                        onSave: function (values) {
                            return postJson(config.urls.createCollection, { name: values.name }).then(function (result) {
                                appendOption(document.getElementById('collection-select'), result.id, result.name);
                            });
                        }
                    });
                } else if (kind === 'category') {
                    var categorySelect = document.getElementById('category-select');
                    var parentOptions = [];
                    Array.prototype.forEach.call(categorySelect.options, function (option) {
                        if (option.value !== '') {
                            parentOptions.push({ value: option.value, label: option.textContent });
                        }
                    });
                    openAddModal({
                        title: 'Add Category',
                        fields: [
                            { name: 'name', label: 'Category name', type: 'text' },
                            { name: 'parent_id', label: 'Parent category (optional)', type: 'select', options: parentOptions, emptyLabel: 'Top level' }
                        ],
                        onSave: function (values) {
                            return postJson(config.urls.createCategory, { name: values.name, parent_id: values.parent_id }).then(function (result) {
                                var depth = 0;
                                if (values.parent_id) {
                                    var parentOption = categorySelect.querySelector('option[value="' + values.parent_id + '"]');
                                    depth = parentOption ? (parseInt(parentOption.dataset.depth || '0', 10) + 1) : 1;
                                }
                                appendOption(categorySelect, result.id, '— '.repeat(depth) + result.name, { depth: depth });
                            });
                        }
                    });
                } else if (kind === 'tag') {
                    openAddModal({
                        title: 'Add Tag',
                        fields: [{ name: 'name', label: 'Tag name', type: 'text' }],
                        onSave: function (values) {
                            return postJson(config.urls.createTag, { name: values.name }).then(function (result) {
                                var container = document.getElementById('tags-checkbox-list');
                                var label = document.createElement('label');
                                label.className = 'checkbox-item me-3';
                                label.innerHTML = '<input type="checkbox" name="tag_ids[]" value="' + result.id + '" checked> ' + result.name;
                                container.appendChild(label);
                            });
                        }
                    });
                } else if (kind === 'attribute') {
                    openAddModal({
                        title: 'Add Attribute',
                        fields: [{ name: 'name', label: 'Attribute name (e.g. Character)', type: 'text' }],
                        onSave: function (values) {
                            return postJson(config.urls.createAttribute, { name: values.name }).then(function (result) {
                                attributesById[result.id] = { id: result.id, name: result.name, values: [] };
                                (config.attributes || []).push(attributesById[result.id]);
                                document.querySelectorAll('.attribute-picker').forEach(function (select) {
                                    appendOption(select, result.id, result.name);
                                });
                            });
                        }
                    });
                } else if (kind === 'attribute_value') {
                    var attributeId = parseInt(button.dataset.attributeId, 10);
                    openAddModal({
                        title: 'Add Value',
                        fields: [
                            { name: 'value', label: 'Value (e.g. Cinnamoroll)', type: 'text' },
                            { name: 'code', label: 'Code (e.g. CN) - short inventory prefix for SKUs, optional', type: 'text' }
                        ],
                        onSave: function (values) {
                            return postJson(config.urls.createAttributeValue, { attribute_id: attributeId, value: values.value, code: values.code }).then(function (result) {
                                var attr = attributesById[attributeId];
                                if (attr) {
                                    attr.values.push({ id: result.id, value: result.value, code: result.code });
                                }
                                document.querySelectorAll('.attribute-values-container[data-attribute-id="' + attributeId + '"]').forEach(function (container) {
                                    addValueCheckbox(container, attributeId, result.id, result.value, true, result.code);
                                });
                            });
                        }
                    });
                }
            });
        });
    }

    // ---------------------------------------------------------------------------------
    // Attribute Builder (Variable products): choose attribute -> check values -> repeat.
    // ---------------------------------------------------------------------------------
    var attributeBlockIndex = 0;

    function addValueCheckbox(container, attributeId, valueId, valueLabel, checked, code) {
        var label = document.createElement('label');
        label.className = 'checkbox-item me-3';
        var displayLabel = valueLabel + (code ? ' (' + code + ')' : '');
        label.innerHTML = '<input type="checkbox" class="attribute-value-checkbox" data-attribute-id="' + attributeId + '" value="' + valueId + '"' + (checked ? ' checked' : '') + '> ' + displayLabel;
        container.appendChild(label);
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
            '<div class="d-flex justify-content-between align-items-center mb-1">' +
            '<label class="form-label small mb-0">Values</label>' +
            '<button type="button" class="btn btn-sm btn-link add-value-btn p-0" data-add-modal="attribute_value">+ Add Value</button>' +
            '</div>' +
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
        initAddButtons();

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
            '<td><input type="number" step="0.001" min="0" class="form-control form-control-sm variation-weight"' + fieldName('variation_weight') + ' style="width:90px;" value="' + (options.weight || '') + '"' + readonlyAttr + '></td>' +
            '<td>' +
            '<select class="form-select form-select-sm variation-price-mode"' + fieldName('variation_price_mode') + disabledAttr + '>' +
            '<option value="inherit"' + (options.priceMode !== 'custom' ? ' selected' : '') + '>Follow Product Price</option>' +
            '<option value="custom"' + (options.priceMode === 'custom' ? ' selected' : '') + '>Custom Price</option>' +
            '</select>' +
            '<input type="number" step="0.01" min="0" class="form-control form-control-sm variation-custom-price mt-1' + (options.priceMode === 'custom' ? '' : ' d-none') + '"' + fieldName('variation_custom_price') + ' value="' + (options.customPrice || '') + '"' + readonlyAttr + '>' +
            '</td>' +
            '<td class="variation-image-cell">' + imagePreview +
            (options.canManage ? '<input type="file" class="form-control form-control-sm variation-image-input image-file-input"' + fieldName('variation_image') + ' accept="image/*">' +
                (options.hasOwnImage ? '<label class="small d-block mt-1"><input type="checkbox" class="variation-image-remove"' + fieldName('variation_remove_image') + ' value="1"> Use parent image</label>' : '') : '') +
            '</td>' +
            '<td class="variation-stock-cell"><input type="number" min="0" class="form-control form-control-sm variation-stock"' + fieldName('variation_stock') + ' style="width:80px;" value="' + (options.stock || 0) + '"' + readonlyAttr + '></td>' +
            '<td>' +
            '<select class="form-select form-select-sm variation-status"' + fieldName('variation_status') + disabledAttr + '>' +
            ['draft', 'active', 'inactive'].map(function (statusValue) {
                return '<option value="' + statusValue + '"' + (options.status === statusValue ? ' selected' : '') + '>' + statusValue + '</option>';
            }).join('') +
            '</select>' +
            '</td>' +
            (options.canManage && options.showRowActions ? '<td class="text-end">' + (options.archived ? '<span class="badge bg-secondary">Archived</span>' : '<button type="button" class="btn btn-sm btn-outline-primary save-variation-row me-1">Save</button><button type="button" class="btn btn-sm btn-outline-danger archive-variation-row">Archive</button>') + '</td>' : '');
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
    function renderPreviewTable() {
        var tbody = document.querySelector('#variation-table tbody');
        if (!tbody) {
            return;
        }

        var selections = collectAttributeSelections();
        var combos = cartesianCombinations(selections);

        if (combos.length === 0) {
            showError('Select at least one value for a variation-defining attribute before generating.');
            return;
        }

        tbody.innerHTML = '';
        combos.forEach(function (combo) {
            var signature = comboSignature(combo.map(function (p) { return { attributeId: p.attributeId, valueId: p.valueId }; }));
            var row = document.createElement('tr');
            row.className = 'variation-row';
            row.dataset.signature = signature;
            row.innerHTML = variationRowHtml({
                canManage: true,
                showRowActions: false,
                namePrefix: signature,
                label: comboLabel(combo),
                sku: buildPreviewSku(combo),
                priceMode: 'inherit',
                status: 'draft',
                stock: 0,
                fallbackNote: 'uses parent main image'
            });
            priceModeChangeHandler(row);
            imagePreviewHandler(row);
            tbody.appendChild(row);
        });

        document.getElementById('variation-table-wrapper').classList.remove('d-none');
        initAvailabilityToggle();
    }

    // --- Edit mode: ask the server to generate, then re-render the real table ----------
    function renderServerVariationRow(variation) {
        var row = document.createElement('tr');
        row.className = 'variation-row';
        row.dataset.variationId = variation.id;
        var archived = variation.status === 'archived';
        row.innerHTML = variationRowHtml({
            canManage: true,
            showRowActions: true,
            archived: archived,
            label: variation.label || '(no attributes)',
            sku: variation.sku,
            barcode: variation.barcode,
            weight: variation.weight,
            priceMode: variation.price_mode,
            customPrice: variation.custom_price,
            imagePath: variation.image_path,
            hasOwnImage: !!variation.image_path,
            stock: variation.available_quantity,
            status: variation.status,
            readonly: archived
        });
        priceModeChangeHandler(row);
        imagePreviewHandler(row);
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
        document.getElementById('variation-table-wrapper').classList.remove('d-none');
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
                formData.append('weight', row.querySelector('.variation-weight').value);
                formData.append('price_mode', row.querySelector('.variation-price-mode').value);
                formData.append('custom_price', row.querySelector('.variation-custom-price').value);
                formData.append('stock', row.querySelector('.variation-stock').value);
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
                    window.location.reload();
                }).catch(function (error) {
                    showError(error.message);
                });
            });
        });

        document.querySelectorAll('.archive-variation-row').forEach(function (button) {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                if (!window.confirm('Archive this variation? It will no longer be sellable, but its history is kept.')) {
                    return;
                }
                var row = button.closest('.variation-row');
                postJson(config.urls.archiveVariation, { variation_id: row.dataset.variationId }).then(function () {
                    window.location.reload();
                }).catch(function (error) {
                    showError(error.message);
                });
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
            var stock = document.getElementById('bulk-stock').value;
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
                        row.querySelector('.variation-weight').value = weight;
                    }
                    if (status) {
                        row.querySelector('.variation-status').value = status;
                    }
                    if (stock !== '') {
                        row.querySelector('.variation-stock').value = stock;
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
            if (stock !== '') {
                formData.append('stock', stock);
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
    // Boot.
    // ---------------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initProductTypeToggle();
        initAvailabilityToggle();
        initSaleFields();
        initSearchableSelects();
        initFilterableCheckboxLists();
        initAddButtons();
        initAttributeBuilder();
        initGenerateVariations();
        initBulkActions();
        initGallery();

        if (config.isEdit && (config.variations || []).length > 0) {
            renderServerVariationTable(config.variations);
        }
    });
})();
