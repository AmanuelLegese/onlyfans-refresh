<?php

use App\Http\Controllers\FakeUpstreamController;
use Illuminate\Support\Facades\Route;

Route::get('/api', function () {
    return response()->json(['message' => 'API is working']);
});

Route::get('/fake/api/users/{username}', FakeUpstreamController::class);
