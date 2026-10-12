<?php

namespace App\Reports;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A payment as a report counts it: the reader's day it came in on, and how much of it is GST.
 */
final readonly class ReceivedPayment
{
    /**
     * @param  CarbonImmutable  $day  the day it was received, where the reader is
     * @param  Money  $gst  the GST in it: its share of the invoice's
     */
    public function __construct(
        public Payment $payment,
        public Invoice $invoice,
        public CarbonImmutable $day,
        public Money $gst,
    ) {}

    /**
     * What came in, GST and all.
     */
    public function amount(): Money
    {
        return $this->payment->amount;
    }

    /**
     * What came in without the GST: income.
     */
    public function net(): Money
    {
        return $this->payment->amount->subtract($this->gst);
    }
}
