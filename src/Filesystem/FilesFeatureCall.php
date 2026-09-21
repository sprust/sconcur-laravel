<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

use Closure;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileStoppedException;
use SConcur\Exceptions\Files\FileTimeoutException;
use SConcur\Laravel\Support\Coroutine;

/**
 * Runs a file operation on the Files feature inside a coroutine and natively everywhere
 * else, keeping the native contract either way.
 *
 * Outside a coroutine the feature would only add a boundary crossing to the same work, so
 * the native call runs. Inside one the feature runs, and a failure of it hands the call
 * to the native implementation: that is what answers the failure the way callers have
 * always seen it — the `false`, the warning Laravel turns into an ErrorException, the
 * Flysystem exception with the native message — rather than a Files exception nobody
 * catches. A failing operation is rare and is paid for twice; a mismatched error is paid
 * for by every caller.
 *
 * Two failures are not handed over: a stop (the coroutine is being unwound, and a native
 * retry would hold it) and a deadline the application set on purpose.
 */
class FilesFeatureCall
{
    /**
     * @template TResult
     *
     * @param Closure(): TResult $feature
     * @param Closure(): TResult $native
     *
     * @return TResult
     */
    public static function run(Closure $feature, Closure $native): mixed
    {
        if (!Coroutine::isActive()) {
            return $native();
        }

        try {
            return $feature();
        } catch (FileStoppedException|FileTimeoutException $exception) {
            throw $exception;
        } catch (FilesException) {
            return $native();
        }
    }
}
