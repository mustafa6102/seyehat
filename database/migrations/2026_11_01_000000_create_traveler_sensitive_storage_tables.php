<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Empty storage only: nothing writes here until SensitiveTravelerActivation opens. Foreign keys never cascade a delete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traveler_data_keys', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('traveler_id', 255)->primary();
            $table->binary('encrypted_dek');
            $table->string('key_version', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('rotated_at', 6)->nullable();
            $table->foreign('traveler_id', 'traveler_data_keys_traveler_fk')->references('id')->on('travelers')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::create('traveler_sensitive_fields', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('traveler_id', 255);
            $table->string('field_path', 32);
            $table->binary('ciphertext');
            $table->unsignedSmallInteger('aad_version');
            $table->string('schema_version', 16);
            $table->dateTime('updated_at', 6);
            $table->primary(['traveler_id', 'field_path']);
            $table->foreign('traveler_id', 'traveler_sensitive_fields_key_fk')->references('traveler_id')->on('traveler_data_keys')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traveler_sensitive_fields');
        Schema::dropIfExists('traveler_data_keys');
    }
};
