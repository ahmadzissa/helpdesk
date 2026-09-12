<?php

use App\Http\Controllers\ExternalTicketController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/external')->middleware(['throttle:120,1', AuthenticateApiKey::class])->group(function () {
    Route::post('tickets', [ExternalTicketController::class, 'store']);
    Route::post('delivery-events', [ExternalTicketController::class, 'delivery']);
});
