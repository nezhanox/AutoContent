<?php

use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\VideoController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(HandleInertiaRequests::class)->prefix('console')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store']);

    Route::middleware('auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('console.dashboard');
        Route::post('logout', [AuthController::class, 'destroy'])->name('console.logout');

        Route::get('videos', [VideoController::class, 'index'])->name('console.videos.index');
        Route::post('videos/generate', [VideoController::class, 'generate'])->name('console.videos.generate');
    });
});
