/**
 * Mewmii OS v2 Phase 2 - the shared Drawer framework (docs/PHASE2_READINESS_REVIEW.md §7,
 * docs/PHASE2_IMPLEMENTATION.md). It knows only a URL, a title, and how to show loading /
 * success / error / close - never what a Product or a Supplier looks like. Each module owns
 * its own content: its own modules/<domain>/ajax/drawer.php (Controller) renders
 * modules/<domain>/views/drawer.php (View) into a ready-to-inject HTML fragment, which this
 * file just fetches and drops into the panel - see docs/PHASE2_IMPLEMENTATION.md for the
 * extension guide.
 *
 * Built on bootstrap.Offcanvas (already loaded via bootstrap.bundle.min.js, includes/
 * footer.php) rather than hand-rolled JS like assets/js/sidebar.js's mobile drawer - Esc,
 * backdrop click, and focus trap all come free from Bootstrap's own component; none of them
 * are reimplemented here.
 */
(function () {
    'use strict';

    var drawerEl = document.getElementById('app-drawer');
    if (!drawerEl) {
        return;
    }

    var bodyEl = document.getElementById('app-drawer-body');
    var titleEl = document.getElementById('app-drawer-title');
    var offcanvas = null;
    var currentRequest = null;
    var lastConfig = null;

    function getInstance() {
        if (!offcanvas && window.bootstrap) {
            offcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
        }
        return offcanvas;
    }

    function renderLoading() {
        bodyEl.innerHTML = '<div class="d-flex justify-content-center p-4">' +
            '<div class="spinner-border text-secondary" role="status">' +
            '<span class="visually-hidden">Loading&hellip;</span></div></div>';
    }

    // The one piece of markup the framework itself owns (see docs/PHASE2_READINESS_REVIEW.md
    // §7): a network failure means the module's own endpoint never got a chance to render its
    // own error content, so there is nothing module-provided to show here.
    function renderError() {
        bodyEl.innerHTML = '<div class="p-3">' +
            '<p class="text-danger small mb-2">Something went wrong loading this.</p>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" id="app-drawer-retry">Retry</button></div>';

        var retryBtn = document.getElementById('app-drawer-retry');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                if (lastConfig) {
                    load(lastConfig);
                }
            });
        }
    }

    function load(config) {
        // Same "abort the in-flight request before starting a new one" convention as
        // assets/js/global_search.js - only the latest open() call can ever land.
        if (currentRequest) {
            currentRequest.abort();
        }

        var controller = new AbortController();
        currentRequest = controller;

        renderLoading();

        fetch(config.url, { credentials: 'same-origin', signal: controller.signal })
            .then(function (response) {
                // Contract: a Drawer endpoint always renders deliberate, displayable HTML for
                // any 2xx or 4xx response (permission denied, not found, bad request - e.g.
                // ajax_require_permission_html(), includes/ajax_helpers.php) - only a network
                // failure (caught below) or a genuine 5xx falls through to the generic
                // fallback, since those are the only cases where the endpoint never got the
                // chance to render its own content.
                if (response.ok || (response.status >= 400 && response.status < 500)) {
                    return response.text().then(function (html) {
                        bodyEl.innerHTML = html;
                    });
                }
                throw new Error('Drawer request failed with status ' + response.status);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                renderError();
            });
    }

    function open(config) {
        if (!config || !config.url) {
            return;
        }

        lastConfig = config;

        if (titleEl) {
            titleEl.textContent = config.title || '';
        }

        var instance = getInstance();
        if (instance) {
            instance.show();
        }

        load(config);
    }

    function close() {
        var instance = getInstance();
        if (instance) {
            instance.hide();
        }
    }

    window.DrawerUI = {
        open: open,
        close: close
    };
})();
