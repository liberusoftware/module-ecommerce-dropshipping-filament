<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Liberu\Ecommerce\Dropshipping\Filament\Pages\Reconciliation;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ListPurchases;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages\ViewPurchase;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\EventsRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ListSuppliers;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages\ViewSupplier;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\OffersRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\PurchasesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Dropshipping\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Livewire\Livewire;

/**
 * Two merchants holding **deliberately identical** references.
 *
 * The same supplier code, the same product reference, the same order reference
 * and the same destination reference on both sides — because two merchants both
 * buying from `acme` and both calling an order `order-1` is the ordinary case,
 * and a proof that only creates one merchant's rows proves nothing about a
 * `where` clause that was never written.
 *
 * The host had no tenancy in this path at all: one supplier list and one API key
 * per supplier, shared by every merchant on the install, guarded by
 * `hasRole(['super_admin','admin'])` — platform-wide, not per-merchant.
 *
 * **Every relation on every screen is exercised, not only every list.** Three
 * waves running, tenancy leaked through relations rather than queries, because a
 * package whose every query states its tenant never exercises its own relations.
 */
function twoMerchants(): array
{
    $ours = supplier(tenantId: TestTenant::PRIMARY, code: 'acme');
    $theirs = supplier(tenantId: TestTenant::OTHER, code: 'acme');

    // Two offers here and three there, so a count that is right is
    // distinguishable from a count that is zero *and* from one that is five.
    offer($ours, 'product-1', 'OURS-1');
    offer($ours, 'product-2', 'OURS-2');
    offer($theirs, 'product-1', 'THEIRS-1');
    offer($theirs, 'product-2', 'THEIRS-2');
    offer($theirs, 'product-3', 'THEIRS-3');

    $mine = routedPurchase(TestTenant::PRIMARY, 'order-1', 'destination-1');
    $yours = routedPurchase(TestTenant::OTHER, 'order-1', 'destination-1');

    sentUnconfirmed($mine);
    sentUnconfirmed($yours);

    return ['ours' => $ours, 'theirs' => $theirs, 'mine' => $mine->fresh(), 'yours' => $yours->fresh()];
}

it('lists only this merchant’s suppliers and purchases', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListSuppliers::class)
        ->assertCanSeeTableRecords(Supplier::query()->whereKey($rows['ours']->getKey())->get())
        ->assertCanNotSeeTableRecords(Supplier::query()->whereKey($rows['theirs']->getKey())->get());

    Livewire::test(ListPurchases::class)
        ->assertCanSeeTableRecords(Purchase::query()->whereKey($rows['mine']->getKey())->get())
        ->assertCanNotSeeTableRecords(Purchase::query()->whereKey($rows['yours']->getKey())->get());
});

it('counts only this merchant’s offers, through the relation rather than through a query', function (): void {
    $rows = twoMerchants();

    $counted = SupplierResource::getEloquentQuery()->firstOrFail();

    // **Not zero.** An unguarded restatement builds the relation from a fresh
    // instance whose `tenant_id` is null, becomes `where('tenant_id', '')`, and
    // reports zero for everybody — which looks exactly like isolation working.
    // And not five, which is the other merchant's rows counted as ours.
    expect((int) $counted->id)->toBe((int) $rows['ours']->id)
        ->and($counted->offers_count)->toBe(2);
});

it('shows only this supplier’s offers and purchases through the supplier’s own relations', function (): void {
    $rows = twoMerchants();

    $offers = Livewire::test(OffersRelationManager::class, [
        'ownerRecord' => $rows['ours'],
        'pageClass' => ViewSupplier::class,
    ])->instance()->getTable()->getRecords();

    $purchases = Livewire::test(PurchasesRelationManager::class, [
        'ownerRecord' => $rows['ours'],
        'pageClass' => ViewSupplier::class,
    ])->instance()->getTable()->getRecords();

    expect($offers)->toHaveCount(2)
        ->and($offers->pluck('supplier_sku')->sort()->values()->all())->toBe(['OURS-1', 'OURS-2'])
        ->and($purchases)->toHaveCount(1)
        ->and($purchases->first()->reference)->toBe($rows['mine']->reference);
});

it('shows only this purchase’s lines and events', function (): void {
    $rows = twoMerchants();

    $lines = Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $rows['mine'],
        'pageClass' => ViewPurchase::class,
    ])->instance()->getTable()->getRecords();

    $events = Livewire::test(EventsRelationManager::class, [
        'ownerRecord' => $rows['mine'],
        'pageClass' => ViewPurchase::class,
    ])->instance()->getTable()->getRecords();

    // One line and one transmission, and neither merchant's rows in the other's
    // tables, although both used the order reference `order-1`, the destination
    // reference `destination-1` and the supplier code `acme`.
    expect($lines)->toHaveCount(1)
        ->and($lines->first()->line_ref)->toBe('line-1')
        ->and($events)->toHaveCount(1);
});

it('answers another merchant’s reference exactly as it answers nobody’s', function (): void {
    $rows = twoMerchants();

    // Not a 403 and not a different message: the panel is not a directory of the
    // deployment, so "belongs to somebody else" and "does not exist" are one
    // answer. A refusal that differs publishes the existence of the row.
    $openPurchase = fn (string $reference): mixed => Livewire::test(ViewPurchase::class, ['record' => $reference]);
    $openSupplier = fn (string $reference): mixed => Livewire::test(ViewSupplier::class, ['record' => $reference]);

    expect(fn (): mixed => $openPurchase($rows['yours']->reference))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $openPurchase('dpu_nothingatall'))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $openSupplier($rows['theirs']->reference))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $openSupplier('dsp_nothingatall'))->toThrow(ModelNotFoundException::class);
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide. There is no fallback here and the
    // schema has no nullable tenant to fall back to.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => TestTenant::current());
});

it('queues only this merchant’s unconfirmed purchases for reconciliation', function (): void {
    $rows = twoMerchants();

    $queued = Livewire::test(Reconciliation::class)->instance()->getTable()->getRecords();

    expect($queued)->toHaveCount(1)
        ->and($queued->first()['purchase'])->toBe($rows['mine']->reference);
});

it('shows the other merchant’s rows when the panel is the other merchant', function (): void {
    $rows = twoMerchants();

    // The mirror image, which is what separates "scoped" from "always empty".
    TestTenant::use(TestTenant::OTHER);
    PurchaseResource::forgetView();
    SupplierResource::forgetPerformance();

    Livewire::test(ListPurchases::class)
        ->assertCanSeeTableRecords(Purchase::query()->whereKey($rows['yours']->getKey())->get())
        ->assertCanNotSeeTableRecords(Purchase::query()->whereKey($rows['mine']->getKey())->get());

    expect(SupplierResource::getEloquentQuery()->firstOrFail()->offers_count)->toBe(3);
});
