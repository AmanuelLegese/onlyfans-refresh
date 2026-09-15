<?php

use App\Http\Controllers\FakeUpstreamController;
use Illuminate\Support\Facades\Route;

// Fake OnlyFans API for tests and workloads. Served by the `upstream` container;
// the controller returns 404 unless FAKE_UPSTREAM_ENABLED is true.
Route::get('/fake/api/users/{username}', FakeUpstreamController::class);
