<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use LogicException;

/** @mixin Token */
class ManagementSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $token = $this->resource;
        if (! $token instanceof Token) {
            throw new LogicException('A Passport access token is required.');
        }
        $refreshToken = $token->getRelationValue('refreshToken');

        return [
            'id' => (string) $token->getKey(),
            'created_at' => $this->date($token->getAttribute('created_at')),
            'access_expires_at' => $this->date($token->getAttribute('expires_at')),
            'access_revoked' => (bool) $token->getAttribute('revoked'),
            'refresh_expires_at' => $refreshToken instanceof RefreshToken && ! $refreshToken->getAttribute('revoked')
                ? $this->date($refreshToken->getAttribute('expires_at'))
                : null,
        ];
    }

    private function date(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::ATOM) : null;
    }
}
