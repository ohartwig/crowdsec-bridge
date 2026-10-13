/**
 * Live character counter for the reason textarea. Server still validates (FR-008/R-007),
 * this is UX-only.
 */
document.addEventListener('DOMContentLoaded', () => {
    const textareas = document.querySelectorAll('[data-crowdsec-reason-counter]');
    textareas.forEach((textarea) => {
        const counter = document.querySelector(textarea.dataset.crowdsecReasonCounter);
        if (!counter) {
            return;
        }
        const max = parseInt(textarea.getAttribute('maxlength') || '1000', 10);
        const update = () => {
            const len = textarea.value.length;
            counter.textContent = len + ' / ' + max;
            counter.classList.toggle('text-danger', len > max);
        };
        textarea.addEventListener('input', update);
        update();
    });
});
