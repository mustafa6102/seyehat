<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCH-FlightSearchJob v3.4: association with the initiating TripRequest. Nullable because jobs created
 * before v3.4 have no known TripRequest; no backfill relationship is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_search_jobs', function (Blueprint $table): void {
            $table->string('trip_request_id')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('flight_search_jobs', function (Blueprint $table): void {
            $table->dropColumn('trip_request_id');
        });
    }
};
