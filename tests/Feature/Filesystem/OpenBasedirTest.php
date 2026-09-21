<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use PHPUnit\Framework\Attributes\Test;

/**
 * open_basedir is enforced by PHP's own file functions, and the feature opens files in the
 * extension, past it. Under open_basedir the package keeps to the native calls, so a path
 * outside the allowed directories stays refused inside a coroutine too.
 *
 * Runs in a PHP process of its own: open_basedir can only be narrowed at runtime, and the
 * test process has to keep its own access.
 */
class OpenBasedirTest extends BaseFilesystemTestCase
{
    protected const string OUTSIDE_PATH = '/etc/hostname';

    #[Test]
    public function aPathOutsideOpenBasedirStaysRefused(): void
    {
        if (!is_readable(self::OUTSIDE_PATH)) {
            self::markTestSkipped(self::OUTSIDE_PATH . ' is not there to be refused');
        }

        $packageRoot = dirname(__DIR__, 3);

        $script = $this->root . '/probe.php';

        // A metadata call on the disk reaches the feature without a native check in front
        // of it; through this link it would see a file open_basedir hides.
        symlink(self::OUTSIDE_PATH, $this->root . '/outside');

        file_put_contents($script, <<<PHP
            <?php

            require '{$packageRoot}/vendor/autoload.php';

            \$filesystem = new SConcur\\Laravel\\Filesystem\\Filesystem();
            \$answers    = [];

            \$waitGroup = SConcur\\WaitGroup::create();

            \$adapter = new SConcur\\Laravel\\Filesystem\\SconcurLocalFilesystemAdapter(location: '{$this->root}');

            \$waitGroup->add(static function () use (\$filesystem, \$adapter, &\$answers): void {
                \$answers['hash']       = @\$filesystem->hash('%OUTSIDE%', 'md5');
                \$answers['copy']       = @\$filesystem->copy('%OUTSIDE%', '{$this->root}/copy.txt');
                \$answers['fileExists'] = @\$adapter->fileExists('outside');
            });

            \$waitGroup->waitAll();

            echo json_encode(\$answers);
            PHP);

        file_put_contents($script, str_replace('%OUTSIDE%', self::OUTSIDE_PATH, (string) file_get_contents($script)));

        $output = shell_exec(
            escapeshellarg(PHP_BINARY)
            . ' -d ' . escapeshellarg('open_basedir=' . $this->root . ':' . $packageRoot)
            . ' ' . escapeshellarg($script)
            . ' 2>&1',
        );

        self::assertSame('{"hash":false,"copy":false,"fileExists":false}', $output);
        self::assertFileDoesNotExist($this->root . '/copy.txt');
    }
}
