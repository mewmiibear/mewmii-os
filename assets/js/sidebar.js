/**
 * Mobile sidebar drawer (Responsive Improvement pass). Below the 992px breakpoint, the same
 * <aside id="app-sidebar" class="sidebar"> element already rendered by includes/header.php
 * becomes a fixed off-canvas drawer (see the @media (max-width: 991.98px) block in that
 * file's <style>) - this file only toggles the .is-open class that transitions it in/out of
 * view, plus the backdrop behind it. Nothing about the sidebar's own links, permissions, or
 * markup changes; at >=992px this drawer CSS doesn't apply at all, so none of this has any
 * effect on desktop.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggleBtn = document.getElementById('sidebar-toggle-btn');
        var sidebar = document.getElementById('app-sidebar');
        var backdrop = document.getElementById('sidebar-backdrop');

        if (!toggleBtn || !sidebar || !backdrop) {
            return;
        }

        function openSidebar() {
            sidebar.classList.add('is-open');
            backdrop.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        toggleBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('is-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        backdrop.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        // Navigating via a sidebar link should close the drawer first - the browser
        // navigation itself will tear this page down anyway, this just avoids a jarring
        // "still open over the old page" flash during that navigation.
        sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
            link.addEventListener('click', closeSidebar);
        });

        // If the viewport crosses back over the desktop breakpoint while the drawer is open
        // (window resize, tablet rotation), drop the open/overflow-hidden state so desktop's
        // static sidebar is never left mid-transition or the page stuck non-scrollable.
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992) {
                closeSidebar();
            }
        });
    });
})();
