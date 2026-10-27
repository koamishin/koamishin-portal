<?php

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Http\Controllers\Auth\OpenIdConnectController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Setup\SetupController;
use App\Models\Tenant;
use App\Models\TenantApplication;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
]))->name('home');

Route::get('dashboard', fn () => Inertia::render('Dashboard'))->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
});

Route::middleware('web')->group(function (): void {
    /** @phpstan-ignore-next-line */
    Route::impersonate();
    Route::get('impersonate/take-redirect', [ImpersonateController::class, 'takeRedirect'])->name('impersonate.take-redirect');
    Route::get('impersonate/leave-redirect', [ImpersonateController::class, 'leaveRedirect'])->name('impersonate.leave-redirect');
});

$socialProviders = implode('|', SocialLoginProvider::values());

Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->where('provider', $socialProviders)
    ->name('auth.social.redirect');

Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->where('provider', $socialProviders)
    ->name('auth.social.callback');

Route::get('.well-known/openid-configuration', [OpenIdConnectController::class, 'discovery'])->name('openid.discovery');
Route::get('oauth/jwks', [OpenIdConnectController::class, 'jwks'])->name('oauth.jwks');

Route::get('setup', [SetupController::class, 'index'])->name('setup.index');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware(['web', 'auth'])->get('admin/{tenant:slug}/switch-app/{application}', function (Tenant $tenant, TenantApplication $application) {
    if ($application->tenant_id !== $tenant->id) {
        abort(403);
    }
    session(['current_application_id' => $application->id]);

    return redirect()->to(TenantApplicationResource::getUrl('view', [
        'record' => $application,
        'tenant' => $tenant,
    ]));
})->name('filament.admin.switch-application');

require __DIR__.'/settings.php';
