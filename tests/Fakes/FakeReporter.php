<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Tests\Fakes;

use Liberu\Ecommerce\Dropshipping\Contracts\ReportsShipments;
use Liberu\Ecommerce\Dropshipping\Data\SupplierReport;

/**
 * A shipment reporter that answers whatever a test gives it.
 *
 * `null` is not "no news": it means **this provider cannot be asked**, which is
 * the case of a supplier who only pushes callbacks. An empty `SupplierReport`
 * means the supplier *was* asked and nothing has happened yet. The panel has to
 * tell those apart, so the fake can produce both.
 */
final class FakeReporter implements ReportsShipments
{
    public function __construct(private readonly ?SupplierReport $answer) {}

    /** The provider that has no lookup by the buyer's own reference. */
    public static function cannotBeAsked(): self
    {
        return new self(null);
    }

    public static function answering(SupplierReport $report): self
    {
        return new self($report);
    }

    public function fetch(string $supplierCode, ?string $credentialRef, string $purchaseReference): ?SupplierReport
    {
        return $this->answer;
    }
}
