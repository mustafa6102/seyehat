<?php

namespace App\Http\Support;

/** Loads the active privacy text version; a [BRACKETED] field is an unresolved draft placeholder, never filled in here. */
final class PrivacyNotices
{
    /** @return array<string, mixed> */
    public function travelerProfile(): array
    {
        $config = config('privacy_notices.traveler_profile');
        $path = base_path($config['directory'].'/'.$config['active_version'].'.json');
        $notice = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Only a recorded approval ends the draft state; a complete text waiting for it is not in force.
        $notice['is_draft'] = $notice['status'] !== 'APPROVED';
        $notice['notice'] = array_map($this->segments(...), $notice['notice']);
        $notice['consent']['text'] = $this->segments($notice['consent']['text']);
        $notice['pending_fields'] = count(array_filter(
            array_merge(...[...$notice['notice'], $notice['consent']['text']]),
            fn (array $segment): bool => $segment['pending'],
        ));

        return $notice;
    }

    /** @return list<array{text: string, pending: bool}> */
    private function segments(string $text): array
    {
        $parts = preg_split('/(\[[^\]]+\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $part): array => ['text' => $part, 'pending' => str_starts_with($part, '[')], $parts);
    }
}
