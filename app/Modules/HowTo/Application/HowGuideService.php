<?php

namespace App\Modules\HowTo\Application;

use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\HowTo\Infrastructure\Persistence\DatabaseHowOperationRepository;
use App\Modules\Poi\Application\PoiService;
use App\Modules\TripBuilder\Application\PlanPrecondition;
use App\Modules\TripBuilder\Infrastructure\Persistence\DatabaseTripPlanRepository;
use Throwable;

final readonly class HowGuideService
{
    public function __construct(
        private DatabaseTripPlanRepository $plans,
        private PoiService $pois,
        private ContextualGuideRenderer $renderer,
        private DatabaseHowOperationRepository $operations,
    ) {}

    /** @return array{body: array<string, mixed>, status: int, etag: string} */
    public function read(string $tripRequestId, string $itemId, int $expectedVersion): array
    {
        [$revision, $item, $poi] = $this->context($tripRequestId, $itemId, $expectedVersion);

        return $this->result($this->renderer->render($tripRequestId, $revision['version'], $item['item_id'], $poi), 200, $revision['version']);
    }

    /** @return array{body: array<string, mixed>, status: int, etag: string} */
    public function refresh(PrincipalRef $principal, string $tripRequestId, string $itemId, int $expectedVersion, string $key): array
    {
        [$revision, $item, $poi] = $this->context($tripRequestId, $itemId, $expectedVersion);
        $principalHash = hash('sha256', $principal->issuer."\0".$principal->subject);
        $scopeHash = hash('sha256', implode("\0", [$principalHash, 'refreshTripPlanItemGuide', $tripRequestId, $itemId, (string) $expectedVersion]));
        $fingerprint = hash('sha256', implode("\0", ['refreshTripPlanItemGuide', $tripRequestId, $itemId, (string) $expectedVersion]));
        $existing = $this->operations->find($scopeHash, $key);
        if ($existing !== null) {
            return $this->replay($existing, $fingerprint, $expectedVersion);
        }
        if (! $this->operations->claim($scopeHash, $principalHash, $tripRequestId, $itemId, $expectedVersion, $key, $fingerprint)) {
            return $this->replay($this->operations->find($scopeHash, $key) ?? throw HowGuideRejected::idempotencyInProgress(), $fingerprint, $expectedVersion);
        }

        $status = 200;
        try {
            $verified = $this->pois->verify((string) $poi['poi_id']);
            if ($verified === null || $verified['verified'] !== true) {
                $status = 503;
                $body = $this->error('HOW_REVERIFY_UNAVAILABLE', 'Kaynak bilgisi şu anda yeniden doğrulanamadı; mevcut bilgi eski olarak gösteriliyor.');
            } else {
                $body = $this->renderer->render($tripRequestId, $revision['version'], $item['item_id'], $verified['poi']);
            }
        } catch (Throwable) {
            $status = 503;
            $body = $this->error('HOW_REVERIFY_UNAVAILABLE', 'Kaynak bilgisi şu anda yeniden doğrulanamadı; mevcut bilgi eski olarak gösteriliyor.');
        }
        $this->operations->complete($scopeHash, $key, $status, $body);

        return $this->result($body, $status, $revision['version']);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function context(string $tripRequestId, string $itemId, int $expectedVersion): array
    {
        $trip = $this->plans->tripFor($tripRequestId);
        if ($trip === null || ($revision = $this->plans->currentRevision($trip['trip_id'])) === null) {
            throw HowGuideRejected::notFound();
        }
        if ($revision['version'] !== $expectedVersion) {
            throw HowGuideRejected::stale();
        }
        foreach ($revision['items'] as $item) {
            if ($item['item_id'] !== $itemId) {
                continue;
            }
            if (($item['item_type'] ?? null) !== 'POI') {
                throw HowGuideRejected::unsupported();
            }
            $poiId = $item['poi_snapshot']['poi_id'] ?? null;
            if (! is_string($poiId) || $poiId === '') {
                throw HowGuideRejected::unsupported();
            }
            $poi = $this->pois->get($poiId);
            if ($poi === null) {
                throw HowGuideRejected::notFound();
            }

            return [$revision, $item, $poi];
        }

        throw HowGuideRejected::notFound();
    }

    /** @param array<string, mixed> $record */
    private function replay(array $record, string $fingerprint, int $version): array
    {
        if ($record['request_fingerprint'] !== $fingerprint) {
            throw HowGuideRejected::idempotencyConflict();
        }
        if ($record['status'] !== 'COMPLETED' || ! is_int($record['response_status']) || ! is_array($record['response_body'])) {
            throw HowGuideRejected::idempotencyInProgress();
        }

        return $this->result($record['response_body'], $record['response_status'], $version);
    }

    private function result(array $body, int $status, int $version): array
    {
        return ['body' => $body, 'status' => $status, 'etag' => PlanPrecondition::etag($version)];
    }

    private function error(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message_tr' => $message, 'correlation_id' => null, 'details' => null]];
    }
}
