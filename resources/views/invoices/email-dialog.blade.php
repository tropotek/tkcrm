{{--
    The email that carries an issued invoice to its client, before it is sent, in the shared
    preview-and-send dialog (components/mail-dialog.blade.php).

    $problem: why it cannot be sent, or null. Without one: $envelope, $documents and $html, the message's parts.
    $note: the note typed so far.   $last: the last time this invoice was emailed, or null.
--}}
<x-mail-dialog title="Email invoice {{ $invoice->number }}"
    :action="route('invoices.email.store', $invoice)" :refresh="route('invoices.email.create', $invoice)"
    :problem="$problem"
    :fix="$invoice->status() === App\Enums\InvoiceStatus::Unpaid && blank($invoice->company->email) ? ['url' => route('companies.edit', $invoice->company), 'label' => 'Add an email to '.$invoice->company->name] : null"
    :envelope="$envelope" :documents="$documents" :html="$html" :note="$note" :note-error="$errors->first('note')" :last="$last" />
