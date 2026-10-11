{{--
    The one dialog on the page, empty until htmx loads a form into it. resources/js/modal.js opens it
    when something is swapped into #app-modal-content and closes it on a "modal-close" event.

    <button hx-get="{{ route('payments.create', $invoice) }}" hx-target="#app-modal-content">Add payment</button>

    The response is an <x-modal> (components/modal.blade.php).
--}}
<div class="modal fade" id="app-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content" id="app-modal-content"></div>
    </div>
</div>
