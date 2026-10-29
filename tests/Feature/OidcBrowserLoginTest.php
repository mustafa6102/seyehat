<?php

namespace Tests\Feature;

use App\Http\Controllers\OidcLoginController;
use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Application\OidcBearerAuthenticator;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\Authorization\Infrastructure\Web\SessionWebPrincipalResolver;
use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryTripRequestRepository;
use Tests\TestCase;

/**
 * ADR-010 browser login: OIDC Authorization Code + PKCE (S256) against a fake issuer; the session principal drives /planla.
 * No real identity provider is contacted.
 */
final class OidcBrowserLoginTest extends TestCase
{
    private const ISSUER = 'https://idp.test/realms/seyahat';

    private const CLIENT_ID = 'seyahat-web';

    private static ?string $privateKey = null;

    /** @var array<string, mixed> claim overrides the fake token endpoint signs into the next ID token (null removes a claim) */
    private array $idTokenClaims = [];

    private ?string $lastNonce = null;

    private ?string $issuedIdToken = null;

    private InMemoryTripRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        self::$privateKey ??= self::newRsaKey();
        $this->app->bind(WebPrincipalResolver::class, SessionWebPrincipalResolver::class);
        $this->app->bind(BearerAuthenticator::class, OidcBearerAuthenticator::class);
        config([
            'services.oidc.issuer' => self::ISSUER,
            'services.oidc.audience' => 'seyahat-api',
            'services.oidc.client_id' => self::CLIENT_ID,
            'services.oidc.client_secret' => null,
            'services.oidc.redirect_uri' => 'http://localhost/giris/donus',
            'cache.default' => 'array',
        ]);
        $this->fakeIssuer();
        $this->repository = new InMemoryTripRequestRepository;
        $this->app->instance(TripRequestService::class, new TripRequestApplicationService($this->repository));
    }

    #[Test]
    public function signed_out_planner_submission_persists_nothing(): void
    {
        // Signed out, the planner sends the user to the OIDC login and creates nothing (RULE-PLT-AUTH-001-04).
        $this->from('/')->post('/planla', $this->plannerForm())->assertRedirect(route('auth.login', ['donus' => '/']));
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function login_start_redirects_with_code_flow_state_nonce_and_s256_pkce(): void
    {
        $location = $this->get('/giris?donus=/planlar/5/plan')->assertRedirect()->headers->get('Location');

        self::assertStringStartsWith('https://idp.test/realms/seyahat/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $pending = session(SessionWebPrincipalResolver::PENDING_LOGIN_KEY);
        self::assertSame('code', $query['response_type']);
        self::assertSame([self::CLIENT_ID, 'http://localhost/giris/donus', 'openid', 'S256'], [$query['client_id'], $query['redirect_uri'], $query['scope'], $query['code_challenge_method']]);
        self::assertSame($pending['state'], $query['state']);
        self::assertSame($pending['nonce'], $query['nonce']);
        self::assertGreaterThanOrEqual(43, strlen($query['state']));
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        self::assertArrayNotHasKey('code_verifier', $query);
        self::assertSame('/planlar/5/plan', $pending['return_to']);
    }

    #[Test]
    public function a_valid_callback_establishes_the_issuer_and_subject_principal_and_regenerates_the_session(): void
    {
        $state = $this->startLogin('/planlar/5/plan');
        $before = session()->getId();
        $this->idTokenClaims = ['email' => 'someone@example.test', 'name' => 'Başka Biri', 'preferred_username' => 'other'];

        $this->get('/giris/donus?code=auth-code&state='.$state)->assertRedirect('/planlar/5/plan');

        self::assertNotSame($before, session()->getId());
        self::assertSame(['issuer' => self::ISSUER, 'subject' => 'user-123'], session(SessionWebPrincipalResolver::SESSION_KEY));
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://idp.test/realms/seyahat/token'
            && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code'
            && $request['redirect_uri'] === 'http://localhost/giris/donus' && $request['client_id'] === self::CLIENT_ID
            && is_string($request['code_verifier']) && ! isset($request['client_secret']));
    }

    #[Test]
    public function wrong_missing_or_replayed_state_is_rejected_before_token_processing(): void
    {
        foreach (['wrong' => '&state=not-the-state', 'missing' => ''] as $label => $suffix) {
            $this->startLogin('/');
            $this->get('/giris/donus?code=auth-code'.$suffix)->assertRedirect('/')
                ->assertSessionHas('auth_error', 'Giriş tamamlanamadı. Lütfen yeniden deneyin.');
            self::assertNull(session(SessionWebPrincipalResolver::SESSION_KEY), $label);
        }
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/token'));

        // The login state is single-use: a second callback with the same (now consumed) state is refused.
        $state = $this->startLogin('/');
        $this->get('/giris/donus?code=auth-code&state='.$state)->assertRedirect('/');
        $this->post('/cikis');
        $this->get('/giris/donus?code=auth-code&state='.$state)->assertSessionHas('auth_error');
        self::assertNull(session(SessionWebPrincipalResolver::SESSION_KEY));
    }

    #[Test]
    public function invalid_id_tokens_are_rejected(): void
    {
        $cases = [
            'wrong issuer' => ['iss' => 'https://evil.test'],
            'wrong audience' => ['aud' => 'other-client'],
            'missing subject' => ['sub' => null],
            'wrong nonce' => ['nonce' => 'not-the-nonce'],
            'missing nonce' => ['nonce' => null],
            'expired' => ['exp' => time() - 1],
            'several audiences without azp' => ['aud' => [self::CLIENT_ID, 'other']],
        ];
        foreach ($cases as $label => $claims) {
            $state = $this->startLogin('/');
            $this->idTokenClaims = $claims;
            $this->get('/giris/donus?code=c&state='.$state)->assertSessionHas('auth_error');
            self::assertNull(session(SessionWebPrincipalResolver::SESSION_KEY), $label);
        }
    }

    #[Test]
    public function the_signed_in_principal_owns_web_created_trip_requests_regardless_of_form_fields(): void
    {
        $this->login();

        $this->post('/planla', $this->plannerForm() + ['owner_issuer' => 'https://evil.test', 'owner_subject' => 'attacker', 'email' => 'x@y.z'])
            ->assertRedirect();

        $stored = $this->repository->all();
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->owner()->equals(new PrincipalRef(self::ISSUER, 'user-123')));
    }

    #[Test]
    public function logout_removes_the_principal_and_planner_creation_is_denied_again(): void
    {
        $this->login();
        $this->post('/cikis')->assertRedirect('/');

        self::assertNull(session(SessionWebPrincipalResolver::SESSION_KEY));
        $this->from('/')->post('/planla', $this->plannerForm())->assertRedirect(route('auth.login', ['donus' => '/']));
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function unsafe_return_paths_are_replaced_by_the_application_root(): void
    {
        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', 'javascript:alert(1)', "/ok\nX", ''] as $unsafe) {
            self::assertSame('/', OidcLoginController::safePath($unsafe), $unsafe);
        }
        self::assertSame('/planlar/5/plan', OidcLoginController::safePath('/planlar/5/plan'));

        $state = $this->startLogin('https://evil.test/steal');
        $this->get('/giris/donus?code=c&state='.$state)->assertRedirect('/');
    }

    #[Test]
    public function missing_browser_login_configuration_fails_closed_in_turkish(): void
    {
        config(['services.oidc.client_id' => null]);

        $this->get('/giris')->assertRedirect('/')->assertSessionHas('auth_error', 'Giriş şu anda kullanılamıyor: kimlik sağlayıcı yapılandırılmadı.');
        Http::assertNothingSent();
    }

    #[Test]
    public function tokens_stay_out_of_session_logs_responses_and_ownership(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $this->login();
        $page = $this->post('/planla', $this->plannerForm())->getContent().$this->get('/')->getContent();

        $idToken = (string) $this->issuedIdToken;
        self::assertNotSame('', $idToken);
        $stored = json_encode(session()->all()).serialize($this->repository->all()).$page.implode('', $logged);
        foreach ([$idToken, explode('.', $idToken)[1], 'opaque-access-token'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
    }

    #[Test]
    public function a_browser_session_does_not_authenticate_the_bearer_api(): void
    {
        $this->login();

        $this->postJson('/api/v1/trip-requests', ['status' => 'DRAFT'])->assertStatus(401);
        self::assertSame([], $this->repository->all());
    }

    private function login(): void
    {
        $state = $this->startLogin('/');
        $this->get('/giris/donus?code=auth-code&state='.$state)->assertRedirect('/');
        self::assertNotNull(session(SessionWebPrincipalResolver::SESSION_KEY));
    }

    private function startLogin(string $returnTo): string
    {
        $this->get('/giris?donus='.rawurlencode($returnTo))->assertRedirect();
        $pending = session(SessionWebPrincipalResolver::PENDING_LOGIN_KEY);
        // The callback removes the pending state before the token request, so the fake issuer echoes this nonce.
        $this->lastNonce = $pending['nonce'];

        return $pending['state'];
    }

    private function fakeIssuer(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['rsa'];
        Http::fake([
            'https://idp.test/realms/seyahat/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://idp.test/realms/seyahat/auth',
                'token_endpoint' => 'https://idp.test/realms/seyahat/token',
                'jwks_uri' => 'https://idp.test/realms/seyahat/certs',
            ]),
            'https://idp.test/realms/seyahat/certs' => Http::response(['keys' => [[
                'kty' => 'RSA', 'kid' => 'kid-1', 'use' => 'sig',
                'n' => JWT::urlsafeB64Encode($details['n']), 'e' => JWT::urlsafeB64Encode($details['e']),
            ]]]),
            'https://idp.test/realms/seyahat/token' => function (): PromiseInterface {
                $now = time();
                $claims = array_filter(array_merge([
                    'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => 'user-123', 'iat' => $now, 'exp' => $now + 300,
                    'nonce' => $this->lastNonce,
                ], $this->idTokenClaims), fn ($value) => $value !== null);
                $this->issuedIdToken = JWT::encode($claims, self::$privateKey, 'RS256', 'kid-1');

                return Http::response(['id_token' => $this->issuedIdToken, 'access_token' => 'opaque-access-token', 'token_type' => 'Bearer']);
            },
            '*' => Http::response('unexpected', 500),
        ]);
    }

    /** @return array<string, string> */
    private function plannerForm(): array
    {
        return [
            'origin_kind' => 'city', 'origin_value' => 'İstanbul', 'origin_timezone' => 'Europe/Istanbul',
            'destination_kind' => 'airport', 'destination_value' => 'NRT', 'destination_timezone' => 'Asia/Tokyo',
            'journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-12-01',
            'traveler_count' => '1',
        ];
    }

    private static function newRsaKey(): string
    {
        $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $config = is_file($cnf) ? ['config' => $cnf] : [];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key, 'RSA test key could not be generated.');
        openssl_pkey_export($key, $pem, null, $config);

        return $pem;
    }
}
