<?php

namespace App\Models;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Jobs\SendMail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use LogicException;
use Throwable;

/**
 * One message tkcrm sent, or tried to: what it was, who it was for, and how it went.
 *
 * `MailLog::send()` is how a message is sent: it records it and queues the job that sends it.
 * The row then follows the job: queued, then sent, or failed once every attempt has failed.
 *
 * @property MailKind $kind
 * @property MailState $state
 * @property list<string> $to
 * @property list<string> $cc
 * @property list<string> $bcc
 * @property ?CarbonImmutable $sent_at
 */
class MailLog extends Model
{
    protected $table = 'mail_log';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'queued',
        'attempts' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MailKind::class,
            'state' => MailState::class,
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a message and queue it for sending. Who it is for and its subject are read from the
     * message's envelope. `$related` is the record it is about, which must be in the morph map.
     */
    public static function send(Mailable $message, MailKind $kind, ?Model $related = null): self
    {
        $envelope = $message->envelope();
        $addresses = fn (array $list) => array_values(array_map(fn (Address|string $address) => is_string($address) ? $address : $address->address, $list));

        $log = new self([
            'kind' => $kind,
            'to' => $addresses($envelope->to),
            'cc' => $addresses($envelope->cc),
            'bcc' => $addresses($envelope->bcc),
            'subject' => (string) $envelope->subject,
            'message' => serialize(clone $message),
        ]);
        $log->related()->associate($related);
        $log->save();

        SendMail::dispatch($message, $log->id);

        return $log;
    }

    /**
     * The record the message is about, or null.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Everyone it was addressed to, copies included.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /**
     * An attempt is about to be made.
     */
    public function markAttempt(): void
    {
        $this->increment('attempts');
    }

    public function markSent(): void
    {
        $this->update(['state' => MailState::Sent, 'sent_at' => now(), 'error' => null]);
    }

    /**
     * An attempt failed. The message stays queued while the job has attempts left, and is failed
     * once it has none.
     */
    public function markError(Throwable $error, bool $forGood = false): void
    {
        $this->update(['state' => $forGood ? MailState::Failed : MailState::Queued, 'error' => mb_substr($error->getMessage(), 0, 2000)]);
    }

    public function canResend(): bool
    {
        return $this->state === MailState::Failed;
    }

    /**
     * Queue a failed message again, as it was. It starts over with a full set of attempts.
     */
    public function resend(): void
    {
        $this->canResend() || throw new LogicException('Only a message that failed can be sent again.');

        $message = unserialize($this->message);

        $message instanceof Mailable || throw new LogicException('This message can no longer be read, so it cannot be sent again.');

        $this->update(['state' => MailState::Queued, 'error' => null, 'attempts' => 0]);

        SendMail::dispatch($message, $this->id);
    }
}
