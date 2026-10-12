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

        $recipients = User::orderBy('id')->pluck('email')->all();

        if ($waiting === [] || $recipients === []) {
            $this->components->info($waiting === [] ? 'Nothing is waiting, so no digest was sent.' : 'There is no login to send the digest to.');

            return self::SUCCESS;
        }

        MailLog::send(new ReviewDigestMail($recipients, $waiting), MailKind::Digest);

        $this->components->info('A digest of '.count($waiting).' waiting '.(count($waiting) === 1 ? 'invoice' : 'invoices').' is queued to go to '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }
}
