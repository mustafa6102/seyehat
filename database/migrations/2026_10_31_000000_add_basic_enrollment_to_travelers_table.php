<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travelers', function (Blueprint $table): void {
            $table->dateTime('created_at', 6)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unique(['trip_id', 'idempotency_key'], 'travelers_trip_id_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('travelers', function (Blueprint $table): void {
            $table->dropUnique('travelers_trip_id_idempotency_key_unique');
            $table->dropColumn(['created_at', 'idempotency_key', 'request_fingerprint']);
        });
    }
};
