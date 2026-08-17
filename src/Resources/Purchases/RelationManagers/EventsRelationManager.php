<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\PurchaseEvent;

/**
 * Everything that has happened to this purchase, in the order it happened.
 *
 * **Append-only, and nothing on this screen can edit or remove a row.** The
 * domain refuses an update and a delete in model hooks and asserts that no
 * writer takes the query-builder path around them; every ability this manager
 * would otherwise inherit is forced closed by name. A correction is a new event,
 * because these rows are the only account of things that happened at a third
 * party.
 *
 * **Two times, and they are not the same time.** `Happened` is when it happened
 * at the supplier; `Recorded` is when we heard. The fold is ordered by the
 * first, so a callback arriving late cannot overwrite a state that happened
 * after it — the defect a mutable status column has and this module does not.
 *
 * `Because of` is the supplier's own reference for the thing that happened, and
 * it is the idempotency key: a provider retrying a callback four times records
 * once, and a reconcile racing that callback records once between them.
 */
final class EventsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'events';

    protected static ?string $title = 'What has happened';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('What happened')
                    ->badge()
                    ->formatStateUsing(fn (EventKind $state): string => Render::eventLabel($state))
                    ->color(fn (EventKind $state): string => Render::eventColour($state)),
                TextColumn::make('occurred_at')->label('Happened')->dateTime(),
                TextColumn::make('recorded_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('When we heard. The replay is ordered by when it happened, so a late callback cannot overwrite a later state.'),
                TextColumn::make('cause_ref')
                    ->label('Because of')
                    ->copyable()
                    ->tooltip('The supplier\'s own reference for the thing that happened. It is the idempotency key: the same cause and kind records once.'),
                TextColumn::make('actual_cost_minor')
                    ->label('Cost stated')
                    ->state(fn (PurchaseEvent $record): string => Render::money($record->actualCost())),
                TextColumn::make('promised_by')->label('Promised by')->placeholder(Render::NONE),
                TextColumn::make('carrier_code')->label('Carrier')->placeholder(Render::NONE),
                TextColumn::make('tracking_ref')
                    ->label('Tracking')
                    ->copyable()
                    ->placeholder(Render::NONE)
                    ->tooltip('A fact the supplier reported and we republish. The carrier relationship belongs to Carrier Operations, and the shipment to the customer to Fulfillment.'),
                TextColumn::make('detail_code')
                    ->label('Detail')
                    ->placeholder(Render::NONE)
                    ->state(fn (PurchaseEvent $record): string => $record->detail_code === null
                        ? Render::NONE
                        : $record->detail_code.($record->detail_field === null ? '' : ' on '.$record->detail_field))
                    ->tooltip('A supplier code and the *name* of the field it objected to, never the value. Free provider prose is how a recipient\'s name reaches storage.'),
                TextColumn::make('provider_reference')->label('Their reference')->copyable()->placeholder(Render::NONE)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference')->label('Event')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            // Already in the fold's order, which lives on the relation so no
            // caller can read it out of sequence.
            ->paginated(false)
            ->filters([])
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has happened to this purchase yet')
            ->emptyStateDescription('It has been routed and written, and not sent. Sending needs the recipient\'s address, which this module does not hold and this panel therefore cannot supply.');
    }
}
