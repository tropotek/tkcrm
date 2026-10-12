<?php

namespace App\Domains;

/**
 * What one run of the site checks did.
 */
final readonly class SiteCheckRun
{
    /**
     * @param  bool  $skipped  this machine was offline, so nothing was recorded
     * @param  int  $checked  how many sites were checked and recorded
     * @param  int  $changed  how many went down or came up
     */
    public function __construct(
        public bool $skipped = false,
        public int $checked = 0,
        public int $answered = 0,
        public int $failed = 0,
        public int $changed = 0,
    ) {}
}
