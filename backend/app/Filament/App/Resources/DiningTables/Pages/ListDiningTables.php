<?php

namespace App\Filament\App\Resources\DiningTables\Pages;

use App\Filament\App\Resources\DiningTables\DiningTableResource;
use App\Models\DiningTable;
use App\Support\Tenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ListDiningTables extends ListRecords
{
    protected static string $resource = DiningTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMany')
                ->label('Add many tables')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->visible(fn () => DiningTableResource::canCreate())
                ->schema([
                    ...DiningTableResource::branchAndAreaFields(),
                    TextInput::make('prefix')->default('T')->maxLength(10)->helperText('T makes T1, T2, T3...'),
                    TextInput::make('from')->numeric()->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('to')->numeric()->integer()->maxValue(500)->default(10)->required()
                        ->minValue(fn (Get $get) => max(1, (int) $get('from'))),
                ])
                ->action(function (array $data) {
                    $company = Tenant::current();
                    $limit = $company->limitFor('tables');
                    $room = $limit === null ? PHP_INT_MAX : max(0, $limit - $company->diningTables()->count());

                    $created = 0;
                    $skipped = 0;

                    DB::transaction(function () use ($data, $company, $room, &$created, &$skipped) {
                        foreach (range((int) $data['from'], (int) $data['to']) as $number) {
                            $name = trim(($data['prefix'] ?? '').$number);

                            $exists = DiningTable::query()
                                ->where('branch_id', $data['branch_id'])
                                ->where('name', $name)
                                ->exists();

                            if ($exists || $created >= $room) {
                                $skipped++;

                                continue;
                            }

                            DiningTable::query()->create([
                                'company_id' => $company->id,
                                'branch_id' => $data['branch_id'],
                                'table_area_id' => $data['table_area_id'] ?? null,
                                'name' => $name,
                                'sort_order' => $number,
                            ]);
                            $created++;
                        }
                    });

                    Notification::make()
                        ->title("Added {$created} tables".($skipped ? ", skipped {$skipped} (already exist or plan limit)" : ''))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
