<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The abilities no resource in this package publishes, forced closed by name.
 *
 * Naming them is the whole point. A missing policy is permissive; a policy that
 * exists but lacks the method asked about is *also* permissive, because
 * Filament's `get_authorization_response()` falls through to allow when the
 * method is absent. "We did not write a delete policy" is therefore not "delete
 * is off" — it is "delete is on and nobody decided".
 *
 * **`canCreate` and `canEdit` are in this list even though the domain does
 * publish writes**, because Filament's own create and edit machinery writes the
 * row itself. `RegisterSupplier` and `PublishSupplyOffer` are idempotent on
 * natural keys and refuse a cost in the wrong currency, a minimum below one and
 * a negative lead time; a Filament form saving the model would skip all of it.
 * Every write here is a named action calling the published action, so there is
 * one path and the domain's guards are on it.
 *
 * Deleting is worse. A purchase event is append-only, enforced in model hooks
 * *and* asserted against the query builder, and a purchase is the record that
 * the merchant asked a supplier to ship goods to somebody. A delete button here
 * would destroy the only account of a despatch that has already happened.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
