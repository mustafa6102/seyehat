<?php

namespace Tests\Unit;

use App\Logging\RedactSensitiveContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactSensitiveContextTest extends TestCase
{
    public function test_sensitive_values_are_redacted_recursively(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Warning,
            message: 'safe message',
            context: [
                'token' => 'secret-value',
                'nested' => ['passport_number' => 'P123'],
                'status' => 'UNKNOWN',
            ],
            extra: ['authorization' => 'Bearer secret'],
        );

        $processed = (new RedactSensitiveContext)($record);

        self::assertSame('[REDACTED]', $processed->context['token']);
        self::assertSame('[REDACTED]', $processed->context['nested']['passport_number']);
        self::assertSame('UNKNOWN', $processed->context['status']);
        self::assertSame('[REDACTED]', $processed->extra['authorization']);
    }
}
