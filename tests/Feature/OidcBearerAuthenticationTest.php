<?php

namespace Tests\Feature;

use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Application\TripRequestOwnershipGuard;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Application\OidcBearerAuthenticator;
use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\Authorization\Infrastructure\Web\SessionWebPrincipalResolver;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryTripRequestRepository;
use Tests\TestCase;

/**
 * ADR-010 / PLT-AUTH-001 (v3.16): OIDC bearer validation, PrincipalRef, TripRequest ownership (TS-PLT-AUTH-001-04..09).
 * Discovery and JWKS are faked; no real identity provider is contacted.
 */
final class OidcBearerAuthenticationTest extends TestCase
{
    private const ISSUER = 'https://idp.test/realms/seyahat';

    private const AUDIENCE = 'seyahat-api';

    private static ?string $privateKey = null;

    private static ?string $otherPrivateKey = null;

    private InMemoryTripRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        self::$privateKey ??= self::newRsaKey();
        self::$otherPrivateKey ??= self::newRsaKey();
        // The real authenticator and the production web resolver (TestCase binds test fixtures by default).
        $this->app->bind(BearerAuthenticator::class, OidcBearerAuthenticator::class);
        $this->app->instance(WebPrincipalResolver::class, new SessionWebPrincipalResolver);
        config(['services.oidc.issuer' => self::ISSUER, 'services.oidc.audience' => self::AUDIENCE, 'cache.default' => 'array']);
        $this->fakeIdentityProvider();
        $this->repository = new InMemoryTripRequestRepository;
        $this->app->instance(TripRequestService::class, new TripRequestApplicationService($this->repository));
    }

    #[Test]
    public function a_valid_asymmetric_token_yields_the_issuer_and_subject_principal(): void
    {
        $principal = $this->authenticate('Bearer '.$this->token());

        self::assertTrue($principal->equals(new PrincipalRef(self::ISSUER, 'user-123')));
        self::assertFalse($principal->equals(new PrincipalRef(self::ISSUER, 'USER-123')), 'subject comparison is case-sensitive');
    }

    #[Test]
    public function invalid_tokens_are_rejected(): void
    {
        $now = time();
        $cases = [
            'missing header' => null,
            'not bearer' => 'Basic '.base64_encode('a:b'),
            'malformed' => 'Bearer not-a-jwt',
            'alg none' => 'Bearer '.$this->unsigned(['alg' => 'none', 'kid' => 'kid-1']),
            'hmac with public key' => 'Bearer '.JWT::encode($this->claims(), $this->publicKeyPem(), 'HS256', 'kid-1'),
            'invalid signature' => 'Bearer '.JWT::encode($this->claims(), self::$otherPrivateKey, 'RS256', 'kid-1'),
            'unknown kid' => 'Bearer '.$this->token([], 'kid-unknown'),
            'wrong issuer' => 'Bearer '.$this->token(['iss' => 'https://evil.test']),
            'wrong audience' => 'Bearer '.$this->token(['aud' => 'other-api']),
            'expired' => 'Bearer '.$this->token(['exp' => $now - 1]),
            'not yet valid' => 'Bearer '.$this->token(['nbf' => $now + 600]),
            'missing exp' => 'Bearer '.$this->token(['exp' => null]),
            'missing subject' => 'Bearer '.$this->token(['sub' => null]),
            'empty subject' => 'Bearer '.$this->token(['sub' => '']),
        ];
        foreach ($cases as $label => $header) {
            try {
                $this->authenticate($header);
                self::fail("Accepted: {$label}");
            } catch (AuthenticationRequired) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function missing_or_invalid_configuration_fails_closed_without_calling_the_provider(): void
    {
        foreach ([['services.oidc.issuer' => null], ['services.oidc.audience' => null], ['services.oidc.issuer' => 'http://idp.test']] as $override) {
            config(array_merge(['services.oidc.issuer' => self::ISSUER, 'services.oidc.audience' => self::AUDIENCE], $override));
            Http::swap(new Factory);
            Http::fake();
            try {
                $this->authenticate('Bearer '.$this->token());
                self::fail('Accepted with incomplete configuration');
            } catch (AuthenticationRequired) {
                Http::assertNothingSent();
            }
        }
    }

    #[Test]
    public function unusable_discovery_or_jwks_fails_closed(): void
    {
        $responses = [
            'discovery 500' => [Http::response('down', 500), null],
            'discovery issuer mismatch' => [Http::response(['issuer' => 'https://other.test', 'jwks_uri' => 'https://idp.test/realms/seyahat/certs']), null],
            'jwks over http' => [Http::response(['issuer' => self::ISSUER, 'jwks_uri' => 'http://idp.test/realms/seyahat/certs']), null],
            'jwks only symmetric' => [null, Http::response(['keys' => [['kty' => 'oct', 'kid' => 'kid-1', 'k' => 'c2VjcmV0', 'alg' => 'HS256']]])],
        ];
        foreach ($responses as $label => [$discovery, $jwks]) {
            Http::swap(new Factory);
            $this->app['cache']->store()->flush();
            $this->fakeIdentityProvider($discovery, $jwks);
            try {
                $this->authenticate('Bearer '.$this->token());
                self::fail("Accepted: {$label}");
            } catch (AuthenticationRequired) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function create_trip_request_requires_a_bearer_token_and_stores_nothing_without_it(): void
    {
        $response = $this->postJson('/api/v1/trip-requests', $this->payload());

        $response->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED', 'message_tr' => 'Bu işlem için kimlik doğrulaması gerekiyor.', 'correlation_id' => null, 'details' => null]]);
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function authenticated_create_stores_the_principal_as_owner_without_exposing_it(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/v1/trip-requests', $this->payload());

        $response->assertOk();
        $stored = $this->repository->all()[0];
        self::assertTrue($stored->owner()->equals(new PrincipalRef(self::ISSUER, 'user-123')));
        $body = (string) $response->getContent();
        foreach (['owner', 'issuer', 'subject', 'user-123', 'idp.test'] as $needle) {
            self::assertStringNotContainsString($needle, $body);
        }
    }

    #[Test]
    public function owner_fields_cannot_be_supplied_through_the_public_request(): void
    {
        foreach (['owner_issuer', 'owner_subject', 'owner'] as $field) {
            $this->withToken($this->token())
                ->postJson('/api/v1/trip-requests', $this->payload() + [$field => 'attacker'])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function owner_is_persisted_once_and_never_changed(): void
    {
        $repository = $this->sqliteRepository();
        $created = $repository->save($this->draft()->ownedBy(new PrincipalRef(self::ISSUER, 'user-123')));

        // A later save of the same record carrying another owner cannot change the stored owner.
        $repository->save(new TripRequest(
            id: $created->id, status: $created->status, originPlace: $created->originPlace, destinationPlace: $created->destinationPlace,
            dateWindow: null, travelerCount: $created->travelerCount, preferences: null, journeyType: $created->journeyType,
            outboundDate: $created->outboundDate, ownerIssuer: 'https://evil.test', ownerSubject: 'attacker',
        ));
        $row = DB::table('trip_requests')->where('id', $created->id)->first();
        self::assertSame([self::ISSUER, 'user-123'], [$row->owner_issuer, $row->owner_subject]);
        self::assertTrue($repository->find($created->id)->owner()->equals(new PrincipalRef(self::ISSUER, 'user-123')));

        $this->expectException(\LogicException::class);
        $created->ownedBy(new PrincipalRef(self::ISSUER, 'someone-else'));
    }

    #[Test]
    public function ownership_guard_allows_only_the_exact_owner_and_denies_legacy_records(): void
    {
        $repository = $this->sqliteRepository();
        $owned = $repository->save($this->draft()->ownedBy(new PrincipalRef(self::ISSUER, 'user-123')));
        $legacy = $repository->save($this->draft());
        $guard = new TripRequestOwnershipGuard($repository);

        self::assertSame(OwnershipDecision::ALLOWED, $guard->check(new PrincipalRef(self::ISSUER, 'user-123'), $owned->id));
        self::assertSame(OwnershipDecision::DENIED, $guard->check(new PrincipalRef(self::ISSUER, 'user-456'), $owned->id));
        self::assertSame(OwnershipDecision::DENIED, $guard->check(new PrincipalRef(self::ISSUER, 'User-123'), $owned->id));
        self::assertSame(OwnershipDecision::DENIED, $guard->check(new PrincipalRef('https://other.test', 'user-123'), $owned->id));
        self::assertSame(OwnershipDecision::DENIED, $guard->check(new PrincipalRef(self::ISSUER, 'user-123'), $legacy->id), 'legacy records are never claimable');
        self::assertNull($repository->find($legacy->id)->owner());
        self::assertNull(DB::table('trip_requests')->where('id', $legacy->id)->value('owner_subject'), 'checking never claims a legacy record');
        self::assertSame(OwnershipDecision::NOT_FOUND, $guard->check(new PrincipalRef(self::ISSUER, 'user-123'), '999999'));
    }

    #[Test]
    public function web_creation_without_an_authenticated_principal_creates_nothing(): void
    {
        $this->from('/')->post('/planla', ['origin_value' => 'x'])
            ->assertRedirect(route('auth.login', ['donus' => '/']));
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function access_tokens_never_reach_logs_or_persistence(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $token = $this->token();
        $expired = $this->token(['exp' => time() - 1]);

        $this->withToken($token)->postJson('/api/v1/trip-requests', $this->payload())->assertOk();
        $this->withToken($expired)->postJson('/api/v1/trip-requests', $this->payload())->assertStatus(401);

        $stored = serialize($this->repository->all());
        foreach ([$token, $expired, explode('.', $token)[1], explode('.', $token)[2]] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
            foreach ($logged as $line) {
                self::assertStringNotContainsString($secret, $line);
            }
        }
    }

    private function authenticate(?string $header): PrincipalRef
    {
        return $this->app->make(BearerAuthenticator::class)->authenticate($header);
    }

    /** @param  array<string, mixed>  $overrides  null removes a claim */
    private function token(array $overrides = [], string $kid = 'kid-1'): string
    {
        return JWT::encode($this->claims($overrides), self::$privateKey, 'RS256', $kid);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = time();

        return array_filter(array_merge(['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'user-123', 'iat' => $now, 'exp' => $now + 300], $overrides), fn ($v) => $v !== null);
    }

    /** @param  array<string, mixed>  $header */
    private function unsigned(array $header): string
    {
        return JWT::urlsafeB64Encode((string) json_encode($header)).'.'.JWT::urlsafeB64Encode((string) json_encode($this->claims())).'.';
    }

    private function publicKeyPem(): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['key'];
    }

    private function fakeIdentityProvider(mixed $discovery = null, mixed $jwks = null): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['rsa'];
        Http::fake([
            'https://idp.test/realms/seyahat/.well-known/openid-configuration' => $discovery
                ?? Http::response(['issuer' => self::ISSUER, 'jwks_uri' => 'https://idp.test/realms/seyahat/certs']),
            'https://idp.test/realms/seyahat/certs' => $jwks ?? Http::response(['keys' => [[
                'kty' => 'RSA', 'kid' => 'kid-1', 'use' => 'sig',
                'n' => JWT::urlsafeB64Encode($details['n']), 'e' => JWT::urlsafeB64Encode($details['e']),
            ]]], 200, ['Cache-Control' => 'max-age=300']),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    private function sqliteRepository(): DatabaseTripRequestRepository
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        return new DatabaseTripRequestRepository(DB::connection());
    }

    private function draft(): TripRequest
    {
        return TripRequest::draft($this->payload())->normalize();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'status' => 'DRAFT',
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'airport', 'value' => 'NRT', 'iana_timezone' => 'Asia/Tokyo'],
            'journey_type' => 'ONE_WAY',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
            'traveler_count' => 1,
            'preferences' => null,
        ];
    }

    private static function newRsaKey(): string
    {
        // Windows PHP builds ship openssl.cnf under extras/ssl without configuring it.
        $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $config = is_file($cnf) ? ['config' => $cnf] : [];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key, 'RSA test key could not be generated.');
        openssl_pkey_export($key, $pem, null, $config);

        return $pem;
    }
}
