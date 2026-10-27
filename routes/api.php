<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OpenIdConnectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn(Request $request) => $request->user())->middleware('auth:api');

Route::get('/oauth/userinfo', [OpenIdConnectController::class, 'userinfo'])
    ->middleware('auth:api')
    ->name('api.oauth.userinfo');
