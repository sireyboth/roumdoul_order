<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
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
                ->disk('public')
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
                ->disk('public')
                ->directory('covers')
                ->maxSize(4096),
            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(30),
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
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = Auth::user();

        return app(CompanyProvisioner::class)->create($data, $user);
    }

    public function getModel(): string
    {
        return Company::class;
    }
}
