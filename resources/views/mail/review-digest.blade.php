{{-- Plain text. $waiting: one per open invoice, with its client, how many lines and its total. --}}
{!! count($waiting) === 1 ? '1 open invoice is' : count($waiting).' open invoices are' !!} waiting to be looked over and sent in {!! $site !!}:

@foreach ($waiting as $invoice)
- {!! $invoice['client'] !!}: {{ $invoice['lines'] === 1 ? '1 line' : $invoice['lines'].' lines' }}, {{ $invoice['total'] }}
@endforeach

Review and send them: {{ $url }}

Nothing has been issued or sent to a client.
