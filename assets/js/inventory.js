/**
 * Vanilla JS for the Inventory page: the Adjust Stock modal (plain form, no fetch - just
 * pre-selects the right product/variation before Bootstrap shows the modal) and the View
 * History modal (lazy-loaded: nothing is fetched until the modal is actually opened, per
 * the "don't load history until asked" requirement). No framework, no build step - matches
 * assets/js/product-form.js's plain fetch() style.
 */
(function () {
    'use strict';

    var historyModalEl = document.getElementById('historyModal');
    var historyState = { productId: null, variationId: 0, page: 1 };

    function openAdjustModal(unitKey) {
        var select = document.getElementById('adjust-unit-key');
        if (select) {
            select.value = unitKey || '';
        }

        var modalEl = document.getElementById('adjustStockModal');
        if (modalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    function openHistoryModal(productId, variationId, label) {
        historyState.productId = productId;
        historyState.variationId = variationId || 0;
        historyState.page = 1;

        var title = document.getElementById('history-modal-title');
        if (title) {
            title.textContent = 'Transaction History' + (label ? ' — ' + label : '');
        }

        if (historyModalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(historyModalEl).show();
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function fetchHistory() {
        if (!historyState.productId) {
            return;
        }

        var body = document.getElementById('history-body');
        var pageInfo = document.getElementById('history-page-info');
        body.innerHTML = '<p class="text-muted">Loading&hellip;</p>';

        var params = new URLSearchParams({
            product_id: historyState.productId,
            variation_id: historyState.variationId,
            page: historyState.page,
            search: document.getElementById('history-search').value || '',
            type: document.getElementById('history-type-filter').value || ''
        });

        fetch('/modules/inventory/ajax/history.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.error) {
                    body.innerHTML = '<p class="text-danger">' + escapeHtml(json.error) + '</p>';
                    return;
                }

                renderTypeFilter(json.types);
                renderRows(json.rows);

                var lastPage = Math.max(1, Math.ceil(json.total / json.page_size));
                pageInfo.textContent = 'Page ' + json.page + ' of ' + lastPage + ' (' + json.total + ' total)';
                document.getElementById('history-prev').disabled = json.page <= 1;
                document.getElementById('history-next').disabled = json.page >= lastPage;
            })
            .catch(function () {
                body.innerHTML = '<p class="text-danger">Failed to load history.</p>';
            });
    }

    function renderTypeFilter(types) {
        var select = document.getElementById('history-type-filter');
        var current = select.value;
        var options = ['<option value="">All transaction types</option>'];
        (types || []).forEach(function (type) {
            options.push('<option value="' + escapeHtml(type) + '">' + escapeHtml(type) + '</option>');
        });
        select.innerHTML = options.join('');
        select.value = current;
    }

    function renderRows(rows) {
        var body = document.getElementById('history-body');

        if (!rows || rows.length === 0) {
            body.innerHTML = '<p class="text-muted">No transactions found.</p>';
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">' +
            '<thead><tr><th>Date &amp; Time</th><th>Type</th><th>Qty</th><th>Balance</th><th>Reference</th><th>Notes</th></tr></thead><tbody>';

        rows.forEach(function (row) {
            var qtyClass = row.quantity > 0 ? 'text-success' : (row.quantity < 0 ? 'text-danger' : '');
            var qtyText = (row.quantity > 0 ? '+' : '') + row.quantity;
            var reference = row.reference_url
                ? '<a href="' + escapeHtml(row.reference_url) + '">' + escapeHtml(row.reference_label) + '</a>'
                : escapeHtml(row.reference_label);
            var notes = [row.reason, row.notes].filter(Boolean).map(escapeHtml).join(' — ');

            html += '<tr>' +
                '<td>' + escapeHtml(row.created_at) + '</td>' +
                '<td>' + escapeHtml(row.transaction_type) + '</td>' +
                '<td class="' + qtyClass + '">' + escapeHtml(qtyText) + '</td>' +
                '<td>' + (row.balance_after === null ? '&mdash;' : escapeHtml(row.balance_after)) + '</td>' +
                '<td>' + reference + '</td>' +
                '<td class="text-muted small">' + (notes || '&mdash;') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        body.innerHTML = html;
    }

    if (historyModalEl) {
        historyModalEl.addEventListener('shown.bs.modal', function () {
            historyState.page = 1;
            fetchHistory();
        });
    }

    var filterBtn = document.getElementById('history-filter-apply');
    if (filterBtn) {
        filterBtn.addEventListener('click', function () {
            historyState.page = 1;
            fetchHistory();
        });
    }

    var prevBtn = document.getElementById('history-prev');
    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (historyState.page > 1) {
                historyState.page -= 1;
                fetchHistory();
            }
        });
    }

    var nextBtn = document.getElementById('history-next');
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            historyState.page += 1;
            fetchHistory();
        });
    }

    window.InventoryUI = {
        openAdjustModal: openAdjustModal,
        openHistoryModal: openHistoryModal
    };
})();
