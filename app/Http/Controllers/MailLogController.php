<?php

namespace App\Http\Controllers;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Models\MailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The mail log: every message sent or tried, newest first, and a way to send a failed one again.
 */
class MailLogController extends Controller
{
    /**
     * `?state=` and `?kind=` narrow the list.
     */
    public function index(Request $request): View
    {
        $state = MailState::tryFrom((string) $request->query('state'));
        $kind = MailKind::tryFrom((string) $request->query('kind'));

        return view('mail-log.index', [
            'messages' => MailLog::query()
                ->with('related')
                ->when($state, fn ($query) => $query->where('state', $state))
                ->when($kind, fn ($query) => $query->where('kind', $kind))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'state' => $state,
            'kind' => $kind,
            'failed' => MailLog::where('state', MailState::Failed)->count(),
        ]);
    }

    public function resend(MailLog $mailLog): RedirectResponse
    {
        if (! $mailLog->canResend()) {
            return redirect()->back(fallback: route('mail-log.index'))->with('error', 'Only a message that failed can be sent again.');
        }

        $mailLog->resend();

        return redirect()->back(fallback: route('mail-log.index'))->with('success', "\"{$mailLog->subject}\" is queued to be sent again.");
    }
}
