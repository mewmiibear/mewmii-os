(function () {
    'use strict';

    // Lightweight, additive pre-submit validation (Forms Audit pass) - a client-side UX gate
    // in front of the existing full-page POST, which still submits and is still re-validated
    // server-side exactly as before; nothing here replaces or duplicates a validation rule.
    // Uses the browser's own HTML5 constraint validation (required / type="email" / maxlength,
    // whatever the markup already declares) via checkValidity(), so this can never drift out
    // of sync with what each field actually requires.
    function initEntryFormValidation(form) {
        if (!form) {
            return;
        }

        var fields = form.querySelectorAll('input, select, textarea');

        function validateField(field) {
            var isValid = field.checkValidity();
            field.classList.toggle('is-invalid', !isValid);
            return isValid;
        }

        fields.forEach(function (field) {
            field.addEventListener('input', function () {
                if (field.classList.contains('is-invalid')) {
                    validateField(field);
                }
            });
            field.addEventListener('blur', function () {
                validateField(field);
            });
        });

        form.addEventListener('submit', function (event) {
            var firstInvalid = null;

            fields.forEach(function (field) {
                if (!validateField(field) && !firstInvalid) {
                    firstInvalid = field;
                }
            });

            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });
    }

    document.querySelectorAll('form[data-validate="1"]').forEach(initEntryFormValidation);
})();
