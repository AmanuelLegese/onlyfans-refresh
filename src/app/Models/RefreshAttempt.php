<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'profile_id',
        'account_id',
        'job_uuid',
        'queue_attempt',
        'upstream_attempt',
        'mode',
        'outcome',
        'http_status',
        'revision',
        'duration_ms',
        'queued_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_attempt' => 'integer',
            'upstream_attempt' => 'integer',
            'http_status' => 'integer',
            'revision' => 'integer',
            'duration_ms' => 'integer',
            'queued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
