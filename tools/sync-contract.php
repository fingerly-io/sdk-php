<?php

declare(strict_types=1);

/**
 * Refreshes the vendored server contract snapshot.
 *
 * The snapshot keeps the test suite independent of sibling repositories. Point
 * FINGERLY_CONTRACT_DIR at the integration workspace's contract directory to
 * pull a newer one:
 *
 *     FINGERLY_CONTRACT_DIR=../../contract composer contract:sync
 *
 * `ContractTest::testTheSnapshotMatchesItsManifest` verifies the result, so
 * there is no separate check mode: `composer test` is the check.
 */
const FILES = ['openapi.json', 'fixtures.json'];

exit(main());

function main(): int
{
    $root = dirname(__DIR__);
    $source = getenv('FINGERLY_CONTRACT_DIR');
    $source = is_string($source) && '' !== $source ? $source : $root.'/contract';
    $target = $root.'/contract';

    try {
        $manifestRaw = read($source.'/manifest.json');
        $manifest = json_decode($manifestRaw, true, 32, \JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('the manifest records no files');
        }

        // Copy only after every file matches the hash the server recorded, so
        // a half-written snapshot cannot land.
        $staged = ['manifest.json' => $manifestRaw];
        foreach (FILES as $name) {
            $content = read($source.'/'.$name);
            $actual = hash('sha256', $content);
            if (($manifest['files'][$name] ?? null) !== $actual) {
                throw new RuntimeException(sprintf('invalid contract hash for %s: %s', $name, $actual));
            }
            $staged[$name] = $content;
        }

        if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
            throw new RuntimeException('could not create the contract directory');
        }
        foreach ($staged as $name => $content) {
            if (false === file_put_contents($target.'/'.$name, $content)) {
                throw new RuntimeException('could not write '.$name);
            }
        }
    } catch (Throwable $e) {
        fwrite(\STDERR, $e->getMessage().\PHP_EOL);

        return 1;
    }

    echo 'PHP contract snapshot refreshed.'.\PHP_EOL;

    return 0;
}

function read(string $path): string
{
    $content = @file_get_contents($path);
    if (false === $content) {
        throw new RuntimeException('could not read '.$path);
    }

    return $content;
}
