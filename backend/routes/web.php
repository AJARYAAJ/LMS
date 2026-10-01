<?php

use App\Http\Controllers\Api\TrackedLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/up'),
]));

// Short campaign links (UTM link builder).
Route::get('/l/{code}', [TrackedLinkController::class, 'follow'])->where('code', '[A-Za-z0-9]{7}')->middleware('throttle:600,1');
