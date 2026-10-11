<?php

namespace App\Enums;

/**
 * Where a logged message has got to. Queued covers one waiting between attempts.
 */
enum MailState: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The Bootstrap colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Queued => 'secondary',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
