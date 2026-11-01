<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->unsignedInteger('room_count')->nullable()->after('passenger_composition');
            $table->string('owner_issuer', 255)->nullable()->after('room_count');
            $table->string('owner_subject', 255)->nullable()->after('owner_issuer');
            $table->index(['owner_issuer', 'owner_subject']);
        });

        Schema::create('trip_request_flight_selections', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('trip_request_id', 255)->primary();
            $table->string('status', 32);
            $table->longText('offer_snapshot');
            $table->string('provider_id', 64);
            $table->string('provider_offer_id', 255);
            $table->bigInteger('verified_price_minor_units')->nullable();
            $table->char('verified_price_currency', 3)->nullable();
            $table->dateTime('verified_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['provider_id', 'provider_offer_id', 'status'], 'trfs_provider_offer_status_idx');
        });

        foreach (['accommodation_search_jobs', 'poi_discovery_jobs'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('search_id', 36)->primary();
                $table->string('idempotency_key', 255)->unique();
                $table->string('trip_request_id', 255);
                $table->string('status', 16);
                $table->longText('result_json')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->string('error_message_tr', 255)->nullable();
                $table->dateTime('expires_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->index(['trip_request_id', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }

        Schema::create('trip_request_accommodation_selections', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('trip_request_id', 255)->primary();
            $table->string('status', 32);
            $table->longText('offer_snapshot');
            $table->string('provider_id', 64);
            $table->string('provider_offer_id', 255);
            $table->bigInteger('verified_price_minor_units')->nullable();
            $table->char('verified_price_currency', 3)->nullable();
            $table->dateTime('verified_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['provider_id', 'provider_offer_id', 'status'], 'tras_provider_offer_status_idx');
        });

        Schema::create('pois', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('poi_id', 36)->primary();
            $table->string('provider_id', 64);
            $table->string('provider_place_id', 64);
            $table->longText('poi_json');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['provider_id', 'provider_place_id'], 'pois_provider_place_unique');
        });

        Schema::create('trips', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('trip_id', 36)->primary();
            $table->string('trip_request_id', 255)->unique();
            $table->dateTime('created_at', 6);
        });

        Schema::create('plan_revisions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('trip_id', 36);
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->longText('items_json');
            $table->longText('change_json');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->dateTime('created_at', 6);
            $table->primary(['trip_id', 'version']);
            $table->unique(['trip_id', 'idempotency_key']);
            $table->index(['trip_id', 'status']);
            $table->foreign('trip_id', 'plan_revisions_trip_fk')->references('trip_id')->on('trips')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::create('how_operation_idempotency', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('scope_hash', 64);
            $table->char('principal_hash', 64);
            $table->string('operation', 64);
            $table->string('trip_request_id', 255);
            $table->string('item_id', 255);
            $table->unsignedInteger('base_revision_version');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->primary(['scope_hash', 'idempotency_key']);
            $table->index('trip_request_id', 'how_idem_trip_request_index');
            $table->index(['status', 'created_at'], 'how_idem_status_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `trip_requests` ADD CONSTRAINT `trip_requests_room_count_chk` CHECK (`room_count` IS NULL OR `room_count` >= 1)');
            DB::statement('ALTER TABLE `trip_requests` ADD CONSTRAINT `trip_requests_owner_pair_chk` CHECK ((`owner_issuer` IS NULL AND `owner_subject` IS NULL) OR (`owner_issuer` IS NOT NULL AND `owner_subject` IS NOT NULL))');
            DB::statement("ALTER TABLE `trip_request_flight_selections` ADD CONSTRAINT `trfs_status_chk` CHECK (`status` IN ('CURRENT', 'RECONFIRMATION_REQUIRED', 'EXPIRED')), ADD CONSTRAINT `trfs_verified_price_pair_chk` CHECK ((`verified_price_minor_units` IS NULL AND `verified_price_currency` IS NULL) OR (`verified_price_minor_units` IS NOT NULL AND `verified_price_currency` IS NOT NULL))");
            DB::statement("ALTER TABLE `accommodation_search_jobs` ADD CONSTRAINT `accommodation_search_jobs_status_chk` CHECK (`status` IN ('QUEUED', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED', 'EXPIRED'))");
            DB::statement("ALTER TABLE `trip_request_accommodation_selections` ADD CONSTRAINT `tras_status_chk` CHECK (`status` IN ('CURRENT', 'RECONFIRMATION_REQUIRED', 'EXPIRED')), ADD CONSTRAINT `tras_verified_price_pair_chk` CHECK ((`verified_price_minor_units` IS NULL AND `verified_price_currency` IS NULL) OR (`verified_price_minor_units` IS NOT NULL AND `verified_price_currency` IS NOT NULL))");
            DB::statement("ALTER TABLE `poi_discovery_jobs` ADD CONSTRAINT `poi_discovery_jobs_status_chk` CHECK (`status` IN ('QUEUED', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED', 'EXPIRED'))");
            DB::statement("ALTER TABLE `plan_revisions` ADD CONSTRAINT `plan_revisions_status_chk` CHECK (`status` IN ('CURRENT', 'SUPERSEDED')), ADD CONSTRAINT `plan_revisions_version_chk` CHECK (`version` >= 1)");
            DB::statement("ALTER TABLE `how_operation_idempotency` ADD CONSTRAINT `how_idem_version_chk` CHECK (`base_revision_version` >= 1), ADD CONSTRAINT `how_idem_status_chk` CHECK (`status` IN ('IN_PROGRESS', 'COMPLETED')), ADD CONSTRAINT `how_idem_response_chk` CHECK ((`status` = 'IN_PROGRESS' AND `response_status` IS NULL AND `response_json` IS NULL AND `completed_at` IS NULL) OR (`status` = 'COMPLETED' AND `response_status` IS NOT NULL AND `response_json` IS NOT NULL AND `completed_at` IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('how_operation_idempotency');
        Schema::dropIfExists('plan_revisions');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('pois');
        Schema::dropIfExists('trip_request_accommodation_selections');
        Schema::dropIfExists('poi_discovery_jobs');
        Schema::dropIfExists('accommodation_search_jobs');
        Schema::dropIfExists('trip_request_flight_selections');

        Schema::table('trip_requests', function (Blueprint $table): void {
            $table->dropIndex(['owner_issuer', 'owner_subject']);
            $table->dropColumn(['room_count', 'owner_issuer', 'owner_subject']);
        });
    }
};
