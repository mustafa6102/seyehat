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

        $response = $this->postJson('/api/v1/travelers', $payload)
            ->assertOk()
            ->assertExactJson($payload);

        $location = $response->headers->get('Location');
        self::assertNotNull($location);

        $this->getJson($location)
            ->assertOk()
            ->assertExactJson($payload);
    }

    #[Test]
    public function ts_trv_001_02_child_and_infant_age_categories_are_preserved_without_inference(): void
    {
        foreach (['CHILD', 'INFANT'] as $index => $ageCategory) {
            $payload = $this->travelerPayload();
            $payload['id'] = 'traveler-'.($index + 2);
            $payload['age_category'] = $ageCategory;

            $this->postJson('/api/v1/travelers', $payload)
                ->assertOk()
                ->assertJsonPath('age_category', $ageCategory);
        }
    }

    #[Test]
    public function traveler_contract_rejects_non_canonical_and_provider_specific_fields(): void
    {
        $payload = $this->travelerPayload();
        $payload['provider_document_id'] = 'raw-provider-value';

        $this->postJson('/api/v1/travelers', $payload)
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED')
            ->assertJsonPath('error.message_tr', 'İstek sözleşme doğrulamasından geçemedi.');

        $payload = $this->travelerPayload();
        $payload['held_visas'][0]['country_code'] = 'JP';

        $this->postJson('/api/v1/travelers', $payload)
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
    }

    #[Test]
    public function collections_must_be_json_arrays_and_nested_values_must_match_the_contract(): void
    {
        $cases = [
            'nationalities object' => ['nationalities', ['primary' => 'TR']],
            'documents object' => ['travel_documents', ['main' => ['document_type' => 'PASSPORT', 'verification_status' => 'FRESH']]],
            'visas object' => ['held_visas', ['x' => ['verification_status' => 'FRESH']]],
            'permits object' => ['residence_permits', ['x' => ['verification_status' => 'FRESH']]],
            'nested nationality' => ['nationalities', [['TR']]],
            'unknown verification status' => ['held_visas', [['verification_status' => 'VALID']]],
            'document without type' => ['travel_documents', [['verification_status' => 'FRESH']]],
        ];

        foreach ($cases as $name => [$field, $value]) {
            $payload = $this->travelerPayload();
            $payload[$field] = $value;

            $this->postJson('/api/v1/travelers', $payload)
                ->assertBadRequest()
                ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
    }

    #[Test]
    public function empty_document_visa_and_permit_collections_are_accepted_as_the_contract_allows(): void
    {
        $payload = [...$this->travelerPayload(), 'nationalities' => [], 'travel_documents' => [], 'held_visas' => [], 'residence_permits' => []];

        $this->postJson('/api/v1/travelers', $payload)
            ->assertOk()
            ->assertExactJson($payload);
    }

    #[Test]
    public function unknown_traveler_returns_turkish_not_found_error(): void
    {
        $this->getJson('/api/v1/travelers/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('error.message_tr', 'Yolcu bulunamadı.');
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
