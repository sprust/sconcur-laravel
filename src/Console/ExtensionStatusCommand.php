<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Connection\Extension;

/**
 * Check whether the sconcur extension is installed and matches the package
 * version. Resolved in-process (no subprocess). Exit code 0 = ready, 1 = not.
 */
class ExtensionStatusCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:extension:status
        {--json : Output a single machine-readable line}';

    protected $description = 'Show the sconcur extension status';

    public function handle(): int
    {
        $packageVersion = Extension::REQUIRED_EXTENSION_VERSION;
        $installed      = extension_loaded('sconcur');

        $extensionVersion = ($installed && function_exists('SConcur\Extension\version'))
            ? \SConcur\Extension\version()
            : null;

        $ready = $installed
            && $extensionVersion !== null
            && version_compare($extensionVersion, $packageVersion, '==');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'extension_installed' => $installed,
                'package_version'     => $packageVersion,
                'extension_version'   => $extensionVersion,
                'ready'               => $ready,
            ], JSON_THROW_ON_ERROR));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('extension installed', $installed ? 'yes' : 'no');
        $this->components->twoColumnDetail('package version', $packageVersion);
        $this->components->twoColumnDetail('extension version', $extensionVersion ?? 'n/a');
        $this->components->twoColumnDetail('ready', $ready ? 'yes' : 'no');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
