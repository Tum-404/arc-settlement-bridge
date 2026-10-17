<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArcWebhookController;
use App\Http\Controllers\Api\RecipientController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\SolidInvoiceWebhookController;
use App\Http\Middleware\EnsureDemoMode;
use Illuminate\Support\Facades\Route;

Route::prefix('settlements')->group(function (): void {
    Route::get('/', [SettlementController::class, 'index'])->name('api.settlements.index');
    Route::post('/', [SettlementController::class, 'store'])->name('api.settlements.store');
    Route::get('/{settlement}', [SettlementController::class, 'show'])->name('api.settlements.show');
    Route::middleware(EnsureDemoMode::class)
        ->post('/{settlement}/simulate-confirm', [SettlementController::class, 'simulateConfirm'])
        ->name('api.settlements.simulate-confirm');
});

Route::prefix('recipients')->group(function (): void {
    Route::get('/', [RecipientController::class, 'index'])->name('api.recipients.index');
    Route::get('/by-wallet/{cryptoAddress}', [RecipientController::class, 'showByWallet'])->name('api.recipients.show-by-wallet');
    Route::post('/', [RecipientController::class, 'store'])->name('api.recipients.store');
    Route::patch('/{recipient}/toggle', [RecipientController::class, 'toggle'])->name('api.recipients.toggle');
    Route::delete('/{recipient}', [RecipientController::class, 'destroy'])->name('api.recipients.destroy');
});

Route::post('/webhooks/arc', [ArcWebhookController::class, 'handle'])->name('api.webhooks.arc');
Route::post('/webhooks/solidinvoice', [SolidInvoiceWebhookController::class, 'handle'])->name('api.webhooks.solidinvoice');
