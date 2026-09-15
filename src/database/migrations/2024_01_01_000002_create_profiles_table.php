<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('username')->unique();
            $table->unsignedBigInteger('upstream_id')->nullable()->unique();
            $table->unsignedBigInteger('likes')->nullable()->comment('Upstream likes count');
            $table->unsignedBigInteger('revision')->nullable()->comment('Upstream revision number');
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->unsignedInteger('posts_count')->nullable();
            $table->unsignedInteger('photos_count')->nullable();
            $table->unsignedInteger('videos_count')->nullable();
            $table->jsonb('profile_data')->nullable()->comment('Whitelisted remaining fields');
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_attempt_outcome')->nullable()->comment('success|stale_revision|rate_limited|timeout|server_error|client_error|malformed');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->string('last_failure_detail', 500)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('next_refresh_at')->nullable()->index();
            $table->timestamp('refresh_queued_at')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
