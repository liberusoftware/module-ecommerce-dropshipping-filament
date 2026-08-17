<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Dropshipping\Filament\Support\Render;
use Liberu\Ecommerce\Dropshipping\Queries\OutstandingPurchases;

/**
 * Every purchase, and what the merchant currently owes for the open ones.
 *
 * **No header actions.** A purchase appears because an order was routed over
 * supply offers — a decision this module takes and never accepts as an input —
 * so there is nothing on this screen that creates one. The host's equivalent
 * decision was `$request->has('dropship')` on a checkout form.
 *
 * The outstanding figure is a fold, per currency, never summed. A single number
 * for a merchant sourcing in GBP and CNY would be this panel inventing a rate on
 * the line an accountant reads.
 */
final class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    public function mount(): void
    {
        PurchaseResource::forgetView();

        parent::mount();
    }

    public function getSubheading(): string
    {
        return 'Outstanding: '.Render::outstanding(
            (new OutstandingPurchases())(PanelTenant::current()),
        ).'. What has been committed to and not yet closed, from the expectation rather than the acknowledgement — most outstanding purchases have not been acknowledged yet.';
    }
}
