{{-- Plain text. $headline: what happened. $lines: what is known, a line each. $link: the site's page. --}}
{!! $headline !!}.

@foreach ($lines as $line)
{!! $line !!}
@endforeach

{{ $link }}

Sent by {!! $site !!}, which checks its watched sites every 5 minutes. Alerts for a site can be switched off on its page.
