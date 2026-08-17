<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Support\Fold;

/**
 * What has been sent to this supplier.
 *
 * The state column is a **fold over the purchase's events**, not a column: there
 * is no status on the row and there deliberately is not one, because a late
 * callback overwriting a status is how a despatched purchase goes back to
 * "acknowledged". So the state is not sortable and not filterable here — a
 * sortable state is a promise that a column exists to sort by.
 *
 * The eager load is not an optimisation. `Fold::of()` walks `lines` and
 * `events.shipmentLines`, and without it a page of twenty rows is sixty queries.
 */
final class PurchasesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'purchases';

    protected static ?string $title = 'Purchases sent to them';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'lines', 'events.shipmentLines']))
            ->columns([
                TextColumn::make('reference')->label('Purchase')->searchable()->copyable(),
                TextColumn::make('order_ref')
                    ->label('Order')
                    ->searchable()
                    ->copyable()
                    ->tooltip('The host\'s own order reference, opaque here. It is half the natural key — the other half is this supplier, so two suppliers can each hold a purchase for one order.'),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Purchase $record): PurchaseState => Fold::of($record)->state)
                    ->formatStateUsing(fn (PurchaseState $state): string => Render::state($state))
                    ->color(fn (PurchaseState $state): string => Render::stateColour($state)),
                TextColumn::make('expected_cost_minor')
                    ->label('Expected')
                    ->state(fn (Purchase $record): string => Render::money($record->expectedCost())),
                TextColumn::make('routed_at')->label('Routed')->dateTime()->sortable(),
            ])
            ->defaultSort('routed_at', 'desc')
            ->filters([])
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been sent to this supplier')
            ->emptyStateDescription('A purchase appears when an order is routed here, which is a decision over this supplier\'s offers and never an input. There is no screen anywhere in this panel that assigns a supplier to an order by hand.');
    }
}
