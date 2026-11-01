<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->char('event_id', 36)->primary();
            $table->string('provider_id', 64);
            $table->string('provider_event_id', 128);
            $table->longText('event_json');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['provider_id', 'provider_event_id'], 'uq_events_provider');
        });

        Schema::create('event_discovery_jobs', function (Blueprint $table): void {
            $table->char('search_id', 36)->primary();
            $table->string('idempotency_key', 255);
            $table->string('trip_request_id', 255);
            $table->string('status', 16)->default('QUEUED');
            $table->longText('result_json')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message_tr', 255)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique('idempotency_key', 'uq_event_discovery_jobs_key');
            $table->index('trip_request_id', 'idx_event_discovery_jobs_trip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_discovery_jobs');
        Schema::dropIfExists('events');
    }
};
