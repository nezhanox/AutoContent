<?php

use App\Http\Controllers\Console\ArchitectureController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\VideoController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(HandleInertiaRequests::class)->prefix('console')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware('auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('console.dashboard');
        Route::post('logout', [AuthController::class, 'destroy'])->name('console.logout');

        Route::get('videos', [VideoController::class, 'index'])->name('console.videos.index');
        Route::post('videos/generate', [VideoController::class, 'generate'])->name('console.videos.generate');
        Route::post('videos/{video}/retry', [VideoController::class, 'retry'])->name('console.videos.retry');

        Route::get('architecture', [ArchitectureController::class, 'index'])->name('console.architecture');
    });
});
