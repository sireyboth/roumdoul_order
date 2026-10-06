<?php

namespace App\Filament\Admin\Resources\Companies\Pages;

use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // For restaurants you sign up yourself (sales visit): creates the account and owner login.
            Action::make('onboard')
                ->label('Add restaurant')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('name')->label('Restaurant name')->required(),
                    TextInput::make('branch_name')->label('First branch')->default('Main branch')->required(),
                    Select::make('currency')->options(['USD' => 'USD', 'KHR' => 'KHR'])->default('USD')->required(),
                    Select::make('plan')->options(fn () => Plan::query()->where('is_active', true)->pluck('name', 'code'))->default('starter'),
                    TextInput::make('owner_name')->required(),
                    TextInput::make('owner_email')->email()->required(),
                    TextInput::make('owner_password')->password()->revealable()->minLength(8)
                        ->helperText('Only used if this email has no account yet.'),
                ])
                ->action(function (array $data) {
                    $owner = User::query()->firstOrCreate(
                        ['email' => strtolower($data['owner_email'])],
                        ['name' => $data['owner_name'], 'password' => $data['owner_password'] ?: str()->random(16)],
                    );

                    $company = app(CompanyProvisioner::class)->create($data, $owner, $data['plan'] ?? null);

                    Notification::make()->title("{$company->name} is ready at /app/{$company->slug}")->success()->send();
                }),
        ];
    }
}
