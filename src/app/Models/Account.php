<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['name', 'credentials', 'max_concurrency'];

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
