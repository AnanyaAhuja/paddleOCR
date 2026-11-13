<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documents', [DocumentController::class, 'create'])->name('documents.create');
Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::get('/documents/{id}', [DocumentController::class, 'show'])->name('documents.show');

// Manual verification of imported documents (php artisan documents:import).
Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
Route::get('/reviews/summary', [ReviewController::class, 'summary'])->name('reviews.summary');
Route::get('/reviews/{extraction}', [ReviewController::class, 'show'])->name('reviews.show');
Route::post('/reviews/{extraction}', [ReviewController::class, 'update'])->name('reviews.update');
Route::get('/reviews/{extraction}/image/{side}', [ReviewController::class, 'image'])
    ->whereIn('side', ['front', 'back'])->name('reviews.image');
