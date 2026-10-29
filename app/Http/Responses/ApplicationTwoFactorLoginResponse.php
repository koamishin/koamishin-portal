<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $authentication = app(ApplicationAuthentication::class);
        $flow = $authentication->flowId($request);
        if ($flow !== null) {
            $request->session()->put('koamishin.sso_mfa_verified.'.$flow, true);
        }

        return $authentication->resume($request) ?? parent::toResponse($request);
    }
}
