<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Illuminate\Redis\Connections\Connection as BaseConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Redis\Connection;
use SConcur\Laravel\Redis\Connector;

/**
 * The same call through Laravel's PhpRedisConnection and through the sconcur connection,
 * against the same server, must answer the same.
 *
 * phpredis is Laravel's default client and the one code written against `Redis::` was
 * written against, so it is the reference: its answer is the expected one, whatever it is.
 * The calls cover the methods PhpRedisConnection overrides, phpredis's own signatures with
 * an options array, reply shapes phpredis reworks (status, nil, booleans, floats, maps) and
 * the object a pipeline or transaction callback gets.
 *
 * Every call runs a second time with a key prefix on both connections, and then the keys the
 * call left in the database have to match as well: phpredis puts its OPT_PREFIX on the keys
 * of each of its methods, and an application moving over must find its keys under the same
 * names.
 *
 * What differs on purpose is pinned in FacadeTest instead: a refused command throws rather
 * than answering false, the scan family answers `[cursor, items]`, a status an EVAL script
 * returns stays a string.
 *
 * phpredis is installed in the CLI container only; without it the test is skipped.
 */
class PhpRedisParityTest extends BaseRedisTestCase
{
    private const string PREFIX = 'app:';

    /**
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('calls')]
    public function theSconcurConnectionAnswersLikePhpRedis(string $method, array $arguments): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('phpredis is not installed.');
        }

        $expected = $this->callOn(
            connection: $this->phpRedis(),
            method: $method,
            arguments: $arguments,
        );

        $actual = $this->callOn(
            connection: $this->redis(),
            method: $method,
            arguments: $arguments,
        );

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('calls')]
    public function theSconcurConnectionPrefixesLikePhpRedis(string $method, array $arguments): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('phpredis is not installed.');
        }

        $expected = $this->callOn(
            connection: $this->phpRedis(self::PREFIX),
            method: $method,
            arguments: $arguments,
            prefix: self::PREFIX,
        );

        $expectedKeys = $this->keys();

        $actual = $this->callOn(
            connection: $this->prefixedSconcur(),
            method: $method,
            arguments: $arguments,
            prefix: self::PREFIX,
        );

        self::assertSame($expected, $actual);
        self::assertSame($expectedKeys, $this->keys());
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function calls(): iterable
    {
        yield 'get missing' => ['get', ['nope']];
        yield 'get' => ['get', ['str']];
        yield 'set' => ['set', ['k', 'v']];
        yield 'set EX NX' => ['set', ['k', 'v', 'EX', 10, 'NX']];
        yield 'set EX NX existing' => ['set', ['str', 'v', 'EX', 10, 'NX']];
        yield 'setex' => ['setex', ['k', 10, 'v']];
        yield 'psetex' => ['psetex', ['k', 10000, 'v']];
        yield 'setnx' => ['setnx', ['str', 'v']];
        yield 'getset' => ['getset', ['str', 'new']];
        yield 'getset missing' => ['getset', ['nope', 'new']];
        yield 'mget' => ['mget', [['str', 'nope']]];
        yield 'mset' => ['mset', [['a' => '1', 'b' => '2']]];
        yield 'msetnx' => ['msetnx', [['str' => '1', 'z' => '2']]];
        yield 'incr' => ['incr', ['num']];
        yield 'incrby' => ['incrby', ['num', 5]];
        yield 'incrbyfloat' => ['incrbyfloat', ['num', 1.5]];
        yield 'decr' => ['decr', ['num']];
        yield 'decrby' => ['decrby', ['num', 3]];
        yield 'append' => ['append', ['str', 'x']];
        yield 'strlen' => ['strlen', ['str']];
        yield 'getrange' => ['getrange', ['str', 0, 2]];
        yield 'setrange' => ['setrange', ['str', 0, 'V']];
        yield 'exists one' => ['exists', ['str']];
        yield 'exists many' => ['exists', ['str', 'num', 'nope']];
        yield 'del one' => ['del', ['str']];
        yield 'del array' => ['del', [['str', 'num']]];
        yield 'del variadic' => ['del', ['str', 'num', 'nope']];
        yield 'unlink' => ['unlink', ['str']];
        yield 'expire' => ['expire', ['str', 100]];
        yield 'expire missing' => ['expire', ['nope', 100]];
        yield 'pexpire' => ['pexpire', ['str', 100000]];
        yield 'ttl' => ['ttl', ['str']];
        yield 'ttl missing' => ['ttl', ['nope']];
        yield 'pttl' => ['pttl', ['str']];
        yield 'persist' => ['persist', ['str']];
        yield 'rename' => ['rename', ['str', 'str2']];
        yield 'renamenx' => ['renamenx', ['str', 'num']];
        yield 'type string' => ['type', ['str']];
        yield 'type hash' => ['type', ['hash']];
        yield 'type none' => ['type', ['nope']];
        yield 'keys' => ['keys', ['s*']];
        yield 'dbsize' => ['dbsize', []];
        yield 'ping' => ['ping', []];
        yield 'echo' => ['echo', ['hi']];
        yield 'hset' => ['hset', ['hash', 'f3', 'v3']];
        yield 'hset existing' => ['hset', ['hash', 'f1', 'vv']];
        yield 'hget' => ['hget', ['hash', 'f1']];
        yield 'hget missing' => ['hget', ['hash', 'nope']];
        yield 'hgetall' => ['hgetall', ['hash']];
        yield 'hgetall missing' => ['hgetall', ['nope']];
        yield 'hmget variadic' => ['hmget', ['hash', 'f1', 'nope']];
        yield 'hmget array' => ['hmget', ['hash', ['f1', 'nope']]];
        yield 'hmset array' => ['hmset', ['h2', ['a' => '1']]];
        yield 'hmset variadic' => ['hmset', ['h2', 'a', '1', 'b', '2']];
        yield 'hdel' => ['hdel', ['hash', 'f1']];
        yield 'hexists' => ['hexists', ['hash', 'f1']];
        yield 'hexists missing' => ['hexists', ['hash', 'nope']];
        yield 'hincrby' => ['hincrby', ['h3', 'n', 2]];
        yield 'hincrbyfloat' => ['hincrbyfloat', ['h3', 'n', 1.5]];
        yield 'hkeys' => ['hkeys', ['hash']];
        yield 'hvals' => ['hvals', ['hash']];
        yield 'hlen' => ['hlen', ['hash']];
        yield 'hsetnx' => ['hsetnx', ['hash', 'f1', 'x']];
        yield 'hstrlen' => ['hstrlen', ['hash', 'f1']];
        yield 'lpush' => ['lpush', ['list', 'z']];
        yield 'rpush variadic' => ['rpush', ['list', 'y', 'z']];
        yield 'lpop' => ['lpop', ['list']];
        yield 'lpop missing' => ['lpop', ['nope']];
        yield 'rpop' => ['rpop', ['list']];
        yield 'lrange' => ['lrange', ['list', 0, -1]];
        yield 'llen' => ['llen', ['list']];
        yield 'lindex' => ['lindex', ['list', 1]];
        yield 'lindex missing' => ['lindex', ['list', 10]];
        yield 'lset' => ['lset', ['list', 0, 'A']];
        yield 'lrem' => ['lrem', ['list', 1, 'a']];
        yield 'ltrim' => ['ltrim', ['list', 0, 1]];
        yield 'rpoplpush' => ['rpoplpush', ['list', 'list2']];
        yield 'blpop' => ['blpop', ['list', 1]];
        yield 'blpop array' => ['blpop', [['list'], 1]];
        yield 'blpop empty' => ['blpop', ['nope', 1]];
        yield 'brpop' => ['brpop', ['list', 1]];
        yield 'sadd' => ['sadd', ['set', 'z']];
        yield 'sadd variadic' => ['sadd', ['set', 'q', 'w']];
        yield 'srem' => ['srem', ['set', 'x']];
        yield 'smembers' => ['smembers', ['nope']];
        yield 'sismember' => ['sismember', ['set', 'x']];
        yield 'sismember no' => ['sismember', ['set', 'q']];
        yield 'scard' => ['scard', ['set']];
        yield 'spop missing' => ['spop', ['nope']];
        yield 'srandmember missing' => ['srandmember', ['nope']];
        yield 'smove' => ['smove', ['set', 'set2', 'x']];
        yield 'zadd' => ['zadd', ['zset', 4, 'four']];
        yield 'zadd map' => ['zadd', ['zset', ['five' => 5]]];
        yield 'zadd nx' => ['zadd', ['zset', 'NX', 1, 'one']];
        yield 'zrange' => ['zrange', ['zset', 0, -1]];
        yield 'zrange withscores bool' => ['zrange', ['zset', 0, -1, true]];
        yield 'zrange withscores opt' => ['zrange', ['zset', 0, -1, ['withscores' => true]]];
        yield 'zrevrange' => ['zrevrange', ['zset', 0, -1]];
        yield 'zrangebyscore' => ['zrangebyscore', ['zset', 1, 2]];
        yield 'zrangebyscore withscores' => ['zrangebyscore', ['zset', '-inf', '+inf', ['withscores' => true]]];
        yield 'zrangebyscore limit' => ['zrangebyscore', ['zset', '-inf', '+inf', ['limit' => ['offset' => 1, 'count' => 1]]]];
        yield 'zrevrangebyscore' => ['zrevrangebyscore', ['zset', '+inf', '-inf']];
        yield 'zscore' => ['zscore', ['zset', 'two']];
        yield 'zscore missing' => ['zscore', ['zset', 'nope']];
        yield 'zincrby' => ['zincrby', ['zset', 1.5, 'one']];
        yield 'zrem' => ['zrem', ['zset', 'one']];
        yield 'zcard' => ['zcard', ['zset']];
        yield 'zcount' => ['zcount', ['zset', 1, 2]];
        yield 'zrank' => ['zrank', ['zset', 'two']];
        yield 'zrank missing' => ['zrank', ['zset', 'nope']];
        yield 'zrevrank' => ['zrevrank', ['zset', 'two']];
        yield 'zremrangebyscore' => ['zremrangebyscore', ['zset', 1, 2]];
        yield 'zremrangebyrank' => ['zremrangebyrank', ['zset', 0, 0]];
        yield 'zinterstore' => ['zinterstore', ['out', ['zset', 'zset']]];
        yield 'zunionstore weights' => ['zunionstore', ['out', ['zset', 'zset'], ['weights' => [1, 2], 'aggregate' => 'max']]];
        yield 'zpopmin' => ['zpopmin', ['zset']];
        yield 'eval' => ['eval', ["return redis.call('get', KEYS[1])", 1, 'str']];
        yield 'eval nil' => ['eval', ["return redis.call('get', KEYS[1])", 1, 'nope']];
        yield 'eval table' => ['eval', ["return {1, 'a', false}", 0]];
        yield 'evalsha' => ['evalsha', ["return 7", 0]];
        yield 'publish' => ['publish', ['chan', 'msg']];
        yield 'flushdb' => ['flushdb', []];
        yield 'command get' => ['command', ['get', ['str']]];
        yield 'command get missing' => ['command', ['get', ['nope']]];
        yield 'executeRaw' => ['executeRaw', [['GET', 'str']]];
        yield 'pipeline' => ['pipeline', [static function ($pipe) { $pipe->set('a', 1); $pipe->incr('a'); $pipe->get('a'); $pipe->get('nope'); $pipe->hgetall('hash'); }]];
        yield 'transaction' => ['transaction', [static function ($tx) { $tx->set('a', 1); $tx->incr('a'); $tx->get('nope'); $tx->hgetall('hash'); }]];
        yield 'config get' => ['config', ['GET', 'maxmemory']];
        yield 'getdel' => ['getdel', ['str']];
        yield 'getdel missing' => ['getdel', ['nope']];
        yield 'lmove' => ['lmove', ['list', 'l2', 'LEFT', 'RIGHT']];
        yield 'lmove missing' => ['lmove', ['nope', 'l2', 'LEFT', 'RIGHT']];
        yield 'lpos' => ['lpos', ['list', 'b']];
        yield 'lpos missing' => ['lpos', ['list', 'q']];
        yield 'lpop count' => ['lpop', ['list', 2]];
        yield 'spop count' => ['spop', ['set', 5]];
        yield 'srandmember count' => ['srandmember', ['set', 5]];
        yield 'sunion' => ['sunion', ['set', 'nope']];
        yield 'smismember' => ['smismember', ['set', 'x', 'q']];
        yield 'zpopmax' => ['zpopmax', ['zset', 2]];
        yield 'zrangebylex' => ['zrangebylex', ['zset', '-', '+']];
        yield 'zrevrange withscores' => ['zrevrange', ['zset', 0, 1, true]];
        yield 'zmscore' => ['zmscore', ['zset', 'one', 'nope']];
        yield 'zrandmember' => ['zrandmember', ['nope']];
        yield 'hrandfield' => ['hrandfield', ['nope']];
        yield 'setbit' => ['setbit', ['bits', 3, 1]];
        yield 'getbit' => ['getbit', ['bits', 3]];
        yield 'bitcount' => ['bitcount', ['str']];
        yield 'pfadd' => ['pfadd', ['hll', ['a', 'b']]];
        yield 'pfcount' => ['pfcount', ['hll']];
        yield 'xlen' => ['xlen', ['stream']];
        yield 'object encoding' => ['object', ['encoding', 'str']];
        yield 'dump missing' => ['dump', ['nope']];
        yield 'expire NX' => ['expire', ['str', 10, 'NX']];
        yield 'copy' => ['copy', ['str', 'str3']];
        yield 'touch' => ['touch', ['str', 'nope']];
        yield 'ping arg' => ['ping', ['hello']];
        yield 'sort' => ['sort', ['list', ['alpha' => true]]];
        yield 'hincrby missing' => ['hincrby', ['h9', 'n', 1]];
        yield 'zadd incr' => ['zadd', ['zset', 'INCR', 1, 'one']];
        yield 'zadd ch' => ['zadd', ['zset', 'CH', 9, 'one']];
        yield 'script load' => ['script', ['load', 'return 1']];
        yield 'script exists' => ['script', ['exists', 'deadbeef']];
        yield 'executeRaw set' => ['executeRaw', [['SET', 'k', 'v']]];
        yield 'executeRaw missing' => ['executeRaw', [['GET', 'nope']]];
        yield 'command hgetall' => ['command', ['hgetall', ['hash']]];
        yield 'command zrange withscores' => ['command', ['zrange', ['zset', 0, -1, true]]];
        yield 'pipeline native set opts' => ['pipeline', [static function ($p) { $p->set('k', 'v', ['nx', 'ex' => 10]); $p->set('k', 'v', ['nx', 'ex' => 10]); $p->ttl('k'); }]];
        yield 'pipeline set ttl int' => ['pipeline', [static function ($p) { $p->set('k', 'v', 100); $p->ttl('k'); }]];
        yield 'pipeline zadd type info' => ['pipeline', [static function ($p) { $p->zadd('z', 1, 'a'); $p->type('z'); $p->expire('z', 10); $p->zscore('z', 'a'); }]];
        yield 'pipeline eval native' => ['pipeline', [static function ($p) { $p->eval("return ARGV[1]", ['x'], 0); }]];
        yield 'pipeline lrem native' => ['pipeline', [static function ($p) { $p->lrem('list', 'a', 1); }]];
        yield 'transaction hmget' => ['transaction', [static function ($t) { $t->hmget('hash', ['f1', 'nope']); $t->zrange('zset', 0, 0, ['withscores' => true]); }]];
        yield 'sort alpha desc' => ['sort', ['list', ['sort' => 'desc', 'alpha' => true, 'limit' => [0, 2]]]];
        yield 'lpos rank' => ['lpos', ['list', 'b', ['rank' => 1]]];
        yield 'getex ex' => ['getex', ['str', ['EX' => 100]]];
        yield 'copy replace' => ['copy', ['str', 'num', ['replace' => true]]];
        yield 'flushdb async' => ['flushdb', ['ASYNC']];
        yield 'zrevrangebyscore withscores limit' => ['zrevrangebyscore', ['zset', '+inf', '-inf', ['withscores' => true, 'limit' => [0, 2]]]];
        yield 'zinterstore aggregate' => ['zinterstore', ['out', ['zset', 'zset'], ['aggregate' => 'max']]];
        yield 'hset map' => ['hset', ['h5', ['a' => '1', 'b' => '2']]];
        yield 'exists array' => ['exists', [['str', 'num']]];
        yield 'mget assoc keys' => ['mget', [['x' => 'str', 'y' => 'nope']]];
        yield 'blpop multiple keys' => ['blpop', ['nope', 'list', 1]];
        yield 'brpoplpush' => ['brpoplpush', ['list', 'list2', 1]];
        yield 'sinterstore' => ['sinterstore', ['dest', 'set', 'set']];
        yield 'bitop' => ['bitop', ['AND', 'dest', 'str', 'num']];
        yield 'sort by get store' => ['sort', ['list', ['by' => 'weight_*', 'get' => ['#'], 'store' => 'sorted', 'alpha' => true]]];
        yield 'eval keys and argv' => ['eval', ["return {KEYS[1], KEYS[2], ARGV[1]}", 2, 'str', 'num', 'arg']];
        yield 'xread' => ['xread', [['stream' => '0'], 1]];
        yield 'xrange' => ['xrange', ['stream', '-', '+']];
        yield 'renamenx missing' => ['renamenx', ['str', 'fresh']];
        yield 'zunionstore missing' => ['zunionstore', ['out', ['zset', 'nope']]];
        yield 'pipeline keys' => ['pipeline', [static function ($p) { $p->mset(['m1' => 'a', 'm2' => 'b']); $p->rename('m1', 'm3'); $p->mget(['m2', 'm3']); $p->eval("return KEYS[1]", ['m2'], 1); }]];
        yield 'transaction keys' => ['transaction', [static function ($t) { $t->lpush('tl', 'a'); $t->lmove('tl', 'tl2', 'LEFT', 'RIGHT'); $t->exists('tl', 'tl2'); }]];
        yield 'pipeline rawCommand' => ['pipeline', [static function ($p) { $p->rawCommand('SET', 'raw', 'v'); $p->rawCommand('GET', 'str'); }]];
    }

    /**
     * Seeds the database the same way for either connection, under the prefix the call is made
     * with, then makes the call.
     *
     * @param list<mixed> $arguments
     */
    private function callOn(BaseConnection $connection, string $method, array $arguments, string $prefix = ''): mixed
    {
        $this->redis()->client()->flushDb(confirm: true);

        $client = $this->redis()->client();

        $client->set($prefix . 'str', 'value');
        $client->set($prefix . 'num', '10');
        $client->rPush($prefix . 'list', 'a', 'b', 'c');
        $client->sAdd($prefix . 'set', 'x', 'y');
        $client->hSet($prefix . 'hash', ['f1' => 'v1', 'f2' => 'v2']);
        $client->command('ZADD', [$prefix . 'zset', 1, 'one', 2, 'two', 3, 'three']);
        $client->command('XADD', [$prefix . 'stream', '1-0', 'field', 'value']);

        return $connection->{$method}(...$arguments);
    }

    /**
     * The names in the database, as the server holds them.
     *
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = (array) $this->redis()->client()->command('KEYS', ['*']);

        sort($keys);

        return array_map(strval(...), $keys);
    }

    /** A PhpRedisConnection on the database the sconcur `default` connection uses. */
    private function phpRedis(string $prefix = ''): BaseConnection
    {
        $config = (array) config('database.redis.default');

        return (new PhpRedisConnector())->connect(
            [
                'host'     => $config['host'] ?? '127.0.0.1',
                'port'     => $config['port'] ?? 6379,
                'password' => $config['password'] ?? null,
                'database' => $config['database'] ?? 0,
                'prefix'   => $prefix,
            ],
            [],
        );
    }

    /** The sconcur `default` connection with the prefix of the second run. */
    private function prefixedSconcur(): Connection
    {
        return (new Connector())->connect(
            config: [
                ...(array) config('database.redis.default'),
                'prefix' => self::PREFIX,
            ],
            options: [],
        );
    }
}
