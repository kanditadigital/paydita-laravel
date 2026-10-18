<?php

use Illuminate\Support\Facades\Route;
use Kandita\Paydita\Http\ReceiveEventController;

Route::post(config('paydita.webhook.path'), ReceiveEventController::class)
    ->middleware(config('paydita.webhook.middleware', []))
    ->name('paydita.events');
