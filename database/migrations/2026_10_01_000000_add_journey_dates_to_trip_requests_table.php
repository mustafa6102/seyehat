<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCH-TripRequest v3.9 (TRIP-001, OD-022 JOURNEY + DATE): direction-specific journey dates. Nullable; existing
 * rows keep their legacy date_window untouched and get no journey data (no backfill is guessed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->string('journey_type')->nullable()->after('date_window');
            $table->json('outbound_date')->nullable()->after('journey_type');
            $table->json('return_date')->nullable()->after('outbound_date');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->dropColumn(['journey_type', 'outbound_date', 'return_date']);
        });
    }
};
