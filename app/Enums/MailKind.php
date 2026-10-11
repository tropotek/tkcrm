<?php

namespace App\Enums;

/**
 * What a logged message is.
 */
enum MailKind: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case Statement = 'statement';
    case Reminder = 'reminder';
    case SiteAlert = 'site_alert';
    case Digest = 'digest';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Receipt => 'Receipt',
            self::Statement => 'Statement',
            self::Reminder => 'Overdue reminder',
            self::SiteAlert => 'Site alert',
            self::Digest => 'Review digest',
            self::Test => 'Test',
        };
    }
}
