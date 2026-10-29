<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationRegisterResponse extends RegisterResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        return app(ApplicationAuthentication::class)->resume($request) ?? parent::toResponse($request);
    }
}
