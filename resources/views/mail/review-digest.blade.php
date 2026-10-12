{{-- Plain text. $waiting: one per open invoice, with its client, how many lines and its total. $overdue: one per invoice due a reminder. --}}
@if ($waiting)
{!! count($waiting) === 1 ? '1 open invoice is' : count($waiting).' open invoices are' !!} waiting to be looked over and sent in {!! $site !!}:

@foreach ($waiting as $invoice)
- {!! $invoice['client'] !!}: {{ $invoice['lines'] === 1 ? '1 line' : $invoice['lines'].' lines' }}, {{ $invoice['total'] }}
@endforeach

@endif
@if ($overdue)
{!! count($overdue) === 1 ? '1 overdue invoice is' : count($overdue).' overdue invoices are' !!} due a reminder{!! $waiting ? '' : ' in '.$site !!}:

@foreach ($overdue as $invoice)
- {!! $invoice['client'] !!}: invoice {{ $invoice['number'] }}, {{ $invoice['owing'] }} owing, {{ $invoice['days'] }} days overdue
@endforeach

@endif
Review and send them: {{ $url }}

Nothing has been issued or sent to a client.
