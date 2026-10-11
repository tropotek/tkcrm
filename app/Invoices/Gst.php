<?php

namespace App\Invoices;

use App\Facades\Settings;

/**
 * GST is charged on a whole invoice at one rate, or not at all: it is not decided line by line,
 * and no product is exempt. The business is either registered for GST (the setting is on) or not.
 *
 * An open invoice is worked out at the rate in force today. Issuing stores the rate with the
 * totals, so an issued invoice keeps what it charged whatever the settings say later.
 */
final class Gst
{
    /**
     * Whether the business is registered, so that invoices issued now charge GST.
     */
    public static function enabled(): bool
    {
        return (bool) Settings::get('gst_enabled');
    }

    /**
     * The rate in basis points an invoice issued now would charge: 0 when GST is off.
     */
    public static function rate(): int
    {
        return self::enabled() ? (int) Settings::get('gst_rate') : 0;
    }
}
