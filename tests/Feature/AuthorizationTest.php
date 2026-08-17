<?php

declare(strict_types=1);

use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\EventsRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\OffersRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\RelationManagers\PurchasesRelationManager;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;
use Liberu\Ecommerce\Dropshipping\Models\Purchase;

/**
 * The ability matrix, asserted by name.
 *
 * A missing policy is permissive, and a policy that exists but lacks the method
 * asked about is *also* permissive, because Filament's
 * `get_authorization_response()` falls through to allow. So the only way to know
 * an ability is closed is to ask it and be told no; these are the "and be told
 * no" half. **`isReadOnly()` would enforce none of it — it hides UI.**
 */
// Nested rows deliberately: `[ClassA::class, ClassB::class]` is PHP's callable
// array syntax, and Pest calls it instead of iterating it.
$resources = [
    [SupplierResource::class],
    [PurchaseResource::class],
];

$managers = [
    [OffersRelationManager::class],
    [PurchasesRelationManager::class],
    [LinesRelationManager::class],
    [EventsRelationManager::class],
];

it('closes every destructive resource ability by name', function (string $resource): void {
    $record = new Purchase();

    expect($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('publishes no Filament create and no Filament edit, because every write here is a domain action', function (string $resource): void {
    $record = new Purchase();

    // The domain *does* publish `RegisterSupplier` and `PublishSupplyOffer`, and
    // both are offered — as named actions calling those actions. Filament's own
    // create and edit machinery would write the row itself, skipping the natural
    // keys that make them idempotent and the currency check that makes an offer
    // legal.
    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse();
})->with($resources);

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new Purchase();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. **Associating a purchase with a different supplier is host fault 1
    // rebuilt as a button** — a supplier assigned by hand, outside the sourcing
    // plan, naming who gets paid.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited: inheriting it
        // would rest the table's visibility on a policy method nobody wrote.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_merge(
        get_class_methods(DeniesUnpublishedResourceAbilities::class),
        get_class_methods(DeniesUnpublishedRelationAbilities::class),
    );

    // A trait's methods report the *using* class as their declaring class, so
    // the file is what separates "this class wrote it" from "the trait that
    // closes it did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));
