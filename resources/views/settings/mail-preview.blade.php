{{--
    A message as a client would get it, filled with made-up values, in the shared dialog.
    $filled: the App\Mail\FilledTemplate.   $unknown: placeholders in the wording that the message does not have.
--}}
<x-modal title="{{ $template->label() }} email, with sample values" size="lg" dismiss="Close">
    @if ($unknown)
        <div class="alert alert-warning">
            {{ implode(', ', $unknown) }} {{ count($unknown) === 1 ? 'is not a placeholder' : 'are not placeholders' }} this message has, so {{ count($unknown) === 1 ? 'it is' : 'they are' }} left as typed.
        </div>
    @endif

    <dl class="row mb-2">
        <dt class="col-sm-2">Subject</dt>
        <dd class="col-sm-10 mb-0">{{ $filled->subject }}</dd>
    </dl>

    {{-- In a frame, so the message's own styles and the page's stay apart. --}}
    <iframe title="The message" sandbox srcdoc="{{ $filled->html() }}" class="border rounded w-100" style="height: 60vh"></iframe>

    <x-slot:footer></x-slot:footer>
</x-modal>
