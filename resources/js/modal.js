// The shared dialog (components/layouts/modal.blade.php). htmx loads a form into #app-modal-content;
// this opens the dialog when that happens, closes it when the server says so, and empties it afterwards.
import { Modal } from 'bootstrap';

const element = document.getElementById('app-modal');

if (element) {
    const content = element.querySelector('#app-modal-content');
    const dialog = element.querySelector('.modal-dialog');
    const modal = Modal.getOrCreateInstance(element);

    content.addEventListener('htmx:afterSwap', (event) => {
        // Swaps deeper inside the dialog bubble up to here too.
        if (event.detail.target !== content) {
            return;
        }

        const size = content.querySelector('[data-modal-size]')?.dataset.modalSize;
        dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
        if (size) {
            dialog.classList.add(`modal-${size}`);
        }

        modal.show();
    });

    // Sent by a reply with the header HX-Trigger: modal-close, once a form in the dialog has saved.
    document.body.addEventListener('modal-close', () => modal.hide());

    element.addEventListener('hidden.bs.modal', () => {
        content.innerHTML = '';
    });
}
