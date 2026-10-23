<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\ConfigureSensitiveDataRedaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * PLT-PII-001 / RULE-PLT-REL-001-04 and the analysis DoD "Loglarda sır/PII sızıntısı yoktur": configured credentials
 * never reach a log record, whichever key, message or exception carries them, and database errors are reported
 * without their bound values.
 */
final class SecretLeakPreventionTest extends TestCase
{
    private const DUFFEL_TOKEN = 'duffel_test_Zx81SecretTokenValue';

    private const GEONAMES_USERNAME = 'geo_user_7731';

    private const DIAGNOSTICS_TOKEN = 'diag-token-4f2a9c';

    private string $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stream = storage_path('framework/testing/secret-leak-'.bin2hex(random_bytes(4)).'.log');
        config([
            'services.duffel.access_token' => self::DUFFEL_TOKEN,
            'services.geonames.username' => self::GEONAMES_USERNAME,
            'diagnostics.token' => self::DIAGNOSTICS_TOKEN,
            'logging.channels.secret-test' => [
                'driver' => 'monolog',
                'level' => 'debug',
                'handler' => StreamHandler::class,
                'handler_with' => ['stream' => $this->stream],
                'tap' => [ConfigureSensitiveDataRedaction::class],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->stream);

        parent::tearDown();
    }

    #[Test]
    public function configured_credentials_are_removed_from_messages_nested_context_and_exceptions(): void
    {
        Log::channel('secret-test')->error('GeoNames call failed for http://api.geonames.org/searchJSON?username='.self::GEONAMES_USERNAME, [
            'provider' => ['request' => 'Bearer '.self::DUFFEL_TOKEN, 'attempt' => 2],
            'note' => 'diagnostics header '.self::DIAGNOSTICS_TOKEN.' rejected',
            'exception' => new RuntimeException('Duffel rejected token '.self::DUFFEL_TOKEN, 0, new RuntimeException('inner '.self::GEONAMES_USERNAME)),
        ]);

        $written = $this->written();
        self::assertStringContainsString('GeoNames call failed', $written);
        self::assertStringContainsString('"attempt":2', $written);
        self::assertStringContainsString('RuntimeException', $written);
        self::assertStringContainsString('configured secret removed; trace omitted', $written);
        foreach ([self::DUFFEL_TOKEN, self::GEONAMES_USERNAME, self::DIAGNOSTICS_TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, $written);
        }
        self::assertGreaterThanOrEqual(4, substr_count($written, '[REDACTED]'));
    }

    #[Test]
    public function credential_header_keys_are_redacted_whatever_their_spelling(): void
    {
        Log::channel('secret-test')->warning('probe', [
            'X-Diagnostics-Token' => 'hdr-1',
            'Proxy-Authorization' => 'hdr-2',
            'x-api-key' => 'hdr-3',
            'Client_Secret' => 'hdr-4',
            'safe' => 'kept',
        ]);

        $written = $this->written();
        foreach (['hdr-1', 'hdr-2', 'hdr-3', 'hdr-4'] as $value) {
            self::assertStringNotContainsString($value, $written);
        }
        self::assertStringContainsString('"safe":"kept"', $written);
    }

    #[Test]
    public function an_exception_without_secrets_keeps_its_class_message_and_trace(): void
    {
        Log::channel('secret-test')->error('unexpected', ['exception' => new RuntimeException('ordinary failure')]);

        $written = $this->written();
        self::assertStringContainsString('ordinary failure', $written);
        self::assertStringContainsString('RuntimeException', $written);
        self::assertStringContainsString('#0 ', $written);
        self::assertStringNotContainsString('trace omitted', $written);
    }

    #[Test]
    public function a_database_error_is_reported_without_bound_values_or_driver_text(): void
    {
        config(['logging.default' => 'secret-test']);
        $driverError = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'DOC-SECRET-991\' for key');
        $driverError->errorInfo = ['23000', 1062, 'Duplicate entry \'DOC-SECRET-991\' for key'];
        $exception = new QueryException('mysql', 'insert into travelers (id, travel_documents) values (?, ?)', ['trav-1', '[{"document_type":"PASSPORT","number":"DOC-SECRET-991"}]'], $driverError);

        report($exception);

        $written = $this->written();
        self::assertStringContainsString('database.query_failed', $written);
        self::assertStringContainsString('insert into travelers (id, travel_documents) values (?, ?)', $written);
        self::assertStringContainsString('"sqlstate":"23000"', $written);
        self::assertStringContainsString('"driver_code":1062', $written);
        foreach (['DOC-SECRET-991', 'PASSPORT', 'Duplicate entry'] as $leak) {
            self::assertStringNotContainsString($leak, $written);
        }
    }

    private function written(): string
    {
        return (string) @file_get_contents($this->stream);
    }
}
