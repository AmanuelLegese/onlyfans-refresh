<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('credentials')->comment('Encrypted array of upstream credentials');
            $table->unsignedInteger('max_concurrency')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
