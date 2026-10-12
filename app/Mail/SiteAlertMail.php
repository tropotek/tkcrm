<?php

namespace App\Mail;

use App\Facades\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the people who sign in that a watched site has gone down or come back. It carries what
 * it says as it was when the site changed, so a message sent late still says what happened then.
 * It goes to no client.
 */
class SiteAlertMail extends Mailable
{
    /**
     * @param  list<string>  $recipients  the logins' email addresses
     * @param  string  $headline  "wattlecreek.example.com is down"
     * @param  list<string>  $lines  what is known, a line each
     * @param  string  $link  the site's page
     */
    public function __construct(public array $recipients, public string $headline, public array $lines, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(to: $this->recipients, subject: $this->headline);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.site-alert', with: ['site' => Settings::get('site_name')]);
    }
}
