<?php

use App\Diagnostics\PlanlaFailureRecorder;
use App\Modules\Authorization\Infrastructure\Http\AuthenticateBearer;
use App\Modules\Authorization\Infrastructure\Http\AuthenticateWebSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // v3.16 bearerAuth (ADR-010): OIDC access-token authentication for protected API operations.
        // OD-028 (v3.17): auth.web-session = signed-in browser session principal for the /web-api/v1 web channel (web routes, CSRF).
        // alias() replaces earlier aliases, so both are registered in one call.
        $middleware->alias([
            'auth.bearer' => AuthenticateBearer::class,
            'auth.web-session' => AuthenticateWebSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            $request = request();
            if ($request instanceof Request
                && $request->route()?->getName() === 'trip-planner.store'
                && ! $exception instanceof ValidationException
                && ! $exception instanceof HttpExceptionInterface) {
                app(PlanlaFailureRecorder::class)->record($exception, $request);

                return false;
            }

            return null;
        });

        // PLT-PII-001 ("Loglarda sır/PII sızıntısı yoktur"): a database error message embeds the bound values and the
        // driver text (e.g. traveler document data or a duplicate key value). It is reported with the placeholder SQL
        // and codes only; the response the user sees is unchanged.
        $exceptions->report(function (QueryException $exception) {
            $appPath = str_replace('\\', '/', app_path()).'/';
            $frame = null;
            foreach ($exception->getTrace() as $step) {
                $file = str_replace('\\', '/', (string) ($step['file'] ?? ''));
                if (str_starts_with($file, $appPath)) {
                    $frame = 'app/'.substr($file, strlen($appPath)).':'.(int) ($step['line'] ?? 0);
                    break;
                }
            }
            Log::error('database.query_failed', [
                'connection' => $exception->getConnectionName(),
                'sql' => $exception->getSql(),
                'sqlstate' => is_string($exception->errorInfo[0] ?? null) ? $exception->errorInfo[0] : null,
                'driver_code' => is_int($exception->errorInfo[1] ?? null) ? $exception->errorInfo[1] : null,
                'application_frame' => $frame,
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*', 'web-api/*')
        );

        // JSON endpoints (public API and web location lookups) never return Laravel's default English body,
        // exception messages or traces. Codes come from the reason/error registry; where the registry defines
        // none (unexpected 5xx, 405) the code stays null (GAP-ERR-UNREGISTERED-001).
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*', 'konumlar/*', 'web-api/*') || $exception instanceof ValidationException) {
                return null;
            }
            $status = match (true) {
                $exception instanceof TokenMismatchException => 419,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            [$code, $messageTr] = match ($status) {
                404 => ['RESOURCE_NOT_FOUND', 'İstenen kaynak bulunamadı.'],
                405 => [null, 'Bu işlem bu adres için desteklenmiyor.'],
                // OD-028 web channel: CSRF token missing/invalid or session expired (no registered reason code).
                419 => [null, 'Oturum doğrulaması geçersiz veya süresi doldu; sayfayı yenileyip yeniden deneyin.'],
                429 => ['PLATFORM_RATE_LIMITED', 'Çok fazla istek gönderildi; lütfen biraz sonra yeniden deneyin.'],
                503 => [null, 'Hizmet şu anda kullanılamıyor; lütfen daha sonra yeniden deneyin.'],
                default => [null, 'Beklenmeyen bir hata oluştu; lütfen daha sonra yeniden deneyin.'],
            };

            return response()->json(
                ['error' => ['code' => $code, 'message_tr' => $messageTr, 'correlation_id' => null, 'details' => null]],
                $status,
                $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
            );
        });
    })->create();
