<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Support;

use Liberu\Ecommerce\Dropshipping\Data\Money;
use Liberu\Ecommerce\Dropshipping\Data\OutstandingValue;
use Liberu\Ecommerce\Dropshipping\Data\ProblemReport;
use Liberu\Ecommerce\Dropshipping\Data\PurchaseView;
use Liberu\Ecommerce\Dropshipping\Data\ReconcileOutcome;
use Liberu\Ecommerce\Dropshipping\Data\SupplierPerformance;
use Liberu\Ecommerce\Dropshipping\Enums\Availability;
use Liberu\Ecommerce\Dropshipping\Enums\EventKind;
use Liberu\Ecommerce\Dropshipping\Enums\PurchaseState;

/**
 * The sentences this panel is most likely to get wrong, written once so it
 * cannot write them differently on two screens.
 *
 * Every rule here is one of the domain's refusals, kept a refusal:
 *
 * - **`null` is never `false` and never `0`.** `onTime` and `despatchedOn` are
 *   null when there is no promise or no complete despatch, which is a different
 *   fact from a supplier who missed one.
 * - **A cost variance across currencies is absent, not zero.** The domain
 *   returns `null` rather than inventing a rate, and so does this.
 * - **An on-time share with no measurable despatches is an em dash**, never
 *   `0%`. The domain publishes counts precisely so a caller has to decide what
 *   a denominator of zero means; this is that decision, taken out loud.
 * - **Outstanding value is per currency and never summed.**
 */
final class Render
{
    public const NONE = '—';

    /** Money as its decimal string and its currency, never as a float. */
    public static function money(?Money $money): string
    {
        return $money === null ? self::NONE : $money->decimal().' '.$money->currency;
    }

    /** A signed amount, so an overcharge reads as one. */
    public static function signedMoney(Money $money): string
    {
        return ($money->minor > 0 ? '+' : '').$money->decimal().' '.$money->currency;
    }

    /**
     * The difference between what the offer said and what the supplier charged.
     *
     * `PurchaseView::costVariance()` returns `null` for two different reasons —
     * the supplier has not stated a cost, and the two costs are in currencies
     * this module will not convert between — and an operator has to be able to
     * tell them apart, because the first resolves itself and the second is a
     * supplier trading in a currency the offer was not priced in. The domain
     * collapses them; this does not.
     */
    public static function variance(PurchaseView $view): string
    {
        if ($view->actualCost === null) {
            return self::NONE.' the supplier has not stated a cost';
        }

        $variance = $view->costVariance();

        if ($variance === null) {
            return self::NONE.' charged in '.$view->actualCost->currency
                .', expected in '.$view->expectedCost->currency
                .'; converting here would be inventing a rate';
        }

        return self::signedMoney($variance);
    }

    /**
     * Whether the supplier met the promise.
     *
     * Three answers and not two. Rendering `null` as "Late" accuses a supplier
     * of missing a promise nobody made, and rendering it as "On time" credits
     * one with keeping it.
     */
    public static function onTime(?bool $onTime): string
    {
        return match ($onTime) {
            true => 'On time',
            false => 'Late',
            null => self::NONE,
        };
    }

    public static function onTimeColour(?bool $onTime): string
    {
        return match ($onTime) {
            true => 'success',
            false => 'danger',
            null => 'gray',
        };
    }

    /** A date, or the reason there is not one yet. */
    public static function date(?string $date, string $absent = self::NONE): string
    {
        return $date ?? $absent;
    }

    /**
     * The share of measurable despatches that met their promise.
     *
     * The domain publishes counts and never a rate, so the division happens
     * here — once — and a denominator of zero is an em dash rather than `0%`.
     * A supplier who has despatched nothing has no record, and "0% on time" is
     * a sentence a merchant terminates a contract over.
     *
     * The denominator is on-time plus late, **not** everything despatched:
     * a despatch against a purchase that made no promise cannot be judged, and
     * counting it as a miss would punish a supplier for a lead time nobody
     * recorded.
     */
    public static function onTimeShare(SupplierPerformance $performance): string
    {
        $measurable = $performance->onTime + $performance->late;

        if ($measurable === 0) {
            return self::NONE.($performance->shipped === 0
                ? ' nothing despatched yet'
                : ' '.$performance->shipped.' despatched against no promise');
        }

        return (int) round($performance->onTime / $measurable * 100).'% of '.$measurable.' measurable';
    }

    /**
     * What the merchant owes suppliers, one line per currency.
     *
     * Never a single figure. A merchant sourcing in GBP and CNY has two debts,
     * and one number for them would be this panel inventing an exchange rate on
     * the screen an accountant reads.
     *
     * @param  list<OutstandingValue>  $values
     */
    public static function outstanding(array $values): string
    {
        if ($values === []) {
            return 'Nothing outstanding';
        }

        return implode(' · ', array_map(
            static fn (OutstandingValue $value): string => self::money($value->value)
                .' across '.$value->purchases.' '.($value->purchases === 1 ? 'purchase' : 'purchases'),
            $values,
        ));
    }

    /**
     * What reconciling concluded — and the four answers are four.
     *
     * The middle two look identical from a distance and mean opposite things:
     * *nobody is bound* is an install that is not finished, and *the provider
     * cannot be asked* is a supplier who only pushes callbacks, where
     * reconciliation is a person picking up a telephone. Collapsing them into
     * "could not reconcile" is how a merchant waits forever for an answer that
     * was never going to arrive.
     *
     * The fourth is the one that is easiest to lose: the supplier **was** asked
     * and nothing has happened yet. That is a real answer and it is not a
     * failure.
     */
    public static function reconcile(ReconcileOutcome $outcome, PurchaseState $before): string
    {
        if (! $outcome->resolved) {
            return match ($outcome->unresolvedReason) {
                'no_reporter_bound' => 'Nobody was asked. No shipment reporter is bound to this install, so nothing was attempted and nothing was assumed. Bind one, or reconcile this purchase with the supplier directly.',
                'provider_cannot_be_asked' => 'The supplier was reached and cannot be asked. This provider has no lookup by our own reference — it only pushes callbacks — so reconciling this purchase is a person\'s job, not this button\'s.',
                default => 'Unresolved: '.($outcome->unresolvedReason ?? 'the domain gave no reason, which is itself worth reporting').'.',
            };
        }

        if ($outcome->state === $before) {
            return 'The supplier was asked and nothing has happened yet. Still '.self::state($outcome->state)
                .' — which is an answer, not a failure.';
        }

        return 'The supplier answered. Now '.self::state($outcome->state).'.';
    }

    public static function state(PurchaseState $state): string
    {
        return match ($state) {
            PurchaseState::Draft => 'Routed, not sent',
            // Not "failed" and not "sending". It means we asked and do not know,
            // which is the state the host had no way to express at all.
            PurchaseState::Transmitting => 'Sent, unconfirmed',
            PurchaseState::Acknowledged => 'Accepted',
            PurchaseState::PartiallyShipped => 'Part despatched',
            PurchaseState::Shipped => 'Despatched',
            PurchaseState::Rejected => 'Refused',
            PurchaseState::Cancelled => 'Cancelled',
        };
    }

    public static function stateColour(PurchaseState $state): string
    {
        return match ($state) {
            PurchaseState::Draft => 'gray',
            PurchaseState::Transmitting => 'warning',
            PurchaseState::Acknowledged => 'info',
            PurchaseState::PartiallyShipped => 'info',
            PurchaseState::Shipped => 'success',
            PurchaseState::Rejected => 'danger',
            PurchaseState::Cancelled => 'gray',
        };
    }

    public static function eventLabel(EventKind $kind): string
    {
        return match ($kind) {
            EventKind::Transmitted => 'Sent to the supplier',
            EventKind::Acknowledged => 'Supplier accepted',
            EventKind::Rejected => 'Supplier refused',
            EventKind::Shipped => 'Supplier despatched',
            EventKind::ExceptionRaised => 'Problem raised',
            EventKind::Cancelled => 'Cancelled',
        };
    }

    public static function eventColour(EventKind $kind): string
    {
        return match ($kind) {
            EventKind::Transmitted => 'warning',
            EventKind::Acknowledged => 'info',
            EventKind::Shipped => 'success',
            EventKind::Rejected, EventKind::ExceptionRaised => 'danger',
            EventKind::Cancelled => 'gray',
        };
    }

    public static function availability(Availability $availability): string
    {
        return match ($availability) {
            Availability::InStock => 'In stock',
            Availability::OutOfStock => 'Out of stock',
            Availability::Discontinued => 'Discontinued',
            // A stance, not a missing value: a supplier publishing a catalogue
            // without stock levels says something different from one saying no.
            Availability::Unknown => 'Not stated',
        };
    }

    public static function availabilityColour(Availability $availability): string
    {
        return match ($availability) {
            Availability::InStock => 'success',
            Availability::OutOfStock => 'warning',
            Availability::Discontinued => 'danger',
            Availability::Unknown => 'gray',
        };
    }

    /**
     * What was ordered against what has gone, per line.
     *
     * @param  array<string, int>  $ordered
     * @param  array<string, int>  $shipped
     */
    public static function quantities(array $ordered, array $shipped): string
    {
        if ($ordered === []) {
            return self::NONE;
        }

        return implode(', ', array_map(
            static fn (string $ref, int $quantity): string => $ref.': '.($shipped[$ref] ?? 0).' of '.$quantity,
            array_keys($ordered),
            $ordered,
        ));
    }

    /**
     * What went wrong, in the supplier's own codes.
     *
     * `field` names which field was rejected and never its value, which is the
     * narrowest thing that keeps a rejection actionable without this module —
     * or this panel — holding a second copy of where somebody lives.
     *
     * @param  list<ProblemReport>  $problems
     */
    public static function problems(array $problems): string
    {
        if ($problems === []) {
            return 'None';
        }

        return implode('; ', array_map(
            static fn (ProblemReport $problem): string => self::eventLabel($problem->kind).': '
                .$problem->code
                .($problem->field === null ? '' : ' on '.$problem->field)
                .' ('.$problem->occurredAt->toDateString().')',
            $problems,
        ));
    }
}
