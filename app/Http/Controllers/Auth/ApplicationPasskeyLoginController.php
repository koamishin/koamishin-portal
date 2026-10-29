<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkeys;

class ApplicationPasskeyLoginController extends PasskeyLoginController
{
    public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): PasskeyLoginResponse
    {
        $guard = $request->attributes->get('koamishin.application_guard');
        if (! $guard instanceof StatefulGuard) {
            return parent::store($request, $verify);
        }

        $passkey = $verify($request->credential(), $request->verificationOptions());
        if (! $passkey->user instanceof User || ! Passkeys::allowsLogin($request, $passkey) || $passkey->user->isBanned()) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        $guard->login($passkey->user);
        $request->session()->regenerate();

        return app(PasskeyLoginResponse::class);
    }
}
