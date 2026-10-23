<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;

final readonly class FlightSearchJobRecord
{
    /** @param array{offers: array<int, mixed>}|null $result */
    public function __construct(
        public string $searchId,
        public string $idempotencyKey,
        public string $status,
        public ?array $result,
        public ?string $errorCode,
        public ?string $errorMessageTr,
        public ?DateTimeImmutable $expiresAt,
        public ?string $tripRequestId = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['COMPLETED', 'FAILED', 'EXPIRED'], true);
    }

    /**
     * No further processing is allowed: a terminal state, or a PARTIAL result that was finalized
     * with a provider degradation label (PLT-REL-001). Replays of settled jobs are no-ops.
     */
    public function isSettled(): bool
    {
        return $this->isTerminal() || ($this->status === 'PARTIAL' && $this->errorMessageTr !== null);
    }

    /** @param array{offers: array<int, mixed>} $result */
    public function withPartialFailure(array $result, ?string $code, string $messageTr): self
    {
        return new self($this->searchId, $this->idempotencyKey, 'PARTIAL', $result, $code, $messageTr, $this->expiresAt, $this->tripRequestId);
    }

    public function withStatus(string $status): self
    {
        return new self($this->searchId, $this->idempotencyKey, $status, $this->result, $this->errorCode, $this->errorMessageTr, $this->expiresAt, $this->tripRequestId);
    }

    public function withResult(string $status, array $result): self
    {
        return new self($this->searchId, $this->idempotencyKey, $status, $result, null, null, $this->expiresAt, $this->tripRequestId);
    }

    public function withFailure(?string $code, string $messageTr): self
    {
        return new self($this->searchId, $this->idempotencyKey, 'FAILED', null, $code, $messageTr, $this->expiresAt, $this->tripRequestId);
    }

    public function withExpiry(): self
    {
        return new self($this->searchId, $this->idempotencyKey, 'EXPIRED', null, null, 'Arama işinin geçerlilik süresi doldu.', $this->expiresAt, $this->tripRequestId);
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'search_id' => $this->searchId,
            'trip_request_id' => $this->tripRequestId,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->errorMessageTr === null ? null : ['code' => $this->errorCode, 'message_tr' => $this->errorMessageTr],
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
