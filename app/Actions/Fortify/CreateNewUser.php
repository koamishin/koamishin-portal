<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RoleEnums;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->assignRole($this->defaultRole());
            app(ApplicationAuthentication::class)->enrollNewUser(request(), $user);

            return $user;
        });
    }

    protected function defaultRole(): Role
    {
        /** @var Role $role */
        $role = Role::query()->firstOrCreate([
            'name' => RoleEnums::USER->value,
            'guard_name' => 'web',
        ]);

        return $role;
    }
}
