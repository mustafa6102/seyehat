<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Empty storage only: nothing writes here while SensitiveTravelerActivation is closed. Foreign keys never cascade a delete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traveler_subject_bindings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('traveler_id', 255)->primary();
            $table->char('trip_id', 36);
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->string('status', 16);
            $table->string('proof_ref', 255);
            $table->dateTime('verified_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->unique(['trip_id', 'issuer', 'subject'], 'traveler_subject_bindings_principal_unique');
            $table->foreign('traveler_id', 'traveler_subject_bindings_traveler_fk')->references('id')->on('travelers')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('trip_id', 'traveler_subject_bindings_trip_fk')->references('trip_id')->on('trips')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traveler_subject_bindings');
    }
};
