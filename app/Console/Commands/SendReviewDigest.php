<?php

namespace App\Console\Commands;

use App\Enums\MailKind;
use App\Mail\ReviewDigestMail;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\User;
use App\Review\ReviewQueue;
use Illuminate\Console\Command;

class SendReviewDigest extends Command
{
    protected $signature = 'tkcrm:send-review-digest';

    protected $description = 'Email every login the list of what is waiting in the review queue. Sends nothing when the queue is empty';

    public function handle(): int
    {
        $waiting = ReviewQueue::invoices()->get()->map(fn (Invoice $invoice) => [
            'client' => $invoice->company->name,
            'lines' => $invoice->lines->count(),
            'total' => $invoice->totals()->total->format(),
        ])->all();

        $overdue = ReviewQueue::reminders()->get()->map(fn (Invoice $invoice) => [
            'client' => $invoice->company->name,
            'number' => $invoice->number,
            'owing' => $invoice->outstanding()->format(),
            'days' => $invoice->daysOverdue(),
        ])->all();

        $recipients = User::orderBy('id')->pluck('email')->all();
        $count = count($waiting) + count($overdue);

        if ($count === 0 || $recipients === []) {
            $this->components->info($count === 0 ? 'Nothing is waiting, so no digest was sent.' : 'There is no login to send the digest to.');

            return self::SUCCESS;
        }

        MailLog::send(new ReviewDigestMail($recipients, $waiting, $overdue), MailKind::Digest);

        $this->components->info('A digest of '.$count.' waiting '.($count === 1 ? 'invoice' : 'invoices').' is queued to go to '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }
}
