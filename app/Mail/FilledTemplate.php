<?php

namespace App\Mail;

use App\Support\Owner;
use Illuminate\Mail\Mailables\Content;

/**
 * A message's wording with its placeholders filled, made by `MailTemplate::fill()`: the subject for
 * the envelope, and the body, the signature and the owner's note for the one mail layout
 * (`mail/layout.blade.php`, with `mail/layout-text.blade.php` as its plain text part).
 *
 * The body, signature and note are plain text. The layout escapes them and sets them as paragraphs.
 */
final readonly class FilledTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
        public string $signature,
        public ?string $note = null,
    ) {}

    /**
     * What a Mailable returns from content().
     */
    public function content(): Content
    {
        return new Content(html: 'mail.layout', text: 'mail.layout-text', with: $this->viewData());
    }

    /**
     * The HTML part alone, as the message is previewed on a page.
     */
    public function html(): string
    {
        return view('mail.layout', $this->viewData())->render();
    }

    public function text(): string
    {
        return view('mail.layout-text', $this->viewData())->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        return [
            'subject' => $this->subject,
            'note' => Placeholders::paragraphs((string) $this->note),
            'body' => Placeholders::paragraphs($this->body),
            'signature' => Placeholders::paragraphs($this->signature),
            'owner' => app(Owner::class),
        ];
    }
}
