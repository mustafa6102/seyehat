<?php

namespace App\Modules\FlightSearch\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Money minor units cannot be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Money currency must be an ISO 4217 alpha code.');
        }
    }

    /** @return array{minor_units: int, currency: string} */
    public function toContract(): array
    {
        return ['minor_units' => $this->minorUnits, 'currency' => $this->currency];
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money currencies must match; implicit FX is forbidden.');
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }
}
