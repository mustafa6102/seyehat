<?php

namespace App\Modules\ProviderAcl\Contracts;

final readonly class CountryOption
{
    /** @param string $code ISO 3166-1 alpha-2 */
    public function __construct(
        public string $code,
        public string $label,
    ) {}

    /** @return array{code: string, label: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label];
    }
}
