<?php

namespace App\Modules\Authorization\Infrastructure\Http;

use App\Modules\Authorization\Contracts\AuthenticationRequired;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\PrincipalRef;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v3.16 bearerAuth for API routes. A valid token attaches the SCH-PrincipalRef as the request attribute
 * PrincipalRef::class; anything else is 401 RC-AUTHN-REQUIRED with "WWW-Authenticate: Bearer" and no validation detail.
 * Authorization (403) is decided later by the operation, never here.
 */
final readonly class AuthenticateBearer
{
    public function __construct(private BearerAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $principal = $this->authenticator->authenticate($request->headers->get('Authorization'));
        } catch (AuthenticationRequired $exception) {
            return new JsonResponse(['error' => [
                'code' => AuthenticationRequired::MACHINE_CODE,
                'message_tr' => $exception->getMessage(),
                'correlation_id' => null,
                'details' => null,
            ]], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        $request->attributes->set(PrincipalRef::class, $principal);

        return $next($request);
    }
}
