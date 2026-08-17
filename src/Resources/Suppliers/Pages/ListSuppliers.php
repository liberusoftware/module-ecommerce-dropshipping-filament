<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages;

use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Dropshipping\Actions\RegisterSupplier;
use Liberu\Ecommerce\Dropshipping\Exceptions\DropshippingException;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;

/**
 * The suppliers, and the one write that makes the rest of the module possible.
 *
 * **Registering goes through `RegisterSupplier`, not through a Filament create
 * page.** The action is idempotent on `(tenant_id, code)` — the merchant's own
 * code, a natural key, because the supplier relationship exists before this
 * module does — so entering the same code twice updates rather than
 * duplicating. A Filament form saving the model would have neither the key nor
 * the idempotency, and would need a second screen to edit what it created.
 *
 * **No API key field, and there will not be one.** The supplier names a
 * credential the host's secret store holds. The host stored one key per supplier
 * per install, shared by every merchant, and its API controller then removed the
 * key from the response by copying two other fields out by hand — so a new
 * config field leaked by default.
 */
final class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    public function mount(): void
    {
        SupplierResource::forgetPerformance();

        parent::mount();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')
                ->label('Register a supplier')
                ->icon('heroicon-o-plus-circle')
                ->modalHeading('Register a supplier')
                ->modalDescription(
                    'Writes the supplier through the domain action, which is idempotent on the code you give it: entering a code this merchant already uses updates that supplier rather than creating a second one. '
                    .'No credential is entered here — name the one your secret store holds.'
                )
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Your own code for this supplier. It is the key, so the same code twice is one supplier.'),
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('currency')
                        ->label('Currency')
                        ->required()
                        ->minLength(3)
                        ->maxLength(3)
                        ->helperText('The ISO 4217 code this supplier invoices in. An offer priced in another currency is refused rather than converted.'),
                    TextInput::make('currency_exponent')
                        ->label('Minor units')
                        ->required()
                        ->numeric()
                        ->default(2)
                        ->minValue(0)
                        ->maxValue(4)
                        ->helperText('0 for JPY, 2 for most.'),
                    Select::make('timezone')
                        ->label('Timezone')
                        ->required()
                        ->searchable()
                        ->options(fn (): array => array_combine(
                            DateTimeZone::listIdentifiers(),
                            DateTimeZone::listIdentifiers(),
                        ))
                        ->helperText('The calendar a promised despatch date is counted in. There is no default, because a promise computed in the wrong timezone is a day out and nobody notices.'),
                    TextInput::make('default_lead_time_days')
                        ->label('Default lead time, in days')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Used where an offer states none. It is what makes a promise measurable at all.'),
                    TextInput::make('credential_ref')
                        ->label('Credential name')
                        ->maxLength(128)
                        ->helperText('A name your secret store resolves. Never a key: nothing in this module stores, shows or accepts one.'),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Routing takes no line from an inactive supplier, and names that as the reason rather than saying "no supplier available".'),
                ])
                ->action(function (array $data): void {
                    try {
                        $supplier = App::make(RegisterSupplier::class)(
                            tenantId: PanelTenant::current(),
                            code: (string) $data['code'],
                            name: (string) $data['name'],
                            currency: strtoupper((string) $data['currency']),
                            timezone: (string) $data['timezone'],
                            // Cast on arrival: a Filament numeric input hands
                            // back a string, and a `TypeError` inside a Livewire
                            // component method comes back as a bare 419.
                            defaultLeadTimeDays: (int) $data['default_lead_time_days'],
                            currencyExponent: (int) $data['currency_exponent'],
                            credentialRef: self::text($data['credential_ref'] ?? null),
                            isActive: (bool) ($data['is_active'] ?? true),
                        );
                    } catch (DropshippingException $e) {
                        Notification::make()->title('Nothing was written')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    SupplierResource::forgetPerformance();

                    Notification::make()
                        ->title('Registered')
                        ->body($supplier->name.' — '.$supplier->reference)
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
