<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * Only what is decided before the downloader runs: the download itself needs the network.
 */
class ExtensionLoadCommandTest extends BaseTestCase
{
    private bool $createdServersDirectory = false;

    protected function tearDown(): void
    {
        @unlink(base_path('servers/sconcur'));

        if ($this->createdServersDirectory) {
            @rmdir(base_path('servers'));
        }

        parent::tearDown();
    }

    /**
     * The .so an earlier version of the command saved as servers/sconcur, the file, when it
     * did not create the directory. The command says what is in the way rather than failing
     * to create a directory over it.
     */
    #[Test]
    public function aFileWhereTheDefaultDirectoryGoesIsReported(): void
    {
        if (!is_dir(base_path('servers'))) {
            mkdir(base_path('servers'));

            $this->createdServersDirectory = true;
        }

        file_put_contents(base_path('servers/sconcur'), 'an old sconcur.so');

        $this->command('sconcur:extension:load')
            ->expectsOutputToContain('is a file, not a directory')
            ->assertFailed();

        self::assertSame('an old sconcur.so', file_get_contents(base_path('servers/sconcur')));
    }
}
