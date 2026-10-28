<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

/**
 * What the workers of a master group run, named by the group's `role` key. Each case is one
 * of the package's runtimes; WorkerRoles turns it into the closure a forked worker calls.
 */
enum WorkerRoleEnum: string
{
    case Http     = 'http';
    case Rabbitmq = 'rabbitmq';
    case Ws       = 'ws';
    case Tasks    = 'tasks';
}
