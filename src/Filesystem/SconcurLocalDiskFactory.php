<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

use Illuminate\Filesystem\LocalFilesystemAdapter as IlluminateLocalFilesystemAdapter;
use Illuminate\Support\Arr;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use RuntimeException;

/**
 * Builds a `sconcur_local` disk out of its config entry — the same entry a `local` disk
 * takes, plus `timeout_ms`.
 *
 * It mirrors FilesystemManager::createLocalDriver() and createFlysystem(), which are
 * protected, with the one difference of the adapter, and hands back the framework's
 * LocalFilesystemAdapter, so path(), url() and the rest behave as on `local`.
 *
 * What the disk would not honour is refused rather than ignored: `serve`, whose route the
 * framework registers for the `local` driver only, and `read-only` and `prefix`, whose
 * adapters live in optional Flysystem packages this one does not require.
 */
class SconcurLocalDiskFactory
{
    protected const array REFUSED_KEYS = [
        'serve',
        'read-only',
        'prefix',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function make(array $config): IlluminateLocalFilesystemAdapter
    {
        foreach (self::REFUSED_KEYS as $key) {
            if (!empty($config[$key])) {
                throw new RuntimeException(
                    sprintf(
                        'A %s disk does not support `%s`; use a `local` disk for that.',
                        SconcurLocalFilesystemAdapter::DRIVER,
                        $key,
                    ),
                );
            }
        }

        $visibility = PortableVisibilityConverter::fromArray(
            (array) ($config['permissions'] ?? []),
            (string) ($config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE),
        );

        $adapter = new SconcurLocalFilesystemAdapter(
            location: (string) $config['root'],
            visibility: $visibility,
            lockFlags: (int) ($config['lock'] ?? LOCK_EX),
            linkHandling: ($config['links'] ?? null) === 'skip'
                ? SconcurLocalFilesystemAdapter::SKIP_LINKS
                : SconcurLocalFilesystemAdapter::DISALLOW_LINKS,
            timeoutMs: (int) ($config['timeout_ms'] ?? 0),
        );

        return new IlluminateLocalFilesystemAdapter(
            $this->flysystem(adapter: $adapter, config: $config),
            $adapter,
            $config,
        );
    }

    /**
     * FilesystemManager::createFlysystem() without the wrappers refused above: the
     * options Flysystem reads.
     *
     * @param array<string, mixed> $config
     */
    protected function flysystem(FilesystemAdapter $adapter, array $config): Flysystem
    {
        return new Flysystem($adapter, Arr::only($config, [
            'directory_visibility',
            'disable_asserts',
            'retain_visibility',
            'temporary_url',
            'url',
            'visibility',
        ]));
    }
}
