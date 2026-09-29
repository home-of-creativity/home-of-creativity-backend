<?php

use App\Http\Controllers\GoogleDriveAccountController;
use App\Http\Controllers\LinkedInOAuthController;
use App\Http\Controllers\PhotographyAlarmController;
use App\Http\Controllers\ThreadsOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/threads/callback', [ThreadsOAuthController::class, 'callback'])
    ->middleware('throttle:30,1');

Route::get('/auth/linkedin/callback', [LinkedInOAuthController::class, 'callback'])
    ->middleware('throttle:30,1');

Route::get('/auth/google-drive/callback', [GoogleDriveAccountController::class, 'callback'])
    ->middleware('throttle:30,1');

Route::get('/photography-alarm/{booking}', [PhotographyAlarmController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('photography.alarm');

Route::get('/photography-alarm/{booking}/event.ics', [PhotographyAlarmController::class, 'calendar'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('photography.alarm.ics');
