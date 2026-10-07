<?php

namespace App\Filament\App\Resources\Staff;

use App\Enums\StaffRole;
use App\Filament\App\Resources\Staff\Pages\ListStaff;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Support\Facades\DB;
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

    /** A manager limited to some branches only sees staff of those branches (and themselves). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $ids = BranchScope::ids();

        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('user_id', Auth::id())
            ->orWhereIn('user_id', DB::table('branch_user')->whereIn('branch_id', $ids)->select('user_id')));
    }

    /** Non-owners pick branches once the restaurant has more than one. */
    public static function needsBranches(mixed $role): bool
    {
        $role = $role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role);

        return $role !== null && $role !== StaffRole::Owner && (Tenant::current()?->branches()->count() ?? 0) > 1;
    }

    /** @return array<int, int> */
    public static function branchIdsOf(Membership $membership): array
    {
        return DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branches.company_id', $membership->company_id)
            ->where('branch_user.user_id', $membership->user_id)
            ->pluck('branch_user.branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Saves the ticked branches. A limited manager only changes their own branches;
     * the person's other branches stay as the owner set them. Owners get no rows.
     *
     * @param  array<int, int|string>|null  $selected
     */
    public static function syncBranches(Membership $membership, ?array $selected): void
    {
        $companyBranches = Branch::query()->where('company_id', $membership->company_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mine = BranchScope::ids() ?? $companyBranches;
        $current = self::branchIdsOf($membership);

        $wanted = $membership->role === StaffRole::Owner
            ? []
            : array_values(array_unique(array_merge(
                array_diff($current, $mine), // branches this editor may not touch
                array_intersect(array_map('intval', $selected ?? []), $mine),
            )));

        DB::table('branch_user')->where('user_id', $membership->user_id)->whereIn('branch_id', $companyBranches)->delete();
        DB::table('branch_user')->insert(array_map(fn ($id) => ['branch_id' => $id, 'user_id' => $membership->user_id], $wanted));
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
                // Only an owner can make someone an owner.
                ->options(fn () => collect(StaffRole::cases())
                    ->reject(fn (StaffRole $r) => $r === StaffRole::Owner && ! BranchScope::seesAllBranches())
                    ->mapWithKeys(fn (StaffRole $r) => [$r->value => $r->getLabel()]))
                ->required()
                ->live()
                ->disabled(fn (?Membership $record) => $record?->user_id === Auth::id()),
            CheckboxList::make('branch_ids')
                ->label('Branches')
                ->helperText('They only see and work at these branches. Owners always see every branch.')
                ->options(fn () => BranchScope::branches(Branch::query())->orderBy('sort_order')->pluck('name', 'id'))
                ->columns(2)
                ->required(fn (Get $get) => self::needsBranches($get('role')))
                ->visible(fn (Get $get) => self::needsBranches($get('role')))
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
                TextColumn::make('branches')
                    ->label('Branches')
                    ->state(function (Membership $record) {
                        if ($record->role === StaffRole::Owner || (Tenant::current()?->branches()->count() ?? 0) <= 1) {
                            return 'All branches';
                        }

                        $names = Branch::query()->whereIn('id', self::branchIdsOf($record))->orderBy('sort_order')->pluck('name');

                        return $names->isEmpty() ? 'None: cannot work anywhere' : $names->implode(', ');
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'None') ? 'danger' : null),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (Membership $record) => $record->user_id === Auth::id() || self::protectsOwner($record)),
            ])
            ->recordActions([
                EditAction::make()
                    ->hidden(fn (Membership $record) => self::protectsOwner($record))
                    ->fillForm(fn (Membership $record) => [
                        'name' => $record->user->name,
                        'email' => $record->user->email,
                        'phone' => $record->user->phone,
                        'role' => $record->role,
                        'branch_ids' => self::branchIdsOf($record),
                        'is_active' => $record->is_active,
                    ])
                    ->using(function (Membership $record, array $data) {
                        $record->user->update(array_filter([
                            'name' => $data['name'],
                            'phone' => $data['phone'] ?? null,
                            'password' => $data['password'] ?? null,
                        ], fn ($v) => $v !== null && $v !== ''));

                        $changes = ['is_active' => $data['is_active'] ?? $record->is_active];

                        if (isset($data['role']) && $record->user_id !== Auth::id() && self::mayGiveRole($data['role'])) {
                            $changes['role'] = $data['role'];
                        }

                        if (! empty($data['pin'])) {
                            $changes['pin_hash'] = bcrypt($data['pin']);
                        }

                        $record->update($changes);

                        // People cannot change their own branches.
                        if ($record->user_id !== Auth::id()) {
                            self::syncBranches($record->fresh(), $data['branch_ids'] ?? []);
                        }

                        return $record;
                    }),
                DeleteAction::make()
                    ->label('Remove')
                    ->modalDescription('They lose access to this restaurant. Their account and past records stay.')
                    ->hidden(fn (Membership $record) => $record->user_id === Auth::id() || self::protectsOwner($record)),
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

        abort_unless(self::mayGiveRole($data['role']), 403, 'Only an owner can add an owner.');

        $membership = Membership::query()->create([
            'company_id' => Tenant::id(),
            'user_id' => $user->id,
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'pin_hash' => ! empty($data['pin']) ? bcrypt($data['pin']) : null,
        ]);

        self::syncBranches($membership, $data['branch_ids'] ?? []);

        return $membership;
    }

    /** Managers cannot create owners. */
    public static function mayGiveRole(mixed $role): bool
    {
        $role = $role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role);

        return $role !== StaffRole::Owner || BranchScope::seesAllBranches();
    }

    /** Only owners may change or remove an owner. */
    public static function protectsOwner(Membership $record): bool
    {
        return $record->role === StaffRole::Owner && $record->user_id !== Auth::id() && ! BranchScope::seesAllBranches();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
        ];
    }
}
