<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArcWebhookController;
use App\Http\Controllers\Api\SettlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('settlements')->group(function (): void {
    Route::get('/', [SettlementController::class, 'index'])->name('api.settlements.index');
    Route::post('/', [SettlementController::class, 'store'])->name('api.settlements.store');
    Route::get('/{settlement}', [SettlementController::class, 'show'])->name('api.settlements.show');
    if (app()->environment('local', 'staging', 'testing')) {
        Route::post('/{settlement}/simulate-confirm', [SettlementController::class, 'simulateConfirm'])->name('api.settlements.simulate-confirm');
    }
});

Route::post('/webhooks/arc', [ArcWebhookController::class, 'handle'])->name('api.webhooks.arc');
