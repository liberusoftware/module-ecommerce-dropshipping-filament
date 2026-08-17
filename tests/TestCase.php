<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Liberu\Ecommerce\Dropshipping\DropshippingServiceProvider;
use Liberu\Ecommerce\Dropshipping\Filament\DropshippingFilamentServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Livewire\LivewireServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * The providers a panel needs, in an order that is not decorative.
     *
     * **Filament's providers register before Livewire's, and must.**
     * `filament/support` re-`bind()`s Livewire's `DataStore`, and a `bind()`
     * drops whatever instance was already registered under that key. Boot
     * Livewire first and every component lookup gets a fresh empty store, which
     * surfaces during render as
     * `ViewErrorBag::put(): $bag must be MessageBag, null given` — an error
     * about validation bags, thrown nowhere near the ordering that caused it.
     * Do not tidy this list into alphabetical order.
     *
     * The domain provider is named here rather than by putting the domain
     * package in `require-dev` as well as `require`. Both work, but a package
     * listed twice is what `composer validate` warns about, and `Install` runs
     * `composer validate`.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,

            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,

            LivewireServiceProvider::class,

            DropshippingServiceProvider::class,
            DropshippingFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /**
     * `app.debug` is true deliberately, in every case that renders a Livewire
     * component — which here is all of them.
     *
     * Livewire swallows a `TypeError` raised inside a component method whole:
     * the method writes nothing, throws nothing, sets no error state, and the
     * request comes back as a bare `419 Page Expired`. A Filament numeric input
     * handing a string to a domain action that takes an `int` is the common way
     * in, and so is a model boolean that is `null` rather than `false` because
     * nothing gave it an `$attributes` default. Without debug on, both read as a
     * session problem rather than as the signature mistake they are.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
