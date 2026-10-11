<?php

namespace App\Filament\Admin\Resources\Users;

use App\Enums\StaffRole;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every login on the platform. Block = the person cannot sign in anywhere (back office
 * or staff screens), whatever restaurant they work at. Restaurants switch staff on/off
 * for themselves on their Staff page; this is for Roumdoul (abuse, unpaid, lost device).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('companies'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (User $record) => $record->email),
                TextColumn::make('restaurants')
                    ->state(fn (User $record) => $record->companies
                        ->map(fn ($company) => $company->name.' ('.($company->pivot->role instanceof StaffRole ? $company->pivot->role->value : $company->pivot->role).(! $company->pivot->is_active ? ', off' : '').')')
                        ->implode(', '))
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('md'),
                IconColumn::make('is_platform_admin')->label('Admin')->boolean()->visibleFrom('lg'),
                TextColumn::make('is_active')
                    ->label('Sign in')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Allowed' : 'Blocked')
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')->since()->label('Joined')->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Sign in')->trueLabel('Allowed')->falseLabel('Blocked'),
            ])
            ->recordActions([
                Action::make('block')
                    ->label('Block')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (User $record) => $record->is_active && $record->id !== Auth::id())
                    ->requiresConfirmation()
                    ->modalDescription('They are signed out of every screen and cannot sign in until you unblock them. Their restaurants and records stay.')
                    ->action(fn (User $record) => static::block($record)),
                Action::make('unblock')
                    ->label('Unblock')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (User $record) => ! $record->is_active)
                    ->action(function (User $record) {
                        $record->update(['is_active' => true]);
                        Notification::make()->title("{$record->name} can sign in again")->success()->send();
                    }),
            ]);
    }

    /** Blocks the account and ends its sessions: staff-screen tokens and back-office logins. */
    public static function block(User $user): void
    {
        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        Notification::make()->title("{$user->name} is blocked")->success()->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
