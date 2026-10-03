<?php

use App\Http\Controllers\CreationController;
use App\Http\Controllers\CreationFileController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\StylizationController;
use App\Http\Controllers\StylizationFileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('samples/demo.glb', SampleController::class)->name('samples.demo');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/creations')->name('dashboard');

    Route::get('create', [CreationController::class, 'create'])->name('creations.create');
    Route::get('creations', [CreationController::class, 'index'])->name('creations.index');
    Route::get('creations/{creation}', [CreationController::class, 'show'])->name('creations.show');
    Route::delete('creations/{creation}', [CreationController::class, 'destroy'])->name('creations.destroy');
    Route::post('creations/{creation}/retry', [CreationController::class, 'retry'])->middleware('throttle:generate')->name('creations.retry');
    Route::get('creations/{creation}/files/{type}', CreationFileController::class)
        ->whereIn('type', ['source', 'model', 'print', 'thumbnail'])
        ->name('creations.files');

    Route::post('stylizations', [StylizationController::class, 'store'])->middleware('throttle:generate')->name('stylizations.store');
    Route::get('stylizations/{stylization}', [StylizationController::class, 'show'])->name('stylizations.show');
    Route::post('stylizations/{stylization}/approve', [StylizationController::class, 'approve'])->middleware('throttle:generate')->name('stylizations.approve');
    Route::post('stylizations/{stylization}/retry', [StylizationController::class, 'retry'])->middleware('throttle:generate')->name('stylizations.retry');
    Route::delete('stylizations/{stylization}', [StylizationController::class, 'destroy'])->name('stylizations.destroy');
    Route::get('stylizations/{stylization}/files/{type}', StylizationFileController::class)
        ->whereIn('type', ['original', 'result'])
        ->name('stylizations.files');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/topup', [CreditController::class, 'topup'])->name('credits.topup');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
