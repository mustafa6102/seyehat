<?php

namespace Tests\Feature;

use App\Logging\ConfigureSensitiveDataRedaction;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** PLT-PII-001 / RULE-PLT-PII-001-02: the channel tap receives Laravel's Logger and attaches the processor to Monolog. */
final class LogChannelRedactionTest extends TestCase
{
    #[Test]
    public function the_tap_accepts_laravels_logger_wrapper_and_attaches_the_processor_to_monolog(): void
    {
        $handler = new TestHandler;
        $monolog = new \Monolog\Logger('tap-test', [$handler]);

        (new ConfigureSensitiveDataRedaction)(new Logger($monolog));
        $monolog->warning('probe', ['token' => 'raw-token', 'email' => 'a@example.test', 'safe' => 'kept']);

        $context = $handler->getRecords()[0]->context;
        self::assertSame('[REDACTED]', $context['token']);
        self::assertSame('[REDACTED]', $context['email']);
        self::assertSame('kept', $context['safe']);
    }

    #[Test]
    public function configured_channels_using_the_tap_initialize_without_the_emergency_fallback_and_redact(): void
    {
        $stream = storage_path('framework/testing/redaction-'.bin2hex(random_bytes(4)).'.log');
        config([
            'logging.channels.redaction-test' => [
                'driver' => 'monolog',
                'level' => 'debug',
                'handler' => StreamHandler::class,
                'handler_with' => ['stream' => $stream],
                'tap' => [ConfigureSensitiveDataRedaction::class],
            ],
            'logging.channels.pii_access.handler_with.stream' => $stream,
        ]);

        try {
            Log::channel('redaction-test')->error('boom', ['authorization' => 'Bearer secret', 'passport_number' => 'P123']);
            Log::channel('pii_access')->info('pii.access', ['action' => 'read', 'resource' => 'Traveler.sensitive_pii', 'document_number' => 'D999']);
            // The stack channel (also tapped) resolves to its real channels, not to the emergency logger.
            config(['logging.channels.stack.channels' => ['redaction-test']]);
            Log::channel('stack')->warning('stacked', ['api_key' => 'k-123']);
            Log::forgetChannel('stack');

            $written = (string) file_get_contents($stream);
            self::assertStringContainsString('boom', $written);
            self::assertStringContainsString('pii.access', $written);
            self::assertStringContainsString('stacked', $written);
            self::assertStringContainsString('"action":"read"', $written);
            foreach (['Bearer secret', 'P123', 'D999', 'k-123', 'emergency'] as $leak) {
                self::assertStringNotContainsString($leak, $written);
            }
            self::assertStringContainsString('[REDACTED]', $written);
        } finally {
            @unlink($stream);
        }
    }
}
