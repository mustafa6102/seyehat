<?php

namespace App\Http\Support;

use Illuminate\Contracts\Session\Session;

/**
 * "Taleplerim" scope: the TripRequests created through the planner in THIS browser session only.
 *
 * The SSOT defines no owner/account/session relation for TripRequest (PLT-AUTH-001 AuthN is blocked by
 * ADR-010), so this is not ownership: nothing is stored in the database, other sessions' requests are never
 * listed, and the list ends with the session. A cross-session list needs an approved ownership model.
 */
final class SessionTripRequests
{
    private const KEY = 'trip_requests.created';

    /** Upper bound so the session payload stays small; oldest entries are dropped first. */
    private const LIMIT = 50;

    public function remember(Session $session, string $tripRequestId): void
    {
        $ids = array_values(array_filter($this->stored($session), fn (string $id): bool => $id !== $tripRequestId));
        $ids[] = $tripRequestId;

        $session->put(self::KEY, array_slice($ids, -self::LIMIT));
    }

    /** @return list<string> newest first */
    public function newestFirst(Session $session): array
    {
        return array_reverse($this->stored($session));
    }

    /** Drops IDs whose TripRequest no longer exists, so stale entries are not looked up again. */
    public function forget(Session $session, array $tripRequestIds): void
    {
        if ($tripRequestIds === []) {
            return;
        }

        $session->put(self::KEY, array_values(array_diff($this->stored($session), $tripRequestIds)));
    }

    /** @return list<string> oldest first */
    private function stored(Session $session): array
    {
        $ids = $session->get(self::KEY, []);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
