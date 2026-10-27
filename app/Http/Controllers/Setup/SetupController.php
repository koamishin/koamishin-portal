<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\ApplicationDetailsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class SetupController extends Controller
{
    /**
     * Show initial instance setup wizard.
     */
    public function index(): Response|RedirectResponse
    {
        if ($this->isAlreadyInstalled()) {
            return redirect()->route('home');
        }

        return Inertia::render('setup/Index');
    }

    /**
     * Handle initial instance setup and administrator account creation.
     */
    public function store(Request $request, ApplicationDetailsSettings $settings): RedirectResponse
    {
        if ($this->isAlreadyInstalled()) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'instance_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $settings->site_name = $validated['instance_name'];
        $settings->save();

        $role = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $admin = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $admin->assignRole($role);

        if (! file_exists(storage_path('oauth-public.key'))) {
            Artisan::call('passport:keys', ['--force' => true]);
        }

        Auth::login($admin);

        return redirect('/admin');
    }

    protected function isAlreadyInstalled(): bool
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->exists();
    }
}
