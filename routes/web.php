<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsureDemoMode;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('home');

Route::middleware(EnsureDemoMode::class)
    ->post('/demo/reset', [DashboardController::class, 'resetDemo'])
    ->name('demo.reset');
