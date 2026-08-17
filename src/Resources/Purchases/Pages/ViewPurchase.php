<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Dropshipping\Actions\CancelPurchase;
use Liberu\Ecommerce\Dropshipping\Actions\ReconcilePurchase;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Exceptions\DropshippingException;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;

/**
 * One purchase, and the two things a desk may do to it.
 *
 * **Neither of them sends anything.** There is no transmit button and no retry
 * button on this page or anywhere in this package, and their absence is the
 * whole correction to the host:
 *
 * - Transmitting needs the recipient's name and address, which this module does
 *   not store and this panel therefore cannot supply. Whatever a "send" button
 *   here filled that argument with would be invented.
 * - Re-sending is how the host shipped goods twice. Its queue job guarded on
 *   `orders.supplier_order_reference` — a value the *supplier* mints, which only
 *   exists once the response has arrived — so a timeout after the supplier
 *   accepted left the column empty, the at-least-once queue retried, and real
 *   goods went out again at the merchant's cost. The guard was right about the
 *   danger and could not deliver the remedy, because the key it tested was
 *   minted by the other party.
 *
 * The remedy is **Reconcile**: ask the supplier what they have, rather than
 * asking again for the goods.
 */
final class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    /** The reasons a desk cancels. A closed list, so nobody types a person into it. */
    public const REASONS = [
        'customer_cancelled' => 'The customer cancelled the order',
        'payment_failed' => 'Payment did not complete',
        'resourcing' => 'Being sourced from a different supplier',
        'duplicate' => 'A duplicate of another purchase',
        'merchant_decision' => 'The merchant decided not to place it',
    ];

    public function mount(int|string $record): void
    {
        PurchaseResource::forgetView();

        parent::mount($record);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->reconcile(), $this->cancel()];
    }

    /**
     * Ask the supplier what happened. **Never send it again.**
     *
     * Four outcomes, and an operator must be able to tell them apart, because
     * two of them look identical from a distance and mean opposite things:
     *
     * 1. no shipment reporter is bound — the install is not finished, nothing
     *    was attempted, and nothing was assumed;
     * 2. the provider cannot be asked — this supplier only pushes callbacks and
     *    has no lookup by our own reference, so reconciling is a person's job;
     * 3. the supplier was asked and nothing has happened yet — a real answer,
     *    and not a failure;
     * 4. the supplier answered and the state moved.
     *
     * Offered from `Sent, unconfirmed`, `Accepted` and `Part despatched`. Not
     * from a draft, which has not been sent to anybody, and not from a closed
     * purchase, where there is nothing left to learn.
     */
    private function reconcile(): Action
    {
        return Action::make('reconcile')
            ->label('Reconcile with the supplier')
            ->icon('heroicon-o-question-mark-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Ask the supplier what they have')
            ->modalDescription(
                'Asks the supplier what has happened to this purchase under our own reference. It does not send the purchase again, and there is no button anywhere here that does — '
                .'re-sending after a timeout is how the same goods ship twice at the merchant\'s cost.'
            )
            ->modalSubmitActionLabel('Ask')
            ->visible(fn (Purchase $record): bool => in_array(
                PurchaseResource::view($record)->state,
                [PurchaseState::Transmitting, PurchaseState::Acknowledged, PurchaseState::PartiallyShipped],
                true,
            ))
            ->action(function (Purchase $record): void {
                $before = PurchaseResource::view($record)->state;

                try {
                    $outcome = App::make(ReconcilePurchase::class)($record);
                } catch (DropshippingException $e) {
                    Notification::make()->title('Nothing was reconciled')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                PurchaseResource::forgetView();

                $moved = $outcome->resolved && $outcome->state !== $before;

                Notification::make()
                    ->title(match (true) {
                        ! $outcome->resolved => 'Not reconciled',
                        $moved => 'Reconciled',
                        default => 'Asked, and nothing has happened',
                    })
                    ->body(Render::reconcile($outcome, $before))
                    ->color($outcome->resolved ? ($moved ? 'success' : 'info') : 'warning')
                    ->persistent()
                    ->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Stop a purchase the merchant no longer wants.
     *
     * Cancellable from a draft and from an acknowledgement, and from nothing
     * else — the domain refuses the rest and this only hides the button. A
     * `Sent, unconfirmed` purchase is not cancellable because we do not know
     * whether the supplier has it: reconcile first. A despatched one is not
     * cancelled, it is returned, and returns are not this module's.
     *
     * **Cancelling does not tell the supplier.** That is a transmission, and
     * 0.1.0 ships no seam for it; the domain's changelog records that rather
     * than shipping a branch nothing exercises, and this modal says so rather
     * than letting an operator believe a message was sent.
     */
    private function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel this purchase')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Cancel this purchase')
            ->modalDescription(
                'Records a cancellation on the purchase. It does not tell the supplier — this module has no seam that sends one, so if the purchase has been acknowledged you have to tell them yourself. '
                .'Nothing is deleted: the purchase and every event on it stay exactly as they are.'
            )
            ->visible(fn (Purchase $record): bool => in_array(
                PurchaseResource::view($record)->state,
                [PurchaseState::Draft, PurchaseState::Acknowledged],
                true,
            ))
            ->schema([
                Select::make('reason')
                    ->label('Why')
                    ->required()
                    ->options(self::REASONS)
                    // A closed list rather than free text. `detail_code` is a
                    // supplier-grade code, and a box an operator types prose into
                    // is how a recipient's name reaches storage in a module that
                    // holds none.
                    ->helperText('A fixed list. There is no free-text box here, because free text is how a name or an address reaches a module that stores neither.'),
            ])
            ->action(function (Purchase $record, array $data): void {
                try {
                    $view = App::make(CancelPurchase::class)($record, (string) $data['reason']);
                } catch (DropshippingException $e) {
                    Notification::make()->title('Nothing was cancelled')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                PurchaseResource::forgetView();

                Notification::make()
                    ->title('Cancelled')
                    ->body('Now '.Render::state($view->state).'. The supplier has not been told by this module.')
                    ->success()
                    ->persistent()
                    ->send();

                $this->refreshFormData([]);
            });
    }
}
