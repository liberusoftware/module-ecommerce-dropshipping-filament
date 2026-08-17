<?php

declare(strict_types=1);

use Liberu\Ecommerce\Dropshipping\Filament\DropshippingPlugin;
use Liberu\Ecommerce\Dropshipping\Filament\Pages\Reconciliation;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Purchases\PurchaseResource;
use Liberu\Ecommerce\Dropshipping\Filament\Resources\Suppliers\SupplierResource;

/**
 * The rules that are this package's rather than the fleet's.
 *
 * The shared boundary suite in `package-testbench` asserts the manifest, the
 * required files, the absence of `App\` and the panel plugins. These are the
 * ones specific to a surface over a module that decides its own routing, stores
 * no PII, and must never offer to send a purchase twice.
 */
function sourceFiles(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * A source file with its comments removed.
 *
 * Every rule below is about what this package *does*, and a docblock naming the
 * thing it refuses to do is the most likely text in the repository to trip a
 * naive grep — these docblocks name `supplier_id`, `dropxl` and re-sending
 * precisely because the package ships none of them.
 */
function sourceCode(string $file): string
{
    $stripped = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

it('registers everything through the plugin and nothing from the service provider', function (): void {
    // Registering from a provider would put the sourcing desk into every panel
    // the application has, including a shopper-facing one — where a screen
    // naming the merchant's suppliers and what it pays them is its margin,
    // published.
    $provider = file_get_contents(dirname(__DIR__, 2).'/src/DropshippingFilamentServiceProvider.php');

    expect($provider)->not->toContain('Resource::class')
        ->and($provider)->not->toContain('->resources(')
        ->and($provider)->not->toContain('->pages(');

    $plugin = file_get_contents(dirname(__DIR__, 2).'/src/DropshippingPlugin.php');

    expect($plugin)->toContain('SupplierResource::class')
        ->and($plugin)->toContain('PurchaseResource::class')
        ->and($plugin)->toContain('Reconciliation::class')
        ->and($plugin)->not->toContain('->widgets(');
});

it('declares every class the manifest names for the panel', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue();
        }
    }

    expect($manifest['presentation']['filament']['admin'])->toBe([DropshippingPlugin::class])
        ->and(class_exists(SupplierResource::class))->toBeTrue()
        ->and(class_exists(PurchaseResource::class))->toBeTrue()
        ->and(class_exists(Reconciliation::class))->toBeTrue();
});

it('writes nothing through Eloquent, because every write here is a published domain action', function (): void {
    // A form that saved a supplier would skip the natural key that makes
    // registering idempotent; one that saved an offer would skip the currency
    // check; one that saved a purchase event would skip the append-only hooks
    // and the unique index that arbitrates a provider's retried callback.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('->save()')
            ->and($source)->not->toContain('->update(')
            ->and($source)->not->toContain('->delete()')
            ->and($source)->not->toContain('->forceFill(')
            ->and($source)->not->toContain('->increment(')
            ->and($source)->not->toContain('->decrement(');
    }
});

/**
 * **Routing is never an input.** Host fault 1 was a hidden form field naming the
 * supplier who gets paid: `CheckoutController.php:359-360` read
 * `$request->input('supplier_id', 'dropxl')`. No form in this package may offer
 * a supplier, and no default supplier may be named anywhere in it.
 */
it('offers no field anywhere that names a supplier', function (): void {
    // The names, not the word: `supplier_sku` is a supplier's code for a
    // product and is legitimately typed on an offer. What may not exist is a
    // field that *chooses* a supplier.
    $forbidden = ['supplier', 'supplier_id', 'supplier_code', 'supplier_ref', 'supplier_reference'];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach (['Select', 'TextInput', 'Radio', 'Hidden', 'Toggle', 'CheckboxList'] as $field) {
            foreach ($forbidden as $name) {
                expect($source)->not->toContain($field."::make('".$name."')");
            }
        }
    }
});

it('names no supplier as a default, anywhere', function (): void {
    // `'dropxl'` as a default argument was the host's fault, not its
    // convenience, and a provider name in provider-neutral code is a branch
    // nobody without that provider's account can test.
    foreach (sourceFiles() as $file) {
        expect(strtolower(sourceCode($file)))->not->toContain('dropxl')
            ->and(strtolower(sourceCode($file)))->not->toContain('supplier1')
            ->and(strtolower(sourceCode($file)))->not->toContain('supplier2');
    }
});

/**
 * **Nothing here sends a purchase, and nothing here sends one twice.**
 *
 * The host's queue job guarded a retry on a reference the *supplier* minted, so
 * a timeout after the supplier accepted shipped real goods twice at the
 * merchant's cost. Transmission also needs the recipient's address, which this
 * module deliberately does not hold.
 */
it('calls no transmit action and offers no button that would', function (): void {
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('TransmitPurchase')
            ->and($source)->not->toContain('RoutePurchases')
            ->and($source)->not->toContain("Action::make('transmit')")
            ->and($source)->not->toContain("Action::make('retry')")
            ->and($source)->not->toContain("Action::make('resend')")
            ->and($source)->not->toContain("Action::make('send')");
    }
});

/**
 * **No PII, on any screen.** The module holds one opaque `destination_ref` and
 * no name, email or address; the host wrote the provider's echo of all three
 * into `orders.supplier_response` and kept it for ever.
 */
it('renders no destination and reaches for no personal field', function (): void {
    // Identifiers rather than English. Several strings on these screens explain
    // *why* no address is held, and a rule that forbade the word would forbid
    // the explanation while leaving a column named `recipient_name` legal.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('destination_ref')
            ->and($source)->not->toContain('Data\Destination')
            ->and($source)->not->toContain('Destination(');

        foreach (['TextColumn', 'TextEntry', 'TextInput', 'Select'] as $component) {
            foreach (['destination', 'destination_ref', 'recipient', 'recipient_name', 'address', 'postcode', 'email', 'phone'] as $name) {
                expect($source)->not->toContain($component."::make('".$name."')");
            }
        }
    }
});

it('offers no erasure and no export, because a person spans more modules than this one', function (): void {
    // An erase button per module is how a person is erased from four of six.
    // The host's person-wide path is the one that must run these.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('RedactDestination')
            ->and($source)->not->toContain('ExportDestinationRecord');
    }
});

it('takes no credential and shows no secret', function (): void {
    foreach (sourceFiles() as $file) {
        $source = strtolower(sourceCode($file));

        expect($source)->not->toContain('api_key')
            ->and($source)->not->toContain('apikey')
            ->and($source)->not->toContain('->password(');
    }
});

it('names no status column, because a purchase state is a fold', function (): void {
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain("make('status')")
            ->and($source)->not->toContain("make('is_dropshipped')")
            ->and($source)->not->toContain("make('supplier_order_reference')")
            ->and($source)->not->toContain("make('supplier_response')");
    }
});

it('reaches for no framework-foundation helper', function (): void {
    // `config()`, `app()`, `auth()` and `now()` live in `laravel/framework`,
    // not in `illuminate/support`. They pass CI because the testbench drags the
    // framework in, and they are a lying constraint for a real consumer.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toMatch('/(?<![\w>$])config\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])app\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])auth\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])now\(/');
    }
});

it('states its Filament floor as the true one', function (): void {
    // `v5.3.0`'s support package caps at `illuminate/contracts: ^11.28|^12.0`
    // and `v5.4.0` is the first to add `^13.0`. `^5.4` is the floor rather than
    // a choice dressed up as one.
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['require']['filament/filament'])->toBe('^5.4');
});

it('lists the sibling packages the manifest declares and no others', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, 512, JSON_THROW_ON_ERROR);

    $siblings = array_filter(
        $composer['require'],
        fn (string $constraint, string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($siblings)->toBe($manifest['requires']['packages'])
        ->and($siblings)->toBe(['liberusoftware/ecommerce-dropshipping' => '^0.1']);
});

it('carries no session identifier in any file', function (): void {
    foreach (array_merge(sourceFiles(), [
        dirname(__DIR__, 2).'/README.md',
        dirname(__DIR__, 2).'/CHANGELOG.md',
    ]) as $file) {
        $source = (string) file_get_contents($file);

        // Split so that this assertion is not itself the thing it forbids: a
        // repository-wide grep for the literals has to come back empty.
        expect($source)->not->toContain('claude'.'.ai')
            ->and($source)->not->toContain('Claude-'.'Session');
    }
});
