/**
 * Active-filter chips (V3 Phase 3.6b, Option A - DISPLAY ONLY).
 *
 * Renders a read-only summary of which filters are currently applied, inside the page's own
 * .filter-card. It shows state; it never changes it. There is no per-chip removal, no URL is
 * built or rewritten, no form is submitted, and the page's existing Clear / Clear filters /
 * Clear quick filters controls are left exactly as they are.
 *
 * Opt-in per page: <div class="card filter-card" data-filter-chips="1">. Pages without the
 * attribute are untouched, which is what keeps the 3.6b-1 pilot to three pages.
 *
 * WHY THIS READS THE RENDERED FORM rather than taking data from PHP: a chip needs a human label
 * ("Category") and a human value ("Large Plush"), but the page only knows category_id=7. Passing
 * a label map from each page would mean editing all 24 filter pages. The rendered form already
 * contains both - every one of the 87 filter controls across those 24 pages has a paired <label>,
 * and a <select> carries the display text of the chosen option. So the form is the single source
 * for both halves of a chip and no per-page data is required.
 *
 * The form's values reflect what the SERVER applied, because they were rendered from $_GET. The
 * snapshot is therefore taken once at startup and never recomputed: if the operator changes a
 * select without submitting, the chips must keep describing the result actually on screen, not a
 * filter that has not been applied yet.
 *
 * Degrades to nothing without JS. That is acceptable here precisely because the chips are
 * decorative - no filtering behaviour depends on them.
 */
(function () {
    'use strict';

    // Controls that live in the filter card but do not filter anything - they order or switch the
    // view. Chipping them would report "Direction: Ascending" as though it narrowed the results.
    // Matched on the control's name attribute, [] stripped.
    var NOT_A_FILTER = ['sort', 'dir', 'direction', 'order', 'orderby', 'view', 'period', 'page'];

    function controlName(control) {
        return (control.name || '').replace(/\[\]$/, '');
    }

    function isExcluded(control) {
        return NOT_A_FILTER.indexOf(controlName(control)) !== -1;
    }

    /**
     * Label for a control, in decreasing order of reliability:
     *   1. <label for="id"> - the only form that is explicit. Needed for the checkbox filters,
     *      whose label FOLLOWS the input instead of preceding it.
     *   2. the first <label> inside the control's own column wrapper.
     *   3. the control's name, humanised - a fallback that should not normally be reached.
     */
    function labelFor(control) {
        if (control.id) {
            var explicit = document.querySelector('label[for="' + CSS.escape(control.id) + '"]');
            if (explicit && explicit.textContent.trim() !== '') {
                return explicit.textContent.trim();
            }
        }

        var column = control.closest('[class*="col-"]') || control.parentElement;
        if (column) {
            var nearby = column.querySelector('label');
            if (nearby && nearby.textContent.trim() !== '') {
                return nearby.textContent.trim();
            }
        }

        return controlName(control).replace(/_id$/, '').replace(/_/g, ' ');
    }

    /**
     * The applied value(s) of a control, as text a person would recognise, or [] when the control
     * is not currently narrowing anything. Returns an array because a multi-select contributes one
     * chip per chosen option - "Tags: Plush" and "Tags: Keychain" read better than one chip
     * holding a comma-joined list.
     */
    function appliedValues(control) {
        var tag = control.tagName.toLowerCase();

        if (tag === 'select') {
            var chosen = Array.prototype.filter.call(control.selectedOptions || [], function (option) {
                // value="" is the placeholder ("All", "Any", "None") - not a filter.
                return option.value !== '';
            });
            return chosen.map(function (option) {
                return option.textContent.trim().replace(/\s+/g, ' ');
            });
        }

        if (control.type === 'checkbox' || control.type === 'radio') {
            // A ticked box is itself the statement; its label carries the meaning.
            return control.checked ? [''] : [];
        }

        var value = (control.value || '').trim();
        return value === '' ? [] : [value];
    }

    /**
     * Parameter names already shown by an active .btn-filter preset on this page.
     *
     * The preset row is a separate, older component: links that APPLY a filter, marked .is-active
     * when their filter is the one in effect. It is deliberately left alone. But an active preset
     * and the form control behind it describe the same state, so chipping both would say the same
     * thing twice - on Inventory, directly under the preset that already says it. Those parameter
     * names are read off the active presets' own hrefs and skipped.
     */
    function parametersShownByActivePresets() {
        var covered = [];
        document.querySelectorAll('.btn-filter.is-active[href]').forEach(function (preset) {
            var query;
            try {
                query = new URL(preset.href, window.location.origin).searchParams;
            } catch (error) {
                return;
            }
            query.forEach(function (_value, key) {
                if (covered.indexOf(key) === -1) {
                    covered.push(key);
                }
            });
        });
        return covered;
    }

    function buildChip(label, value) {
        var chip = document.createElement('span');
        chip.className = 'filter-chip';

        var name = document.createElement('span');
        name.className = 'filter-chip-label';
        name.textContent = value === '' ? label : label + ':';
        chip.appendChild(name);

        if (value !== '') {
            chip.appendChild(document.createTextNode(' ' + value));
        }
        return chip;
    }

    function renderChips(card) {
        var form = card.querySelector('form');
        if (!form) {
            return;
        }

        var covered = parametersShownByActivePresets();
        var chips = [];

        Array.prototype.forEach.call(form.elements, function (control) {
            if (!control.name || control.disabled) {
                return;
            }
            var type = (control.type || '').toLowerCase();
            if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset') {
                return;
            }
            if (isExcluded(control) || covered.indexOf(controlName(control)) !== -1) {
                return;
            }

            var label = labelFor(control);
            appliedValues(control).forEach(function (value) {
                chips.push(buildChip(label, value));
            });
        });

        if (chips.length === 0) {
            return;
        }

        var row = document.createElement('div');
        row.className = 'filter-chips';
        // Read-only status region: announced, but not offered as something to operate.
        row.setAttribute('role', 'status');
        row.setAttribute('aria-label', 'Active filters');

        var heading = document.createElement('span');
        heading.className = 'filter-chips-heading';
        heading.textContent = 'Active filters';
        row.appendChild(heading);

        chips.forEach(function (chip) {
            row.appendChild(chip);
        });

        form.insertAdjacentElement('afterend', row);
    }

    function init() {
        document.querySelectorAll('.filter-card[data-filter-chips="1"]').forEach(renderChips);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
