<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Laravel\Fortify\Http\Responses\VerifyEmailResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationVerifyEmailResponse extends VerifyEmailResponse
{
    public function toResponse($request): Response
    {
        return app(ApplicationAuthentication::class)->resume($request) ?? parent::toResponse($request);
    }
}
