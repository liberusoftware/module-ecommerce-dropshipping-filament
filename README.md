# ecommerce-dropshipping-filament

[![Tests](https://github.com/liberusoftware/module-ecommerce-dropshipping-filament/actions/workflows/tests.yml/badge.svg)](https://github.com/liberusoftware/module-ecommerce-dropshipping-filament/actions/workflows/tests.yml)

The sourcing desk: a Filament 5 panel over
[`liberusoftware/ecommerce-dropshipping`](https://github.com/liberusoftware/module-ecommerce-dropshipping).

The suppliers a merchant sources from, what each of them supplies at what cost
and in how many days, every purchase transmitted to them replayed from its own
events, both costs and the difference between them, the promise made about timing
and whether it was met, and the purchases nobody can currently account for.

## What it is not

**No screen here assigns a supplier to an order.** Routing is a decision over
published supply offers — lead time first, then cost within a currency, then
supplier code — and it is never an input. The host's checkout read
`$request->has('dropship')` and `$request->input('supplier_id', 'dropxl')`: a
hidden form field decided an order was drop-shipped and another named the
supplier who would be paid. It had no choice, because there was no supplier
table, no supplier model and no product-to-supplier link anywhere in the tree.
The suppliers screen is where the data that decision is now made from lives.

**Nothing here sends a purchase, and nothing here sends one twice.** There is no
transmit, re-transmit or retry action in this package, in any state. Sending
takes the recipient's address, which this module deliberately does not hold; and
re-sending is the fault — the host's queue guarded a retry on a reference the
*supplier* minted, so a timeout after the supplier accepted shipped real goods
twice at the merchant's cost. A purchase that was sent and never confirmed is
offered **Reconcile**, which asks the supplier what they have.

**Nothing absent is rendered as a number.** A promise nobody made and a promise a
supplier missed are different facts and read differently. A cost variance across
two currencies is absent rather than converted. An on-time share with nothing
measurable to divide is an em dash, never `0%` — the domain publishes counts
precisely so somebody has to decide what a denominator of zero means, and this
panel decides out loud. Outstanding value is one figure per currency and is never
summed.

**No destination, no address, no name, no email.** This module stores one opaque
reference and none of the rest; this panel does not even show the reference.

## Screens

| Screen | What it is for |
| --- | --- |
| **Suppliers** | The table the host did not have: the code that is the natural key, the currency, the timezone a promise is counted in, the lead time, and how many products each publishes an offer for |
| **Supplier** | Its performance as counts with one division taken out loud, what it is owed per currency, what it supplies, and every purchase sent to it. Registers suppliers and publishes offers |
| **Purchases** | Reference, supplier, order, the state folded from the events, both dates and the verdict. Outstanding value on the face of it |
| **Purchase** | Both costs and the difference, the promise and its outcome, ordered against despatched per line, the problems raised, and the append-only journal. Reconciles and cancels |
| **Needs reconciliation** | Every purchase sent and never confirmed, and every open purchase carrying a problem. No repair button and no figure |

## Installing

The domain package is not on Packagist, so the host adds the repository entry
itself:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-dropshipping" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-dropshipping-filament" }
]
```

```bash
composer require liberusoftware/ecommerce-dropshipping-filament
```

Installing boots nothing. The host enables both modules and attaches the plugin
to the panels it means to:

```dotenv
MODULES_ENABLED=ecommerce-dropshipping,ecommerce-dropshipping-filament
```

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\Dropshipping\Filament\DropshippingPlugin;

$panel->plugin(
    DropshippingPlugin::make()
        ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
);
```

[`docs/adoption.md`](docs/adoption.md) covers what the host still has to do —
routing, transmission and erasure are all its, and none of them is here.

## Documentation

| | |
| --- | --- |
| [`docs/panel.md`](docs/panel.md) | Every screen, what it renders and why it renders it that way |
| [`docs/adoption.md`](docs/adoption.md) | What a host does, what it binds, and what it must not expect this package to do |
| [`docs/runbook.md`](docs/runbook.md) | What breaks, what the symptom looks like, what to do about it |

## Requirements

PHP 8.5, Laravel 13, Filament 5.4 or later, and
`liberusoftware/ecommerce-dropshipping` `^0.1`.

## License

MIT. See [LICENSE.md](LICENSE.md).
