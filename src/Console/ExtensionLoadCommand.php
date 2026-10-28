<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

/**
 * Download the sconcur extension (.so) matching the package version by
 * running the library downloader (vendor/bin/sconcur-load) and streaming its
 * output. The target defaults to the servers/sconcur directory, created when missing.
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

        if (is_string($path) && $path !== '') {
            $target = $path;
        } else {
            $target = base_path('servers/sconcur');

            // The default names a directory, and the downloader takes a path that is not
            // one for the file to write: servers/sconcur would become the .so itself, or
            // fail without servers/. A file already there is such a .so, written by a
            // version of this command that did not create the directory.
            if (file_exists($target) && !is_dir($target)) {
                $this->error(sprintf(
                    '%s is a file, not a directory: remove it, or pass the target as the argument',
                    $target,
                ));

                return self::FAILURE;
            }

            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                $this->error(sprintf('Cannot create the directory %s', $target));

                return self::FAILURE;
            }
        }

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
