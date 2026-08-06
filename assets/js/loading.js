/* ==============================================================================
   Mewmii OS - Loading and pending states
   V3 Phase 3.5.

   Consolidation, not invention. Before this file there were three separate
   inline loading placeholders and one bespoke button-disable, each written out
   by hand where it was needed:

     drawer.js              spinner + error + retry   (the most complete)
     inventory.js           <p class="text-muted">Loading...</p>
     product-form.js        <p class="text-muted small mb-0">Loading...</p>
     supplier-order-form.js buttons disabled on submit, no spinner

   The two <p> placeholders were the same idea written twice with different
   classes. They become one call. The button-disable gains the spinner and the
   preserved width that section 2.13 already specifies, using the same Bootstrap
   spinner markup drawer.js already renders - so nothing new is introduced
   visually, it is the existing pattern applied where it was missing.

   drawer.js is deliberately NOT refactored onto this: it owns loading, error
   AND retry as one interlocking flow for a remote panel, and flattening that
   into a generic helper would lose the retry affordance. It stays the richer
   case; this covers the simple ones.
   ============================================================================== */

(function () {
    'use strict';

    /**
     * Replaces a container's contents with the standard inline loading line.
     * Same muted-text treatment the two hand-written versions used.
     */
    function placeholder(container, text) {
        if (!container) {
            return;
        }
        var label = text || 'Loading&hellip;';
        container.innerHTML = '<p class="text-muted small mb-0">' + label + '</p>';
    }

    /**
     * Puts a submit control into (or out of) its pending state.
     *
     * The width is pinned before the label is swapped, because a button that
     * shrinks to fit a spinner drags the rest of the toolbar with it - section
     * 2.13 calls that out specifically. The original label is stashed on the
     * element so releasing restores exactly what was there, including markup.
     */
    function buttonPending(button, isPending) {
        if (!button) {
            return;
        }

        if (isPending) {
            if (button.dataset.pending === '1') {
                return;
            }
            button.dataset.pendingLabel = button.innerHTML;
            button.style.minWidth = button.offsetWidth + 'px';
            button.dataset.pending = '1';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>'
                + '<span class="visually-hidden">Working&hellip;</span>';
            return;
        }

        if (button.dataset.pending !== '1') {
            return;
        }
        button.innerHTML = button.dataset.pendingLabel || '';
        button.style.minWidth = '';
        button.disabled = false;
        button.removeAttribute('aria-busy');
        delete button.dataset.pending;
        delete button.dataset.pendingLabel;
    }

    /** Every submit control inside a form, for the submit-once case. */
    function formPending(form, isPending) {
        if (!form) {
            return;
        }
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            buttonPending(button, isPending);
        });
    }

    window.LoadingUI = {
        placeholder: placeholder,
        buttonPending: buttonPending,
        formPending: formPending
    };
})();
