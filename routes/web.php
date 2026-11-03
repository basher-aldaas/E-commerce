<?php

use Illuminate\Support\Facades\Route;

// Single-page web interface for the API (public/app.html).
Route::get('/', function () {
    return response()->file(public_path('app.html'));
});
