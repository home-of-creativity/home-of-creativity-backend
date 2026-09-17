<?php

use App\Http\Controllers\ThreadsOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/threads/callback', [ThreadsOAuthController::class, 'callback'])
    ->middleware('throttle:30,1');
