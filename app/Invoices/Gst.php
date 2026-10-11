<?php

namespace App\Invoices;

use App\Facades\Settings;
use App\Models\Product;

/**
 * The GST rate a new invoice line takes. A line keeps the rate it was made with: turning GST on
 * or off, or changing the rate, in the settings changes the lines made afterwards and no others.
 */
final class Gst
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('gst_enabled');
    }

    /**
     * The rate in basis points for a new line: none with GST off or for something GST-free,
     * otherwise the configured rate.
     */
    public static function rate(bool $gstFree = false): int
    {
        return self::enabled() && ! $gstFree ? (int) Settings::get('gst_rate') : 0;
    }

    /**
     * The rate for a line made from a product, or typed in by hand with none.
     */
    public static function rateFor(?Product $product): int
    {
        return self::rate($product?->gst_free ?? false);
    }
}
