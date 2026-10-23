<?php

namespace App\Http\Controllers;

use App\Diagnostics\PlanlaFailureRecorder;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ProductionDiagnosticsController extends Controller
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly ProviderCapabilityMatrix $capabilities,
        private readonly PlanlaFailureRecorder $failures,
    ) {}

    public function show(): JsonResponse
    {
        $storageLogs = storage_path('logs');
        $laravelLog = $storageLogs.DIRECTORY_SEPARATOR.'laravel.log';
        $databaseConnectivity = 'FAIL';
        $failedJobCount = null;
        try {
            $this->database->getPdo();
            $databaseConnectivity = 'OK';
            $failedJobCount = $this->database->table('failed_jobs')->count();
        } catch (Throwable) {
            // The diagnostics contract exposes status only.
        }

        $capabilityStatus = 'FAIL';
        try {
            $capability = $this->capabilities->productionCapability(DuffelFlightProviderAdapter::PROVIDER_ID, 'search');
            $capabilityStatus = $capability->status;
        } catch (Throwable) {
            // The diagnostics contract exposes status only.
        }

        return response()->json([
            'diagnostics' => [
                'app_environment' => app()->environment(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'base_path' => base_path(),
                'log_default_channel' => config('logging.default'),
                'log_stack_channels' => array_values((array) config('logging.channels.stack.channels', [])),
                'single_log_path' => config('logging.channels.single.path'),
                'storage_logs_exists' => is_dir($storageLogs),
                'storage_logs_writable' => is_writable($storageLogs),
                'laravel_log_exists' => is_file($laravelLog),
                'laravel_log_writable' => is_file($laravelLog) && is_writable($laravelLog),
                'database_driver' => config('database.default'),
                'database_connectivity' => $databaseConnectivity,
                'queue_driver' => config('queue.default'),
                'failed_job_count' => $failedJobCount,
                'duffel_flights_search_capability' => $capabilityStatus,
                'duffel_access_token' => filled(config('services.duffel.access_token')) ? 'configured' : 'not-configured',
                'build_identifier' => config('app.build_identifier'),
            ],
            'latest_planla_failure' => $this->failures->latest(),
        ]);
    }

    public function probe(): JsonResponse
    {
        $correlationId = (string) Str::uuid();
        $context = ['correlation_id' => $correlationId, 'timestamp' => now()->toIso8601String()];

        return response()->json([
            'correlation_id' => $correlationId,
            'normal_logger' => $this->probeLogger(fn () => Log::info('production_diagnostics.probe', $context)),
            'single_logger' => $this->probeLogger(fn () => Log::channel('single')->info('production_diagnostics.single_probe', $context)),
        ]);
    }

    /** @param callable(): void $write */
    private function probeLogger(callable $write): string
    {
        try {
            $write();

            return 'OK';
        } catch (Throwable) {
            return 'FAIL';
        }
    }
}
