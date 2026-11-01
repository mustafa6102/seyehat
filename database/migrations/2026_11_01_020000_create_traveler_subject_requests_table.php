<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Empty storage only. No foreign key on purpose: the record of a deletion must outlive the deleted Traveler. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traveler_subject_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('id', 36)->primary();
            $table->string('trip_request_id', 255);
            $table->char('trip_id', 36);
            $table->string('traveler_id', 255);
            $table->string('request_type', 16);
            $table->string('status', 16);
            $table->boolean('is_open')->nullable();
            $table->char('subject_id', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('processed_at', 6)->nullable();
            $table->string('processed_by', 255)->nullable();
            $table->string('decision_ref', 255)->nullable();
            $table->unique(['traveler_id', 'request_type', 'is_open'], 'traveler_subject_requests_open_unique');
            $table->index(['is_open', 'created_at'], 'traveler_subject_requests_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traveler_subject_requests');
    }
};
