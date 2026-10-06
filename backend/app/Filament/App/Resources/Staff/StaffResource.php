<?php

namespace App\Filament\App\Resources\Staff;

use App\Enums\StaffRole;
use App\Filament\App\Resources\Staff\Pages\ListStaff;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Everyone who works at the restaurant. Owners and managers use this back
 * office; cashiers, kitchen and waiters will sign in to the staff screens
 * (Step 1) with the same email and password.
 */
class StaffResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $slug = 'staff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Team';

    public static function canCreate(): bool
    {
        return ! (Tenant::current()?->hasReachedLimit('staff') ?? true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->disabledOn('edit')
                ->helperText('Used to sign in. Someone who already has a Roumdoul Order account keeps their password.'),
            TextInput::make('phone')->tel()->maxLength(30),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave empty to keep the current password.' : 'Only used for new accounts.'),
            Select::make('role')
                ->options(StaffRole::class)
                ->required()
                ->live()
                ->disabled(fn (?Membership $record) => $record?->user_id === Auth::id()),
            TextInput::make('pin')
                ->label('Manager PIN')
                ->password()
                ->revealable()
                ->regex('/^\d{4,6}$/')
                ->helperText('4 to 6 digits. Approves voids and refunds on the staff screens.')
                ->visible(fn (Get $get) => in_array($get('role'), [StaffRole::Owner, StaffRole::Manager, 'owner', 'manager'], true)),
            Toggle::make('is_active')
                ->label('Can sign in')
                ->default(true)
                ->disabled(fn (?Membership $record) => $record?->user_id === Auth::id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable()->weight('bold'),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('user.phone')->label('Phone')->placeholder('—'),
                TextColumn::make('role')->badge(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (Membership $record) => $record->user_id === Auth::id()),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (Membership $record) => [
                        'name' => $record->user->name,
                        'email' => $record->user->email,
                        'phone' => $record->user->phone,
                        'role' => $record->role,
                        'is_active' => $record->is_active,
                    ])
                    ->using(function (Membership $record, array $data) {
                        $record->user->update(array_filter([
                            'name' => $data['name'],
                            'phone' => $data['phone'] ?? null,
                            'password' => $data['password'] ?? null,
                        ], fn ($v) => $v !== null && $v !== ''));

                        $changes = ['is_active' => $data['is_active'] ?? $record->is_active];

                        if (isset($data['role']) && $record->user_id !== Auth::id()) {
                            $changes['role'] = $data['role'];
                        }

                        if (! empty($data['pin'])) {
                            $changes['pin_hash'] = bcrypt($data['pin']);
                        }

                        $record->update($changes);

                        return $record;
                    }),
                DeleteAction::make()
                    ->label('Remove')
                    ->modalDescription('They lose access to this restaurant. Their account and past records stay.')
                    ->hidden(fn (Membership $record) => $record->user_id === Auth::id()),
            ]);
    }

    /** Finds or creates the person's account, then gives them a job at this company. */
    public static function createMember(array $data): Membership
    {
        $user = User::query()->firstWhere('email', strtolower($data['email']))
            ?? User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

        return Membership::query()->create([
            'company_id' => Tenant::id(),
            'user_id' => $user->id,
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'pin_hash' => ! empty($data['pin']) ? bcrypt($data['pin']) : null,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
        ];
    }
}
