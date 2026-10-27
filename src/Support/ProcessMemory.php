<?php

declare(strict_types=1);

namespace SConcur\Laravel\Support;

/**
 * What the whole process holds in memory, as the kernel counts it.
 *
 * memory_get_usage() sees only the PHP heap. The extension allocates outside it — its
 * runtime, its drivers, their buffers — so a leak there never shows in the heap, and a
 * limit compared against the heap alone never trips. The resident set size covers both.
 *
 * The resident set size counts a page shared with other processes whole in each of them:
 * a worker forked from the master carries the application the master loaded, and that
 * copy shows in the RSS of every worker. The proportional share divides such a page among
 * the processes mapping it, so it sums across a pool to the memory the pool takes.
 */
class ProcessMemory
{
    /** The resident set size of this process, or 0 where /proc is not available. */
    public function rssBytes(): int
    {
        return $this->kibFieldBytes(
            contents: (string) @file_get_contents('/proc/self/status'),
            field: 'VmRSS',
        );
    }

    /**
     * The proportional share of the resident memory (Pss) and of swap (SwapPss) of this
     * process, from one read of /proc/self/smaps_rollup; 0 each where it is not available.
     * The kernel walks the page tables to answer, so it is read every few seconds rather
     * than on every tick.
     *
     * @return array{pssBytes: int, swapPssBytes: int}
     */
    public function proportionalShareBytes(): array
    {
        $rollup = (string) @file_get_contents('/proc/self/smaps_rollup');

        return [
            'pssBytes'     => $this->kibFieldBytes(contents: $rollup, field: 'Pss'),
            'swapPssBytes' => $this->kibFieldBytes(contents: $rollup, field: 'SwapPss'),
        ];
    }

    /**
     * The value of the "<field>: <n> kB" line of a /proc file, in bytes, or 0 when it is
     * missing. The colon is part of the match, so Pss does not take the Pss_Anon line.
     */
    protected function kibFieldBytes(string $contents, string $field): int
    {
        if (preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+)\s+kB/m', $contents, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1] * 1024;
    }
}
