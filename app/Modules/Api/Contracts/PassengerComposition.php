<?php

namespace App\Modules\Api\Contracts;

final readonly class PassengerComposition
{
    private function __construct(
        public int $adults,
        public int $children,
        public int $infants,
    ) {}

    /** @param array<string, mixed>|null $value */
    public static function fromContract(?array $value, ?int $travelerCount = null): ?self
    {
        if ($value === null) {
            return null;
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['adults', 'children', 'infants']) {
            return null;
        }
        foreach ($value as $count) {
            if (! is_int($count) || $count < 0) {
                return null;
            }
        }

        $composition = new self($value['adults'], $value['children'], $value['infants']);
        if ($composition->total() < 1 || ($travelerCount !== null && $composition->total() !== $travelerCount)) {
            return null;
        }

        return $composition;
    }

    public function total(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    /** @return array{adults:int, children:int, infants:int} */
    public function toContract(): array
    {
        return ['adults' => $this->adults, 'children' => $this->children, 'infants' => $this->infants];
    }
}
