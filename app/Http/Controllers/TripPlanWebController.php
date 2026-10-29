<?php

namespace App\Http\Controllers;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\TripBuilder\Application\PlanMutationRejected;
use App\Modules\TripBuilder\Application\PlanPrecondition;
use App\Modules\TripBuilder\Application\TripPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Web-channel adapter of OP-addTripPlanPoiItem / OP-removeTripPlanItem (v3.16) for the signed-in browser user: the principal
 * comes from the session (ADR-010), ownership from the same TripRequestOwnership guard, and every mutation goes through
 * TripPlanService with the OD-027 precondition and Idempotency-Key carried by the form. Only the POI or item identifier,
 * the expected revision and the idempotency key are read from the request; nothing else can influence the mutation.
 */
final readonly class TripPlanWebController
{
    public function __construct(
        private WebPrincipalResolver $principals,
        private TripRequestOwnership $ownership,
        private TripRequestService $tripRequests,
        private TripPlanService $plans,
    ) {}

    public function store(Request $request, string $tripRequestId): RedirectResponse
    {
        $refused = $this->authorize($request, $tripRequestId);
        if ($refused !== null) {
            return $refused;
        }
        $poiId = $request->input('poi_id');
        [$key, $precondition] = $this->mutationMeta($request);
        if (! is_string($poiId) || $poiId === '' || strlen($poiId) > 255 || $key === null || $precondition === null) {
            return $this->back($tripRequestId, error: 'İstek geçerli değil. Sayfayı yenileyip yeniden deneyin.');
        }
        if (! $this->isSearchable($tripRequestId)) {
            return $this->back($tripRequestId, error: 'Seyahat talebinin zorunlu alanları eksik; plana yer eklenemez.');
        }

        return $this->mutate($tripRequestId, fn () => $this->plans->addPoi($tripRequestId, $key, $precondition, $poiId), 'Gezilecek yer plana eklendi.');
    }

    /** Web-channel OP-addTripPlanEventItem (EVT-001 v3.22). Only event_id, expected_version and idempotency_key are read. */
    public function storeEvent(Request $request, string $tripRequestId): RedirectResponse
    {
        $refused = $this->authorize($request, $tripRequestId);
        if ($refused !== null) {
            return $refused;
        }
        $eventId = $request->input('event_id');
        [$key, $precondition] = $this->mutationMeta($request);
        if (! is_string($eventId) || $eventId === '' || strlen($eventId) > 255 || $key === null || $precondition === null) {
            return $this->backEvent($tripRequestId, error: 'İstek geçerli değil. Sayfayı yenileyip yeniden deneyin.');
        }
        if (! $this->isSearchable($tripRequestId)) {
            return $this->backEvent($tripRequestId, error: 'Seyahat talebinin zorunlu alanları eksik; plana etkinlik eklenemez.');
        }

        return $this->mutateEvent($tripRequestId, fn () => $this->plans->addEvent($tripRequestId, $key, $precondition, $eventId), 'Etkinlik plana eklendi.');
    }

    public function destroy(Request $request, string $tripRequestId, string $itemId): RedirectResponse
    {
        $refused = $this->authorize($request, $tripRequestId);
        if ($refused !== null) {
            return $refused;
        }
        [$key, $precondition] = $this->mutationMeta($request);
        if ($key === null || $precondition === null || $precondition->ifNoneMatchAny || strlen($itemId) > 255) {
            return $this->back($tripRequestId, error: 'İstek geçerli değil. Sayfayı yenileyip yeniden deneyin.');
        }

        return $this->mutate($tripRequestId, fn () => $this->plans->removeItem($tripRequestId, $key, $precondition, $itemId), 'Gezilecek yer plandan çıkarıldı.');
    }

    /** Authentication and ownership before anything else; the browser never supplies the owner. */
    private function authorize(Request $request, string $tripRequestId): ?RedirectResponse
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return $this->back($tripRequestId, error: 'Planı düzenlemek için giriş yapmanız gerekiyor.');
        }

        return match ($this->ownership->check($principal, $tripRequestId)) {
            OwnershipDecision::ALLOWED => null,
            OwnershipDecision::NOT_FOUND => abort(404),
            OwnershipDecision::DENIED => $this->back($tripRequestId, error: 'Bu planı yalnız sahibi düzenleyebilir.'),
        };
    }

    /**
     * OD-027 from the form: an empty expected_version means "no revision yet" (If-None-Match: *), a number is the exact
     * CURRENT version (If-Match). The idempotency key is the per-action value rendered with the page.
     *
     * @return array{0: string|null, 1: PlanPrecondition|null}
     */
    private function mutationMeta(Request $request): array
    {
        $key = $request->input('idempotency_key');
        $version = $request->input('expected_version');
        $key = is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,255}$/', $key) === 1 ? $key : null;
        $precondition = match (true) {
            $version === null || $version === '' => PlanPrecondition::fromHeaders(null, '*'),
            is_string($version) && preg_match('/^[1-9][0-9]{0,9}$/', $version) === 1 => PlanPrecondition::fromHeaders(PlanPrecondition::etag((int) $version), null),
            default => null,
        };

        return [$key, $precondition];
    }

    private function isSearchable(string $tripRequestId): bool
    {
        try {
            return $this->tripRequests->get($tripRequestId)?->status === 'SEARCHABLE';
        } catch (LogicException) {
            return false;
        }
    }

    /** @param  callable(): array{body: array<string, mixed>, etag: string|null}  $mutation */
    private function mutate(string $tripRequestId, callable $mutation, string $success): RedirectResponse
    {
        try {
            $mutation();
        } catch (PlanMutationRejected $rejection) {
            return $this->back($tripRequestId, error: match ($rejection->machineCode) {
                // Never retried or merged silently: the user reloads the canonical plan first.
                'REVISION_STALE' => 'Plan bu sırada değişti. Sayfayı yenileyip yeniden deneyin.',
                'IDEMPOTENCY_KEY_CONFLICT' => 'Bu işlem zaten kullanılmış bir istekle gönderildi. Sayfayı yenileyip yeniden deneyin.',
                default => $rejection->getMessage(),
            });
        }

        return $this->back($tripRequestId, status: $success);
    }

    private function back(string $tripRequestId, ?string $status = null, ?string $error = null): RedirectResponse
    {
        return redirect()->to(route('trip-plan.show', ['tripRequestId' => $tripRequestId]).'#plan-pois')
            ->with($error !== null ? 'plan_error' : 'plan_status', $error ?? $status);
    }

    /** @param  callable(): array{body: array<string, mixed>, etag: string|null}  $mutation */
    private function mutateEvent(string $tripRequestId, callable $mutation, string $success): RedirectResponse
    {
        try {
            $mutation();
        } catch (PlanMutationRejected $rejection) {
            return $this->backEvent($tripRequestId, error: match ($rejection->machineCode) {
                'REVISION_STALE' => 'Plan bu sırada değişti. Sayfayı yenileyip yeniden deneyin.',
                'IDEMPOTENCY_KEY_CONFLICT' => 'Bu işlem zaten kullanılmış bir istekle gönderildi. Sayfayı yenileyip yeniden deneyin.',
                default => $rejection->getMessage(),
            });
        }

        return $this->backEvent($tripRequestId, status: $success);
    }

    private function backEvent(string $tripRequestId, ?string $status = null, ?string $error = null): RedirectResponse
    {
        return redirect()->to(route('trip-plan.show', ['tripRequestId' => $tripRequestId]).'#plan-events')
            ->with($error !== null ? 'plan_error' : 'plan_status', $error ?? $status);
    }
}
