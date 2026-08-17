<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Tests;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Dropshipping\Filament\DropshippingPlugin;

/**
 * A merchant panel with this module's plugin attached, and nothing else.
 *
 * This is the whole of what a host has to write, which is the point of shipping
 * a plugin rather than registering resources from a service provider: the host
 * decides which panels the sourcing desk appears on, and a shopper-facing panel
 * simply does not attach it.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(
                DropshippingPlugin::make()
                    ->tenantUsing(fn (): string => TestTenant::current()),
            );
    }
}
