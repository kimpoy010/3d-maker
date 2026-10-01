<?php

use App\Http\Controllers\CreationController;
use App\Http\Controllers\CreationFileController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('samples/demo.glb', SampleController::class)->name('samples.demo');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/creations')->name('dashboard');

    Route::get('create', [CreationController::class, 'create'])->name('creations.create');
    Route::post('creations', [CreationController::class, 'store'])->middleware('throttle:generate')->name('creations.store');
    Route::get('creations', [CreationController::class, 'index'])->name('creations.index');
    Route::get('creations/{creation}', [CreationController::class, 'show'])->name('creations.show');
    Route::delete('creations/{creation}', [CreationController::class, 'destroy'])->name('creations.destroy');
    Route::get('creations/{creation}/files/{type}', CreationFileController::class)
        ->whereIn('type', ['source', 'model', 'thumbnail'])
        ->name('creations.files');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/topup', [CreditController::class, 'topup'])->name('credits.topup');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
