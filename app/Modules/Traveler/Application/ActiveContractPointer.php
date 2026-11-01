<?php

namespace App\Modules\Traveler\Application;

use Throwable;

/** registry/active_contract.json read only; an unreadable or malformed pointer never satisfies a version. */
final class ActiveContractPointer
{
    public function isAtLeast(string $version): bool
    {
        try {
            $pointer = json_decode((string) file_get_contents(base_path('registry/active_contract.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        $active = $pointer['version'] ?? null;

        return is_string($active) && version_compare($active, $version, '>=');
    }
}
