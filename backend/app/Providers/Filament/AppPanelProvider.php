<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Http\Middleware\UseCompanyTimezone;
use App\Filament\App\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Restaurant back office for owners and managers: /app/{company-slug}/...
 * Each company is a Filament tenant, so every resource is automatically
 * limited to the company in the URL (through each model's company() relation).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->brandName('Roumdoul Order')
            ->login()
            ->registration()
            ->passwordReset()
            ->profile()
            ->tenant(Company::class, slugAttribute: 'slug', ownershipRelationship: 'company')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(EditCompanyProfile::class)
            ->colors([
                'primary' => Color::hex('#0f7a5c'),
            ])
            ->font(
                family: 'Kantumruy Pro',
                url: 'https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@300..700&display=swap',
                provider: GoogleFontProvider::class,
            )
            ->navigationGroups([
                'Menu',
                'Restaurant',
                'Team',
            ])
            ->errorNotifications(fn () => app()->environment('production'))
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->pages([
                Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                UseCompanyTimezone::class,
            ], isPersistent: true);
    }
}
