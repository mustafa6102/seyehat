<?php

namespace App\Modules\Authorization\Infrastructure\Http;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OD-028 (v3.17, option A) web channel: the signed-in browser session's SCH-PrincipalRef, resolved only through
 * WebPrincipalResolver, is attached as the request attribute PrincipalRef::class that the TripRequest-owned operation
 * handlers authorize against (403/404 stay with the operation). No session principal -> 401 RC-AUTHN-REQUIRED. Used on
 * /web-api/v1 web routes only; /api/v1 keeps AuthenticateBearer.
 */
final readonly class AuthenticateWebSession
{
    public function __construct(private WebPrincipalResolver $principals) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return new JsonResponse(['error' => [
                'code' => AuthenticationRequired::MACHINE_CODE,
                'message_tr' => 'Bu işlem için oturum açmanız gerekiyor.',
                'correlation_id' => null,
                'details' => null,
            ]], 401);
        }
        $request->attributes->set(PrincipalRef::class, $principal);

        return $next($request);
    }
}
