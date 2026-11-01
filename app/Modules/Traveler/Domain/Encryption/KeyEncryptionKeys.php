<?php

namespace App\Modules\Traveler\Domain\Encryption;

/**
 * The KEK boundary: an implementation keeps the KEK outside the application database and never returns it. $context is
 * authenticated with the wrap, so a wrapped key cannot be moved to another Traveler. The product is undecided (ADR-010).
 */
interface KeyEncryptionKeys
{
    public function isConfigured(): bool;

    public function currentVersion(): string;

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): WrappedDataKey;

    public function unwrap(WrappedDataKey $wrapped, string $context): string;
}
