/**
 * Wires up TYPO3 Backend Modal.confirm for crowdsec_bridge decision deletion.
 * Texts come from data-* attributes (rendered server-side via XLF — FR-015).
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-crowdsec-delete]');
    if (!trigger) {
        return;
    }
    event.preventDefault();

    const form = trigger.closest('form');
    if (!form) {
        return;
    }

    const isForeign = trigger.dataset.foreignOrigin === '1';
    const severity = isForeign ? Severity.danger : Severity.warning;

    Modal.confirm(
        trigger.dataset.modalTitle || 'Delete decision?',
        trigger.dataset.modalBody || 'This decision will be removed irreversibly.',
        severity,
        [
            {
                text: trigger.dataset.modalCancel || 'Cancel',
                active: true,
                btnClass: 'btn-default',
                name: 'cancel',
            },
            {
                text: trigger.dataset.modalConfirm || 'Delete',
                btnClass: 'btn-' + (isForeign ? 'danger' : 'warning'),
                name: 'confirm',
            },
        ],
    ).addEventListener('button.clicked', (e) => {
        const modal = e.target.closest('.modal');
        if (e.target.name === 'confirm') {
            form.submit();
        }
        if (modal && typeof Modal.dismiss === 'function') {
            Modal.dismiss();
        }
    });
});
