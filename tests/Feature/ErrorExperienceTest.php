<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\ServiceOwnedTripRequests;
use Tests\TestCase;

/** Turkish, contract-shaped error handling for the implemented M1A web and API surface. */
final class ErrorExperienceTest extends TestCase
{
    public const LEAK = 'SQLSTATE[HY000] secret-db-password /var/www/app/Secret.php';

    #[Test]
    public function api_routing_errors_use_the_error_envelope_with_registered_codes(): void
    {
        $this->getJson('/api/v1/bilinmeyen')
            ->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message_tr' => 'İstenen kaynak bulunamadı.', 'correlation_id' => null, 'details' => null]]);

        $this->deleteJson('/api/v1/trip-requests')
            ->assertStatus(405)
            ->assertJsonPath('error.code', null)
            ->assertJsonPath('error.message_tr', 'Bu işlem bu adres için desteklenmiyor.');
    }

    #[Test]
    public function unexpected_failures_never_leak_internal_details_on_api_web_or_location_endpoints(): void
    {
        config(['app.debug' => false]);
        $this->app->instance(TripRequestService::class, new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException(ErrorExperienceTest::LEAK);
            }

            public function get(string $id): ?TripRequestView
            {
                throw new RuntimeException(ErrorExperienceTest::LEAK);
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                throw new RuntimeException(ErrorExperienceTest::LEAK);
            }
        });
        $catalog = $this->createMock(LocationCatalog::class);
        $catalog->method('isAvailable')->willReturn(true);
        $catalog->method('countries')->willThrowException(new RuntimeException(self::LEAK));
        $this->app->instance(LocationCatalog::class, $catalog);

        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $api = $this->withToken(FixedBearerAuthenticator::TOKEN)->getJson('/api/v1/trip-requests/1')->assertStatus(500)
            ->assertJsonPath('error.message_tr', 'Beklenmeyen bir hata oluştu; lütfen daha sonra yeniden deneyin.');
        $location = $this->getJson('/konumlar/ulkeler')->assertStatus(500)
            ->assertJsonPath('error.message_tr', 'Beklenmeyen bir hata oluştu; lütfen daha sonra yeniden deneyin.');
        $web = $this->get('/planlar/1')->assertStatus(500)->assertSee('Beklenmeyen bir hata oluştu');

        foreach ([$api, $location, $web] as $response) {
            foreach (['SQLSTATE', 'secret-db-password', 'Secret.php', 'RuntimeException', 'Server Error'] as $leak) {
                self::assertStringNotContainsString($leak, $response->getContent());
            }
        }
    }

    #[Test]
    public function web_error_pages_are_turkish(): void
    {
        $this->get('/olmayan-sayfa')->assertNotFound()->assertSee('Aradığınız kayıt bulunamadı');
        self::assertStringContainsString('Çok fazla istek gönderildi', view('errors.429')->render());
        self::assertStringContainsString('Hizmet şu anda kullanılamıyor', view('errors.503')->render());
        foreach (['404', '419', '429', '500', '503'] as $page) {
            self::assertStringContainsString('<html lang="tr">', view("errors.{$page}")->render());
        }
    }
}
