<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\Dropshipping\Data\ProblemReport;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Support\Fold;
use UnitEnum;

/**
 * The purchases nobody can currently account for.
 *
 * **This is the screen that earns the panel**, and it exists because of the one
 * state the host had no way to express. Its queue job guarded a retry on
 * `orders.supplier_order_reference`, a value the *supplier* mints, which only
 * exists once the response has arrived — so a timeout after the supplier
 * accepted left the column empty, the at-least-once queue retried, and real
 * goods shipped twice at the merchant's cost. Here the transmission event is
 * written and committed **before** the outbound call, so a timeout, a crash or a
 * worker killed mid-call all leave the purchase `Sent, unconfirmed`, which means
 * *we asked and do not know*.
 *
 * That state has to land somewhere a person looks, or the rule degrades into a
 * queue of purchases nobody ever asks about again.
 *
 * Two things are listed:
 *
 * - **sent and unconfirmed** — reconcile it, which asks the supplier what they
 *   have. There is no button here or anywhere in this package that sends it
 *   again;
 * - **open and carrying a problem** — a supplier raised something and the
 *   purchase is still live.
 *
 * **There is nothing on this page that repairs anything**, and no figure. The
 * fix is on the purchase's own page and it is a question put to the supplier.
 */
final class Reconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Needs reconciliation';

    protected static UnitEnum|string|null $navigationGroup = 'Sourcing';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Purchases nobody can account for';

    protected static ?string $slug = 'dropshipping/reconciliation';

    protected string $view = 'ecommerce-dropshipping-filament::pages.reconciliation';

    public function getSubheading(): string
    {
        return 'A purchase is sent once, and the transmission is recorded before the call rather than after it — so a timeout leaves "we asked and do not know" rather than a row that looks untouched. '
            .'Open one and reconcile it, which asks the supplier what they have. Nothing anywhere in this panel sends a purchase again.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->outstanding())
            ->columns([
                TextColumn::make('purchase')
                    ->label('Purchase')
                    ->copyable()
                    ->tooltip('Search this reference on the purchases screen to reconcile it.'),
                TextColumn::make('supplier')->label('Supplier'),
                TextColumn::make('order_ref')->label('Order')->copyable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->color(fn (string $state): string => $state === Render::state(PurchaseState::Transmitting) ? 'warning' : 'danger'),
                TextColumn::make('reason')->label('What is wrong')->wrap(),
                TextColumn::make('routed_at')->label('Routed'),
            ])
            ->paginated(false)
            // Nothing is searchable, filterable or sortable. This is a queue that
            // should be empty, in the order the purchases were routed, and a
            // filter on it is a way to stop looking at one.
            ->filters([])
            // Array records, so the row's URL and action are unwired: both are
            // typed against a Model.
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Every purchase in this merchant is accounted for')
            ->emptyStateDescription('Nothing is sitting unconfirmed, and no open purchase is carrying a problem a supplier raised.');
    }

    /**
     * Every purchase in this merchant that a person has to do something about.
     *
     * ponytail: one fold per purchase, on page load, unpaginated. "Which
     * purchases are unconfirmed" is not expressible in SQL, because there is no
     * status column and the answer is a replay over the events in order.
     * `cursor()` keeps it off the heap; if a merchant ever has enough purchases
     * for this to be slow, the queue belongs behind a scheduled job writing its
     * findings down, not behind a cleverer query.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outstanding(): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach (Purchase::query()
            ->where('tenant_id', PanelTenant::current())
            ->with(['supplier', 'lines', 'events.shipmentLines'])
            ->orderBy('id')
            ->cursor() as $purchase) {
            $view = Fold::of($purchase);
            $reason = self::reason($view->state, $view->problems);

            if ($reason === null) {
                continue;
            }

            $rows[] = [
                'purchase' => $view->purchaseReference,
                'supplier' => (string) $purchase->supplier->code,
                'order_ref' => $view->orderRef,
                'state' => Render::state($view->state),
                'reason' => $reason,
                'routed_at' => $purchase->routed_at->toDateTimeString(),
            ];
        }

        return collect($rows);
    }

    /**
     * Why this purchase is in the queue, or null if it is not.
     *
     * @param  list<ProblemReport>  $problems
     */
    private static function reason(PurchaseState $state, array $problems): ?string
    {
        if ($state === PurchaseState::Transmitting) {
            return 'Sent, and the supplier has not confirmed. Reconcile it — ask them what they have — rather than sending it again.';
        }

        if ($problems !== [] && $state->isOutstanding()) {
            return 'Open, and the supplier has raised: '.Render::problems($problems);
        }

        return null;
    }
}
