<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Back-office dates and times show in the restaurant's own time zone (data stays in UTC). */
class UseCompanyTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        FilamentTimezone::set(Tenant::current()?->timezone ?? 'Asia/Phnom_Penh');

        return $next($request);
    }
}
