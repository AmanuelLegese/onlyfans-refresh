<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const SOURCE_FAKE = 'fake';

    public const SOURCE_ONLYFANS = 'onlyfans';

    protected $fillable = ['name', 'source', 'credentials', 'max_concurrency'];

    protected $attributes = [
        'source' => self::SOURCE_FAKE,
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'max_concurrency' => 'integer',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function refreshAttempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }
}
