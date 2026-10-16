/**
 * Pre-submit form validation for the IP quick-check field.
 * Server-side validation still runs (FR-008), this is UX only.
 */
(function () {
    'use strict';

    const ipPattern = /^(?:(?:\d{1,3}\.){3}\d{1,3}|[0-9a-fA-F:]+)$/;
    const form = document.querySelector('.crowdsec-quickcheck-form');
    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        const input = form.querySelector('input[name="ip"]');
        if (!input) {
            return;
        }
        const value = input.value.trim();
        if (value === '' || !ipPattern.test(value)) {
            event.preventDefault();
            input.classList.add('is-invalid');
            input.focus();
        }
    });
})();
