<?php

declare(strict_types=1);

namespace SConcur\Laravel\Support;

/**
 * What the whole process holds in memory, as the kernel counts it.
 *
 * memory_get_usage() sees only the PHP heap. The extension allocates outside it — its
 * runtime, its drivers, their buffers — so a leak there never shows in the heap, and a
 * limit compared against the heap alone never trips. The resident set size covers both.
 */
class ProcessMemory
{
    /** The resident set size of this process, or 0 where /proc is not available. */
    public function rssBytes(): int
    {
        $status = @file_get_contents('/proc/self/status');

        if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1] * 1024;
    }
}
