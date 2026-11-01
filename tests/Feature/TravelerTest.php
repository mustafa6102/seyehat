<?php

namespace Tests\Feature;

use App\Modules\Traveler\Application\TravelerApplicationService;
use App\Modules\Traveler\Contracts\TravelerService;
use App\Modules\Traveler\Infrastructure\Logging\LogSensitiveDataAccessLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryTravelerRepository;
use Tests\TestCase;

final class TravelerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            TravelerService::class,
            new TravelerApplicationService(new InMemoryTravelerRepository, new LogSensitiveDataAccessLog),
        );
    }

    #[Test]
    public function ts_trv_001_01_dual_nationalities_and_distinct_document_collections_round_trip(): void
    {
        $payload = $this->travelerPayload();
        $service = app(TravelerService::class);

        self::assertSame($payload, $service->create($payload)->toContract());
        self::assertSame($payload, $service->get($payload['id'])?->toContract());
    }

    #[Test]
    public function ts_trv_001_02_child_and_infant_age_categories_are_preserved_without_inference(): void
    {
        $service = app(TravelerService::class);

        foreach (['CHILD', 'INFANT'] as $index => $ageCategory) {
            $payload = $this->travelerPayload();
            $payload['id'] = 'traveler-'.($index + 2);
            $payload['age_category'] = $ageCategory;

            self::assertSame($ageCategory, $service->create($payload)->ageCategory);
        }
    }

    #[Test]
    public function bearer_authentication_precedes_traveler_schema_validation(): void
    {
        $payload = $this->travelerPayload();
        $payload['provider_document_id'] = 'raw-provider-value';

        $this->postJson('/api/v1/travelers', $payload)->assertUnauthorized();
    }

    #[Test]
    public function malformed_pii_payload_cannot_bypass_the_bearer_gate(): void
    {
        $payload = $this->travelerPayload();
        $payload['nationalities'] = ['primary' => 'TR'];

        $this->postJson('/api/v1/travelers', $payload)->assertUnauthorized();
    }

    #[Test]
    public function empty_document_visa_and_permit_collections_are_preserved_by_the_domain_contract(): void
    {
        $payload = [...$this->travelerPayload(), 'nationalities' => [], 'travel_documents' => [], 'held_visas' => [], 'residence_permits' => []];

        self::assertSame($payload, app(TravelerService::class)->create($payload)->toContract());
    }

    #[Test]
    public function unauthenticated_unknown_traveler_lookup_does_not_reveal_resource_existence(): void
    {
        $this->getJson('/api/v1/travelers/missing')->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function travelerPayload(): array
    {
        return [
            'id' => 'traveler-1',
            'trip_id' => 'trip-1',
            'age_category' => 'ADULT',
            'nationalities' => ['TR', 'DE'],
            'travel_documents' => [
                ['document_type' => 'PASSPORT', 'verification_status' => 'UNVERIFIED'],
            ],
            'held_visas' => [
                ['verification_status' => 'FRESH'],
            ],
            'residence_permits' => [
                ['verification_status' => 'STALE'],
            ],
        ];
    }
}
