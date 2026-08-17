<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Liberu\Ecommerce\Dropshipping\Actions\PublishSupplyOffer;
use Liberu\Ecommerce\Dropshipping\Data\Money;
use Liberu\Ecommerce\Dropshipping\Enums\Availability;
use Liberu\Ecommerce\Dropshipping\Exceptions\DropshippingException;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Liberu\Ecommerce\Dropshipping\Models\SupplyOffer;

/**
 * What this supplier supplies, at what cost, in how many days.
 *
 * **This is the data the host had none of.** There was no `suppliers` table, no
 * supplier model and no product-to-supplier link anywhere in the tree, so
 * checkout could not have routed by data even if it had wanted to — it read
 * `$request->input('supplier_id', 'dropxl')` instead. Everything on this table
 * is an input to a routing decision that is now the module's rather than the
 * browser's.
 *
 * The four columns after the cost are the four things routing reads: the lead
 * time it ranks by, the availability stance it filters on, the minimum quantity
 * it refuses below, and — on the supplier itself — whether they are active. A
 * merchant looking at a line that would not source can see which of them said
 * no.
 *
 * **`Not stated` is a stance, not a missing value, and it is sourceable.** A
 * supplier publishing a catalogue without stock levels says something different
 * from one that says out of stock, and treating them alike would make every
 * catalogue-only supplier invisible.
 *
 * The cost is typed as a decimal string and converted by `Money::fromDecimal`,
 * which is string arithmetic: `(int) (19.99 * 100)` is 1998, so a supplier price
 * through a float is a penny short per unit, silently, forever.
 */
final class OffersRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'offers';

    protected static ?string $title = 'What they supply';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_ref')
                    ->label('Product')
                    ->searchable()
                    ->copyable()
                    ->tooltip('An opaque reference. This module never resolves it to a catalogue product, and never creates one from a supplier feed either — that belongs to Catalog Import and Export.'),
                TextColumn::make('supplier_sku')->label('Their SKU')->searchable()->copyable(),
                TextColumn::make('cost_minor')
                    ->label('Cost')
                    ->state(fn (SupplyOffer $record): string => Render::money($record->cost()))
                    ->sortable(),
                TextColumn::make('lead_time_days')
                    ->label('Lead time')
                    ->formatStateUsing(fn (int $state): string => $state.' days')
                    ->sortable()
                    ->tooltip('Routing ranks by this first, then by cost, then by supplier code. Cheapest-first is rejected: minor units in two currencies are not comparable without a rate this module does not have.'),
                TextColumn::make('availability')
                    ->label('Stance')
                    ->badge()
                    ->formatStateUsing(fn (Availability $state): string => Render::availability($state))
                    ->color(fn (Availability $state): string => Render::availabilityColour($state)),
                TextColumn::make('minimum_quantity')->label('Minimum'),
            ])
            ->filters([
                SelectFilter::make('availability')
                    ->label('Stance')
                    ->options(fn (): array => array_combine(
                        array_column(Availability::cases(), 'value'),
                        array_map(static fn (Availability $case): string => Render::availability($case), Availability::cases()),
                    )),
            ])
            ->defaultSort('lead_time_days')
            ->headerActions([$this->publish()])
            // An offer has no page of its own and this manager publishes no view
            // ability, so a clickable row would offer a route it cannot take.
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This supplier supplies nothing yet')
            ->emptyStateDescription('Until a supplier publishes an offer for a product, no line can be sourced from them and routing says so by name. The host had no offer table at all, which is why its checkout asked the browser instead.');
    }

    /**
     * Publish, or re-publish, one offer.
     *
     * Idempotent on `(tenant, supplier, product)`, because a supplier's feed is
     * republished constantly and re-publishing it must not accumulate rows. The
     * same button therefore corrects an offer as well as creating one, which is
     * why there is no edit action beside it.
     */
    private function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish an offer')
            ->icon('heroicon-o-plus-circle')
            ->modalHeading('Publish a supply offer')
            ->modalDescription('Says that this supplier supplies this product, at this cost, in this many days. Publishing the same product again corrects the offer rather than adding a second one.')
            ->schema([
                TextInput::make('product_ref')
                    ->label('Product reference')
                    ->required()
                    ->maxLength(128)
                    ->helperText('Your catalogue\'s own reference. Nothing here resolves it, and nothing here creates a product from it.'),
                TextInput::make('supplier_sku')
                    ->label('Their SKU')
                    ->required()
                    ->maxLength(128),
                TextInput::make('cost')
                    ->label('Unit cost')
                    ->required()
                    ->helperText(fn (): string => 'A decimal amount in '.$this->supplier()->currency
                        .'. It is read as a string, never as a float, and an amount with more decimal places than the currency has minor units is refused rather than rounded here.'),
                TextInput::make('lead_time_days')
                    ->label('Lead time, in days')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                Select::make('availability')
                    ->label('Stance')
                    ->required()
                    ->default(Availability::Unknown->value)
                    ->options(fn (): array => array_combine(
                        array_column(Availability::cases(), 'value'),
                        array_map(static fn (Availability $case): string => Render::availability($case), Availability::cases()),
                    ))
                    ->helperText('"Not stated" is sourceable. A supplier who publishes no stock levels is saying something different from one who says out of stock.'),
                TextInput::make('minimum_quantity')
                    ->label('Minimum order quantity')
                    ->required()
                    ->numeric()
                    ->default(1)
                    ->minValue(1),
            ])
            ->action(function (array $data): void {
                $supplier = $this->supplier();

                try {
                    $cost = Money::fromDecimal(
                        (string) $data['cost'],
                        $supplier->currency,
                        $supplier->currency_exponent,
                    );

                    $offer = App::make(PublishSupplyOffer::class)(
                        supplier: $supplier,
                        productRef: (string) $data['product_ref'],
                        supplierSku: (string) $data['supplier_sku'],
                        cost: $cost,
                        leadTimeDays: (int) $data['lead_time_days'],
                        availability: Availability::from((string) $data['availability']),
                        minimumQuantity: (int) $data['minimum_quantity'],
                    );
                } catch (DropshippingException|InvalidArgumentException $e) {
                    Notification::make()->title('Nothing was published')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Published')
                    ->body($offer->product_ref.' at '.Render::money($offer->cost()).', '.$offer->lead_time_days.' days.')
                    ->success()
                    ->send();
            });
    }

    private function supplier(): Supplier
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Supplier) {
            throw new InvalidArgumentException('This relation manager only hangs off a supplier.');
        }

        return $owner;
    }
}
