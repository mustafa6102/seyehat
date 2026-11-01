<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveContext;
use App\Modules\Traveler\Contracts\TravelerService;
use App\Modules\Traveler\Domain\TravelerRepository;
use DateTimeImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryTravelerRepository;
use Tests\TestCase;

final class SensitiveDataProtectionTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TravelerRepository::class, new InMemoryTravelerRepository);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    #[Test]
    public function ts_plt_pii_001_03_application_service_audits_sensitive_access_without_values(): void
    {
        $service = app(TravelerService::class);
        $service->create($this->payload());
        $service->get('traveler-pii-1');

        $access = [];
        foreach ($this->logged as $event) {
            if ($event->message === 'pii.access') {
                $access[$event->context['action']] = $event->context;
            }
        }

        self::assertSame(['create', 'read'], array_keys($access));
        foreach ($access as $context) {
            self::assertSame(['subject_id', 'action', 'resource', 'resources', 'traveler_id', 'occurred_at'], array_keys($context));
            self::assertNull($context['subject_id']);
            self::assertSame('Traveler.sensitive_pii', $context['resource']);
            self::assertSame(['TravelerDocument', 'Visa', 'ResidencePermit'], $context['resources']);
            self::assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $context['occurred_at']));
        }
        $everythingLogged = json_encode(array_map(fn (MessageLogged $event): array => [$event->message, $event->context], $this->logged), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PASSPORT', $everythingLogged);
        self::assertStringNotContainsString('UNVERIFIED', $everythingLogged);
    }

    #[Test]
    public function ts_plt_pii_001_03_classified_traveler_fields_are_redacted_by_the_log_processor(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Warning,
            message: 'traveler debug',
            context: ['traveler' => $this->payload(), 'document' => ['document_type' => 'PASSPORT']],
        );

        $context = (new RedactSensitiveContext)($record)->context;

        foreach (['travel_documents', 'held_visas', 'residence_permits'] as $field) {
            self::assertSame('[REDACTED]', $context['traveler'][$field]);
        }
        self::assertSame('[REDACTED]', $context['document']['document_type']);
        self::assertSame('traveler-pii-1', $context['traveler']['id']);
    }

    #[Test]
    public function configured_stack_channel_boots_and_redacts_through_its_tap(): void
    {
        config(['logging.channels.stack.channels' => ['null']]);
        $monolog = Log::channel('stack')->getLogger();
        $handler = new TestHandler;
        $monolog->pushHandler($handler);

        Log::channel('stack')->warning('traveler', ['travel_documents' => [['document_type' => 'PASSPORT']], 'token' => 'secret-token']);

        $record = $handler->getRecords()[0];
        self::assertSame('[REDACTED]', $record->context['travel_documents']);
        self::assertSame('[REDACTED]', $record->context['token']);
    }

    #[Test]
    public function traveler_without_sensitive_collections_produces_no_access_record(): void
    {
        app(TravelerService::class)->create([...$this->payload(), 'travel_documents' => [], 'held_visas' => [], 'residence_permits' => []]);

        self::assertSame([], array_filter($this->logged, fn (MessageLogged $event): bool => $event->message === 'pii.access'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id' => 'traveler-pii-1',
            'trip_id' => 'trip-1',
            'age_category' => 'ADULT',
            'nationalities' => ['TR'],
            'travel_documents' => [['document_type' => 'PASSPORT', 'verification_status' => 'UNVERIFIED']],
            'held_visas' => [['verification_status' => 'FRESH']],
            'residence_permits' => [['verification_status' => 'STALE']],
        ];
    }
}
