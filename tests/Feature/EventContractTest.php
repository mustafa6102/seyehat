<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Event\Application\EventDiscoveryService;
use App\Modules\Event\Infrastructure\Persistence\DatabaseEventDiscoveryJobRepository;
use App\Modules\Event\Infrastructure\Ticketmaster\TicketmasterEventAdapter;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class EventContractTest extends TestCase
{
    #[Test]
    public function discovery_dates_are_derived_in_the_destination_timezone_without_inventing_a_one_way_end(): void
    {
        config(['services.ticketmaster.search_radius_km' => 25]);
        $service = new EventDiscoveryService(new DatabaseEventDiscoveryJobRepository(DB::connection()));
        $trip = new TripRequestView(
            'DRAFT',
            ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome', 'coordinates' => ['latitude' => 41.9, 'longitude' => 12.5]],
            null,
            1,
            null,
            'ONE_WAY',
            ['type' => 'EXACT', 'date' => '2026-12-15'],
        );

        $criteria = $service->criteria('trip-1', $trip);

        self::assertIsArray($criteria);
        self::assertSame('2026-12-14T23:00:00Z', $criteria['start_date_time']);
        self::assertArrayNotHasKey('end_date_time', $criteria);
    }

    #[Test]
    public function legacy_or_flexible_dates_fail_closed(): void
    {
        config(['services.ticketmaster.search_radius_km' => 25]);
        $service = new EventDiscoveryService(new DatabaseEventDiscoveryJobRepository(DB::connection()));
        $place = ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome', 'coordinates' => ['latitude' => 41.9, 'longitude' => 12.5]];

        self::assertIsString($service->criteria('legacy', new TripRequestView('DRAFT', $place, $place, ['start_date' => '2026-12-01', 'end_date' => '2026-12-03'], 1, null)));
        self::assertIsString($service->criteria('flex', new TripRequestView('DRAFT', $place, $place, null, 1, null, 'ONE_WAY', ['type' => 'FLEXIBLE', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03'])));
    }

    #[Test]
    public function cancelled_or_offsale_events_never_receive_an_active_ticket_link(): void
    {
        $adapter = app(TicketmasterEventAdapter::class);
        $map = new ReflectionMethod($adapter, 'mapEvent');
        $expires = (new DateTimeImmutable('+1 day'))->format(DATE_ATOM);
        $base = [
            'id' => 'tm-1', 'name' => 'Sözleşme Etkinliği', 'url' => 'https://www.ticketmaster.com/event/1',
            'dates' => ['start' => ['dateTime' => '2026-12-15T19:00:00Z'], 'timezone' => 'Europe/Rome'],
        ];

        foreach (['cancelled', 'offsale'] as $status) {
            $event = $map->invoke($adapter, array_replace_recursive($base, ['dates' => ['status' => ['code' => $status]]]), now()->toAtomString(), $expires, ['www.ticketmaster.com']);
            self::assertNull($event['ticket_link']);
        }

        $event = $map->invoke($adapter, array_replace_recursive($base, ['dates' => ['status' => ['code' => 'onsale']]]), now()->toAtomString(), $expires, ['www.ticketmaster.com']);
        self::assertSame('https://www.ticketmaster.com/event/1', $event['ticket_link']['value']);
        self::assertArrayNotHasKey('apikey', $event);
    }
}
