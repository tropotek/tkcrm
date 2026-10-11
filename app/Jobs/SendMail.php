<?php

namespace App\Jobs;

use App\Models\MailLog;
use App\Notifications\Notice;
use App\Support\NotificationFeed;
use App\Support\Owner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one message. Every email tkcrm sends on purpose goes through this job, on the queue, so
 * that a page never waits for the mail server and a failure is tried again. It is queued by
 * `MailLog::send()`, which records the message; the job keeps that record up to date.
 *
 * The message is built when the job runs, not when it is queued: its PDFs are made then.
 * It is sent from the owner company's address when one is chosen, and from MAIL_FROM_ADDRESS
 * otherwise.
 *
 * A send that fails is tried three more times, after one, five and fifteen minutes. After that
 * the job is failed for good, the record says so, and every login is told.
 */
class SendMail implements ShouldQueue
{
    use Queueable;

    /** The first attempt and three more. */
    public int $tries = 4;

    /**
     * @param  ?int  $logId  the mail_log row that records this message
     */
    public function __construct(public Mailable $message, public ?int $logId = null) {}

    /**
     * Seconds to wait before each further attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('tkcrm.mail.retry_after');
    }

    public function handle(Owner $owner): void
    {
        $log = $this->log();
        $log?->markAttempt();

        if ($owner->email() !== null && $this->message->from === []) {
            $this->message->from($owner->email(), $owner->name());
        }

        try {
            Mail::send($this->message);
        } catch (Throwable $error) {
            // Kept on the record while the job waits to try again.
            $log?->markError($error);

            throw $error;
        }

        $log?->markSent();
    }

    /**
     * Every attempt has failed.
     */
    public function failed(?Throwable $error): void
    {
        $log = $this->log();

        if ($log === null) {
            return;
        }

        if ($error !== null) {
            $log->markError($error, forGood: true);
        } else {
            $log->update(['state' => 'failed']);
        }

        NotificationFeed::tellEveryone(new Notice(
            'An email could not be sent',
            "{$log->subject}, to ".implode(', ', $log->to).'. It can be sent again from the mail log.',
            route('mail-log.index', ['state' => 'failed'], false),
            'ri-mail-close-line',
        ));
    }

    private function log(): ?MailLog
    {
        return $this->logId === null ? null : MailLog::find($this->logId);
    }
}
