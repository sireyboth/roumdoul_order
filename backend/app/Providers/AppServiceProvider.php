<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch typos in mass-assignment during development instead of losing data silently.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Customer traffic arrives through the Next.js server, so limits are per table
        // (the QR token), not per IP address, which would be shared by every customer.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(240)->by('r|'.$request->route('token')));
        RateLimiter::for('public-write', fn (Request $request) => Limit::perMinute(20)->by('w|'.$request->route('token')));
    }
}
