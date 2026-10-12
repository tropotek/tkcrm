{{--
    An open invoice's email as it would go, in the shared preview-and-send dialog
    (components/mail-dialog.blade.php). Send issues the invoice and queues the email together.

    $problem: why it cannot be issued and sent, or null.   $note: the note typed so far.
    $preview: from App\Review\IssueAndSend::preview(): the number, total and due date it would get,
    and the message's envelope, documents and HTML. Null with a problem.
--}}
<x-mail-dialog title="Issue and send to {{ $invoice->company->name }}"
    :action="route('review.send', $invoice)" :refresh="route('review.show', $invoice)"
    :problem="$problem"
    :fix="$invoice->issueProblem() === null && blank($invoice->company->email) ? ['url' => route('companies.edit', $invoice->company), 'label' => 'Add an email to '.$invoice->company->name] : null"
    :envelope="$preview['envelope'] ?? null" :documents="$preview['documents'] ?? []" :html="$preview['html'] ?? null"
    :note="$note" :note-error="$errors->first('note')" send="Issue and send">
    @if ($preview)
        <div class="alert alert-info">
            Sending issues this as invoice {{ $preview['number'] }} for {{ $preview['total'] }}, due {{ $preview['due'] }}, and emails it.
            Until then nothing is issued and nothing has gone.
        </div>
    @endif
</x-mail-dialog>
