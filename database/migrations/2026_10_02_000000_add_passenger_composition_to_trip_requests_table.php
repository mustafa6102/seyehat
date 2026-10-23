<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->json('passenger_composition')->nullable()->after('traveler_count');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->dropColumn('passenger_composition');
        });
    }
};
