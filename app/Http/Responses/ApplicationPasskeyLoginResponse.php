<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationPasskeyLoginResponse extends PasskeyLoginResponse
{
    public function toResponse($request): Response
    {
        $flow = $request->attributes->get('koamishin.application_flow');
        if (is_array($flow) && $request->wantsJson()) {
            return response()->json(['redirect' => $flow['authorize_url']]);
        }

        return app(ApplicationAuthentication::class)->resume($request) ?? parent::toResponse($request);
    }
}
