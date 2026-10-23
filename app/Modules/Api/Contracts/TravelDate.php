<?php

namespace App\Modules\Api\Contracts;

/**
 * SCH-TravelDate v3.9 (RULE-TRIP-001-02, RULE-TRIP-001-05): one direction's calendar date, EXACT {date} or
 * FLEXIBLE {start_date, end_date}. Values are date-only (no timezone); FLEXIBLE bounds are inclusive and a
 * date is never selected from the range.
 */
final readonly class TravelDate
{
    public const EXACT = 'EXACT';

    public const FLEXIBLE = 'FLEXIBLE';

    private function __construct(
        public string $type,
        public string $earliest,
        public string $latest,
    ) {}

    /** Strict parse of the contract shape; unexpected fields, invalid calendar dates or a reversed range yield null. */
    public static function fromContract(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }
        $keys = array_keys($value);
        sort($keys);

        if ($keys === ['date', 'type'] && $value['type'] === self::EXACT && self::isDate($value['date'])) {
            return new self(self::EXACT, $value['date'], $value['date']);
        }
        if ($keys === ['end_date', 'start_date', 'type'] && $value['type'] === self::FLEXIBLE
            && self::isDate($value['start_date']) && self::isDate($value['end_date']) && $value['end_date'] >= $value['start_date']) {
            return new self(self::FLEXIBLE, $value['start_date'], $value['end_date']);
        }

        return null;
    }

    /** @return array{type: string, date?: string, start_date?: string, end_date?: string} */
    public function toContract(): array
    {
        return $this->type === self::EXACT
            ? ['type' => self::EXACT, 'date' => $this->earliest]
            : ['type' => self::FLEXIBLE, 'start_date' => $this->earliest, 'end_date' => $this->latest];
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
