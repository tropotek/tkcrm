{{--
    The contents of the shared dialog (components/layouts/modal.blade.php), returned by a route that
    htmx loads into #app-modal-content.

    <x-modal title="Add payment" :action="route('payments.store', $invoice)">
        …fields…
        <x-slot:footer><button type="submit" class="btn btn-primary">Save</button></x-slot:footer>
    </x-modal>

    With an action the body and footer are one form, posted by htmx back into the dialog. A 422 reply
    re-renders it with its errors; a reply with the header HX-Trigger: modal-close closes it.

    method: post (default), put, patch or delete.   size: sm, lg or xl.
--}}
@props(['title', 'action' => null, 'method' => 'post', 'size' => null])

<div class="modal-header" @if ($size) data-modal-size="{{ $size }}" @endif>
    <h5 class="modal-title">{{ $title }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

@if ($action)
    <form hx-{{ strtolower($method) }}="{{ $action }}" hx-target="#app-modal-content" class="d-flex flex-column overflow-hidden">
@endif

<div class="modal-body">
    {{ $slot }}
</div>

@isset($footer)
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        {{ $footer }}
    </div>
@endisset

@if ($action)
    </form>
@endif
