// tkcrm JS entrypoint.

// The Minton template shell: sidebar, top bar and the light/dark switch. jQuery is used only in there.
import './minton/app';
import './minton/layout';

// htmx swaps part of a page from a link or a form; Alpine handles small in-page behaviour.
import htmx from 'htmx.org';
import Alpine from 'alpinejs';

import './modal';
import './notifications';

window.htmx = htmx;
window.Alpine = Alpine;

// Swap 422 responses too: they carry the form re-rendered with its validation errors.
htmx.config.responseHandling = [
    { code: '204', swap: false },
    { code: '[23]..', swap: true },
    { code: '422', swap: true },
    { code: '[45]..', swap: false, error: true },
    { code: '...', swap: false },
];

// Every htmx request carries the CSRF token from the page head.
document.addEventListener('htmx:configRequest', (event) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token) {
        event.detail.headers['X-CSRF-TOKEN'] = token;
    }
});

Alpine.start();
