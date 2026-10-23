<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_capabilities', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('provider_id');
            $table->string('operation');
            $table->string('environment');
            $table->boolean('supported');
            $table->string('status');
            $table->text('evidence')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->primary(['provider_id', 'operation', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_capabilities');
    }
};
