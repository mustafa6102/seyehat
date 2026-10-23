<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travelers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('id')->primary();
            $table->string('trip_id')->index();
            $table->string('age_category');
            $table->json('nationalities');
            $table->json('travel_documents');
            $table->json('held_visas');
            $table->json('residence_permits');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travelers');
    }
};
