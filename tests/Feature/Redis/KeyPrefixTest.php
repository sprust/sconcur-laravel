<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SConcur\Laravel\Redis\KeyPrefix;

/**
 * Where the prefix goes, command by command — the layouts PhpRedisParityTest does not reach
 * through the facade, and phpredis's quirks, each as phpredis 6.3.0 sends it (`MONITOR`).
 */
class KeyPrefixTest extends TestCase
{
    /**
     * @param list<mixed> $arguments
     * @param list<mixed> $expected
     */
    #[Test]
    #[DataProvider('commands')]
    public function thePrefixGoesWherePhpRedisPutsIt(string $command, array $arguments, array $expected): void
    {
        self::assertSame(
            $expected,
            KeyPrefix::apply(
                command: $command,
                arguments: $arguments,
                prefix: 'P_',
            ),
        );
    }

    /**
     * @return iterable<string, array{string, list<mixed>, list<mixed>}>
     */
    public static function commands(): iterable
    {
        yield 'first' => ['get', ['k'], ['P_k']];
        yield 'an integer key' => ['GET', [5], ['P_5']];
        yield 'all' => ['DEL', ['a', 'b'], ['P_a', 'P_b']];
        yield 'first two' => ['LMOVE', ['a', 'b', 'LEFT', 'RIGHT'], ['P_a', 'P_b', 'LEFT', 'RIGHT']];
        yield 'all but the timeout' => ['BLPOP', ['a', 'b', 1], ['P_a', 'P_b', 1]];
        yield 'pairs' => ['MSET', ['a', '1', 'b', '2'], ['P_a', '1', 'P_b', '2']];
        yield 'after the operation' => ['BITOP', ['AND', 'd', 'a', 'b'], ['AND', 'P_d', 'P_a', 'P_b']];
        yield 'numkeys first' => ['LMPOP', [2, 'a', 'b', 'LEFT'], [2, 'P_a', 'P_b', 'LEFT']];
        yield 'numkeys after the timeout' => ['BZMPOP', [1, 1, 'z', 'MIN'], [1, 1, 'P_z', 'MIN']];
        yield 'eval' => ['EVAL', ['script', 2, 'a', 'b', 'arg'], ['script', 2, 'P_a', 'P_b', 'arg']];
        yield 'eval without keys' => ['EVAL', ['script', 0, 'arg'], ['script', 0, 'arg']];
        yield 'fcall' => ['FCALL', ['f', 1, 'k', 'a'], ['f', 1, 'P_k', 'a']];
        yield 'destination and numkeys' => ['ZINTERSTORE', ['o', 2, 'a', 'b', 'WEIGHTS', 1, 2], ['P_o', 2, 'P_a', 'P_b', 'WEIGHTS', 1, 2]];
        yield 'after the subcommand' => ['OBJECT', ['encoding', 'k'], ['encoding', 'P_k']];
        yield 'xgroup' => ['XGROUP', ['CREATE', 'x', 'g', '0'], ['CREATE', 'P_x', 'g', '0']];
        yield 'xreadgroup' => ['XREADGROUP', ['GROUP', 'g', 'c', 'COUNT', 1, 'STREAMS', 'x', 'y', '>', '>'], ['GROUP', 'g', 'c', 'COUNT', 1, 'STREAMS', 'P_x', 'P_y', '>', '>']];
        yield 'georadius store' => ['GEORADIUS', ['g', 15, 37, 200, 'km', 'STORE', 's'], ['P_g', 15, 37, 200, 'km', 'STORE', 'P_s']];
        yield 'georadiusbymember storedist' => ['GEORADIUSBYMEMBER', ['g', 'p', 10, 'km', 'STOREDIST', 'd'], ['P_g', 'p', 10, 'km', 'STOREDIST', 'P_d']];
        yield 'migrate one key' => ['MIGRATE', ['h', 1, 'k', 0, 1], ['h', 1, 'P_k', 0, 1]];
        yield 'migrate keys' => ['MIGRATE', ['h', 1, '', 0, 1, 'KEYS', 'a', 'b'], ['h', 1, '', 0, 1, 'KEYS', 'P_a', 'P_b']];
        yield 'pubsub numsub' => ['PUBSUB', ['NUMSUB', 'a', 'b'], ['NUMSUB', 'P_a', 'P_b']];
        yield 'keys pattern' => ['KEYS', ['*'], ['P_*']];
        yield 'publish' => ['PUBLISH', ['chan', 'm'], ['P_chan', 'm']];
        yield 'sort only its key' => ['SORT', ['l', 'BY', 'w_*', 'GET', 'o_*', 'STORE', 's'], ['P_l', 'BY', 'w_*', 'GET', 'o_*', 'STORE', 's']];
        yield 'scan match untouched' => ['SCAN', ['0', 'MATCH', 'x*'], ['0', 'MATCH', 'x*']];
        yield 'pubsub channels untouched' => ['PUBSUB', ['CHANNELS', 'c*'], ['CHANNELS', 'c*']];
        yield 'an unknown command untouched' => ['FT.SEARCH', ['idx', 'q'], ['idx', 'q']];
        yield 'no keys' => ['PING', ['hi'], ['hi']];
    }

    #[Test]
    public function anEmptyPrefixChangesNothing(): void
    {
        self::assertSame(
            ['k', 'v'],
            KeyPrefix::apply(
                command: 'SET',
                arguments: ['k', 'v'],
                prefix: '',
            ),
        );
    }
}
