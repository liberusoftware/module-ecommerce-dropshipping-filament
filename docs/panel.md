# The panel

Every screen, what it renders, and why it renders it that way. Written for
somebody deciding whether a rendering decision here is a bug.

---

## 1. The three rules the whole panel is built around

**Routing is a decision, never an input.** No screen here has a field that names
a supplier. Which supplier a line is sourced from is decided over their published
offers — lead time first, then cost within a currency, then supplier code so the
answer is stable — by `Queries\SourcingPlanFor` and `Actions\RoutePurchases`, on
whatever path the host routes an order on. The host's checkout read
`$request->has('dropship')` and `$request->input('supplier_id', 'dropxl')`: a
hidden form field decided an order was drop-shipped and another named the
supplier who would be paid. It had no choice, because there was no supplier
table, no supplier model and no product-to-supplier link anywhere in the tree.
The **Suppliers** screen is where the data that decision is now made from lives.

**Nothing here sends a purchase, and nothing here sends one twice.** There is no
transmit, re-transmit or retry action in this package, in any state, on any
screen. Two separate reasons, and either alone would be enough:

- Transmitting takes a `Data\Destination` — the recipient's name, address and
  postcode — which this module deliberately does not hold. Anything this panel
  put in that argument would be invented.
- Re-sending is the fault. The host's queue job guarded a retry on
  `orders.supplier_order_reference`, a value the *supplier* mints, which only
  exists once the response has arrived. A timeout after the supplier accepted
  left the column empty, the at-least-once queue retried, and real goods shipped
  twice at the merchant's cost.

The remedy offered instead is **Reconcile**: ask the supplier what they have.

**`null` is rendered as an em dash, never as `false`, `0` or a percentage.** Four
places in this panel where that matters, and each one is a different figure the
domain declined to invent:

| The absence | Rendered |
| --- | --- |
| `onTime` is `null` — no promise, or not all of it has gone | `—`, never "Late" |
| `despatchedOn` is `null` — the purchase is only part despatched | `— not all of it has gone` |
| `costVariance()` is `null` | two different sentences, see §5 |
| `SupplierPerformance` has nothing measurable to divide | `—`, never `0%` |

## 2. Suppliers

The table the host did not have. `config/dropshipping.php` held three literal
keys — `supplier1`, `supplier2`, `dropxl` — each with an endpoint and an API key
from `env()`, shared by every merchant on the install.

**No API key is entered, stored or displayed.** A supplier names a
`credential_ref` that the host's secret store resolves; the value never enters
the domain package, so it cannot enter this one. The host's API controller
removed its key from a response by copying two *other* fields out by hand, which
means a new config field leaked by default.

**Registering goes through `RegisterSupplier` rather than a Filament create
page**, and the action is idempotent on `(tenant_id, code)` — the merchant's own
code for the supplier, a natural key, because the relationship exists before this
module does. So the same button corrects a supplier as well as creating one, and
there is no edit page beside it to keep in step. Filament's own create and edit
machinery is closed by name in `Concerns\DeniesUnpublishedResourceAbilities`,
because it would write the row itself and skip the key.

The offer count is a `withCount` over a relation that restates its parent's
tenant, and its test asserts the count is **right and not zero**. That is not
paranoia: an unguarded restatement builds the relation from a fresh instance
whose `tenant_id` is `null`, becomes `where('tenant_id', '')`, and reports zero
for everybody — which looks exactly like isolation working. Wave 16 shipped that
defect.

## 3. One supplier

**Performance is counts, and one division taken out loud.** The domain publishes
`SupplierPerformance` with seven integers and no rate, precisely so a caller has
to decide what a denominator of zero means. This panel decides:

- the denominator is `onTime + late`, **not** everything despatched. A despatch
  against a purchase that carried no promise cannot be judged, and counting it as
  a miss would punish a supplier for a lead time nobody recorded — which is every
  purchase the host ever made, because it recorded no lead time anywhere;
- a denominator of zero is an em dash and a reason, never `0%`. "0% on time" is a
  sentence a merchant terminates a contract over.

**What the supplier is owed is one figure per currency**, never summed. A
merchant sourcing in GBP and CNY has two debts, and one number for them would be
this panel inventing an exchange rate on the line an accountant reads.

**What they supply** is the relation that makes routing possible: the cost, the
lead time routing ranks by, the availability stance it filters on, and the
minimum quantity it refuses below. `Not stated` is a **stance, not a missing
value, and it is sourceable** — a supplier publishing a catalogue without stock
levels says something different from one that says out of stock, and treating
them alike would make every catalogue-only supplier invisible.

Publishing an offer goes through `PublishSupplyOffer`, which is idempotent on
`(tenant, supplier, product)` because a supplier's feed is republished
constantly. The cost is typed as a **decimal string** and converted by
`Money::fromDecimal`, which is string arithmetic: `(int) (19.99 * 100)` is 1998,
so a supplier price through a float is a penny short per unit, silently, for
ever. An amount with more decimal places than the currency has minor units is
refused rather than rounded here.

## 4. Purchases

**The state column is a fold, so it is neither sortable nor filterable.** There
is no status column in the schema and deliberately is not one: the state is
replayed from append-only events ordered by *when they happened* rather than when
we heard, so a callback arriving late cannot overwrite a state that happened
after it. A sortable state column would be a promise that a column exists to sort
by.

The one state that needs acting on has its own screen (§6) rather than a filter,
because "which purchases are unconfirmed" is not expressible in SQL.

The supplier column and the supplier filter are reads. Neither assigns anything,
and no form on this screen names a supplier at all —
`ModuleBoundaryRulesTest` asserts it by grepping for the field names.

The outstanding figure on the list is a fold over the merchant's open purchases,
from the **expectation** rather than the acknowledgement, because most
outstanding purchases have not been acknowledged yet.

## 5. One purchase

**Both costs are shown and neither overwrites the other.** The expectation came
from the offer at routing time; the actual came from the acknowledgement. The
difference is the only thing that makes a supplier's pricing auditable, and the
host recorded neither — nothing anywhere stored what the merchant paid, so margin
on a drop-shipped line was unknowable after the fact.

**`PurchaseView::costVariance()` returns `null` for two different reasons and
this panel renders two different sentences.** The domain collapses them, which is
right for arithmetic and wrong for an operator:

- the supplier has not stated a cost — resolves itself when they acknowledge;
- the costs are in different currencies — a supplier invoicing in a currency the
  offer was never priced in, which is a thing somebody has to go and fix. The
  domain refuses to convert rather than inventing a rate, and so does this.

This is the one place this package knowingly says more than the domain does; it
is reported as a gap rather than fixed there.

**Two actions, and they are the only two.**

*Reconcile* asks the supplier what they have. It is offered from `Sent,
unconfirmed`, `Accepted` and `Part despatched` — not from a draft, which has been
sent to nobody, and not from a closed purchase, where there is nothing to learn.
**Four outcomes**, and the notification tells them apart:

| Outcome | What it means |
| --- | --- |
| no reporter bound | The install is not finished. Nothing was attempted and nothing assumed |
| the provider cannot be asked | This supplier only pushes callbacks. Reconciling it is a person's job |
| asked, nothing has happened | A real answer, and not a failure |
| the supplier answered | The state moved |

The middle two look identical from a distance and mean opposite things.
Collapsing them is how a merchant waits for ever for an answer that was never
going to arrive.

*Cancel* is offered from a draft and from an acknowledgement, and from nothing
else — the domain refuses the rest and the button only hides. A `Sent,
unconfirmed` purchase is not cancellable because we do not know whether the
supplier has it; a despatched one is not cancelled, it is returned, and returns
are not this module's. **Cancelling does not tell the supplier**, because that is
a transmission and 0.1.0 ships no seam for it — the modal says so rather than
letting an operator believe a message went out. The reason is a **closed list**,
not a text box: `detail_code` is a supplier-grade code, and free text is how a
recipient's name reaches storage in a module that holds none.

**Lines** show ordered against despatched, because a supplier despatches part of
a purchase, from more than one warehouse, on more than one day. The host had one
`supplier_tracking_number` column on the order, which no code ever wrote and no
code ever read.

**Events** are the append-only journal, with two times that are not the same
time: when it happened at the supplier, and when we heard. Nothing on that table
can be edited or removed, and the abilities that would are closed by name.

## 6. Needs reconciliation

The screen that earns the panel. Two things land here:

- **sent and unconfirmed** — the state a timeout leaves behind, which the host
  had no way to express at all;
- **open and carrying a problem** — a supplier raised something and the purchase
  is still live.

A refused purchase is not listed: it is terminal and there is nothing left to
ask. A fully despatched one is not listed either.

**Nothing on this page repairs anything, and there is no figure on it.** A "fix
it" button would have to invent an answer, and a "send it again" button is the
fault the module exists to correct. The fix is a question put to the supplier, on
the purchase's own page.

## 7. Tenancy

`Support\PanelTenant` is the single place the panel's merchant is resolved, and
**there is no fallback to "no tenant"**: `where('tenant_id', null)` compiles to
`is null`, which lists exactly the orphan rows a scope was meant to hide. Every
table in the domain carries a NOT NULL `tenant_id` so no cross-merchant supply
can be represented at all.

`CustodyTest` runs two merchants holding **deliberately identical** references —
the same supplier code, product reference, order reference and destination
reference on both sides — and exercises every relation on every screen, not only
every list. Three waves running, tenancy leaked through relations rather than
queries, because a package whose every query states its tenant never exercises
its own relations.

A reference belonging to another merchant raises the same `ModelNotFoundException`
as a reference that does not exist. A refusal that differed would publish the
existence of the row.

## 8. What is not here

- **No sourcing preview.** `Queries\SourcingPlanFor` writes nothing and would
  make a good screen, but it needs order lines the host owns, and a form that
  took them here would be a second place an order is described.
- **No erasure and no export.** `Actions\RedactDestination` and
  `Queries\ExportDestinationRecord` are not offered. A person spans more modules
  than this one, and an erase button per module is how somebody is erased from
  four of six.
- **No destination on any screen.** The module holds one opaque
  `destination_ref` and no name, email or address. This panel does not show even
  the reference: it is a handle that links a purchase to a person, and no screen
  here needs one.
- **No widget.** Nothing here belongs on a dashboard a panel might put in front
  of somebody the desk is not for.
