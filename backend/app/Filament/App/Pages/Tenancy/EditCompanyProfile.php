<?php

namespace App\Filament\App\Pages\Tenancy;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Restaurant settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('phone')->tel()->maxLength(30),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('tagline')
                        ->label('Short description')
                        ->placeholder('e.g. Coffee & brunch in BKK1')
                        ->maxLength(120),
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->helperText('Shown at the top of your customer menu.')
                        ->image()
                        ->disk('public')
                        ->directory('logos')
                        ->maxSize(2048),
                    FileUpload::make('cover_path')
                        ->label('Cover photo')
                        ->helperText('A wide photo of your shop or food, shown behind your name on the menu.')
                        ->image()
                        ->disk('public')
                        ->directory('covers')
                        ->maxSize(4096),
                    FileUpload::make('khqr_image_path')
                        ->label('KHQR code (picture)')
                        ->helperText('The KHQR your bank gave the shop. It is printed on bills so customers can scan and pay.')
                        ->image()
                        ->disk('public')
                        ->directory('khqr')
                        ->maxSize(2048),
                ]),
            Section::make('Money')
                ->description('Prices on the menu are entered in this currency. Riel amounts are shown next to dollar totals.')
                ->columns(2)
                ->schema([
                    Select::make('currency')
                        ->options(['USD' => 'US Dollar ($)', 'KHR' => 'Khmer Riel (៛)'])
                        ->required()
                        ->helperText('Changing this does not convert existing prices.'),
                    TextInput::make('khr_per_usd')
                        ->label('Exchange rate (៛ per $1)')
                        ->numeric()
                        ->integer()
                        ->minValue(1000)
                        ->maxValue(10000)
                        ->required(),
                    TextInput::make('vat_bp')
                        ->label('VAT %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->step(0.01)
                        ->suffix('%')
                        ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : 0)
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                    TextInput::make('service_charge_bp')
                        ->label('Service charge %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->step(0.01)
                        ->suffix('%')
                        ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : 0)
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                    Toggle::make('prices_include_vat')
                        ->label('Menu prices already include VAT'),
                ]),
            Section::make('Alerts')
                ->schema([
                    TextInput::make('telegram_chat_id')
                        ->label('Telegram chat ID')
                        ->helperText('Where new orders and daily sales are sent (Step 1).')
                        ->maxLength(64),
                ]),
        ]);
    }
}
