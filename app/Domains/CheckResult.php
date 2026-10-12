<?php

namespace App\Domains;

/**
 * What one request to a site came to, before anything is recorded.
 */
final readonly class CheckResult
{
    /**
     * @param  ?int  $status  the HTTP status, or null where there was no reply
     * @param  ?int  $milliseconds  how long the reply took, where that is known
     * @param  ?array<string, mixed>  $reply  the reply, where it was a JSON object of a size worth keeping
     * @param  ?string  $error  why it failed, in a few words
     */
    public function __construct(
        public bool $ok,
        public ?int $status = null,
        public ?int $milliseconds = null,
        public ?array $reply = null,
        public ?string $error = null,
    ) {}
}
