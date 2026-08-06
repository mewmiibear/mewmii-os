/* ==============================================================================
   Mewmii OS - Confirmation Dialog System
   V3 Phase 3.2a (framework only; call sites are converted in 3.2b-3.2f).

   Replaces native confirm(), which cannot be styled, cannot distinguish a
   reversible workflow step from a permanent deletion, and reads identically
   whether it is guarding "Mark Arrived" or "Delete". Every one of the 53 sites
   in this application guards a FORM SUBMISSION - none guards a link - so the
   interceptor only ever has to deal with the submit event.

   Two entry points, deliberately separate:

     1. Declarative - data-confirm on a <form> or on a submit <button>.
        Covers the 49 static guards.

     2. Programmatic - window.ConfirmUI.confirm(opts) -> Promise<boolean>.
        Covers the 4 sites that confirm only when a runtime condition holds
        (e.g. "only if this order already has a shipping allocation"), which a
        static attribute cannot express.

   Load order matters: this file is loaded by includes/footer.php AFTER the
   Bootstrap bundle. Bootstrap is loaded there, while the other app scripts are
   loaded from includes/header.php - i.e. BEFORE it. A script that reads
   window.bootstrap at parse time from header.php silently gets undefined; that
   exact ordering trap disabled the Reverse Receipt modal for a whole release in
   V2. Loading after Bootstrap avoids it structurally rather than by guessing.
   ============================================================================== */

(function () {
    'use strict';

    var MODAL_ID = 'app-confirm-dialog';
    var TONES = { danger: 1, warning: 1, neutral: 1 };

    /* Set on a form for exactly one programmatic re-submit, so the interceptor
       lets that submit through instead of re-opening the dialog forever. */
    var RESUBMIT_FLAG = '__confirmApproved';

    var modalEl = null;
    var titleEl = null;
    var bodyEl = null;
    var confirmBtn = null;
    var cancelBtn = null;
    var pending = null;      // { resolve } for the currently open dialog
    var settled = false;     // whether the open dialog has already produced an answer

    function el() {
        if (modalEl) { return modalEl; }
        modalEl = document.getElementById(MODAL_ID);
        if (!modalEl) { return null; }
        titleEl = modalEl.querySelector('[data-confirm-role="title"]');
        bodyEl = modalEl.querySelector('[data-confirm-role="body"]');
        confirmBtn = modalEl.querySelector('[data-confirm-role="confirm"]');
        cancelBtn = modalEl.querySelector('[data-confirm-role="cancel"]');
        return modalEl;
    }

    function bootstrapModal(node) {
        if (window.bootstrap && window.bootstrap.Modal) {
            return window.bootstrap.Modal.getOrCreateInstance(node);
        }
        return null;
    }

    /**
     * Opens the dialog and resolves true (confirmed) or false (cancelled, Escape,
     * backdrop, or Bootstrap unavailable). Never rejects - a caller guarding a
     * destructive action must always get a definite answer, and the safe answer
     * is "no".
     */
    function confirmDialog(options) {
        options = options || {};

        return new Promise(function (resolve) {
            var node = el();

            /* No dialog markup, or Bootstrap failed to load: fall back to the
               native confirm rather than letting a destructive action through
               unguarded. Degraded, but never silently unconfirmed. */
            if (!node) {
                resolve(window.confirm(buildFallbackText(options)));
                return;
            }
            var instance = bootstrapModal(node);
            if (!instance) {
                resolve(window.confirm(buildFallbackText(options)));
                return;
            }

            var tone = TONES[options.tone] ? options.tone : 'neutral';

            titleEl.textContent = options.title || 'Are you sure?';
            bodyEl.textContent = options.body || '';
            bodyEl.hidden = !options.body;

            confirmBtn.textContent = options.label || 'Confirm';
            confirmBtn.className = 'btn btn-sm ' + (tone === 'danger' ? 'btn-danger' : 'btn-primary');
            cancelBtn.textContent = options.cancelLabel || 'Cancel';

            node.setAttribute('data-tone', tone);
            /* alertdialog tells a screen reader this interrupts for a consequential
               choice. Reserved for genuinely destructive actions - applying it to a
               routine approval would cry wolf. */
            node.setAttribute('role', tone === 'danger' ? 'alertdialog' : 'dialog');

            pending = { resolve: resolve };
            settled = false;

            /* Destructive actions must not have the confirm button focused: Enter
               on an unread dialog would delete. Focus lands on Cancel, so the
               reflex keypress is the safe one. */
            node.addEventListener('shown.bs.modal', function onShown() {
                node.removeEventListener('shown.bs.modal', onShown);
                (tone === 'danger' ? cancelBtn : confirmBtn).focus();
            });

            /* Fires for Escape, backdrop click, and the Cancel button alike.
               Anything that closes the dialog without an explicit confirm is a
               "no" - Bootstrap also restores focus to the trigger element here. */
            node.addEventListener('hidden.bs.modal', function onHidden() {
                node.removeEventListener('hidden.bs.modal', onHidden);
                settle(false);
            });

            instance.show();
        });
    }

    function settle(answer) {
        if (settled || !pending) { return; }
        settled = true;
        var resolve = pending.resolve;
        pending = null;
        resolve(answer);
    }

    /* Used only when the dialog cannot render. Recombines the title/body split
       into one string, since native confirm has no such structure. */
    function buildFallbackText(options) {
        return [options.title, options.body].filter(Boolean).join('\n\n') || 'Are you sure?';
    }

    function optionsFrom(source) {
        return {
            body: source.getAttribute('data-confirm') || '',
            title: source.getAttribute('data-confirm-title') || 'Are you sure?',
            label: source.getAttribute('data-confirm-label') || 'Confirm',
            cancelLabel: source.getAttribute('data-confirm-cancel') || 'Cancel',
            tone: source.getAttribute('data-confirm-tone') || 'neutral'
        };
    }

    /**
     * Re-submits after the operator confirms.
     *
     * requestSubmit(), NOT submit(). form.submit() silently skips HTML5
     * constraint validation, so a required field left empty would post anyway -
     * a real regression introduced by the very change meant to make things
     * safer. requestSubmit() runs validation and fires the submit event exactly
     * as a real click would, and carries the submitter so any name/value on the
     * button is still sent.
     */
    function resubmit(form, submitter) {
        form[RESUBMIT_FLAG] = true;

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter || undefined);
            return;
        }

        /* Older browsers without requestSubmit: click a real submit control so
           validation still runs. Only if none exists do we fall back to
           submit(), which is the one path that skips validation. */
        var fallbackButton = submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        if (fallbackButton) {
            fallbackButton.click();
        } else {
            form.submit();
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') { return; }

        if (form[RESUBMIT_FLAG]) {
            delete form[RESUBMIT_FLAG];
            return;
        }

        /* The attribute may live on the form or on the button that submitted it -
           4 of the existing sites guard at the button. */
        var submitter = event.submitter || null;
        var source = (submitter && submitter.hasAttribute('data-confirm')) ? submitter
            : (form.hasAttribute('data-confirm') ? form : null);
        if (!source) { return; }

        event.preventDefault();

        confirmDialog(optionsFrom(source)).then(function (ok) {
            if (ok) { resubmit(form, submitter); }
        });
    });

    /* Confirm button resolves true, then closes. hidden.bs.modal fires afterwards
       but settle() is idempotent, so the answer already recorded stands. */
    document.addEventListener('click', function (event) {
        var btn = event.target.closest && event.target.closest('[data-confirm-role="confirm"]');
        if (!btn || !modalEl || !modalEl.contains(btn)) { return; }
        settle(true);
        var instance = bootstrapModal(modalEl);
        if (instance) { instance.hide(); }
    });

    window.ConfirmUI = { confirm: confirmDialog };
})();
