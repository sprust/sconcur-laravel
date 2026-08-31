<?php

declare(strict_types=1);

namespace SConcur\Laravel\Foundation;

/**
 * Container aliases resolved per-coroutine.
 *
 * NOTE: 'db' is intentionally absent. DatabaseServiceProvider::boot() sets
 * Model::setConnectionResolver($app['db']) as a static; a scoped DatabaseManager
 * would be GC'd after the coroutine, leaving Model::$resolver dangling. Physical
 * connection isolation is a separate task — see docs §6.4.
 *
 * Ported from yangusik/laravel-spawn (ScopedService).
 */
enum ScopedService: string
{
    case REQUEST     = 'request';
    case SESSION     = 'session';
    case AUTH        = 'auth';
    case AUTH_DRIVER = 'auth.driver';
    case COOKIE      = 'cookie';
}
