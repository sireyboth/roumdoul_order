<?php

namespace App\Filament\Admin\Pages;

use App\Models\PlatformSetting;
use App\Support\Uploads;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * How restaurants pay Roumdoul for their plan: the ABA QR and account shown on their
 * "Your plan" page, and the contacts shown next to it.
 */
class PaymentSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $navigationLabel = 'Payment settings';

    protected static ?string $title = 'Payment settings';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(PlatformSetting::allValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('How restaurants pay their plan')
                ->description('Shown to owners on their "Your plan" page next to the amount to pay.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    FileUpload::make('payment_qr_path')
                        ->label('ABA / KHQR code (picture)')
                        ->image()
                        ->disk(Uploads::disk())
                        ->directory('platform')
                        ->maxSize(2048),
                    Section::make()->schema([
                        TextInput::make('payment_account_name')->label('Account name')->maxLength(120),
                        TextInput::make('payment_account_number')->label('Account number')->maxLength(60),
                        Textarea::make('payment_note')
                            ->label('Instructions')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('e.g. Put your restaurant name in the transfer note, then send us the screenshot.'),
                    ])->columnSpan(1),
                ]),
            Section::make('Contact')
                ->description('How owners can reach the Roumdoul team.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('support_phone')->label('Phone')->tel()->maxLength(30),
                    TextInput::make('support_telegram')->label('Telegram')->placeholder('@roumdoul')->maxLength(64),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Save')->submit('save')]),
                ]),
        ]);
    }

    public function save(): void
    {
        PlatformSetting::put($this->form->getState());

        Notification::make()->title('Saved')->success()->send();
    }
}
