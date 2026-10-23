<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('status');
            $table->json('origin_place')->nullable();
            $table->json('destination_place')->nullable();
            $table->json('date_window')->nullable();
            $table->unsignedInteger('traveler_count')->nullable();
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_requests');
    }
};
