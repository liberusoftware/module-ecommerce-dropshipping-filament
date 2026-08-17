# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-08-17

First release. A Filament 5 admin panel over `liberusoftware/ecommerce-dropshipping`.

### Added

- `DropshippingPlugin`, attached per panel by the host. Nothing registers
  globally, so a shopper-facing panel does not get a screen naming the
  merchant's suppliers and what it pays them.
- **Suppliers**: the table the host did not have. Its code — the natural key —
  the currency it trades in, the timezone a promised date is counted in, the
  default lead time, whether it is active, and how many products it publishes an
  offer for. Registers one through `RegisterSupplier`, which is idempotent on the
  code, so the same button corrects a supplier as well as creating one.
- **Supplier**: how it has performed, as counts with one division taken out loud;
  what it is owed, per currency; what it supplies, published through
  `PublishSupplyOffer`; and every purchase sent to it.
- **Purchases**: reference, supplier, order, the state folded from the events,
  the expected cost, the promised date, the despatch date and whether the promise
  was met. Filterable by supplier and searchable by reference — never filterable
  by state, because a state is a fold and there is no column to sort one on.
- **Purchase**: both costs and the difference between them, the promise and its
  outcome, ordered against despatched per line, the problems the supplier raised,
  the lines, and the append-only journal. Reconciles through `ReconcilePurchase`
  and cancels through `CancelPurchase`.
- **Needs reconciliation**: every purchase in the merchant that was sent and
  never confirmed, and every open purchase carrying a problem. No repair button
  and no figure.
- Every unpublished Filament ability forced closed by name, per resource and per
  relation manager, including `canAssociate` and `canDissociate`, which are live
  on a `hasMany` and default open — and which, on these relations, would assign a
  purchase to a supplier by hand.

### Notes

- **No transmit, no re-transmit and no retry, anywhere.** Transmission needs the
  recipient's address, which this module deliberately does not hold, and
  re-sending after a timeout is how the host shipped the same goods twice at the
  merchant's cost. A purchase that was sent and never confirmed is offered
  **Reconcile**, which asks the supplier what they have.
- **No field anywhere names a supplier.** Routing is a decision over published
  offers and never an input; the host read
  `$request->input('supplier_id', 'dropxl')` from a checkout form.
- **`null` is rendered as an em dash and never as `false` or `0`.** A promise
  nobody made and a promise a supplier missed are different facts, a cost
  variance across two currencies is absent rather than converted, and an on-time
  share with nothing measurable to divide is an em dash rather than nought per
  cent.
- **The two absences the domain collapses are separated here.**
  `PurchaseView::costVariance()` returns `null` both when the supplier has not
  stated a cost and when the two costs are in different currencies; this panel
  renders two different sentences.
- **Filament floor `^5.4`**, which is the true floor rather than a choice:
  `v5.3.0`'s support package caps at `illuminate/contracts: ^11.28|^12.0` and
  `v5.4.0` is the first to add `^13.0`.

### Deliberately not shipped

- **A sourcing preview.** `Queries\SourcingPlanFor` writes nothing and would make
  a good screen, but it needs order lines the host owns, and a form that took
  them here would be a second place an order is described. The routing decision
  is visible where its inputs are: on a supplier's offers.
- **Erasure and export.** `Actions\RedactDestination` and
  `Queries\ExportDestinationRecord` are not offered. A person spans more modules
  than this one, and an erase button per module is how somebody is erased from
  four of six.
- **A state filter on the purchases list.** A state is a fold and is not
  expressible in SQL. The one state that needs acting on has its own screen.
- **Any widget.** Nothing here belongs on a dashboard that a panel might put in
  front of somebody the desk is not for.
