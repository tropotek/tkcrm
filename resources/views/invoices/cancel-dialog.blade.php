{{--
    Asks before an invoice is cancelled, in the shared dialog.
    $problem: why it cannot be cancelled, or null.
    $tasks: the tasks billed on it. With any, a box, ticked to begin with, re-opens them so their
    time can be billed again; unticked they stay closed and marked billed on the cancelled invoice.
--}}
<x-modal title="Cancel invoice {{ $invoice->number }}" :action="$problem ? null : route('invoices.cancel', $invoice)" dismiss="Keep the invoice">
    @if ($problem)
        <p class="mb-0">{{ $problem }}</p>
    @else
        <p>
            Invoice {{ $invoice->number }} for {{ $invoice->company->name }}, {{ $invoice->totals()->total->format() }}, will be cancelled.
            It keeps its number and its lines as a record, and cannot be brought back.
        </p>

        @if ($tasks->isNotEmpty())
            <div class="form-check">
                <input type="checkbox" id="cancel_reopen_tasks" name="reopen_tasks" value="1" class="form-check-input" checked>
                <label for="cancel_reopen_tasks" class="form-check-label">
                    Re-open {{ $tasks->count() === 1 ? 'the task' : "the {$tasks->count()} tasks" }} billed on it, so the time can be billed again
                </label>
            </div>
            <ul class="mt-2 mb-0 small text-muted">
                @foreach ($tasks as $task)
                    <li>{{ $task->subject }}</li>
                @endforeach
            </ul>
            <div class="form-text">Left unticked, {{ $tasks->count() === 1 ? 'it stays' : 'they stay' }} closed and marked as billed on this invoice.</div>
        @endif

        <x-slot:footer>
            <button type="submit" class="btn btn-danger">Cancel the invoice</button>
        </x-slot:footer>
    @endif
</x-modal>
