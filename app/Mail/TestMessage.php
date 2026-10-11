<?php

namespace App\Mail;

use App\Facades\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message that says only that mail works, sent from the settings page to whoever asked for it.
 */
class TestMessage extends Mailable
{
    public function __construct(public string $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(to: [$this->recipient], subject: 'A test email from '.Settings::get('site_name'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test', with: ['site' => Settings::get('site_name')]);
    }
}
