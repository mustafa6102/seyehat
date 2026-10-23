<?php

namespace Tests\Feature;

use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use App\Modules\ProviderAcl\Infrastructure\Adapters\ProviderAdapterCapabilityGate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\TestCase;

final class ProviderCapabilityMatrixTest extends TestCase
{
    private InMemoryProviderCapabilityRepository $repository;

    private ProviderCapabilityMatrixService $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryProviderCapabilityRepository;
        $this->matrix = new ProviderCapabilityMatrixService($this->repository);
    }

    #[Test]
    public function ac_prv_001_01_sandbox_search_does_not_enable_production_ticket(): void
    {
        $this->repository->save(new ProviderCapability(
            providerId: 'provider-a',
            operation: 'search',
            environment: 'sandbox',
            supported: true,
            status: 'FRESH',
            evidence: 'sandbox-search-evidence',
            verifiedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
        ));

        $ticket = $this->matrix->productionCapability('provider-a', 'ticket');
        $search = $this->matrix->productionCapability('provider-a', 'search');

        self::assertFalse($ticket->supported);
        self::assertSame('UNKNOWN', $ticket->status);
        self::assertSame([
            'operation' => 'ticket',
            'supported' => false,
            'status' => 'UNKNOWN',
            'evidence' => null,
            'verified_at' => null,
            'verification_status' => 'UNVERIFIED',
        ], $ticket->toContract());
        self::assertFalse($search->supported);
        self::assertSame('UNKNOWN', $search->status);
    }

    #[Test]
    public function ac_prv_001_02_missing_unverified_or_expired_refund_evidence_never_enables_production(): void
    {
        $missing = $this->matrix->productionCapability('provider-a', 'refund');

        self::assertFalse($missing->supported);
        self::assertSame('UNKNOWN', $missing->status);

        foreach (['UNVERIFIED', 'EXPIRED'] as $status) {
            $this->repository->save(new ProviderCapability(
                providerId: 'provider-'.$status,
                operation: 'refund',
                environment: 'production',
                supported: true,
                status: $status,
                evidence: 'refund-evidence',
                verifiedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            ));

            $capability = $this->matrix->productionCapability('provider-'.$status, 'refund');

            self::assertFalse($capability->supported);
            self::assertSame($status, $capability->status);
        }
    }

    #[Test]
    public function ac_prv_001_03_verified_production_booking_is_returned_with_evidence(): void
    {
        $verifiedAt = new DateTimeImmutable('2026-09-27T10:00:00+00:00');
        $this->repository->save(new ProviderCapability(
            providerId: 'provider-a',
            operation: 'book',
            environment: 'production',
            supported: true,
            status: 'FRESH',
            evidence: 'production-booking-evidence',
            verifiedAt: $verifiedAt,
        ));

        $capability = $this->matrix->productionCapability('provider-a', 'book');

        self::assertTrue($capability->supported);
        self::assertSame('FRESH', $capability->status);
        self::assertSame('production-booking-evidence', $capability->evidence);
        self::assertEquals($verifiedAt, $capability->verifiedAt);
        self::assertSame([
            'operation' => 'book',
            'supported' => true,
            'status' => 'FRESH',
            'evidence' => 'production-booking-evidence',
            'verified_at' => '2026-09-27T10:00:00+00:00',
            'verification_status' => 'FRESH',
        ], $capability->toContract());
        self::assertTrue(
            (new ProviderAdapterCapabilityGate($this->matrix))
                ->allowsProductionOperation('provider-a', 'book')
        );
    }
}
