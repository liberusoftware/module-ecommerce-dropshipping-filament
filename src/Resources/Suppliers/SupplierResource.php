<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Dropshipping\Data\SupplierPerformance;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ListSuppliers;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ViewSupplier;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\OffersRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\PurchasesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Liberu\Ecommerce\Dropshipping\Queries\OutstandingPurchases;
use Liberu\Ecommerce\Dropshipping\Queries\SupplierPerformanceOf;
use UnitEnum;

/**
 * The suppliers this merchant sources from — **the table the host did not have.**
 *
 * `config/dropshipping.php` held three literal keys, `supplier1`, `supplier2` and
 * `dropxl`, each with an endpoint and an API key from `env()`, shared by every
 * merchant on the install. There was no table, no model and no relation from any
 * product, which is why checkout ended up asking the browser which supplier to
 * pay: there was no data the decision could have been made from.
 *
 * **No API key is entered here and none is shown.** A supplier names a
 * `credential_ref` that the host's secret store resolves; the value never enters
 * the domain package, so it cannot enter this one. The host's controller copied
 * two fields out of the supplier array by hand to avoid leaking the key, which
 * means a new config field leaked by default.
 *
 * The offer count is a `withCount` over a relation that restates its parent's
 * tenant, and its test asserts the count is **right and not zero**: an unguarded
 * restatement builds from a fresh instance whose `tenant_id` is null, becomes
 * `where('tenant_id', '')`, and reports zero for everybody — which looks exactly
 * like isolation working.
 */
final class SupplierResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Supplier::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'supplier';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Suppliers';

    protected static UnitEnum|string|null $navigationGroup = 'Sourcing';

    protected static ?int $navigationSort = 10;

    /** Filament's own return type is `Builder<Model>`; narrowing it here would be a claim the parent does not make. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', PanelTenant::current())
            ->withCount('offers');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            OffersRelationManager::class,
            PurchasesRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('How this supplier has performed')
                ->description('Counts, and one division taken out loud. The domain publishes no rate: a supplier who has despatched nothing has no record, and "0% on time" is a sentence a merchant terminates a contract over.')
                ->columns(4)
                ->schema([
                    TextEntry::make('on_time_share')
                        ->label('Met the promise')
                        ->state(fn (Supplier $record): string => Render::onTimeShare(self::performance($record)))
                        ->columnSpanFull()
                        ->helperText('Out of despatches against a purchase that carried a promise. A despatch against a purchase with no promise cannot be judged and is not counted as a miss — the host recorded no lead time anywhere, so it made no promise and could measure none.'),
                    TextEntry::make('purchases')
                        ->label('Purchases')
                        ->state(fn (Supplier $record): int => self::performance($record)->purchases),
                    TextEntry::make('shipped')
                        ->label('Despatched in full')
                        ->state(fn (Supplier $record): int => self::performance($record)->shipped)
                        ->helperText('A purchase is despatched when the last line has gone, not when the first one has.'),
                    TextEntry::make('unmeasurable')
                        ->label('Despatched, no promise')
                        ->state(fn (Supplier $record): int => self::performance($record)->unmeasurable),
                    TextEntry::make('late')
                        ->label('Late')
                        ->state(fn (Supplier $record): int => self::performance($record)->late),
                    TextEntry::make('rejected')
                        ->label('Refused')
                        ->state(fn (Supplier $record): int => self::performance($record)->rejected),
                    TextEntry::make('exceptions')
                        ->label('Problems raised')
                        ->state(fn (Supplier $record): int => self::performance($record)->exceptions),
                    TextEntry::make('outstanding')
                        ->label('Outstanding')
                        ->state(fn (Supplier $record): string => Render::outstanding(
                            (new OutstandingPurchases())((string) $record->tenant_id, (string) $record->reference),
                        ))
                        ->columnSpan(2)
                        ->helperText('Per currency, never summed. One figure for a merchant sourcing in two currencies would be this panel inventing a rate.'),
                ]),

            Section::make('The supplier')
                ->columns(4)
                ->schema([
                    TextEntry::make('code')
                        ->label('Code')
                        ->copyable()
                        ->helperText('The merchant\'s own code for them. It is the natural key, so registering it again updates rather than duplicating.'),
                    TextEntry::make('reference')->label('Reference')->copyable(),
                    TextEntry::make('currency')
                        ->label('Trades in')
                        ->state(fn (Supplier $record): string => $record->currency.' (exponent '.$record->currency_exponent.')')
                        ->helperText('An offer priced in another currency is refused rather than converted.'),
                    TextEntry::make('timezone')
                        ->label('Timezone')
                        ->helperText('A promised despatch date is a day in the supplier\'s own calendar. Counted against UTC it is a day out, and twice a year it is a day out by a different amount.'),
                    TextEntry::make('default_lead_time_days')
                        ->label('Default lead time')
                        ->state(fn (Supplier $record): string => $record->default_lead_time_days.' days'),
                    TextEntry::make('credential_ref')
                        ->label('Credential')
                        ->placeholder(Render::NONE)
                        ->helperText('A name the host\'s secret store resolves. No key is stored here, shown here or entered here.'),
                    TextEntry::make('is_active')
                        ->label('Active')
                        ->badge()
                        ->state(fn (Supplier $record): string => $record->is_active ? 'Active' : 'Not active')
                        ->color(fn (Supplier $record): string => $record->is_active ? 'success' : 'gray')
                        ->helperText('Routing takes no line from an inactive supplier, and says `supplier_inactive` rather than "no supplier available".'),
                    TextEntry::make('created_at')->label('Registered')->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('offers_count')
                    ->label('Offers')
                    ->sortable()
                    ->tooltip('Products this supplier has published a cost and a lead time for. Nothing in the host linked a product to a supplier at all.'),
                TextColumn::make('currency')->label('Currency'),
                TextColumn::make('default_lead_time_days')
                    ->label('Lead time')
                    ->formatStateUsing(fn (int $state): string => $state.' days')
                    ->sortable(),
                TextColumn::make('timezone')->label('Timezone')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->defaultSort('code')
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'view' => ViewSupplier::route('/{record}'),
        ];
    }

    /**
     * How this supplier has done, replayed.
     *
     * ponytail: memoised per supplier id, because the performance section reads
     * it from six closures and each read folds every purchase this supplier has.
     * The memo is dropped when the page mounts and after every write, so no
     * figure survives the thing it counted moving.
     *
     * @var array<int, SupplierPerformance>
     */
    private static array $performances = [];

    public static function performance(Supplier $record): SupplierPerformance
    {
        return self::$performances[(int) $record->id] ??= (new SupplierPerformanceOf())($record);
    }

    public static function forgetPerformance(): void
    {
        self::$performances = [];
    }
}
