<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Dropshipping\Actions\CancelPurchase;
use Liberu\Ecommerce\Dropshipping\Actions\PublishSupplyOffer;
use Liberu\Ecommerce\Dropshipping\Actions\ReconcilePurchase;
use Liberu\Ecommerce\Dropshipping\Actions\RegisterSupplier;
use Liberu\Ecommerce\Dropshipping\Data\ProblemReport;
use Liberu\Ecommerce\Dropshipping\Data\SupplierReport;
use Liberu\Ecommerce\Dropshipping\Enums\Availability;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;
use Liberu\Ecommerce\Dropshipping\Exceptions\InvalidSupplyOffer;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ViewPurchase;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ListSuppliers;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ViewSupplier;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\OffersRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->supplier = supplier();
    offer($this->supplier);
});

/**
 * Every screen renders, in every state, and every enum has a sentence.
 *
 * A `match` missing an arm is a fatal at render rather than a failing assertion,
 * and a state nobody opened a page in is a state nobody knows renders.
 */
it('renders a supplier’s page, with its performance and what it is owed', function (): void {
    routedPurchase();

    Livewire::test(ViewSupplier::class, ['record' => $this->supplier->reference])
        ->assertOk()
        ->assertSee('Acme Supply Co')
        ->assertSee('20.00 GBP')
        // Nothing despatched, so no share is invented.
        ->assertSee(Render::NONE);
});

/** @return array<string, array{0: Closure}> */
dataset('states', [
    'draft' => [fn (): Purchase => routedPurchase(), PurchaseState::Draft],
    'sent, unconfirmed' => [fn (): Purchase => sentUnconfirmed(routedPurchase()), PurchaseState::Transmitting],
    'acknowledged' => [
        fn (): Purchase => applyReport(sentUnconfirmed(routedPurchase()), acknowledgement()),
        PurchaseState::Acknowledged,
    ],
    'part despatched' => [
        fn (): Purchase => applyReport(
            sentUnconfirmed(routeOrder(lines: [['line-1', 'product-1', 4]])[0]),
            despatch(['line-1' => 2]),
        ),
        PurchaseState::PartiallyShipped,
    ],
    'despatched' => [
        fn (): Purchase => applyReport(sentUnconfirmed(routedPurchase()), despatch(['line-1' => 2])),
        PurchaseState::Shipped,
    ],
    'refused' => [
        fn (): Purchase => applyReport(sentUnconfirmed(routedPurchase()), new SupplierReport(problems: [
            new ProblemReport('reject-1', CarbonImmutable::now(), EventKind::Rejected, 'out_of_stock'),
        ])),
        PurchaseState::Rejected,
    ],
    'cancelled' => [
        function (): Purchase {
            $purchase = routedPurchase();
            app(CancelPurchase::class)($purchase, 'customer_cancelled');

            return $purchase->fresh(['supplier', 'lines', 'events.shipmentLines']);
        },
        PurchaseState::Cancelled,
    ],
]);

it('renders a purchase’s page in every state a purchase can be in', function (Closure $make, PurchaseState $expected): void {
    $purchase = $make();
    PurchaseResource::forgetView();

    expect(PurchaseResource::view($purchase)->state)->toBe($expected);

    Livewire::test(ViewPurchase::class, ['record' => $purchase->reference])
        ->assertOk()
        ->assertSee(Render::state($expected));
})->with('states');

it('gives every purchase state a distinct sentence and a colour', function (): void {
    $labels = array_map(static fn (PurchaseState $state): string => Render::state($state), PurchaseState::cases());
    $colours = array_map(static fn (PurchaseState $state): string => Render::stateColour($state), PurchaseState::cases());

    expect(array_unique($labels))->toHaveCount(count(PurchaseState::cases()))
        ->and(array_filter($colours))->toHaveCount(count(PurchaseState::cases()));
});

it('gives every event kind and every availability stance a sentence and a colour', function (): void {
    $kinds = array_map(static fn (EventKind $kind): string => Render::eventLabel($kind), EventKind::cases());
    $kindColours = array_map(static fn (EventKind $kind): string => Render::eventColour($kind), EventKind::cases());
    $stances = array_map(static fn (Availability $a): string => Render::availability($a), Availability::cases());
    $stanceColours = array_map(static fn (Availability $a): string => Render::availabilityColour($a), Availability::cases());

    expect(array_unique($kinds))->toHaveCount(count(EventKind::cases()))
        ->and(array_filter($kindColours))->toHaveCount(count(EventKind::cases()))
        ->and(array_unique($stances))->toHaveCount(count(Availability::cases()))
        ->and(array_filter($stanceColours))->toHaveCount(count(Availability::cases()))
        // A stance nobody stated is not a missing value, and it does not read
        // like one.
        ->and(Render::availability(Availability::Unknown))->toBe('Not stated');
});

it('says nothing is outstanding rather than showing a zero, and dashes a purchase with no lines', function (): void {
    expect(Render::outstanding([]))->toBe('Nothing outstanding')
        ->and(Render::quantities([], []))->toBe(Render::NONE);
});

/**
 * **A domain refusal is rendered, not a five hundred.**
 *
 * Each write here resolves its action out of the container, so a stub that
 * refuses is exactly what the domain refusing looks like from this side.
 */
it('renders a refusal from the domain rather than failing, on every write it offers', function (): void {
    $refuse = fn (): object => new class()
    {
        public function __invoke(mixed ...$arguments): never
        {
            throw InvalidSupplyOffer::because('the domain said no.');
        }
    };

    app()->bind(RegisterSupplier::class, $refuse);
    app()->bind(PublishSupplyOffer::class, $refuse);
    app()->bind(ReconcilePurchase::class, $refuse);
    app()->bind(CancelPurchase::class, $refuse);

    Livewire::test(ListSuppliers::class)
        ->callAction('register', [
            'code' => 'other',
            'name' => 'Other',
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'timezone' => 'Europe/London',
            'default_lead_time_days' => 1,
            'credential_ref' => null,
            'is_active' => true,
        ])
        ->assertNotified('Nothing was written');

    Livewire::test(OffersRelationManager::class, [
        'ownerRecord' => $this->supplier,
        'pageClass' => ViewSupplier::class,
    ])->callAction(TestAction::make('publish')->table(), [
        'product_ref' => 'product-9',
        'supplier_sku' => 'ACME-9',
        'cost' => '1.00',
        'lead_time_days' => 1,
        'availability' => Availability::InStock->value,
        'minimum_quantity' => 1,
    ])->assertNotified('Nothing was published');

    $draft = routedPurchase();

    Livewire::test(ViewPurchase::class, ['record' => $draft->reference])
        ->callAction('cancel', ['reason' => 'duplicate'])
        ->assertNotified('Nothing was cancelled');

    $unconfirmed = sentUnconfirmed(routedPurchase(orderRef: 'order-2'));
    PurchaseResource::forgetView();

    Livewire::test(ViewPurchase::class, ['record' => $unconfirmed->reference])
        ->callAction('reconcile')
        ->assertNotified('Nothing was reconciled');
});
