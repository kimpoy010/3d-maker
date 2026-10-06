<?php

use App\Http\Controllers\ClickerController;
use App\Http\Controllers\ClickerFileController;
use App\Http\Controllers\CreationController;
use App\Http\Controllers\CreationFileController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\StylizationController;
use App\Http\Controllers\StylizationFileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // The version changes with the file, so browsers that cached an older sample fetch the new one.
    $sample = resource_path('samples/demo.glb');

    return Inertia::render('Welcome', [
        'sampleUrl' => '/samples/demo.glb?v='.(is_file($sample) ? filemtime($sample) : 0),
    ]);
})->name('home');

Route::get('samples/demo.glb', SampleController::class)->name('samples.demo');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/creations')->name('dashboard');

    Route::get('create', [CreationController::class, 'create'])->name('creations.create');
    Route::get('creations', [CreationController::class, 'index'])->name('creations.index');
    Route::get('creations/{creation}', [CreationController::class, 'show'])->name('creations.show');
    Route::delete('creations/{creation}', [CreationController::class, 'destroy'])->name('creations.destroy');
    Route::post('creations/{creation}/unlock', [CreationController::class, 'unlock'])->middleware('throttle:generate')->name('creations.unlock');
    Route::post('creations/{creation}/retry', [CreationController::class, 'retry'])->middleware('throttle:generate')->name('creations.retry');
    Route::get('creations/{creation}/files/{type}', CreationFileController::class)
        ->whereIn('type', ['source', 'model', 'print', 'thumbnail'])
        ->name('creations.files');

    Route::get('clickers', [ClickerController::class, 'index'])->name('clickers.index');
    Route::get('clickers/create', [ClickerController::class, 'create'])->name('clickers.create');
    Route::post('clickers', [ClickerController::class, 'store'])->middleware('throttle:clicker')->name('clickers.store');
    Route::get('clickers/{clicker}', [ClickerController::class, 'show'])->name('clickers.show');
    Route::put('clickers/{clicker}', [ClickerController::class, 'update'])->middleware('throttle:clicker')->name('clickers.update');
    Route::delete('clickers/{clicker}', [ClickerController::class, 'destroy'])->name('clickers.destroy');
    Route::post('clickers/{clicker}/duplicate', [ClickerController::class, 'duplicate'])->middleware('throttle:clicker')->name('clickers.duplicate');
    Route::post('clickers/{clicker}/unlock', [ClickerController::class, 'unlock'])->middleware('throttle:clicker')->name('clickers.unlock');
    Route::get('clickers/{clicker}/files/{type}', ClickerFileController::class)
        ->whereIn('type', ['artwork', 'thumbnail', '3mf', 'stl'])
        ->name('clickers.files');

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
