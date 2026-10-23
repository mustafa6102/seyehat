<?php

namespace App\Diagnostics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class PlanlaFailureRecorder
{
    /** @return array{correlation_id: string, timestamp: string, route: string, action: string, exception_class: string, application_frame: ?string} */
    public function record(Throwable $exception, Request $request): array
    {
        $record = [
            'correlation_id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'route' => (string) ($request->route()?->getName() ?? 'trip-planner.store'),
            'action' => (string) ($request->route()?->getActionName() ?? 'unknown'),
            'exception_class' => $exception::class,
            'application_frame' => $this->firstApplicationFrame($exception),
        ];

        try {
            Log::error('trip_planner.unexpected_failure', $record);
        } catch (Throwable) {
            error_log((string) json_encode([
                'correlation_id' => $record['correlation_id'],
                'exception_class' => $record['exception_class'],
            ], JSON_UNESCAPED_SLASHES));
        }

        $this->storeLatest($record);

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $path = (string) config('diagnostics.record_path');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $record = json_decode((string) @file_get_contents($path), true);

        return is_array($record) ? $record : null;
    }

    private function firstApplicationFrame(Throwable $exception): ?string
    {
        $appPath = str_replace('\\', '/', app_path()).'/';
        foreach ($exception->getTrace() as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (! str_starts_with($file, $appPath)) {
                continue;
            }

            return 'app/'.substr($file, strlen($appPath)).':'.(int) ($frame['line'] ?? 0);
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function storeLatest(array $record): void
    {
        $path = (string) config('diagnostics.record_path');
        if ($path === '') {
            return;
        }

        @file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
