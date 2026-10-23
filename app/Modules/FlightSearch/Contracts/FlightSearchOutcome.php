<?php

namespace App\Modules\FlightSearch\Contracts;

use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;

/**
 * Result of one provider fan-out. Offers come only from providers that answered in budget;
 * failed providers contribute nothing (NFR-AVL-001: no fabricated data).
 */
final readonly class FlightSearchOutcome
{
    /**
     * @param  list<FlightOffer>  $offers
     * @param  list<ProviderFailureKind>  $failures
     */
    public function __construct(
        public array $offers,
        public int $succeededProviders,
        public array $failures = [],
    ) {}

    /** @param list<FlightOffer> $offers */
    public static function completed(array $offers): self
    {
        return new self($offers, 1);
    }

    public function isDegraded(): bool
    {
        return $this->failures !== [];
    }

    public function allProvidersFailed(): bool
    {
        return $this->isDegraded() && $this->succeededProviders === 0;
    }

    /** Registry machine code of the most specific failure; null when the registry defines none. */
    public function reasonCode(): ?string
    {
        return $this->primaryFailure()?->reasonCode();
    }

    public function partialMessageTr(): string
    {
        return $this->primaryFailure()?->messageTr().' Sonuçlar kısmidir; yalnız yanıt veren sağlayıcıların teklifleri gösteriliyor.';
    }

    public function failedMessageTr(): string
    {
        $primary = $this->primaryFailure();
        // Unsupported criteria fail the same way every time; advising a later retry would be untrue.
        if ($primary === ProviderFailureKind::UNSUPPORTED_CRITERIA) {
            return $primary->messageTr().' Uçuş araması tamamlanamadı.';
        }

        return $primary?->messageTr().' Uçuş araması tamamlanamadı. Lütfen daha sonra yeniden deneyin.';
    }

    private function primaryFailure(): ?ProviderFailureKind
    {
        foreach ([ProviderFailureKind::TIMEOUT, ProviderFailureKind::RATE_LIMITED] as $kind) {
            if (in_array($kind, $this->failures, true)) {
                return $kind;
            }
        }

        return $this->failures[0] ?? null;
    }
}
