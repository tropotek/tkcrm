{{--
    The reminder for an overdue invoice, before it is sent, in the shared preview-and-send dialog
    (components/mail-dialog.blade.php).

    $problem: why it cannot be sent, or null. Without one: $envelope, $documents and $html, the message's parts.
    $note: the note typed so far.   $last: the last reminder sent for this invoice, or null.
--}}
<x-mail-dialog title="Remind {{ $invoice->company->name }} of invoice {{ $invoice->number }}"
    :action="route('invoices.reminder.store', $invoice)" :refresh="route('invoices.reminder.create', $invoice)"
    :problem="$problem"
    :fix="$invoice->isOverdue() && blank($invoice->company->email) ? ['url' => route('companies.edit', $invoice->company), 'label' => 'Add an email to '.$invoice->company->name] : null"
    :envelope="$envelope" :documents="$documents" :html="$html" :note="$note" :note-error="$errors->first('note')" :last="$last" send="Send reminder" />
