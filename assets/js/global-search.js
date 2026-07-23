/**
 * Vanilla JS for the header's global search box (Mewmii OS Global Search Phase 3). The only
 * server contact this makes is modules/search/query.php - includes/search.php is never called
 * directly from the browser, and this file never bypasses query.php's own permission checks;
 * it just injects whatever HTML fragment that endpoint decides to return for the current user.
 * No writes, no other endpoint, no framework/build step - matches assets/js/product-form.js.
 */
(function () {
    'use strict';

    var input = document.getElementById('global-search-input');
    var results = document.getElementById('global-search-results');
    var wrapper = document.getElementById('global-search-wrapper');
    if (!input || !results || !wrapper) {
        return;
    }

    var DEBOUNCE_MS = 300;
    var MIN_LENGTH = 2;
    var debounceTimer = null;
    var requestSeq = 0;

    function hideResults() {
        results.style.display = 'none';
        results.innerHTML = '';
    }

    function showResults(html) {
        // html comes straight from modules/search/query.php, which already escapes every
        // dynamic value server-side (app_escape()) - this file never concatenates raw input
        // into HTML itself, it only ever injects that already-safe fragment as-is.
        results.innerHTML = html;
        results.style.display = 'block';
    }

    function runSearch(term) {
        var seq = ++requestSeq;

        fetch('/modules/search/query.php?term=' + encodeURIComponent(term), {
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.ok ? response.text() : '';
            })
            .then(function (html) {
                if (seq !== requestSeq) {
                    return; // superseded by a newer keystroke's request
                }
                if (html.trim() === '') {
                    hideResults();
                    return;
                }
                showResults(html);
            })
            .catch(function () {
                if (seq === requestSeq) {
                    hideResults();
                }
            });
    }

    input.addEventListener('input', function () {
        var term = input.value.trim();

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        if (term.length < MIN_LENGTH) {
            requestSeq++; // invalidate any in-flight request so a stale response can't reappear
            hideResults();
            return;
        }

        debounceTimer = setTimeout(function () {
            runSearch(term);
        }, DEBOUNCE_MS);
    });

    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            hideResults();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hideResults();
            input.blur();
        }
    });
})();
