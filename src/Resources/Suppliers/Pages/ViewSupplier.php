<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\Pages;

use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;

/**
 * One supplier's record, what they supply, and what has been sent to them.
 *
 * No header actions. Deactivating a supplier is a `RegisterSupplier` call with
 * the same code, which the register action on the list already is, and a second
 * button that did the same write differently is a second thing to keep in step.
 */
final class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    public function mount(int|string $record): void
    {
        SupplierResource::forgetPerformance();

        parent::mount($record);
    }
}
