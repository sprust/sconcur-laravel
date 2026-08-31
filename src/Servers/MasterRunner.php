<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

use SConcur\Worker\MasterCli;
use SConcur\Worker\MasterConfig;

/**
 * Adapter over the library MasterCli.
 *
 * Why it exists: MasterCli's only public entry point is run(array $argv), which
 * requires a `--configPath=FILE` JSON file and parses argv. Its lifecycle methods
 * (start/stop/status/reload) — which hold all the tested lock/state-file
 * supervision logic — are protected. We drive the master from an in-memory
 * MasterConfig (built from Laravel config, no JSON), so this subclass widens
 * those four methods to public. It adds no logic of its own.
 *
 * The signatures mirror MasterCli's, `$group` and `$configPath` included: a master
 * supervises several pools since SConcur 0.11, and status and reload can be scoped
 * to one of them by name ('' means all of them).
 */
class MasterRunner extends MasterCli
{
    public function start(MasterConfig $config): int
    {
        return parent::start($config);
    }

    public function stop(MasterConfig $config): int
    {
        return parent::stop($config);
    }

    public function status(MasterConfig $config, string $group = ''): int
    {
        return parent::status($config, $group);
    }

    public function reload(MasterConfig $config, string $configPath, string $group = ''): int
    {
        return parent::reload($config, $configPath, $group);
    }
}
