<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\Dropshipping\Data\ProblemReport;
use Liberu\Ecommerce\Dropshipping\Data\SupplierReport;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Filament\Pages\Reconciliation;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->supplier = supplier();
    offer($this->supplier);
});

function queue(): Collection
{
    return Livewire::test(Reconciliation::class)->instance()->getTable()->getRecords();
}

/**
 * The queue that earns the panel.
 *
 * "Sent and unconfirmed" is the state the host had no way to express at all: its
 * queue job wrote the supplier's own reference *after* the call, so a timeout
 * left a row that looked untouched and an at-least-once queue retried it. That
 * state has to land somewhere a person looks, or the rule degrades into a set of
 * purchases nobody ever asks about again.
 */
it('is empty while nothing needs a person', function (): void {
    routedPurchase();

    expect(queue())->toHaveCount(0);
});

it('queues a purchase that was sent and never confirmed, and says to ask rather than to re-send', function (): void {
    $purchase = sentUnconfirmed(routedPurchase());

    $rows = queue();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['purchase'])->toBe($purchase->reference)
        ->and($rows->first()['reason'])->toContain('Reconcile it')
        ->and($rows->first()['reason'])->toContain('rather than sending it again');
});

it('queues an open purchase whose supplier has raised a problem', function (): void {
    $purchase = applyReport(
        applyReport(sentUnconfirmed(routedPurchase()), acknowledgement()),
        new SupplierReport(problems: [
            new ProblemReport('problem-1', CarbonImmutable::now(), EventKind::ExceptionRaised, 'address_invalid', 'destination.postcode'),
        ]),
    );

    $rows = queue();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['purchase'])->toBe($purchase->reference)
        ->and($rows->first()['reason'])->toContain('address_invalid')
        // The *name* of the field, never its value. Nothing here holds a second
        // copy of where somebody lives.
        ->and($rows->first()['reason'])->toContain('destination.postcode');
});

it('does not queue a purchase the supplier has refused, because there is nothing left to ask', function (): void {
    applyReport(sentUnconfirmed(routedPurchase()), new SupplierReport(problems: [
        new ProblemReport('reject-1', CarbonImmutable::now(), EventKind::Rejected, 'out_of_stock'),
    ]));

    // Rejected is terminal and no longer outstanding. Re-sourcing means routing
    // to a different supplier, which is a decision a person takes elsewhere.
    expect(queue())->toHaveCount(0);
});

it('does not queue a purchase every line of which has gone', function (): void {
    applyReport(
        applyReport(sentUnconfirmed(routedPurchase()), acknowledgement()),
        despatch(['line-1' => 2]),
    );

    expect(queue())->toHaveCount(0);
});

it('offers nothing that repairs, re-sends or retries', function (): void {
    sentUnconfirmed(routedPurchase());

    $table = Livewire::test(Reconciliation::class)->assertOk()->instance()->getTable();

    // The fix is a question put to the supplier, on the purchase's own page. A
    // button here that "cleared" the state would be inventing an answer, and a
    // button that re-sent is the fault the module exists to correct.
    expect($table->getHeaderActions())->toBe([])
        ->and($table->getFlatActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([]);
});
