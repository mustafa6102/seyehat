<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->string('trip_id');
            $table->string('revision_id');
            $table->bigInteger('sequence')->nullable();
            $table->string('correlation_id')->nullable();

            $table->primary(['trip_id', 'revision_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
