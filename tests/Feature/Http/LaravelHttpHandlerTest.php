<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Http;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Laminas\Diactoros\ResponseFactory;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Context\Context;
use SConcur\Laravel\Foundation\ScopedService;
use SConcur\Laravel\Http\LaravelHttpHandler;
use SConcur\Laravel\Tests\Feature\Concurrency\BaseConcurrencyTestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The bridge between what the extension's HTTP server hands over (PSR-7) and what
 * Laravel answers with. It is what every request in the HTTP pool goes through, and it
 * needs no server to exercise: the handler takes a PSR-7 request and gives a PSR-7
 * response.
 */
class LaravelHttpHandlerTest extends BaseConcurrencyTestCase
{
    #[Test]
    public function itAnswersARouteThroughTheKernel(): void
    {
        $response = ($this->handler())(new ServerRequest([], [], '/workbench/ping', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertTrue($body['ok']);
    }

    #[Test]
    public function anUnknownPathComesBackAs404(): void
    {
        $response = ($this->handler())(new ServerRequest([], [], '/workbench/nothing-here', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function responseHeadersSurviveTheBridge(): void
    {
        $response = ($this->handler())(new ServerRequest([], [], '/workbench/ping', 'GET'));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * The handler publishes the request into the coroutine's context — that is how
     * AsyncApplication resolves 'request' per fiber instead of from a container binding
     * every request would share.
     */
    #[Test]
    public function itPublishesTheRequestIntoTheCoroutineContext(): void
    {
        $handler = $this->handler();

        $seen = [];

        $this->interleave([
            function () use ($handler, &$seen): void {
                $handler(new ServerRequest([], [], '/workbench/ping?who=first', 'GET'));

                $request = Context::current()->find(ScopedService::REQUEST->value);

                $seen['first'] = $request?->getRequestUri();
            },
            function () use ($handler, &$seen): void {
                $handler(new ServerRequest([], [], '/workbench/ping?who=second', 'GET'));

                $request = Context::current()->find(ScopedService::REQUEST->value);

                $seen['second'] = $request?->getRequestUri();
            },
        ]);

        self::assertSame(
            [
                'first'  => '/workbench/ping?who=first',
                'second' => '/workbench/ping?who=second',
            ],
            $seen,
        );
    }

    protected function handler(): LaravelHttpHandler
    {
        return new LaravelHttpHandler(
            $this->getApp(),
            new HttpFoundationFactory(),
            new PsrHttpFactory(
                new ServerRequestFactory(),
                new StreamFactory(),
                new UploadedFileFactory(),
                new ResponseFactory(),
            ),
        );
    }
}
