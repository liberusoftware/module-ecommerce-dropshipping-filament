<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every built-in relation-manager ability, forced closed by name.
 *
 * A relation manager carries its own authorization surface, separate from the
 * resource's, and two of its methods are live on a `hasMany` and default open:
 * `canAssociate()` and `canDissociate()`. On these relations that is not
 * theoretical. **Associating a purchase with a different supplier is host fault
 * 1 rebuilt as a button** — routing decided by hand, outside the sourcing plan,
 * naming the supplier who gets paid. Dissociating a purchase line from its
 * purchase silently changes what "fully despatched" means, and with it the
 * promise the supplier is measured against.
 *
 * Every relation manager here is a **reader**. Nothing in these tables is
 * created, edited or removed by Filament's own machinery. The writes offered are
 * named actions calling published domain actions, so the domain's row lock, its
 * unique indexes and its append-only hooks all run on every path.
 *
 * `canViewAny()` is the one ability published, and it is stated rather than
 * inherited: inheriting it would rest the table's visibility on a policy method
 * nobody wrote, which is the same permissive default this trait exists to close.
 *
 * These are declared public where the parent declares them protected. Widening
 * is legal, narrowing is a fatal at class load, and public is what lets a test
 * assert the answer by name instead of through reflection.
 */
trait DeniesUnpublishedRelationAbilities
{
    public function canViewAny(): bool
    {
        return true;
    }

    public function canAssociate(): bool
    {
        return false;
    }

    public function canAttach(): bool
    {
        return false;
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }

    public function canDeleteAny(): bool
    {
        return false;
    }

    public function canDetach(Model $record): bool
    {
        return false;
    }

    public function canDetachAny(): bool
    {
        return false;
    }

    public function canDissociate(Model $record): bool
    {
        return false;
    }

    public function canDissociateAny(): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canForceDelete(Model $record): bool
    {
        return false;
    }

    public function canForceDeleteAny(): bool
    {
        return false;
    }

    public function canReorder(): bool
    {
        return false;
    }

    public function canReplicate(Model $record): bool
    {
        return false;
    }

    public function canRestore(Model $record): bool
    {
        return false;
    }

    public function canRestoreAny(): bool
    {
        return false;
    }

    public function canView(Model $record): bool
    {
        return false;
    }
}
