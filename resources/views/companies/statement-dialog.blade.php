{{--
    A client's statement, from the company's page: the days it covers, what it comes to, a link to
    it as a PDF, and the email that carries it, in the shared preview-and-send dialog
    (components/mail-dialog.blade.php). Changing a day asks for the whole dialog again.

    $from, $to: the range, as typed.   $statement: the App\Statements\Statement, null while the range is wrong.
    $problem: why it cannot be emailed, or null.   $needsEmail: the problem is that the client has no email.
--}}
<x-mail-dialog title="Statement for {{ $company->name }}"
    :action="route('companies.statement.store', $company)" :refresh="route('companies.statement.create', $company)"
    :problem="$problem"
    :fix="$needsEmail ? ['url' => route('companies.edit', $company), 'label' => 'Add an email to '.$company->name] : null"
    :envelope="$envelope" :documents="$documents" :html="$html" :note="$note" :note-error="$errors->first('note')" :last="$last">

    <div class="row align-items-end">
        @foreach (['from' => ['From', $from], 'to' => ['To', $to]] as $name => [$label, $day])
            <div class="col-sm-3">
                <div class="mb-3">
                    <label for="statement_{{ $name }}" class="form-label">{{ $label }}</label>
                    <input type="date" id="statement_{{ $name }}" name="{{ $name }}" value="{{ $day }}" required class="form-control"
                        hx-get="{{ route('companies.statement.create', $company) }}" hx-include="#app-modal-content" hx-trigger="change" hx-target="#app-modal-content">
                </div>
            </div>
        @endforeach

        @if ($statement)
            <div class="col-sm-6">
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-medium">{{ $statement->closing->format() }} owing</div>
                        <div class="text-muted small">
                            {{ count($statement->entries) }} {{ Str::plural('entry', count($statement->entries)) }},
                            {{ count($statement->unpaid) }} unpaid {{ Str::plural('invoice', count($statement->unpaid)) }}
                        </div>
                    </div>
                    <a href="{{ route('companies.statement.pdf', ['company' => $company, 'from' => $from, 'to' => $to]) }}" target="_blank" rel="noopener" class="btn btn-light">
                        <i class="ri-file-pdf-2-line me-1"></i>Open PDF
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-mail-dialog>
