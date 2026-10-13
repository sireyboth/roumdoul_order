<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Subscriptions\PlatformAlerts;
use App\Support\Terms;
use App\Support\Uploads;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register your restaurant';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Restaurant or café name')
                ->required()
                ->maxLength(120),
            FileUpload::make('logo_path')
                ->label('Logo')
                ->helperText('Shown at the top of your customer menu.')
                ->image()
                ->disk(Uploads::disk())
                ->directory('logos')
                ->maxSize(2048)
                ->required(),
            TextInput::make('tagline')
                ->label('Short description')
                ->placeholder('e.g. Coffee & brunch in BKK1')
                ->maxLength(120),
            FileUpload::make('cover_path')
                ->label('Cover photo')
                ->helperText('A wide photo of your shop or food, shown behind your name on the menu.')
                ->image()
                ->disk(Uploads::disk())
                ->directory('covers')
                ->maxSize(4096),
            TextInput::make('phone')
                ->label('Phone')
                ->helperText('The Roumdoul team calls you here to help you set up.')
                ->tel()
                ->required()
                ->maxLength(30),
            TextInput::make('contact_telegram')
                ->label('Telegram')
                ->placeholder('@username or phone')
                ->helperText('Optional. We message you here about your plan and payments.')
                ->maxLength(64),
            TextInput::make('branch_name')
                ->label('First branch name')
                ->placeholder('e.g. BKK1')
                ->default('Main branch')
                ->required()
                ->maxLength(120),
            Select::make('currency')
                ->label('Menu prices are in')
                ->options(['USD' => 'US Dollar ($)', 'KHR' => 'Khmer Riel (៛)'])
                ->default('USD')
                ->required(),
            Terms::checkbox(),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = Auth::user();

        $company = app(CompanyProvisioner::class)->create($data, $user);
        Terms::record($company, $user, Terms::SIGNUP, 'Registered '.$company->name.' (free trial)');

        // Tell the Roumdoul team so they can call and help with the setup.
        app(PlatformAlerts::class)->newRestaurant($company, $user);

        return $company;
    }

    public function getModel(): string
    {
        return Company::class;
    }
}
