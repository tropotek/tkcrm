{{--
    The preview-and-send dialog of an email to a client, in the shared dialog: who it goes to, what
    is attached and the message itself, with room for a personal note. Nothing goes without Send.

    <x-mail-dialog title="Email invoice 1042" :action="route(…store)" :refresh="route(…create)" :problem="…"
        :envelope="…" :documents="…" :html="…" :note="…" :last="…">
        …fields of the message's own, above the rest (a statement's days)…
    </x-mail-dialog>

    action: where Send posts `note` and the slot's fields.
    refresh: the dialog's own address. As the note changes it is asked for again with the form's fields
        and only #mail-preview is taken from the answer.
    problem: why it cannot be sent, or null. With one there is no preview and no Send; `fix` is then
        a link that puts it right, as ['url' => …, 'label' => …].
    envelope, documents, html: the message's Envelope, its documents (as InvoiceMail::documents()
        gives them) and its HTML. Never the Mailable: a view renders one into a string.
    last: the App\Models\MailLog of the last time this was emailed, or null.
    note-error: what is wrong with the note. A component does not see the errors a controller puts
        on the view it returns, so the dialog's own view hands it on: :note-error="$errors->first('note')".
--}}
@props(['title', 'action', 'refresh', 'problem' => null, 'fix' => null, 'envelope' => null, 'documents' => [], 'html' => null, 'note' => null, 'noteError' => null, 'last' => null])

@php
    $redirectTo = config('tkcrm.mail.redirect_to');
    $addresses = fn (array $list) => implode(', ', array_map(fn ($address) => $address->address, $list));
@endphp

<x-modal :title="$title" :action="$problem ? null : $action" size="lg" :dismiss="$problem ? 'Close' : 'Cancel'">
    {{ $slot }}

    @if ($problem)
        {{-- Kept for when the slot's fields put the problem right and ask for the dialog again. --}}
        <input type="hidden" name="note" value="{{ $note }}">
        <p @class(['mb-0' => ! $fix])>{{ $problem }}</p>
        @if ($fix)
            <a href="{{ $fix['url'] }}" class="btn btn-light">{{ $fix['label'] }}</a>
        @endif

        <x-slot:footer></x-slot:footer>
    @else
        @if ($redirectTo)
            <div class="alert alert-warning">Mail is being redirected: this will go to {{ $redirectTo }}, not to the client.</div>
        @endif
        @if ($last)
            <div class="alert alert-info">This was last emailed <x-date :value="$last->created_at" /> ({{ strtolower($last->state->label()) }}).</div>
        @endif

        <dl class="row mb-2">
            <dt class="col-sm-2">To</dt>
            <dd class="col-sm-10">{{ $addresses($envelope->to) }}</dd>

            @if ($envelope->cc)
                <dt class="col-sm-2">Cc</dt>
                <dd class="col-sm-10">{{ $addresses($envelope->cc) }}</dd>
            @endif

            @if ($envelope->bcc)
                <dt class="col-sm-2">Bcc</dt>
                <dd class="col-sm-10">{{ $addresses($envelope->bcc) }} <span class="text-muted small">your own copy</span></dd>
            @endif

            <dt class="col-sm-2">Subject</dt>
            <dd class="col-sm-10">{{ $envelope->subject }}</dd>

            <dt class="col-sm-2">Attached</dt>
            <dd class="col-sm-10 mb-0">
                <ul class="list-unstyled mb-0">
                    @foreach ($documents as $document)
                        <li>
                            <i class="ri-file-pdf-2-line me-1"></i>
                            @if ($document['url'])
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener">{{ $document['filename'] }}</a>
                            @else
                                {{ $document['filename'] }}
                            @endif
                            <span class="text-muted small ms-1">{{ $document['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </dd>
        </dl>

        <div class="mb-3">
            <label for="mail_note" class="form-label">Personal note <span class="text-muted fw-normal">(optional)</span></label>
            <textarea id="mail_note" name="note" rows="2" maxlength="2000" autofocus
                hx-get="{{ $refresh }}" hx-include="closest form" hx-trigger="keyup changed delay:600ms" hx-target="#mail-preview" hx-select="#mail-preview" hx-swap="outerHTML"
                @class(['form-control', 'is-invalid' => filled($noteError)])>{{ $note }}</textarea>
            @if (filled($noteError))
                <div class="invalid-feedback">{{ $noteError }}</div>
            @else
                <div class="form-text">Shown above the message, for this email only.</div>
            @endif
        </div>

        {{-- In a frame, so the message's own styles and the page's stay apart. --}}
        <iframe id="mail-preview" title="The message" sandbox srcdoc="{{ $html }}" class="border rounded w-100" style="height: 45vh"></iframe>

        <x-slot:footer>
            <button type="submit" class="btn btn-primary"><i class="ri-mail-send-line me-1"></i>Send</button>
        </x-slot:footer>
    @endif
</x-modal>
