<?php

use App\Http\Controllers\Auth\OpenIdConnectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::get('/oauth/userinfo', [OpenIdConnectController::class, 'userinfo'])
    ->middleware('auth:api')
    ->name('api.oauth.userinfo');
