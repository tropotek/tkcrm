{{--
    The email that carries an issued invoice to its client, before it is sent: who it goes to, what
    is attached and the message itself, with room for a personal note. In the shared dialog.

    $problem: why it cannot be sent, or null. Without one: $envelope (who it goes to and its subject),
    $documents (what is attached, as InvoiceMail::documents() gives them) and $html (the message).
    $note: the note typed so far.   $last: the last time this invoice was emailed, or null.
    $redirectTo: where MAIL_REDIRECT_TO sends every message, or null.

    As the note changes the dialog asks for itself again and takes only #invoice-email-preview from the answer.
--}}
<x-modal title="Email invoice {{ $invoice->number }}" :action="$problem ? null : route('invoices.email.store', $invoice)" size="lg" :dismiss="$problem ? 'Close' : 'Cancel'">
    @if ($problem)
        <p @class(['mb-0' => filled($invoice->company->email)])>{{ $problem }}</p>
        @if ($invoice->status() === App\Enums\InvoiceStatus::Unpaid && blank($invoice->company->email))
            <a href="{{ route('companies.edit', $invoice->company) }}" class="btn btn-light">Add an email to {{ $invoice->company->name }}</a>
        @endif

        <x-slot:footer></x-slot:footer>
    @else
        @if ($redirectTo)
            <div class="alert alert-warning">Mail is being redirected: this will go to {{ $redirectTo }}, not to the client.</div>
        @endif
        @if ($last)
            <div class="alert alert-info">This invoice was last emailed <x-date :value="$last->created_at" /> ({{ strtolower($last->state->label()) }}).</div>
        @endif

        <dl class="row mb-2">
            <dt class="col-sm-2">To</dt>
            <dd class="col-sm-10">{{ implode(', ', array_map(fn ($address) => $address->address, $envelope->to)) }}</dd>

            @if ($envelope->cc)
                <dt class="col-sm-2">Cc</dt>
                <dd class="col-sm-10">{{ implode(', ', array_map(fn ($address) => $address->address, $envelope->cc)) }}</dd>
            @endif

            @if ($envelope->bcc)
                <dt class="col-sm-2">Bcc</dt>
                <dd class="col-sm-10">{{ implode(', ', array_map(fn ($address) => $address->address, $envelope->bcc)) }} <span class="text-muted small">your own copy</span></dd>
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
            <label for="email_note" class="form-label">Personal note <span class="text-muted fw-normal">(optional)</span></label>
            <textarea id="email_note" name="note" rows="2" maxlength="2000" autofocus
                hx-get="{{ route('invoices.email.create', $invoice) }}" hx-trigger="keyup changed delay:600ms" hx-target="#invoice-email-preview" hx-select="#invoice-email-preview" hx-swap="outerHTML"
                @class(['form-control', 'is-invalid' => $errors->has('note')])>{{ $note }}</textarea>
            @error('note')
                <div class="invalid-feedback">{{ $message }}</div>
            @else
                <div class="form-text">Shown above the message, for this email only.</div>
            @enderror
        </div>

        {{-- In a frame, so the message's own styles and the page's stay apart. --}}
        <iframe id="invoice-email-preview" title="The message" sandbox srcdoc="{{ $html }}" class="border rounded w-100" style="height: 45vh"></iframe>

        <x-slot:footer>
            <button type="submit" class="btn btn-primary"><i class="ri-mail-send-line me-1"></i>Send</button>
        </x-slot:footer>
    @endif
</x-modal>
