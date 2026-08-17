# Adopting the sourcing desk

What a host has to do, and what it must not expect this package to do for it.

## 1. Composer

Neither this package nor the domain package is on Packagist. **Composer honours
`repositories` only from the root manifest**, so the entry in this package's own
`composer.json` gets its CI resolving and does nothing at all for a consumer. The
host adds both:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-dropshipping" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-dropshipping-filament" }
]
```

```bash
composer require liberusoftware/ecommerce-dropshipping-filament
```

Installing boots nothing. Both packages ship no `extra.laravel.providers`, and
the host's `ModuleManagerServiceProvider` registers a module's provider only when
it is named in `MODULES_ENABLED`:

```dotenv
MODULES_ENABLED=ecommerce-dropshipping,ecommerce-dropshipping-filament
```

Enabling the surface without the domain gives you a panel over tables that do not
exist. Enable both.

## 2. Attaching the plugin

Per panel, by the host, in the panel provider:

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\Dropshipping\Filament\DropshippingPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(
            DropshippingPlugin::make()
                ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
        );
}
```

**Attach it to the merchant panel and not to a shopper-facing one.** Everything
here is an operator surface: who the merchant buys from, what it pays them, and
what its margin on a line is. A shopper is never told that a line was
drop-shipped, and there is nothing on any of these screens for one.

`tenantUsing()` is optional. Without it the panel's own Filament tenant is used.
A host whose tenancy is not Filament's says so here rather than leaving two
resources and a page to guess separately.

**There is no "no tenant" mode and there will not be one.**
`where('tenant_id', null)` compiles to `is null`, which lists exactly the orphan
rows a scope exists to hide, and every table in the domain carries a NOT NULL
`tenant_id`. The panel raises rather than resolving an empty merchant id.

## 3. What the host still has to do

**Route orders.** This package does not, and no screen in it assigns a supplier.
Routing is `Queries\SourcingPlanFor` and `Actions\RoutePurchases` in the domain
package, called from wherever the host decides an order's lines are sourced —
after payment, in a listener on the order's own event, or in a job. This panel
reads the result.

Do not carry over `CheckoutController`'s `$request->has('dropship')` or
`$request->input('supplier_id', 'dropxl')`. Both are the fault this module
exists to correct: routing is a decision over published offers, and there is no
default supplier.

**Transmit purchases.** Also not this package's. Transmission takes a
`Data\Destination` — the recipient's name, address and postcode — which this
module does not hold and this panel therefore cannot supply. The host calls
`Actions\TransmitPurchase`, handing it the address from whoever owns the
customer, from a queued job.

The job must **not** retry a purchase that is already `transmitting`. The domain
refuses it with `PurchaseNotTransmittable`, and the honest response to that
refusal is to reconcile, which is what the desk's button does. A host that
catches the refusal and re-sends anyway has rebuilt the fault.

**Erase people.** `Actions\RedactDestination` and
`Queries\ExportDestinationRecord` are not offered on any screen here, and should
not be. A person spans more modules than this one, and an erase button per module
is how somebody is erased from four of six. Call both from the host's
person-wide path, alongside every other module's.

## 4. What the host has to bind, and what happens if it does not

Both of the domain's seams are optional and **unbound by default**, and this
package binds neither. Each has a defined, honest answer when nothing is bound,
and this panel renders that answer rather than hiding it.

| Seam | Unbound | What the desk shows |
| --- | --- | --- |
| `Contracts\TransmitsPurchases` | A purchase cannot be sent and says so, with a `TransmissionRefused` event | Purchases stay `Routed, not sent`. Nothing here sends one anyway |
| `Contracts\ReportsShipments` | Reconciling reports `no_reporter_bound` | "Nobody was asked", said in those words, distinct from "cannot be asked" |

Bind them in a host service provider, or name a class in
`config/dropshipping.php`:

```php
'seams' => [
    'transmitter' => \App\Dropshipping\HttpTransmitter::class,
    'shipment_reporter' => \App\Dropshipping\HttpReporter::class,
],
```

Both are resolved at the moment of use, so a rebinding takes effect on the next
call rather than the next deploy.

**A reporter that returns `null` is not the same as one that is unbound**, and
the desk says so. `null` means *this provider cannot be asked* — a supplier who
only pushes callbacks and has no lookup by our own reference. Where that is true,
reconciliation is a person's job, and an implementation that returned an empty
`SupplierReport` instead would tell an operator "nothing has happened yet" about
a question nobody asked.

## 5. Credentials

**No screen here takes an API key, and no field on any of them holds one.** A
supplier names a `credential_ref`, and the host's secret store resolves it when a
seam implementation needs it. The value never enters the domain package.

The host's `config/dropshipping.php` held one key per supplier per install,
shared by every merchant, on endpoints guarded by
`hasRole(['super_admin','admin'])` — platform-wide, not per-merchant. Delete that
file when this module is enabled; it is not adopted and nothing here reads it.

## 6. What the host deletes, and one thing it must map first

The domain package's `docs/adoption.md` carries the full list. The one that is
not obvious, restated here because a panel operator will be asked about it:

**Removing `Order::STATUS_SUPPLIER_QUEUED` and `STATUS_SUPPLIER_FAILED` from the
Orders state machine changes VAT reporting.** Both appear in
`OssReportService::REPORTABLE_STATUSES` and in `EcSalesListService` as statuses
that are billed. Map them to their replacements before dropping them, or a
quarter's OSS return silently changes shape.

## 7. Navigation

Three entries in a `Sourcing` group: **Suppliers**, **Purchases** and **Needs
reconciliation**. A host that wants them elsewhere overrides
`$navigationGroup` and `$navigationSort` in the usual Filament way; nothing here
depends on the grouping.
