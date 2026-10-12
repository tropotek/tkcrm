<?php

namespace App\Models;

use App\Enums\DomainState;
use Carbon\CarbonImmutable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's site that is watched.
 *
 * Its state, its count of failures, when it was checked and what it last reported are not
 * fillable: the checks keep them.
 *
 * @property DomainState $state
 * @property ?CarbonImmutable $state_changed_at
 * @property int $failures how many checks in a row have failed
 * @property ?CarbonImmutable $checked_at
 * @property ?CarbonImmutable $last_ok_at
 * @property ?CarbonImmutable $version_changed_at
 * @property ?array<string, mixed> $details what the site last reported of itself, where its reply was JSON
 */
#[Fillable(['company_id', 'url', 'notes', 'active', 'alerts'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'alerts' => true,
        'state' => 'unknown',
        'failures' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'alerts' => 'boolean',
            'state' => DomainState::class,
            'state_changed_at' => 'immutable_datetime',
            'failures' => 'integer',
            'checked_at' => 'immutable_datetime',
            'last_ok_at' => 'immutable_datetime',
            'version_changed_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Every check made of it, the latest first.
     *
     * @return HasMany<DomainPing, $this>
     */
    public function pings(): HasMany
    {
        return $this->hasMany(DomainPing::class)->orderByDesc('checked_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInState(Builder $query, DomainState $state): void
    {
        $query->where('state', $state);
    }

    /**
     * The address as it is shown: without its scheme, or a slash with nothing after it.
     */
    public function host(): string
    {
        return rtrim((string) preg_replace('#^https?://#i', '', $this->url), '/');
    }
}
