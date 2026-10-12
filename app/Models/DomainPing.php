<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainPingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of a domain: whether it passed, and what came back.
 *
 * @property bool $ok
 * @property ?int $http_status null where there was no reply at all
 * @property ?int $response_ms
 * @property ?array<string, mixed> $reply the reply, where it was JSON
 * @property CarbonImmutable $checked_at
 */
#[Fillable(['domain_id', 'ok', 'http_status', 'response_ms', 'reply', 'error', 'checked_at'])]
class DomainPing extends Model
{
    /** @use HasFactory<DomainPingFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'http_status' => 'integer',
            'response_ms' => 'integer',
            'reply' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
