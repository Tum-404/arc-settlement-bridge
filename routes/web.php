<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('home');

if (app()->environment(['local', 'testing'])) {
    Route::post('/demo/reset', [DashboardController::class, 'resetDemo'])->name('demo.reset');
}
