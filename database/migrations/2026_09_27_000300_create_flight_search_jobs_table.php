<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_search_jobs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('search_id')->primary();
            $table->string('idempotency_key', 255)->unique();
            $table->string('status', 16);
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message_tr')->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_search_jobs');
    }
};
