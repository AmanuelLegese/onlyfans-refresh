<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Profile extends Model
{
    use Searchable;

    protected $fillable = [
        'account_id',
        'username',
        'upstream_id',
        'likes',
        'revision',
        'name',
        'avatar_url',
        'posts_count',
        'photos_count',
        'videos_count',
        'profile_data',
        'last_attempt_at',
        'last_attempt_outcome',
        'last_success_at',
        'last_failure_at',
        'last_failure_reason',
        'last_failure_detail',
        'consecutive_failures',
        'next_refresh_at',
        'refresh_queued_at',
    ];

    protected function casts(): array
    {
        return [
            'likes' => 'integer',
            'revision' => 'integer',
            'posts_count' => 'integer',
            'photos_count' => 'integer',
            'videos_count' => 'integer',
            'profile_data' => 'array',
            'consecutive_failures' => 'integer',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'refresh_queued_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function refreshAttempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }

    public function searchableAs(): string
    {
        return 'profiles';
    }

    /**
     * Columns the Scout database engine searches (ilike on Postgres, like elsewhere).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
        ];
    }
}
