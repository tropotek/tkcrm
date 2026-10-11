{{--
    The one HTML layout of every email to a client (App\Mail\FilledTemplate). It stands alone, with
    its styles inline and nothing fetched, because a mail reader drops the rest.

    $filled: the App\Mail\FilledTemplate. Its note, body, notices and signature are plain text, set here as
    paragraphs. The note is the owner's own, set apart above the body. A notice is fixed by the message, not the
    owner's wording, and follows the body.
    $owner: App\Support\Owner, the business it is from.
--}}
@php
    $paragraph = 'margin: 0 0 14px;';
    $contact = array_filter([$owner->phone(), $owner->email(), $owner->website()]);
    ['note' => $note, 'body' => $body, 'notices' => $notices, 'signature' => $signature] = $filled->paragraphs();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $filled->subject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e5e7eb; font-family: Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #222222;">
                    <tr>
                        <td style="padding: 20px 28px; border-bottom: 2px solid #222222; font-size: 18px; font-weight: bold;">{{ $owner->name() }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 28px 10px;">
                            @if ($note)
                                <div style="margin: 0 0 18px; padding: 12px 14px 1px; background-color: #f3f4f6; border-left: 3px solid #222222;">
                                    @foreach ($note as $text)
                                        <p style="{{ $paragraph }}">{!! nl2br(e($text)) !!}</p>
                                    @endforeach
                                </div>
                            @endif

                            @foreach ($body as $text)
                                <p style="{{ $paragraph }}">{!! nl2br(e($text)) !!}</p>
                            @endforeach

                            @foreach ($notices as $text)
                                <p style="{{ $paragraph }} color: #444444;">{!! nl2br(e($text)) !!}</p>
                            @endforeach

                            @foreach ($signature as $text)
                                <p style="{{ $paragraph }}">{!! nl2br(e($text)) !!}</p>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 14px 28px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #666666;">
                            {{ $owner->name() }}@if ($owner->abn()), ABN {{ $owner->abn() }}@endif
                            @if ($contact)
                                <br>{{ implode(' · ', $contact) }}
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
