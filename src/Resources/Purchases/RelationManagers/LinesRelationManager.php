<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Models\PurchaseLine;

/**
 * The lines of the purchase, and how much of each has actually gone.
 *
 * The despatched figure comes from the fold rather than from a column: a
 * supplier despatches part of a purchase, from more than one warehouse, on more
 * than one day, so what has gone is the sum of the shipment lines across every
 * despatch event. The host had one `supplier_tracking_number` column on the
 * order, which no code ever wrote and no code ever read.
 *
 * A quantity below what was ordered is why the purchase says `Part despatched`
 * and why it has no despatch date: the promise is not met until the last line
 * has gone.
 */
final class LinesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('line_ref')
                    ->label('Line')
                    ->copyable()
                    ->tooltip('The host\'s own order-line reference, opaque here. It is what a despatch names when it covers part of a purchase.'),
                TextColumn::make('product_ref')->label('Product')->copyable(),
                TextColumn::make('supplier_sku')->label('Their SKU')->copyable(),
                TextColumn::make('quantity')->label('Ordered'),
                TextColumn::make('despatched')
                    ->label('Despatched')
                    ->state(fn (PurchaseLine $record): string => $this->despatched($record))
                    ->tooltip('Summed over every despatch the supplier has reported for this line.'),
                TextColumn::make('unit_cost_minor')
                    ->label('Unit cost')
                    ->state(fn (PurchaseLine $record): string => Render::money($record->unitCost())),
                TextColumn::make('line_cost')
                    ->label('Line cost')
                    ->state(fn (PurchaseLine $record): string => Render::money($record->unitCost()->times($record->quantity))),
            ])
            ->paginated(false)
            ->filters([])
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This purchase has no lines')
            ->emptyStateDescription('A purchase with no lines cannot be complete and cannot be despatched, which is what the fold says about it.');
    }

    private function despatched(PurchaseLine $record): string
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Purchase) {
            return Render::NONE;
        }

        return (string) (PurchaseResource::view($owner)->shippedQuantities[$record->line_ref] ?? 0);
    }
}
