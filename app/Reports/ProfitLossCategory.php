<?php

namespace App\Reports;

use App\Support\Money;

/**
 * One expense category's line of a profit and loss.
 */
final readonly class ProfitLossCategory
{
    /**
     * @param  int  $claimRatio  basis points of an expense in it that can be claimed
     * @param  int  $count  how many expenses it had in the year
     * @param  Money  $total  what was spent, without GST
     * @param  Money  $gst  the GST that was in what was paid
     * @param  Money  $claimable  each expense's share, rounded by itself, added up
     */
    public function __construct(
        public int $categoryId,
        public string $name,
        public int $claimRatio,
        public int $count,
        public Money $total,
        public Money $gst,
        public Money $claimable,
    ) {}
}
