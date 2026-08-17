<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Dropshipping\Data\Money;
use Liberu\Ecommerce\Dropshipping\Enums\Availability;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ListSuppliers;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ViewSupplier;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\OffersRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Liberu\Ecommerce\Dropshipping\Models\SupplyOffer;
use Liberu\Ecommerce\Dropshipping\Queries\OutstandingPurchases;
use Liberu\Ecommerce\Dropshipping\Support\Journal;
use Livewire\Livewire;

/**
 * The table the host did not have, and the two writes that fill it.
 *
 * `config/dropshipping.php` held three literal keys with endpoints and API keys
 * from `env()`, shared by every merchant on the install. There was no table, no
 * model and no product-to-supplier link anywhere, which is why checkout ended up
 * reading `$request->input('supplier_id', 'dropxl')`.
 */
it('registers a supplier through the domain action rather than a Filament form', function (): void {
    Livewire::test(ListSuppliers::class)
        ->callAction('register', [
            'code' => 'acme',
            'name' => 'Acme Supply Co',
            'currency' => 'gbp',
            'currency_exponent' => 2,
            'timezone' => 'Pacific/Auckland',
            'default_lead_time_days' => 4,
            'credential_ref' => 'secret:acme',
            'is_active' => true,
        ])
        ->assertNotified('Registered');

    $supplier = Supplier::query()->firstOrFail();

    expect($supplier->code)->toBe('acme')
        ->and($supplier->currency)->toBe('GBP')
        ->and($supplier->timezone)->toBe('Pacific/Auckland')
        ->and($supplier->default_lead_time_days)->toBe(4)
        // A boolean that is `null` rather than `false` is invisible to a domain
        // suite and fatal to a panel handing it to a typed parameter.
        ->and($supplier->is_active)->toBeTrue();
});

it('registers the same code twice as one supplier, because the code is the key', function (): void {
    $page = Livewire::test(ListSuppliers::class);

    foreach (['Acme Supply Co', 'Acme Supply Limited'] as $name) {
        $page->callAction('register', [
            'code' => 'acme',
            'name' => $name,
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'timezone' => 'Europe/London',
            'default_lead_time_days' => 3,
            'credential_ref' => null,
            'is_active' => true,
        ]);
    }

    expect(Supplier::query()->count())->toBe(1)
        ->and(Supplier::query()->firstOrFail()->name)->toBe('Acme Supply Limited');
});

it('publishes an offer through the domain action, and re-publishing corrects it', function (): void {
    $supplier = supplier();

    $manager = Livewire::test(OffersRelationManager::class, [
        'ownerRecord' => $supplier,
        'pageClass' => ViewSupplier::class,
    ]);

    foreach (['10.00', '9.50'] as $cost) {
        $manager->callAction(TestAction::make('publish')->table(), [
            'product_ref' => 'product-1',
            'supplier_sku' => 'ACME-1',
            'cost' => $cost,
            'lead_time_days' => 2,
            'availability' => Availability::InStock->value,
            'minimum_quantity' => 1,
        ]);
    }

    // A supplier's feed is republished constantly and must not accumulate rows.
    expect(SupplyOffer::query()->count())->toBe(1)
        ->and(SupplyOffer::query()->firstOrFail()->cost()->decimal())->toBe('9.50');
});

it('refuses an offer priced in a currency the supplier does not trade in, and publishes nothing', function (): void {
    $supplier = supplier(currency: 'GBP');

    Livewire::test(OffersRelationManager::class, [
        'ownerRecord' => $supplier,
        'pageClass' => ViewSupplier::class,
    ])->callAction(TestAction::make('publish')->table(), [
        'product_ref' => 'product-1',
        'supplier_sku' => 'ACME-1',
        // Three decimal places in a two-minor-unit currency. Rounding it here
        // would be this panel choosing, silently, on a supplier's price.
        'cost' => '10.005',
        'lead_time_days' => 2,
        'availability' => Availability::InStock->value,
        'minimum_quantity' => 1,
    ])->assertNotified('Nothing was published');

    expect(SupplyOffer::query()->count())->toBe(0);
});

it('takes no API key, stores none and shows none', function (): void {
    $supplier = supplier(credentialRef: 'secret:acme');

    // The credential is a *name* the host's secret store resolves. The host's
    // API controller removed its key from a response by copying two other
    // fields out by hand, so a new config field leaked by default.
    expect($supplier->getAttributes())->not->toHaveKey('api_key')
        ->and($supplier->getAttributes())->not->toHaveKey('api_endpoint')
        ->and($supplier->credential_ref)->toBe('secret:acme');
});

/**
 * **A rate is a division and the division is taken here, out loud.**
 *
 * The domain publishes counts precisely so a caller has to decide what a
 * denominator of zero means. `0%` for a supplier who has despatched nothing is a
 * sentence a merchant terminates a contract over.
 */
it('renders an em dash rather than nought per cent for a supplier who has despatched nothing', function (): void {
    $supplier = supplier();
    offer($supplier);
    sentUnconfirmed(routedPurchase());
    SupplierResource::forgetPerformance();

    $share = Render::onTimeShare(SupplierResource::performance($supplier->fresh()));

    expect($share)->toStartWith(Render::NONE)
        ->and($share)->toContain('nothing despatched yet')
        ->and($share)->not->toContain('0%');
});

it('renders an em dash for despatches against a purchase that carried no promise', function (): void {
    $supplier = supplier();
    offer($supplier);
    $purchase = routedPurchase();

    // A transmission with no promised date. The host recorded no lead time
    // anywhere, so every purchase it ever made was this shape.
    Journal::append(
        purchase: $purchase,
        kind: EventKind::Transmitted,
        causeRef: (string) $purchase->reference,
        occurredAt: CarbonImmutable::parse('2026-03-01T09:00:00Z'),
    );

    applyReport(
        $purchase->fresh(['supplier', 'lines', 'events.shipmentLines']),
        despatch(['line-1' => 2], CarbonImmutable::parse('2026-03-02T09:00:00Z')),
    );
    SupplierResource::forgetPerformance();

    $performance = SupplierResource::performance($supplier->fresh());

    expect($performance->shipped)->toBe(1)
        ->and($performance->unmeasurable)->toBe(1)
        ->and($performance->onTime)->toBe(0)
        ->and($performance->late)->toBe(0)
        // Despatched, and unjudgeable. Counting it as a miss would punish a
        // supplier for a lead time nobody recorded.
        ->and(Render::onTimeShare($performance))->toContain('despatched against no promise');
});

it('divides only over despatches that had a promise to be judged against', function (): void {
    $supplier = supplier();
    offer($supplier);

    $onTime = sentUnconfirmed(routedPurchase(orderRef: 'order-1'), CarbonImmutable::parse('2026-03-01T09:00:00Z'), '2026-03-05');
    applyReport($onTime, despatch(['line-1' => 2], CarbonImmutable::parse('2026-03-03T09:00:00Z')));

    $late = sentUnconfirmed(routedPurchase(orderRef: 'order-2'), CarbonImmutable::parse('2026-03-01T09:00:00Z'), '2026-03-05');
    applyReport($late, despatch(['line-1' => 2], CarbonImmutable::parse('2026-03-09T09:00:00Z')));

    SupplierResource::forgetPerformance();

    expect(Render::onTimeShare(SupplierResource::performance($supplier->fresh())))->toBe('50% of 2 measurable');
});

it('shows what this supplier is owed, per currency', function (): void {
    $supplier = supplier();
    offer($supplier);
    routedPurchase();

    $rendered = Render::outstanding(
        (new OutstandingPurchases())((string) $supplier->tenant_id, (string) $supplier->reference),
    );

    expect($rendered)->toContain('20.00 GBP')
        ->and($rendered)->toContain('1 purchase');
});

it('renders a zero-cost purchase as a figure and a missing one as an em dash', function (): void {
    // `0.00 GBP` is a real amount and `—` is the absence of one. The two are
    // rendered by different branches and must not converge.
    expect(Render::money(new Money(0, 'GBP')))->toBe('0.00 GBP')
        ->and(Render::money(null))->toBe(Render::NONE);
});
