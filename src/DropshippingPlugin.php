<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Dropshipping\Filament\Pages\Reconciliation;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Filament\Support\PanelTenant;

/**
 * The sourcing desk, as the host attaches it.
 *
 * Everything in this package is an operator surface. A shopper never sees which
 * supplier a line was sourced from, what the merchant pays for it, or that it
 * was drop-shipped at all — that is the merchant's margin and the merchant's
 * supplier relationship, and the host's checkout put the second of them in a
 * form field the browser could set.
 *
 *     use Filament\Facades\Filament;
 *     use Liberu\Ecommerce\Dropshipping\Filament\DropshippingPlugin;
 *
 *     $panel->plugin(
 *         DropshippingPlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
 *     );
 */
final class DropshippingPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-dropshipping';
    }

    /**
     * How this panel names the merchant whose desk it is.
     *
     * Optional: without it the panel's own Filament tenant is used. A host whose
     * tenancy is not Filament's says so here rather than leaving two resources
     * and a page to guess separately.
     */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            SupplierResource::class,
            PurchaseResource::class,
        ]);

        $panel->pages([
            Reconciliation::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
