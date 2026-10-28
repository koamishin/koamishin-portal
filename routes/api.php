<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ManagementMembershipController;
use App\Http\Controllers\Api\V1\ManagementMetadataController;
use App\Http\Controllers\Api\V1\ManagementOrganizationController;
use App\Http\Controllers\Api\V1\ManagementSessionController;
use App\Http\Controllers\Api\V1\ManagementUserController;
use App\Http\Controllers\Auth\OpenIdConnectController;
use App\Http\Middleware\AuthenticateManagementClient;
use Illuminate\Support\Facades\Route;

Route::get('/user', [OpenIdConnectController::class, 'userinfo'])->middleware('auth:api');

Route::get('/oauth/userinfo', [OpenIdConnectController::class, 'userinfo'])
    ->middleware('auth:api')
    ->name('api.oauth.userinfo');

Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/organization', [ManagementOrganizationController::class, 'show'])
        ->middleware(AuthenticateManagementClient::using('organization:read'));
    Route::get('/users', [ManagementUserController::class, 'index'])
        ->middleware(AuthenticateManagementClient::using('users:read'));
    Route::get('/users/{userId}', [ManagementUserController::class, 'show'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('users:read'));
    Route::get('/users/{userId}/metadata', [ManagementMetadataController::class, 'show'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('metadata:read'));
    Route::patch('/users/{userId}/metadata', [ManagementMetadataController::class, 'update'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('metadata:read', 'metadata:write'));
    Route::get('/users/{userId}/sessions', [ManagementSessionController::class, 'index'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('sessions:read'));
    Route::delete('/users/{userId}/sessions/{sessionId}', [ManagementSessionController::class, 'destroy'])
        ->whereNumber('userId')
        ->whereAlphaNumeric('sessionId')
        ->middleware(AuthenticateManagementClient::using('sessions:revoke'));
    Route::put('/users/{userId}/membership', [ManagementMembershipController::class, 'store'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('memberships:write'));
    Route::delete('/users/{userId}/membership', [ManagementMembershipController::class, 'destroy'])
        ->whereNumber('userId')
        ->middleware(AuthenticateManagementClient::using('memberships:write'));
});
