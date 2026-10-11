// The shared dialog (components/layouts/modal.blade.php). htmx loads a form into #app-modal-content;
// this opens the dialog when that happens, closes it when the server says so, and empties it afterwards.
import { Modal } from 'bootstrap';

const element = document.getElementById('app-modal');

if (element) {
    const content = element.querySelector('#app-modal-content');
    const dialog = element.querySelector('.modal-dialog');
    const modal = Modal.getOrCreateInstance(element);

    // Whatever loads into the dialog replaces its contents, never the element itself. A button inside
    // a table that swaps itself with hx-swap="outerHTML" would otherwise inherit that and remove the dialog.
    document.body.addEventListener('htmx:beforeSwap', (event) => {
        if (event.detail.target === content) {
            event.detail.swapOverride = 'innerHTML';
        }
    });

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

    // Once open, the field marked autofocus is ready to type in.
    element.addEventListener('shown.bs.modal', () => content.querySelector('[autofocus]')?.focus());

    element.addEventListener('hidden.bs.modal', () => {
        content.innerHTML = '';
    });
}
