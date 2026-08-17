<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Dropshipping\Actions\PublishSupplyOffer;
use Liberu\Ecommerce\Dropshipping\Actions\RecordSupplierReport;
use Liberu\Ecommerce\Dropshipping\Actions\RegisterSupplier;
use Liberu\Ecommerce\Dropshipping\Actions\RoutePurchases;
use Liberu\Ecommerce\Dropshipping\Data\Money;
use Liberu\Ecommerce\Dropshipping\Data\RequestedLine;
use Liberu\Ecommerce\Dropshipping\Data\ShipmentReport;
use Liberu\Ecommerce\Dropshipping\Data\SupplierReport;
use Liberu\Ecommerce\Dropshipping\Enums\Availability;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Filament\Tests\TestCase;
use Liberu\Ecommerce\Dropshipping\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;
use Liberu\Ecommerce\Dropshipping\Models\Supplier;
use Liberu\Ecommerce\Dropshipping\Models\SupplyOffer;
use Liberu\Ecommerce\Dropshipping\Support\Journal;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();

        // Both seams unbound by default, exactly as the domain ships them. A
        // test that inherited a binding from the test before it would be
        // proving the opposite of what it says.
        Config::set('dropshipping.seams.transmitter', null);
        Config::set('dropshipping.seams.shipment_reporter', null);

        // Neither memo survives a test, for the same reason neither survives a
        // page load: a state carried past the events that produced it is a
        // state somebody reads after it moved.
        SupplierResource::forgetPerformance();
        PurchaseResource::forgetView();
    })
    ->in(__DIR__.'/Feature');

/** A supplier, through the published action, which is idempotent on the code. */
function supplier(
    string $tenantId = TestTenant::PRIMARY,
    string $code = 'acme',
    string $name = 'Acme Supply Co',
    string $currency = 'GBP',
    string $timezone = 'Europe/London',
    int $leadTimeDays = 3,
    int $exponent = 2,
    ?string $credentialRef = 'secret:acme',
    bool $isActive = true,
): Supplier {
    return app(RegisterSupplier::class)(
        tenantId: $tenantId,
        code: $code,
        name: $name,
        currency: $currency,
        timezone: $timezone,
        defaultLeadTimeDays: $leadTimeDays,
        currencyExponent: $exponent,
        credentialRef: $credentialRef,
        isActive: $isActive,
    );
}

/** An offer, through the published action, which is idempotent on the product. */
function offer(
    Supplier $supplier,
    string $productRef = 'product-1',
    string $sku = 'ACME-1',
    string $cost = '10.00',
    int $leadTimeDays = 3,
    Availability $availability = Availability::InStock,
    int $minimumQuantity = 1,
): SupplyOffer {
    return app(PublishSupplyOffer::class)(
        supplier: $supplier,
        productRef: $productRef,
        supplierSku: $sku,
        cost: Money::fromDecimal($cost, $supplier->currency, $supplier->currency_exponent),
        leadTimeDays: $leadTimeDays,
        availability: $availability,
        minimumQuantity: $minimumQuantity,
    );
}

/**
 * A purchase, by routing — which is the only way one is made.
 *
 * There is no fixture that names a supplier, because there is no call anywhere
 * that takes one: routing decides over the offers, and the whole module exists
 * because the host asked the browser instead.
 *
 * @param  list<array{0: string, 1: string, 2: int}>  $lines
 */
function routeOrder(
    string $tenantId = TestTenant::PRIMARY,
    string $orderRef = 'order-1',
    string $destinationRef = 'destination-1',
    array $lines = [['line-1', 'product-1', 2]],
): array {
    return app(RoutePurchases::class)(
        $tenantId,
        $orderRef,
        $destinationRef,
        array_map(static fn (array $line): RequestedLine => new RequestedLine($line[0], $line[1], $line[2]), $lines),
    );
}

/** One routed purchase, for the common case of one supplier and one line. */
function routedPurchase(
    string $tenantId = TestTenant::PRIMARY,
    string $orderRef = 'order-1',
    string $destinationRef = 'destination-1',
): Purchase {
    return routeOrder($tenantId, $orderRef, $destinationRef)[0];
}

/**
 * A purchase that has been sent and not confirmed.
 *
 * Written as the journal event rather than by calling `TransmitPurchase`,
 * because transmitting needs a destination — the recipient's name and address —
 * and this package never holds one. The state a timeout leaves behind is exactly
 * this row and nothing else.
 */
function sentUnconfirmed(Purchase $purchase, ?CarbonImmutable $at = null, ?string $promisedBy = null): Purchase
{
    $sentAt = $at ?? CarbonImmutable::now();

    Journal::append(
        purchase: $purchase,
        kind: EventKind::Transmitted,
        causeRef: (string) $purchase->reference,
        occurredAt: $sentAt,
        promisedBy: $promisedBy ?? $purchase->supplier->promiseDate($sentAt, $purchase->expected_lead_time_days),
    );

    return $purchase->fresh(['supplier', 'lines', 'events.shipmentLines']);
}

/** The supplier's answer, applied. */
function applyReport(Purchase $purchase, SupplierReport $report, ?CarbonImmutable $at = null): Purchase
{
    app(RecordSupplierReport::class)($purchase, $report, $at);

    return $purchase->fresh(['supplier', 'lines', 'events.shipmentLines']);
}

/** An acknowledgement, optionally with a cost the supplier states and a revised promise. */
function acknowledgement(
    string $ref = 'ack-1',
    ?CarbonImmutable $at = null,
    ?Money $actualCost = null,
    ?string $promisedBy = null,
): SupplierReport {
    return new SupplierReport(
        providerReference: 'their-'.$ref,
        acknowledgementRef: $ref,
        acknowledgedAt: $at ?? CarbonImmutable::now(),
        actualCost: $actualCost,
        promisedBy: $promisedBy,
    );
}

/**
 * A despatch covering part or all of a purchase.
 *
 * @param  array<string, int>  $lines
 */
function despatch(array $lines, ?CarbonImmutable $at = null, string $causeRef = 'despatch-1'): SupplierReport
{
    return new SupplierReport(shipments: [new ShipmentReport(
        causeRef: $causeRef,
        shippedAt: $at ?? CarbonImmutable::now(),
        carrierCode: 'dpd',
        trackingRef: 'TRK-1',
        lines: $lines,
    )]);
}
