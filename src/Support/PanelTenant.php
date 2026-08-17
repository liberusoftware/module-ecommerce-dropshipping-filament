<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Support;

use Closure;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * Where the panel gets its merchant id from.
 *
 * Every query and every action in the domain package takes the tenant as an
 * argument and never reads one from ambient state, so the argument has to come
 * from somewhere at the edge. This is that somewhere: one place, stated out
 * loud, rather than `Filament::getTenant()` sprinkled through two resources and
 * a page where one of them can quietly forget.
 *
 * **There is no fallback to "no tenant".** `where('tenant_id', null)` compiles
 * to `is null`, which lists exactly the orphan rows a scope was meant to hide,
 * and every table in the domain carries a NOT NULL `tenant_id` so no
 * cross-merchant supply can be represented at all. The host had no tenancy in
 * this path whatsoever: one supplier list and one API key per supplier, shared
 * by every merchant on the install, so an admin of merchant A could ship goods
 * billed to the install's account and nothing was recorded.
 */
final class PanelTenant
{
    /**
     * ponytail: one process-global resolver, because one deployment has one way
     * of naming its merchants. If a host ever needs a different rule per panel,
     * move this onto the plugin and read it from the current panel.
     */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function current(): string
    {
        $tenant = self::$resolver !== null
            ? (self::$resolver)()
            : Filament::getTenant()?->getKey();

        if (! is_string($tenant) && ! is_int($tenant)) {
            throw new RuntimeException(
                'Dropshipping could not resolve a tenant for this panel. '
                .'Set one with DropshippingPlugin::make()->tenantUsing(...) in the panel provider.'
            );
        }

        $tenant = (string) $tenant;

        if ($tenant === '') {
            throw new RuntimeException('Dropshipping resolved an empty tenant id, which would match orphan rows.');
        }

        return $tenant;
    }
}
