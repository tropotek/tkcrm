<?php

namespace App\Jobs;

use App\Support\Owner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one message. Every email tkcrm sends on purpose goes through this job, on the queue, so
 * that a page never waits for the mail server and a failure is tried again.
 *
 * The message is built when the job runs, not when it is queued: its PDFs are made then.
 * It is sent from the owner company's address when one is chosen, and from MAIL_FROM_ADDRESS
 * otherwise.
 *
 * A send that fails is tried three more times, after one, five and fifteen minutes. After that
 * the job is failed for good.
 */
class SendMail implements ShouldQueue
{
    use Queueable;

    /** The first attempt and three more. */
    public int $tries = 4;

    public function __construct(public Mailable $message) {}

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
        if ($owner->email() !== null && $this->message->from === []) {
            $this->message->from($owner->email(), $owner->name());
        }

        Mail::send($this->message);
    }
}
