{{--
    The rows of a table of logged messages: when, what, who for, the subject and how it went.
    $messages: App\Models\MailLog rows.   $showRelated: whether to say what each is about (not on that record's own page).
    A failed message has a button to send it again.
--}}
@foreach ($messages as $message)
    <tr>
        <td class="text-nowrap"><x-date :value="$message->created_at" /></td>
        <td>
            {{ $message->kind->label() }}
            @if ($showRelated && $message->related instanceof App\Models\Invoice)
                <div class="small"><a href="{{ route('invoices.show', $message->related) }}">{{ $message->related->number ? 'Invoice '.$message->related->number : 'Open invoice' }}</a></div>
            @elseif ($showRelated && $message->related instanceof App\Models\Domain)
                <div class="small"><a href="{{ route('domains.show', $message->related) }}">{{ $message->related->host() }}</a></div>
            @elseif ($showRelated && $message->related instanceof App\Models\Company)
                <div class="small"><a href="{{ route('companies.show', $message->related) }}">{{ $message->related->name }}</a></div>
            @endif
        </td>
        <td>
            {{ implode(', ', $message->to) }}
            @if ($message->cc)
                <div class="text-muted small">cc {{ implode(', ', $message->cc) }}</div>
            @endif
            @if ($message->bcc)
                <div class="text-muted small">bcc {{ implode(', ', $message->bcc) }}</div>
            @endif
        </td>
        <td>
            {{ $message->subject }}
            @if ($message->error)
                <div class="text-danger small">{{ Str::limit($message->error, 200) }}</div>
            @endif
        </td>
        <td class="text-nowrap">
            <span class="badge bg-{{ $message->state->colour() }}">{{ $message->state->label() }}</span>
            @if ($message->sent_at)
                <div class="text-muted small"><x-date :value="$message->sent_at" /></div>
            @elseif ($message->state === App\Enums\MailState::Queued && $message->attempts > 0)
                <div class="text-muted small">Tried {{ $message->attempts }} {{ Str::plural('time', $message->attempts) }}, will try again</div>
            @endif
        </td>
        <td class="text-end text-nowrap">
            @if ($message->canResend())
                <form method="POST" action="{{ route('mail-log.resend', $message) }}" class="d-inline" onsubmit="return confirm('Send this message again?')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-light">Send again</button>
                </form>
            @endif
        </td>
    </tr>
@endforeach
