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
 *
 * A notice is something the client must be told whatever the owner's wording says (that no GST was
 * charged): the message gives it, and it follows the body.
 */
final readonly class FilledTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
        public string $signature,
        public ?string $note = null,
        /** @var list<string> */
        public array $notices = [],
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
     * The owner's note, the body, the notices and the signature, each as its paragraphs.
     *
     * @return array{note: list<string>, body: list<string>, notices: list<string>, signature: list<string>}
     */
    public function paragraphs(): array
    {
        return [
            'note' => Placeholders::paragraphs((string) $this->note),
            'body' => Placeholders::paragraphs($this->body),
            'notices' => array_values(array_filter(array_map(trim(...), $this->notices))),
            'signature' => Placeholders::paragraphs($this->signature),
        ];
    }

    /**
     * Only these two names go to the layout. A Mailable's public properties are given to its view
     * as well and win over these, so a message must not have a public `$filled` or `$owner`.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        return ['filled' => $this, 'owner' => app(Owner::class)];
    }
}
