<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Http;

use Generator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Http\IterableResponseBody;
use SConcur\Laravel\Http\LaravelHttpHandler;
use SConcur\Laravel\Support\Coroutine;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\MeasuresStallsTrait;
use SConcur\WaitGroup;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

/**
 * A StreamedResponse built from chunks — Symfony's `new StreamedResponse($iterable)`,
 * Laravel's `response()->stream(fn () => yield …)`, a generator callback — is read chunk by
 * chunk in the request coroutine: every chunk is one read, with or without a wait between.
 * One whose callback prints is sent whole, the way every other response is.
 */
class StreamedResponseTest extends BaseTestCase
{
    use MeasuresStallsTrait;

    private const int COROUTINE_TIMEOUT_MS = 30_000;

    /** @var list<string> */
    private static array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$events = [];

        Route::get('workbench/chunks', static function (Request $request): StreamedResponse {
            $chunks     = (int) $request->query('chunks', '3');
            $pauseUs    = (int) $request->query('pauseUs', '1000');
            $chunkBytes = (int) $request->query('chunkBytes', '0');
            $failAfter  = (int) $request->query('failAfter', '0');

            self::recordTermination();

            $controllerRequestId = spl_object_id(app('request'));
            $controllerAuthId    = spl_object_id(app('auth'));

            $generator = (static function () use (
                $chunks,
                $pauseUs,
                $chunkBytes,
                $failAfter,
                $controllerRequestId,
                $controllerAuthId,
            ): Generator {
                self::$events[] = sprintf(
                    'generator who=%s sameRequest=%d sameAuth=%d active=%d',
                    request()->string('who')->toString(),
                    (int) (spl_object_id(app('request')) === $controllerRequestId),
                    (int) (spl_object_id(app('auth')) === $controllerAuthId),
                    (int) Coroutine::isActive(),
                );

                try {
                    for ($index = 0; $index < $chunks; ++$index) {
                        if ($failAfter > 0 && $index === $failAfter) {
                            throw new RuntimeException('generator failed');
                        }

                        yield $chunkBytes > 0 ? str_repeat('x', $chunkBytes) : "chunk-$index;";

                        Sleeper::usleep($pauseUs);
                    }

                    self::$events[] = 'generator finished';
                } finally {
                    self::$events[] = 'generator finally';
                }
            })();

            return new StreamedResponse(
                $generator,
                200,
                [
                    'Content-Type'   => 'text/plain',
                    'Content-Length' => '1',
                    'X-Streamed'     => 'yes',
                ],
            );
        });

        Route::get('workbench/chunks-array', static fn(): StreamedResponse => new StreamedResponse(['a', 'b', 'c']));

        Route::get('workbench/chunks-laravel', static function (): StreamedResponse {
            self::recordTermination();

            return response()->stream(static function (): Generator {
                yield 'a';

                Sleeper::usleep(1000);

                yield 'b';
            });
        });

        Route::get('workbench/chunks-octane', static fn(): StreamedResponse => new StreamedResponse()->setCallback(
            static function (): Generator {
                yield 'a';
                yield 'b';
            },
        ));

        Route::get('workbench/chunks-subclass', static fn(): StreamedResponse => new class(['a', 'b']) extends StreamedResponse {
        });

        Route::get('workbench/chunks-wait-group', static fn(): StreamedResponse => new StreamedResponse(
            (static function (): Generator {
                yield 'before;';

                $waitGroup = WaitGroup::create();

                $waitGroup->add(static fn() => Sleeper::usleep(1000));

                $waitGroup->waitAll();

                yield 'after;';
            })(),
        ));

        Route::get('workbench/printing', static function (): StreamedResponse {
            self::recordTermination();

            return new StreamedResponse(static function (): void {
                echo 'a';

                self::$events[] = 'printed';

                echo 'b';
            });
        });

        Route::get('workbench/plain', static fn(): JsonResponse => response()->json(['ok' => true]));

        Route::get('workbench/chunks-not-string', static fn(): StreamedResponse => new StreamedResponse(
            // @phpstan-ignore argument.type (a chunk that is not a string is the point of the route)
            (static function (): Generator {
                yield 'a';
                yield ['not', 'a', 'string'];
            })(),
        ));
    }

    #[Test]
    public function everyChunkArrivesAsItIsYielded(): void
    {
        $chunks = [];

        $response = null;

        $this->inCoroutine(function () use (&$chunks, &$response): void {
            $response = $this->handle('/workbench/chunks', ['chunks' => '1000', 'pauseUs' => '500']);

            $body = $response->getBody();

            while (($chunk = $body->read(65_536)) !== '') {
                if ($chunks === []) {
                    self::$events[] = 'first chunk read';
                }

                $chunks[] = $chunk;
            }
        });

        assert($response instanceof ResponseInterface);

        self::assertCount(1000, $chunks);
        self::assertSame('chunk-0;', $chunks[0]);
        self::assertInstanceOf(IterableResponseBody::class, $response->getBody());
        self::assertNull($response->getBody()->getSize());
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame('yes', $response->getHeaderLine('X-Streamed'));
        self::assertSame(
            [
                'generator who= sameRequest=1 sameAuth=1 active=1',
                'first chunk read',
                'generator finished',
                'generator finally',
                'terminated',
            ],
            self::$events,
        );
    }

    #[Test]
    public function chunksWithNoWaitBetweenThemAreSeparateReads(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->readChunks('/workbench/chunks-array'));
    }

    #[Test]
    public function laravelsGeneratorStreamIsReadChunkByChunk(): void
    {
        self::assertSame(['a', 'b'], $this->readChunks('/workbench/chunks-laravel'));
        self::assertSame(['terminated'], self::$events);
    }

    #[Test]
    public function aGeneratorCallbackIsReadChunkByChunk(): void
    {
        self::assertSame(['a', 'b'], $this->readChunks('/workbench/chunks-octane'));
    }

    #[Test]
    public function aSubclassBuiltFromChunksIsReadChunkByChunk(): void
    {
        self::assertSame(['a', 'b'], $this->readChunks('/workbench/chunks-subclass'));
    }

    #[Test]
    public function theBodyIsNotHeldWhileItIsRead(): void
    {
        $peakGrowthBytes = 0;

        $this->inCoroutine(function () use (&$peakGrowthBytes): void {
            $body = $this->handle('/workbench/chunks', [
                'chunks'     => '1000',
                'pauseUs'    => '100',
                'chunkBytes' => '65536',
            ])->getBody();

            $baselineBytes = memory_get_usage();
            $totalBytes    = 0;

            while (($chunk = $body->read(65_536)) !== '') {
                $totalBytes += strlen($chunk);

                $peakGrowthBytes = max($peakGrowthBytes, memory_get_usage() - $baselineBytes);
            }

            self::assertSame(1000 * 65_536, $totalBytes);
        });

        // 64 MB went through; what stays in memory at a time is about one chunk.
        self::assertLessThan(4 * 1024 * 1024, $peakGrowthBytes);
    }

    #[Test]
    public function concurrentGeneratorsSeeTheirOwnRequest(): void
    {
        $bodies = [];

        $waitGroup = WaitGroup::create();

        foreach (['first', 'second'] as $who) {
            $waitGroup->add(
                callback: function () use ($who, &$bodies): void {
                    $bodies[$who] = (string) $this->handle('/workbench/chunks', ['who' => $who])->getBody();
                },
                timeoutMs: self::COROUTINE_TIMEOUT_MS,
            );
        }

        $waitGroup->waitAll();

        self::assertSame('chunk-0;chunk-1;chunk-2;', $bodies['first']);
        self::assertSame('chunk-0;chunk-1;chunk-2;', $bodies['second']);
        self::assertContains('generator who=first sameRequest=1 sameAuth=1 active=1', self::$events);
        self::assertContains('generator who=second sameRequest=1 sameAuth=1 active=1', self::$events);
    }

    #[Test]
    public function aWaitingGeneratorDoesNotHoldTheProcess(): void
    {
        $longestStallMs = $this->longestStallMs([
            function (): void {
                (string) $this->handle('/workbench/chunks', ['chunks' => '5', 'pauseUs' => '50000'])->getBody();
            },
        ]);

        self::assertLessThan(40.0, $longestStallMs);
    }

    /**
     * The generator runs in the request coroutine itself, so a WaitGroup waited on inside it
     * is waited on the way it is anywhere else.
     */
    #[Test]
    public function aWaitGroupCanBeWaitedOnInsideTheGenerator(): void
    {
        self::assertSame(['before;', 'after;'], $this->readChunks('/workbench/chunks-wait-group'));
    }

    #[Test]
    public function aFailingGeneratorFailsTheReadIsReportedAndStillTerminates(): void
    {
        $read      = '';
        $exception = null;

        $exceptionHandler = $this->getApp()->make(ExceptionHandler::class);

        assert($exceptionHandler instanceof Handler);

        $exceptionHandler->reportable(static function (RuntimeException $reported): void {
            self::$events[] = 'reported: ' . $reported->getMessage();
        });

        $this->inCoroutine(function () use (&$read, &$exception): void {
            $body = $this->handle('/workbench/chunks', ['chunks' => '5', 'failAfter' => '2'])->getBody();

            try {
                while (($chunk = $body->read(65_536)) !== '') {
                    $read .= $chunk;
                }
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
        });

        self::assertSame('chunk-0;chunk-1;', $read);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('generator failed', $exception->getMessage());
        self::assertContains('reported: generator failed', self::$events);
        self::assertContains('terminated', self::$events);
    }

    #[Test]
    public function aChunkThatIsNotAStringFailsTheRead(): void
    {
        $read      = '';
        $exception = null;

        $this->inCoroutine(function () use (&$read, &$exception): void {
            $body = $this->handle('/workbench/chunks-not-string')->getBody();

            try {
                while (($chunk = $body->read(65_536)) !== '') {
                    $read .= $chunk;
                }
            } catch (UnexpectedValueException $caught) {
                $exception = $caught;
            }
        });

        self::assertSame('a', $read);
        self::assertInstanceOf(UnexpectedValueException::class, $exception);
    }

    #[Test]
    public function aBodyLeftUnreadStillTerminates(): void
    {
        $this->inCoroutine(function (): void {
            $response = $this->handle('/workbench/chunks', ['chunks' => '5']);

            self::assertSame('chunk-0;', $response->getBody()->read(65_536));

            unset($response);
        });

        // The response holds the generator too, so its finally blocks run once the response
        // is released, which may come after the termination.
        self::assertNotContains('generator finished', self::$events);
        self::assertContains('generator finally', self::$events);
        self::assertContains('terminated', self::$events);
    }

    #[Test]
    public function outsideACoroutineTheChunksAreReadTheSameWay(): void
    {
        $body = $this->handle('/workbench/chunks')->getBody();

        self::assertSame('chunk-0;', $body->read(65_536));
        self::assertSame('chunk-1;', $body->read(65_536));
        self::assertSame('chunk-2;', $body->read(65_536));
        self::assertSame('', $body->read(65_536));
        self::assertSame('terminated', end(self::$events));
    }

    /**
     * A callback that prints has nowhere to be cut: it runs to its end before the response
     * is handed over, and the kernel is terminated after it, as for any other response.
     */
    #[Test]
    public function aCallbackThatPrintsIsSentWhole(): void
    {
        $response = $this->handle('/workbench/printing');

        self::assertNotInstanceOf(IterableResponseBody::class, $response->getBody());
        self::assertSame(2, $response->getBody()->getSize());
        self::assertSame('ab', (string) $response->getBody());
        self::assertSame(['printed', 'terminated'], self::$events);
    }

    #[Test]
    public function anOrdinaryResponseKeepsItsSize(): void
    {
        $response = $this->handle('/workbench/plain');

        self::assertSame('{"ok":true}', (string) $response->getBody());
        self::assertSame(11, $response->getBody()->getSize());
    }

    /**
     * @param array<string, string> $query
     */
    private function handle(string $path, array $query = []): ResponseInterface
    {
        $handler = new LaravelHttpHandler(
            app: $this->getApp(),
            httpFoundationFactory: new HttpFoundationFactory(),
            psrHttpFactory: new PsrHttpFactory(
                serverRequestFactory: new ServerRequestFactory(),
                streamFactory: new StreamFactory(),
                uploadedFileFactory: new UploadedFileFactory(),
                responseFactory: new ResponseFactory(),
            ),
        );

        return $handler(new ServerRequest([], [], $path, 'GET')->withQueryParams($query));
    }

    private function inCoroutine(callable $unit): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: $unit(...),
            timeoutMs: self::COROUTINE_TIMEOUT_MS,
        );

        $waitGroup->waitAll();
    }

    /**
     * @return list<string>
     */
    private function readChunks(string $path): array
    {
        $chunks = [];

        $this->inCoroutine(function () use ($path, &$chunks): void {
            $body = $this->handle($path)->getBody();

            self::assertInstanceOf(IterableResponseBody::class, $body);

            while (($chunk = $body->read(65_536)) !== '') {
                $chunks[] = $chunk;
            }
        });

        return $chunks;
    }

    private static function recordTermination(): void
    {
        app()->terminating(static function (): void {
            self::$events[] = 'terminated';
        });
    }
}
