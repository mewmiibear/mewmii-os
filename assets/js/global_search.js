// Global Search (Sprint 10 - Operator Experience): debounced live dropdown fed by
// modules/search/ajax_quick.php, with Enter/blur-away/full-results handled by the plain GET
// form itself (modules/search/index.php) - no framework, matching the rest of this app's
// vanilla-JS convention (see assets/js/inventory.js).
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var wrapper = document.getElementById('global-search-wrapper');
        var input = document.getElementById('global-search-input');
        var dropdown = document.getElementById('global-search-dropdown');
        if (!wrapper || !input || !dropdown) {
            return;
        }

        var debounceTimer = null;
        var currentRequest = null;

        function renderResults(sections, term) {
            if (sections.length === 0) {
                dropdown.innerHTML = '<div class="p-3 text-muted small">No results for "' + escapeHtml(term) + '"</div>';
                return;
            }

            var html = '';
            sections.forEach(function (section) {
                html += '<div class="px-3 pt-2 pb-1 text-muted small text-uppercase">' + escapeHtml(section.label) + '</div>';
                section.items.forEach(function (item) {
                    html += '<a class="dropdown-item d-block px-3 py-2" href="' + escapeHtml(item.url) + '">'
                        + '<div>' + escapeHtml(item.title) + '</div>'
                        + (item.subtitle ? '<div class="text-muted small">' + escapeHtml(item.subtitle) + '</div>' : '')
                        + '</a>';
                });
            });
            html += '<a class="d-block px-3 py-2 text-center small border-top" href="/modules/search/index.php?q=' + encodeURIComponent(term) + '">See all results &rarr;</a>';
            dropdown.innerHTML = html;
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        function closeDropdown() {
            dropdown.classList.add('d-none');
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            window.clearTimeout(debounceTimer);

            if (term.length < 2) {
                closeDropdown();
                return;
            }

            debounceTimer = window.setTimeout(function () {
                if (currentRequest) {
                    currentRequest.abort();
                }
                var controller = new AbortController();
                currentRequest = controller;

                fetch('/modules/search/ajax_quick.php?q=' + encodeURIComponent(term), { signal: controller.signal })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        renderResults(data.sections || [], term);
                        dropdown.classList.remove('d-none');
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            closeDropdown();
                        }
                    });
            }, 250);
        });

        input.addEventListener('focus', function () {
            if (dropdown.innerHTML !== '' && input.value.trim().length >= 2) {
                dropdown.classList.remove('d-none');
            }
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                closeDropdown();
            }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDropdown();
            }
        });
    });
})();
