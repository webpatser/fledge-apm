<?php

declare(strict_types=1);

/**
 * Elasticsearch cannot hold one field as both a leaf and an object in the
 * same index, so no metric name may be a prefix of another followed by a dot
 * (`a.b` next to `a.b.c`).
 *
 * Names are read from the source: string literals that are an instrument
 * factory's first argument, or are assigned to `$name` before the call.
 *
 * @return list<string>
 */
function packageInstrumentNames(): array
{
    $names = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            '/(?:\$name\s*=\s*|->create\w+\(\s*|->(?:counter|histogram)\(\s*|countJob\(\s*)\'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)\'/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        array_push($names, ...$matches[1]);
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

it('finds the instruments of this release in the source', function () {
    expect(packageInstrumentNames())->toContain(
        'http.client.request.duration',
        'messaging.process.duration',
        'messaging.client.consumed.messages',
        'fledge.queue.job.wait_time',
        'fledge.queue.job.retries',
        'fledge.queue.job.failures',
        'fledge.queue.pending',
        'fledge.queue.delayed',
        'fledge.queue.reserved',
        'fledge.queue.oldest_age',
        'fledge.loop.lag',
        'fledge.loop.watchers',
        'fledge.php.memory.usage',
        'fledge.php.memory.peak',
        'fledge.php.gc.runs',
        'fledge.php.gc.collected',
        'fledge.php.gc.duration',
        'db.client.connection.count',
        'db.client.connection.max',
        'db.client.connection.pending_requests',
    );
});

it('has no instrument name that is a prefix of another followed by a dot', function () {
    $names = packageInstrumentNames();
    $clashes = [];

    foreach ($names as $name) {
        foreach ($names as $other) {
            if ($name !== $other && str_starts_with($other, $name.'.')) {
                $clashes[] = "{$name} < {$other}";
            }
        }
    }

    expect($clashes)->toBe([]);
});
