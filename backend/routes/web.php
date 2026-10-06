<?php

use Illuminate\Support\Facades\Route;

// Restaurant owners and staff work in the Filament back office at /app;
// the platform team uses /admin. Customers never come here: they use the
// Next.js site the table QR codes point to.
Route::redirect('/', '/app');
