<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProfileController::class, 'index']);
Route::post('/profiles/{profile}/refresh', [ProfileController::class, 'refresh'])->name('profiles.refresh');
