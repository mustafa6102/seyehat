<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Taleplerim": session-scoped list of TripRequests created in this browser session (no ownership model exists). */
final class MyTripRequestsPageTest extends TestCase
{
    private SessionScopedTripRequestService $tripRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tripRequests = new SessionScopedTripRequestService;
        $this->app->instance(TripRequestService::class, $this->tripRequests);
    }

    #[Test]
    public function empty_state_offers_a_new_plan_and_the_main_navigation_links_the_page(): void
    {
        $page = $this->get('/taleplerim')
            ->assertOk()
            ->assertSee('Henüz bir seyahat talebiniz yok.')
            ->assertSee('Yeni seyahat planı oluştur')
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('data-request-card', false)
            ->getContent();
        self::assertMatchesRegularExpression('#href="'.preg_quote(url('/taleplerim'), '#').'"\s+aria-current="page"\s*>Seyahatlerim</a>#', $page);

        $this->get('/')->assertOk()->assertSee('href="'.url('/taleplerim').'"', false);
    }

    #[Test]
    public function only_requests_created_in_this_session_are_listed_newest_first(): void
    {
        $this->post('/planla', $this->form('Ankara', 'NRT'))->assertRedirect('/planlar/trip-1');
        $this->post('/planla', $this->form('İzmir', 'CDG'))->assertRedirect('/planlar/trip-2');

        $page = $this->get('/taleplerim')->assertOk()
            ->assertDontSee('Henüz bir seyahat talebiniz yok.')
            ->getContent();
        // Newest first by creation order in this session.
        self::assertLessThan(strpos($page, 'Ankara'), strpos($page, 'İzmir'));
        self::assertSame(2, substr_count($page, 'data-request-card'));
        // A request that exists in storage but was not created in this session is never listed.
        self::assertStringNotContainsString('Trabzon', $page);
        self::assertStringNotContainsString('trip-foreign', $page);

        // Another browser session sees none of them.
        $this->flushSession();
        $this->get('/taleplerim')->assertOk()
            ->assertSee('Henüz bir seyahat talebiniz yok.')
            ->assertDontSee('Ankara')
            ->assertDontSee('İzmir');
    }

    #[Test]
    public function a_card_is_compact_and_leads_to_the_flight_offers_of_that_request(): void
    {
        $this->post('/planla', $this->form('Ankara', 'NRT'));
        $this->post('/planla', $this->form('İzmir', 'CDG', [
            'journey_type' => 'ROUND_TRIP',
            'return_date_mode' => 'exact',
            'return_exact_date' => '2026-10-20',
            'traveler_count' => '3',
        ]));

        $page = $this->get('/taleplerim')->assertOk()
            ->assertSee('Ankara')
            ->assertSee('NRT')
            ->assertSee('Tek yön')
            ->assertSee('Gidiş-dönüş')
            ->assertSee('Gidiş: 15 Ekim 2026')
            ->assertSee('Dönüş: 20 Ekim 2026')
            ->assertSee('1 yolcu')
            ->assertSee('3 yolcu')
            ->assertSee('Uçuş ara')
            ->assertSee('href="'.url('/planlar/trip-1/ucuslar').'"', false)
            ->assertSee('href="'.url('/planlar/trip-2/ucuslar').'"', false)
            ->assertSee('href="'.url('/planlar/trip-1').'"', false)
            ->assertSee('data-storage-key="flight-search:trip-1"', false)
            ->assertSee('data-poll-url="'.url('/web-api/v1/flight-search-jobs/__SEARCH__').'"', false)
            ->assertSee('data-request-status role="status" hidden', false)
            ->getContent();
        // Only the round trip shows a return date.
        self::assertSame(1, substr_count($page, 'Dönüş:'));
        foreach (['Europe/', 'Asia/', 'SEARCHABLE', 'Teklifleri görüntüle'] as $hidden) {
            self::assertStringNotContainsString($hidden, $page);
        }
    }

    #[Test]
    public function stale_or_tampered_session_entries_are_skipped(): void
    {
        $this->withSession(['trip_requests.created' => ['missing', ['nested'], 42]])
            ->get('/taleplerim')
            ->assertOk()
            ->assertSee('Henüz bir seyahat talebiniz yok.');

        // A remembered but not searchable request links only to its summary.
        $this->withSession(['trip_requests.created' => ['trip-draft']])
            ->get('/taleplerim')
            ->assertOk()
            ->assertSee('Talep özeti')
            ->assertDontSee('Uçuş ara')
            ->assertDontSee('data-request-action', false);
    }

    #[Test]
    public function a_failed_submission_is_not_remembered(): void
    {
        $this->from('/')->post('/planla', ['journey_type' => 'ONE_WAY'])->assertRedirect('/');

        $this->get('/taleplerim')->assertOk()->assertSee('Henüz bir seyahat talebiniz yok.');
        self::assertSame(0, $this->tripRequests->created);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function form(string $origin, string $destination, array $fields = []): array
    {
        return $fields + [
            'origin_kind' => 'city',
            'origin_value' => $origin,
            'origin_timezone' => 'Europe/Istanbul',
            'destination_kind' => 'airport',
            'destination_value' => $destination,
            'destination_timezone' => 'Asia/Tokyo',
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-10-15',
            'traveler_count' => '1',
        ];
    }
}

final class SessionScopedTripRequestService implements TripRequestService
{
    public int $created = 0;

    /** @var array<string, TripRequestView> */
    private array $stored;

    /** @var array<string, \App\Modules\Authorization\Contracts\PrincipalRef> */
    private array $owners = [];

    public function __construct()
    {
        $place = fn (string $value): array => ['kind' => 'city', 'value' => $value, 'iana_timezone' => 'Europe/Istanbul'];
        // Records that exist in storage but were created elsewhere (another browser/session).
        $this->stored = [
            'trip-foreign' => new TripRequestView('SEARCHABLE', $place('Trabzon'), $place('Roma'), null, 1, null, 'ONE_WAY', ['type' => 'EXACT', 'date' => '2026-11-01']),
            'trip-draft' => new TripRequestView('DRAFT', $place('Bursa'), $place('Roma'), null, 1, null),
        ];
    }

    public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
    {
        $id = 'trip-'.++$this->created;
        $this->owners[$id] = $owner;
        $this->stored[$id] = new TripRequestView(
            status: 'SEARCHABLE',
            originPlace: $input['origin_place'],
            destinationPlace: $input['destination_place'],
            dateWindow: null,
            travelerCount: $input['traveler_count'],
            preferences: $input['preferences'],
            journeyType: $input['journey_type'],
            outboundDate: $input['outbound_date'],
            returnDate: $input['return_date'] ?? null,
        );

        return new CreatedTripRequest($id, $this->stored[$id]);
    }

    public function get(string $id): ?TripRequestView
    {
        return $this->stored[$id] ?? null;
    }

    public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
    {
        $owned = [];
        foreach ($this->owners as $id => $recordOwner) {
            if ($recordOwner->equals($owner)) {
                $owned[] = ['id' => $id, 'trip_request' => $this->stored[$id]];
            }
        }

        return $owned;
    }
}
