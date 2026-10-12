<?php

namespace App\Mail;

use App\Facades\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The day's list of what is waiting in the review queue, to the people who sign in. It carries
 * the list as it was when it was made, so what is sent is what was waiting then. It goes to no client.
 */
class ReviewDigestMail extends Mailable
{
    /**
     * @param  list<string>  $recipients  the logins' email addresses
     * @param  list<array{client: string, lines: int, total: string}>  $waiting  one per open invoice
     */
    public function __construct(public array $recipients, public array $waiting) {}

    public function envelope(): Envelope
    {
        $count = count($this->waiting);

        return new Envelope(
            to: $this->recipients,
            subject: ($count === 1 ? '1 invoice is' : "{$count} invoices are").' waiting for review in '.Settings::get('site_name'),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.review-digest', with: [
            'site' => Settings::get('site_name'),
            'url' => route('review.index'),
        ]);
    }
}
