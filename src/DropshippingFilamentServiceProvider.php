<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * The module's provider. It registers no resource, no page and no widget.
 *
 * A panel is composed by the host. Registering the sourcing desk from a provider
 * would put it into every panel the application has, including a shopper-facing
 * one — where a screen naming the merchant's suppliers and what it pays them is
 * the whole of its margin, published.
 *
 * The one thing it registers is a view namespace, because the reconciliation
 * queue is a custom page and a custom page needs a view to render its table
 * into. A view namespace is not a surface: nothing is reachable through it until
 * the plugin puts the page on a panel.
 */
class DropshippingFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-dropshipping-filament');
    }
}
