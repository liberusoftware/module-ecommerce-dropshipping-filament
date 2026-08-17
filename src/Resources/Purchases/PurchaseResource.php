<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Dropshipping\Data\PurchaseView;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ListPurchases;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ViewPurchase;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\EventsRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Liberu\Ecommerce\Dropshipping\Queries\PurchaseStanding;
use UnitEnum;

/**
 * What the merchant has asked a supplier to ship, and how it went.
 *
 * **There is no supplier field on this screen, anywhere, in any form.** Which
 * supplier a line is sourced from is a decision over their published offers —
 * lead time first, then cost within a currency, then supplier code so the answer
 * is stable — and it is never an input. The host's checkout read
 * `$request->input('supplier_id', 'dropxl')`, so a hidden form field named the
 * supplier who would be paid, and a second one decided the order was
 * drop-shipped at all. The supplier column here is a fact about a purchase that
 * already exists; the filter beside it narrows a list. Neither assigns anything.
 *
 * **There is no state column in the schema, so the state column here is a fold.**
 * A purchase's state is replayed from its append-only events, ordered by when
 * they happened rather than when we heard, so a callback arriving late cannot
 * overwrite a state that happened after it. That is why the column is neither
 * sortable nor filterable: a sortable state is a promise that a column exists to
 * sort by, and there is not one.
 *
 * **Nothing here transmits and nothing here retries.** Transmission needs the
 * recipient's address, which this module deliberately does not hold, and the
 * one thing a `Sent, unconfirmed` purchase must never be offered is a second
 * send: the host's at-least-once queue retried on a reference the *supplier*
 * minted, so a timeout after the supplier accepted shipped real goods twice at
 * the merchant's cost. The remedy is on the record's own page and it is
 * **Reconcile**.
 */
final class PurchaseResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Purchase::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'purchase';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Purchases';

    protected static UnitEnum|string|null $navigationGroup = 'Sourcing';

    protected static ?int $navigationSort = 20;

    /** Filament's own return type is `Builder<Model>`; narrowing it here would be a claim the parent does not make. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', PanelTenant::current())
            // Not an optimisation: the fold walks both, and every state, promise
            // and despatch date on this screen comes out of it.
            ->with(['supplier', 'lines', 'events.shipmentLines']);
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where it stands')
                ->description('Replayed from the purchase\'s events when this page was opened. There is no status column in this module: a late callback overwriting one is how a despatched purchase goes back to "accepted".')
                ->columns(4)
                ->schema([
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (Purchase $record): string => Render::state(self::view($record)->state))
                        ->color(fn (Purchase $record): string => Render::stateColour(self::view($record)->state))
                        ->helperText(fn (Purchase $record): string => self::stateHelp(self::view($record)->state)),
                    TextEntry::make('promised_by')
                        ->label('Promised by')
                        ->state(fn (Purchase $record): string => Render::date(self::view($record)->promisedBy, Render::NONE.' no promise was made'))
                        ->helperText('The transmission date plus the lead time, counted in the supplier\'s own calendar. An acknowledgement carrying its own date revises it; nothing overwrites the original.'),
                    TextEntry::make('despatched_on')
                        ->label('Despatched on')
                        ->state(fn (Purchase $record): string => Render::date(self::view($record)->despatchedOn, Render::NONE.' not all of it has gone'))
                        ->helperText('The day the last outstanding line went. A part-despatched purchase has no despatch date, because a supplier has not met a promise until all of it has gone.'),
                    TextEntry::make('on_time')
                        ->label('Met the promise')
                        ->badge()
                        ->state(fn (Purchase $record): string => Render::onTime(self::view($record)->onTime))
                        ->color(fn (Purchase $record): string => Render::onTimeColour(self::view($record)->onTime))
                        ->helperText('An em dash means there is no promise or no complete despatch to judge one against — which is a different fact from a supplier who missed one.'),
                    TextEntry::make('quantities')
                        ->label('Despatched of ordered')
                        ->state(fn (Purchase $record): string => Render::quantities(
                            self::view($record)->orderedQuantities,
                            self::view($record)->shippedQuantities,
                        ))
                        ->columnSpanFull()
                        ->helperText('Suppliers despatch part of a purchase, from more than one warehouse, on more than one day. The host\'s single `supplier_tracking_number` column could not say so, and was written by nobody and read by nobody.'),
                    TextEntry::make('problems')
                        ->label('Problems')
                        ->state(fn (Purchase $record): string => Render::problems(self::view($record)->problems))
                        ->columnSpanFull()
                        ->helperText('A supplier\'s own code and the *name* of the field it rejected — never the value. A rejected postcode is actionable without this panel holding a second copy of where somebody lives.'),
                ]),

            Section::make('What it costs')
                ->description('Both costs are kept and neither overwrites the other. The difference between them is the only thing that makes a supplier\'s pricing auditable, and the host recorded neither.')
                ->columns(3)
                ->schema([
                    TextEntry::make('expected_cost')
                        ->label('Expected')
                        ->state(fn (Purchase $record): string => Render::money(self::view($record)->expectedCost))
                        ->helperText('From the offer at routing time, never revised.'),
                    TextEntry::make('actual_cost')
                        ->label('Charged')
                        ->state(fn (Purchase $record): string => Render::money(self::view($record)->actualCost))
                        ->helperText('What the supplier said when they acknowledged.'),
                    TextEntry::make('variance')
                        ->label('Difference')
                        ->state(fn (Purchase $record): string => Render::variance(self::view($record)))
                        ->helperText('Absent rather than zero when there is nothing to compare, and absent rather than converted when the two are in different currencies.'),
                ]),

            Section::make('The purchase')
                ->columns(4)
                ->schema([
                    TextEntry::make('reference')
                        ->label('Purchase')
                        ->copyable()
                        ->helperText('This is the correlation the supplier is given. The host sent `order-` plus a global auto-increment id, so two installs handed one supplier the same reference and the supplier learnt how many orders the merchant had taken.'),
                    TextEntry::make('order_ref')->label('Order')->copyable(),
                    TextEntry::make('supplier.name')->label('Supplier'),
                    TextEntry::make('provider_reference')
                        ->label('Their reference')
                        ->state(fn (Purchase $record): string => self::view($record)->providerReference ?? Render::NONE)
                        ->helperText('Recorded when the acknowledgement arrives, which is why it cannot be the idempotency key.'),
                    TextEntry::make('expected_lead_time_days')
                        ->label('Lead time promised')
                        ->state(fn (Purchase $record): string => $record->expected_lead_time_days.' days'),
                    TextEntry::make('routed_at')->label('Routed')->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Purchase')->searchable()->copyable(),
                TextColumn::make('supplier.code')
                    ->label('Supplier')
                    ->sortable()
                    ->tooltip('Decided by routing over this supplier\'s offers. Nothing on this screen assigns one.'),
                TextColumn::make('order_ref')->label('Order')->searchable()->copyable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Purchase $record): PurchaseState => self::view($record)->state)
                    ->formatStateUsing(fn (PurchaseState $state): string => Render::state($state))
                    ->color(fn (PurchaseState $state): string => Render::stateColour($state)),
                TextColumn::make('expected_cost_minor')
                    ->label('Expected')
                    ->state(fn (Purchase $record): string => Render::money(self::view($record)->expectedCost)),
                TextColumn::make('promised_by')
                    ->label('Promised by')
                    ->state(fn (Purchase $record): string => Render::date(self::view($record)->promisedBy)),
                TextColumn::make('despatched_on')
                    ->label('Despatched')
                    ->state(fn (Purchase $record): string => Render::date(self::view($record)->despatchedOn)),
                TextColumn::make('on_time')
                    ->label('Met it')
                    ->badge()
                    ->state(fn (Purchase $record): string => Render::onTime(self::view($record)->onTime))
                    ->color(fn (Purchase $record): string => Render::onTimeColour(self::view($record)->onTime)),
                TextColumn::make('routed_at')->label('Routed')->dateTime()->sortable(),
            ])
            ->filters([
                // A read filter over a column that exists. There is no state
                // filter beside it, because the state is a fold and is not
                // expressible in SQL — the queue for the one state that needs
                // acting on is its own screen.
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()
                        ->where('tenant_id', PanelTenant::current())
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all()),
            ])
            ->defaultSort('routed_at', 'desc')
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'view' => ViewPurchase::route('/{record}'),
        ];
    }

    /**
     * The fold, once per purchase.
     *
     * ponytail: memoised per purchase id, because the standing section reads it
     * from a dozen closures and a list row from five. The memo is dropped when a
     * page mounts and after every write, so no state survives the events that
     * produced it moving.
     *
     * @var array<int, PurchaseView>
     */
    private static array $views = [];

    public static function view(Purchase $record): PurchaseView
    {
        return self::$views[(int) $record->id] ??= (new PurchaseStanding())($record);
    }

    public static function forgetView(): void
    {
        self::$views = [];
    }

    /** What the state means, in the words an operator has to act on. */
    private static function stateHelp(PurchaseState $state): string
    {
        return match ($state) {
            PurchaseState::Draft => 'Routed and written, not yet sent. The host had no equivalent: its purchase existed only after the supplier answered.',
            PurchaseState::Transmitting => 'We asked and do not know. A timeout, a crash or a worker killed mid-call all land here, and so does a call that never happened. Reconcile — ask the supplier what they have — rather than sending it again.',
            PurchaseState::Acknowledged => 'The supplier has it and has said so. Not an HTTP 200 at the moment of sending, which is all the host ever had.',
            PurchaseState::PartiallyShipped => 'Some lines have gone. There is no despatch date yet and no promise to judge, because the purchase is not done until the last line has gone.',
            PurchaseState::Shipped => 'Every line has gone.',
            PurchaseState::Rejected => 'The supplier refused it. Re-sourcing means routing to a different supplier; asking the same one again after a refusal is a decision a person takes.',
            PurchaseState::Cancelled => 'Stopped before it shipped. The supplier is not told by this module — that is a transmission and 0.1.0 has no seam for it.',
        };
    }
}
