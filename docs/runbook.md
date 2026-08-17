# Runbook

What breaks, what the symptom looks like, and what to do about it.

---

## The panel raises `Dropshipping could not resolve a tenant for this panel`

**Symptom.** Every screen in the group raises, immediately, on a panel that
otherwise works.

**Cause.** The plugin was attached to a panel with no Filament tenancy and no
`tenantUsing()` resolver, or the resolver returned an empty string.

**What to do.** Give it one:

```php
DropshippingPlugin::make()->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey())
```

**What not to do.** Do not make the resolver fall back to a constant, to the
first team in the table, or to `null`. `where('tenant_id', null)` compiles to
`is null` and lists exactly the orphan rows the scope exists to hide. Raising is
the correct behaviour, and this is the one place it is enforced.

## A supplier's offer count is zero and the offers are visibly there

**Symptom.** The **Offers** column on the suppliers list reads `0` for everybody,
while opening a supplier shows its offers.

**Cause.** The guarded tenant restatement in the domain's `RestatesTenant` has
been made unconditional. `withCount()` builds the relation from a fresh instance
whose `tenant_id` is `null`, so `where('tenant_id', (string) null)` becomes
`where('tenant_id', '')` and matches nothing.

**What to do.** Report it against the domain package. `CustodyTest` here asserts
the count is right and **not zero** precisely because this defect looks exactly
like isolation working — wave 16 shipped it.

## Reconciling always says "Nobody was asked"

**Symptom.** Every purchase reconciles to *no shipment reporter is bound*.

**Cause.** The install has no `Contracts\ReportsShipments` binding. That is the
default and it is not an error.

**What to do.** Bind one (`docs/adoption.md` §4), or reconcile with the supplier
by hand. Nothing is broken and nothing was assumed — the message says so on
purpose, because the alternative is a panel that pretends a supplier answered.

## Reconciling always says "The supplier was reached and cannot be asked"

**Symptom.** A reporter *is* bound, and every reconcile still resolves nothing.

**Cause.** The implementation is returning `null`, which means *this provider
cannot be asked* — it has no lookup by our own reference.

**What to do.** If the provider genuinely has no such call, this is correct and
reconciliation for that supplier is a human action. If it does have one, the
implementation is wrong: an implementation that cannot reach the provider must
throw, and one that reached it and found nothing must return an **empty**
`SupplierReport`, which the desk reports as "asked, and nothing has happened".
The three are deliberately different and collapsing them closes different
decisions.

## A purchase has sat at `Sent, unconfirmed` for days

**Symptom.** It is on **Needs reconciliation** and reconciling changes nothing.

**Cause.** Either the supplier never received it — a crash or a timeout before
the outbound call completed — or they received it and have not acknowledged.
From inside this process the two are indistinguishable, which is why the
transmission event is written and committed *before* the call.

**What to do.** Ask the supplier. If they never received it, cancel the purchase
and route the order again; the natural key is
`(tenant_id, supplier_id, order_ref)`, so re-routing to the *same* supplier
returns the same purchase rather than a second one.

**What not to do.** Do not send it again. There is no button here that does, and
adding one is how the same goods ship twice at the merchant's cost — which is
exactly what the host's at-least-once queue did.

## The despatch date is blank on a purchase that has clearly shipped

**Symptom.** State reads `Part despatched`, `Despatched on` reads
`— not all of it has gone`, and `Met the promise` reads `—`.

**Cause.** Not every line has been despatched in full. The **Lines** table shows
which.

**What to do.** Nothing, if the supplier is still shipping. If the supplier
considers the purchase complete and the quantities disagree, their despatch
report is short — check the shipment lines on the event and take it up with them.
A purchase is not despatched until the last line has gone, because a promise is
not met until then.

## `Met the promise` reads `—` on a purchase that was clearly late

**Symptom.** The purchase is fully despatched and the verdict is still an em
dash.

**Cause.** No promise was made. `promised_by` is opened by the transmission event
from the supplier's lead time; if the purchase was transmitted by a path that did
not set one, there is nothing to judge against.

**What to do.** Check that the supplier has a `default_lead_time_days` and that
its offers carry lead times. An em dash here is correct and deliberate:
"unjudgeable" is not "late", and a supplier is not marked down for a lead time
nobody recorded.

## The cost difference reads `— charged in USD, expected in GBP`

**Symptom.** Both costs are present and no variance is shown.

**Cause.** The supplier acknowledged in a currency the offer was not priced in.
The domain refuses to convert rather than inventing a rate, and so does this.

**What to do.** This is a real problem, not a rendering one — either the
supplier's currency on their record is wrong, or they have invoiced in the wrong
one. Correct the supplier's currency and re-publish their offers; existing
purchases keep the expectation they were routed with, which is the point of
keeping both.

## The on-time share reads `— nothing despatched yet` on a busy supplier

**Symptom.** Purchases exist, some are acknowledged, the share is an em dash.

**Cause.** None of them is despatched *in full*. Only complete despatches are
measurable.

**What to do.** Nothing. `0%` would be wrong and worse.

## `A notification was not sent` / a bare `419 Page Expired` after an action

**Symptom.** A modal submits, nothing happens, and the request comes back 419.

**Cause.** Livewire swallows a `TypeError` raised inside a component method
whole. A Filament numeric input hands back a string and a domain action takes an
`int`; a model boolean that is `null` rather than `false` does the same.

**What to do.** Turn `app.debug` on and repeat it — the suite here runs with
debug on for exactly this reason. Then cast on arrival at the action call, which
is what every write in this package does.

## Every screen is empty on a merchant that has data

**Symptom.** Lists render, tenancy resolves, nothing appears.

**Cause.** The panel's tenant key is not the string the domain's `tenant_id`
holds — a `Team` id resolved as an int against rows written with a UUID, or the
reverse.

**What to do.** Compare `PanelTenant::current()` with a `tenant_id` from the
table. The resolver casts to string but does not translate; whatever wrote the
rows and whatever the panel resolves have to be the same identifier.
