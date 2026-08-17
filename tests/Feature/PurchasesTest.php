<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Dropshipping\Data\Money;
use Liberu\Ecommerce\Dropshipping\Data\ProblemReport;
use Liberu\Ecommerce\Dropshipping\Data\ReconcileOutcome;
use Liberu\Ecommerce\Dropshipping\Data\SupplierReport;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ListPurchases;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ViewPurchase;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Filament\Tests\Fakes\FakeReporter;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->supplier = supplier();
    offer($this->supplier);
});

/**
 * **The three buttons that are not here.**
 *
 * There is no transmit, no re-transmit and no retry anywhere in this package.
 * The host's queue job guarded a retry on `orders.supplier_order_reference` — a
 * value the *supplier* mints, which only exists once the response has arrived —
 * so a timeout after the supplier accepted left the column empty, the
 * at-least-once queue retried, and real goods shipped twice at the merchant's
 * cost.
 */
it('offers no send, no re-send and no retry, in any state', function (): void {
    $purchase = routedPurchase();

    $draft = Livewire::test(ViewPurchase::class, ['record' => $purchase->reference]);
    $draft->assertActionDoesNotExist('transmit')
        ->assertActionDoesNotExist('send')
        ->assertActionDoesNotExist('retry')
        ->assertActionDoesNotExist('resend');

    $sent = sentUnconfirmed($purchase);
    PurchaseResource::forgetView();

    $unconfirmed = Livewire::test(ViewPurchase::class, ['record' => $sent->reference]);
    $unconfirmed->assertActionDoesNotExist('transmit')
        ->assertActionDoesNotExist('retry')
        ->assertActionDoesNotExist('resend')
        // The one thing offered instead.
        ->assertActionVisible('reconcile');
});

it('offers reconcile on a purchase that was sent and never confirmed, and cancel on nothing that was', function (): void {
    $purchase = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();

    // Not cancellable: we do not know whether the supplier has it. Reconcile
    // first — the domain refuses the cancel anyway, and this only hides it.
    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->assertActionVisible('reconcile')
        ->assertActionHidden('cancel');
});

it('offers cancel on a draft and reconcile on nothing that has not been sent', function (): void {
    $purchase = routedPurchase();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->assertActionVisible('cancel')
        // Nothing has been sent to anybody, so there is nobody to ask.
        ->assertActionHidden('reconcile');
});

it('cancels a draft through the domain action and says the supplier was not told', function (): void {
    $purchase = routedPurchase();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->callAction('cancel', ['reason' => 'customer_cancelled'])
        ->assertNotified('Cancelled');

    PurchaseResource::forgetView();

    expect(PurchaseResource::view($purchase->fresh())->state)->toBe(PurchaseState::Cancelled);
});

/**
 * **Four outcomes, and two of them look identical and mean opposite things.**
 *
 * "Nobody is bound" is an install that is not finished. "The provider cannot be
 * asked" is a supplier who only pushes callbacks, where reconciling is a person
 * picking up a telephone. Collapsing them is how a merchant waits forever for an
 * answer that was never going to arrive.
 */
it('reports that nobody was asked when no shipment reporter is bound', function (): void {
    $purchase = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->callAction('reconcile')
        ->assertNotified('Not reconciled');

    PurchaseResource::forgetView();

    // Nothing was attempted and nothing was assumed: the state is untouched.
    expect(PurchaseResource::view($purchase->fresh())->state)->toBe(PurchaseState::Transmitting);
});

it('reports that the provider cannot be asked when the seam answers null', function (): void {
    Config::set('dropshipping.seams.shipment_reporter', FakeReporter::cannotBeAsked());

    $purchase = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->callAction('reconcile')
        ->assertNotified('Not reconciled');

    PurchaseResource::forgetView();

    expect(PurchaseResource::view($purchase->fresh())->state)->toBe(PurchaseState::Transmitting);
});

it('reports that the supplier was asked and nothing has happened when the report is empty', function (): void {
    // An empty report is a real answer, deliberately not the same value as
    // `null`: it means the supplier was asked and nothing has happened yet.
    Config::set('dropshipping.seams.shipment_reporter', FakeReporter::answering(new SupplierReport()));

    $purchase = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->callAction('reconcile')
        ->assertNotified('Asked, and nothing has happened');

    PurchaseResource::forgetView();

    expect(PurchaseResource::view($purchase->fresh())->state)->toBe(PurchaseState::Transmitting);
});

it('reconciles when the supplier answers, and never sends the purchase again', function (): void {
    Config::set('dropshipping.seams.shipment_reporter', FakeReporter::answering(acknowledgement()));

    $purchase = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->callAction('reconcile')
        ->assertNotified('Reconciled');

    PurchaseResource::forgetView();
    $fresh = $purchase->fresh(['supplier', 'lines', 'events.shipmentLines']);

    expect(PurchaseResource::view($fresh)->state)->toBe(PurchaseState::Acknowledged)
        // One transmission event, ever. Reconciling is a question, not a resend.
        ->and($fresh->events->where('kind', EventKind::Transmitted)->count())->toBe(1);
});

it('renders the four reconcile outcomes as four different sentences', function (): void {
    $outcomes = [
        Render::reconcile(
            new ReconcileOutcome('dpu_1', false, PurchaseState::Transmitting, 'no_reporter_bound'),
            PurchaseState::Transmitting,
        ),
        Render::reconcile(
            new ReconcileOutcome('dpu_1', false, PurchaseState::Transmitting, 'provider_cannot_be_asked'),
            PurchaseState::Transmitting,
        ),
        Render::reconcile(
            new ReconcileOutcome('dpu_1', true, PurchaseState::Transmitting),
            PurchaseState::Transmitting,
        ),
        Render::reconcile(
            new ReconcileOutcome('dpu_1', true, PurchaseState::Acknowledged),
            PurchaseState::Transmitting,
        ),
    ];

    expect(array_unique($outcomes))->toHaveCount(4)
        ->and($outcomes[0])->toContain('Nobody was asked')
        ->and($outcomes[1])->toContain('cannot be asked')
        ->and($outcomes[2])->toContain('nothing has happened yet')
        ->and($outcomes[3])->toContain('The supplier answered');
});

/**
 * **`null` is not `false`.** A promise nobody made and a promise a supplier
 * missed are different facts, and rendering the first as "Late" accuses a
 * supplier of missing something that was never said.
 */
it('renders no promise, an unmet promise and a met promise as three different things', function (): void {
    expect(Render::onTime(null))->toBe(Render::NONE)
        ->and(Render::onTime(false))->toBe('Late')
        ->and(Render::onTime(true))->toBe('On time')
        ->and(Render::onTimeColour(null))->not->toBe(Render::onTimeColour(false));
});

it('shows an em dash rather than a verdict while only part of a purchase has gone', function (): void {
    $purchase = routeOrder(lines: [['line-1', 'product-1', 4]])[0];
    $sent = sentUnconfirmed($purchase, CarbonImmutable::parse('2026-03-01T09:00:00Z'), '2026-03-04');

    // Two of four. A supplier has not met a promise until all of it has gone,
    // so there is no despatch date and nothing to judge.
    $partly = applyReport($sent, despatch(['line-1' => 2], CarbonImmutable::parse('2026-03-02T09:00:00Z')));
    PurchaseResource::forgetView();

    $view = PurchaseResource::view($partly);

    expect($view->state)->toBe(PurchaseState::PartiallyShipped)
        ->and($view->despatchedOn)->toBeNull()
        ->and($view->onTime)->toBeNull()
        ->and(Render::onTime($view->onTime))->toBe(Render::NONE)
        ->and(Render::quantities($view->orderedQuantities, $view->shippedQuantities))->toBe('line-1: 2 of 4');
});

it('says late only when a promise was made and missed', function (): void {
    $purchase = sentUnconfirmed(routedPurchase(), CarbonImmutable::parse('2026-03-01T09:00:00Z'), '2026-03-04');
    $shipped = applyReport($purchase, despatch(['line-1' => 2], CarbonImmutable::parse('2026-03-09T09:00:00Z')));
    PurchaseResource::forgetView();

    $view = PurchaseResource::view($shipped);

    expect($view->onTime)->toBeFalse()
        ->and(Render::onTime($view->onTime))->toBe('Late')
        ->and($view->despatchedOn)->toBe('2026-03-09');
});

/**
 * **A variance across currencies is absent, not zero — and it is a different
 * absence from a supplier who has not stated a cost.**
 *
 * The domain returns `null` for both, which is right for arithmetic and wrong
 * for an operator: the first resolves itself and the second is a supplier
 * invoicing in a currency the offer was never priced in.
 */
it('renders a missing cost and an unconvertible cost as two different absences', function (): void {
    $unstated = sentUnconfirmed(routedPurchase());
    PurchaseResource::forgetView();
    $unstatedView = PurchaseResource::view($unstated);

    $crossCurrency = applyReport(
        sentUnconfirmed(routedPurchase(orderRef: 'order-2')),
        acknowledgement(ref: 'ack-2', actualCost: new Money(2400, 'USD', 2)),
    );
    PurchaseResource::forgetView();
    $crossView = PurchaseResource::view($crossCurrency);

    expect($unstatedView->costVariance())->toBeNull()
        ->and($crossView->costVariance())->toBeNull()
        // Both null in the domain; two different sentences here.
        ->and(Render::variance($unstatedView))->toContain('has not stated a cost')
        ->and(Render::variance($crossView))->toContain('inventing a rate')
        ->and(Render::variance($unstatedView))->not->toBe(Render::variance($crossView));
});

it('renders a real variance signed and in the expected currency', function (): void {
    $purchase = applyReport(
        sentUnconfirmed(routedPurchase()),
        acknowledgement(actualCost: new Money(2400, 'GBP', 2)),
    );
    PurchaseResource::forgetView();

    // Ordered two at 10.00, expected 20.00, charged 24.00.
    expect(Render::variance(PurchaseResource::view($purchase)))->toBe('+4.00 GBP');
});

it('renders outstanding value per currency and never sums across them', function (): void {
    $euro = supplier(code: 'euro-supply', currency: 'EUR');
    offer($euro, 'product-2', 'EURO-1', '5.00');

    routedPurchase();
    routeOrder(orderRef: 'order-2', lines: [['line-1', 'product-2', 3]]);

    $subheading = Livewire::test(ListPurchases::class)->instance()->getSubheading();

    expect($subheading)->toContain('20.00 GBP')
        ->and($subheading)->toContain('15.00 EUR')
        // Never one figure. 20.00 and 15.00 are two debts, not 35.
        ->and($subheading)->not->toContain('35.00');
});

it('shows the state as a fold and the problems in the supplier’s own codes', function (): void {
    $purchase = applyReport(sentUnconfirmed(routedPurchase()), new SupplierReport(problems: [
        new ProblemReport('problem-1', CarbonImmutable::parse('2026-03-02T09:00:00Z'), EventKind::ExceptionRaised, 'address_invalid', 'destination.postcode'),
    ]));
    PurchaseResource::forgetView();

    $view = PurchaseResource::view($purchase);
    $rendered = Render::problems($view->problems);

    expect($view->problems)->toHaveCount(1)
        ->and($rendered)->toContain('address_invalid')
        ->and($rendered)->toContain('destination.postcode')
        // An exception is not terminal; the purchase is still unconfirmed.
        ->and($view->state)->toBe(PurchaseState::Transmitting);
});
