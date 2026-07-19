<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());
});
