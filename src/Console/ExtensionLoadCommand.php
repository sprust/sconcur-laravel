<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

/**
 * Download the sconcur Go extension (.so) matching the package version by
 * running the library downloader (vendor/bin/sconcur-load) and streaming its
 * output. The target defaults to servers/sconcur.
 */
class ExtensionLoadCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:extension:load
        {path? : Target path (file or directory) for sconcur.so}';

    protected $description = 'Download the matching sconcur extension (.so)';

    public function handle(): int
    {
        $bin  = base_path('vendor/bin/sconcur-load');
        $path = $this->argument('path');

        $target = is_string($path) && $path !== '' ? $path : base_path('servers/sconcur');

        $command = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bin),
            escapeshellarg($target),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
