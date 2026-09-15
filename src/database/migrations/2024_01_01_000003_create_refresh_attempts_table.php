<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->uuid('job_uuid');
            $table->unsignedInteger('queue_attempt')->default(1);
            $table->unsignedInteger('upstream_attempt')->default(1);
            $table->string('mode')->comment('legacy|fixed');
            $table->string('outcome')->comment('success|stale_revision|rate_limited|timeout|server_error|client_error|malformed');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('revision')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index('job_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_attempts');
    }
};
