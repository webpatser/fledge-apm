<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Instrumentation\Instrumentation;

/**
 * Every concrete instrumentation class shipped in src/Instrumentation/,
 * discovered from the directory so a rename cannot silently skip one.
 * Helpers living next to them (recorders, redaction) are not instrumentations.
 *
 * @return list<class-string<Instrumentation>>
 */
function shippedInstrumentations(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Instrumentation/*.php') as $file) {
        $class = 'Fledge\\Apm\\Instrumentation\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Instrumentation::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * Boots a fresh provider and returns the instrumentation classes the
 * container resolved while doing so.
 *
 * @return list<class-string>
 */
function bootProviderAndCollectResolved(bool $enabled): array
{
    config()->set('apm.enabled', $enabled);
    config()->set('apm.exporter', 'memory');

    $resolved = [];

    foreach (shippedInstrumentations() as $class) {
        app()->resolving($class, function (object $instance) use (&$resolved): void {
            $resolved[] = $instance::class;
        });
    }

    $provider = new ApmServiceProvider(app());
    $provider->register();
    $provider->boot();

    sort($resolved);

    return $resolved;
}

it('maps every instrumentation class present in src/Instrumentation to an existing class', function () {
    $mapped = array_values(ApmServiceProvider::INSTRUMENTATIONS);
    sort($mapped);

    expect(shippedInstrumentations())->not->toBeEmpty()
        ->and($mapped)->toBe(shippedInstrumentations());

    foreach ($mapped as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");
    }
});

it('registers every shipped instrumentation when APM is enabled', function () {
    expect(bootProviderAndCollectResolved(enabled: true))->toBe(shippedInstrumentations());
});

it('registers no instrumentation when APM is disabled', function () {
    expect(bootProviderAndCollectResolved(enabled: false))->toBe([]);
});
