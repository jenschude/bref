<?php

namespace Bref\Test\Runtime;

use Bref\Runtime\CurlHandler;
use Bref\Runtime\CurlReuseHandler;
use Bref\Runtime\LambdaHandler;
use Bref\Runtime\LambdaRuntime;
use Bref\Runtime\GuzzleHandler;
use Bref\Test\Server;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class LambdaRuntimeTest extends TestCase
{
    protected function setUp()
    {
        Server::start();
    }

    protected function tearDown()
    {
        Server::stop();
    }

    const MAX_EVENTS = 10;

    public function getHandlers()
    {
        return [
            'default' => [null],
            CurlHandler::class => [new CurlHandler()],
            CurlReuseHandler::class => [new CurlReuseHandler()],
            GuzzleHandler::class => [new GuzzleHandler()],
        ];
    }

    /**
     * @dataProvider getHandlers
     */
    public function testRuntime(LambdaHandler $handler = null)
    {
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1
                ],
                '{ "Hello": "world!"}'
            ),
            new Response(200) // lambda response accepted
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
        $requests = Server::received();
        $this->assertCount(2, $requests);

        /** @var Request $eventRequest */
        $eventRequest = $requests[0];
        /** @var Request $eventResponse */
        $eventResponse = $requests[1];
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventResponse->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/response', $eventResponse->getUri()->__toString());
        $this->assertJsonStringEqualsJsonString('{"hello": "world"}', $eventResponse->getBody()->__toString());
    }

    /**
     * @dataProvider getHandlers
     */
    public function testFailedHandler(LambdaHandler $handler = null)
    {
        $this->expectExceptionMessageRegExp('/Failed to fetch next Lambda invocation: .*404 Not Found/');
        Server::enqueue([
            new Response( // lambda event
                404,
                [
                    'lambda-runtime-aws-request-id' => 1
                ],
                '{ "Hello": "world!"}'
            ),
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    /**
     * @dataProvider getHandlers
     */
    public function testMissingInvocationId(LambdaHandler $handler = null)
    {
        $this->expectExceptionMessage('Failed to determine the Lambda invocation ID');
        Server::enqueue([
            new Response( // lambda event
                200,
                [],
                '{ "Hello": "world!"}'
            ),
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    /**
     * @dataProvider getHandlers
     */
    public function testEmptyBody(LambdaHandler $handler = null)
    {
        $this->expectExceptionMessage('Empty Lambda runtime API response');
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1
                ]
            ),
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    /**
     * @dataProvider getHandlers
     */
    public function testErrorOnResponse(LambdaHandler $handler = null)
    {
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1
                ],
                '{ "Hello": "world!"}'
            ),
            new Response(400),
            new Response(200)
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return $event;
            }
        );
        $requests = Server::received();
        $this->assertCount(3, $requests);

        /** @var Request $eventRequest */
        $eventRequest = $requests[0];
        /** @var Request $eventFailureResponse */
        $eventFailureResponse = $requests[1];
        /** @var Request $eventFailureLog */
        $eventFailureLog = $requests[2];
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventFailureResponse->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/response', $eventFailureResponse->getUri()->__toString());
        $this->assertSame('POST', $eventFailureLog->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/error', $eventFailureLog->getUri()->__toString());

        $error = json_decode($eventFailureLog->getBody());
        $this->expectOutputRegex('/^Fatal error: Uncaught Exception: Error while calling the Lambda runtime API: .*400 Bad Request/');
        $this->assertStringStartsWith('Error while calling the Lambda runtime API: ', $error->errorMessage);
        $this->assertContains('400 Bad Request', $error->errorMessage);
    }

    /**
     * @dataProvider getHandlers
     */
    public function testInvalidResponse(LambdaHandler $handler = null)
    {
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1
                ],
                '{ "Hello": "world!"}'
            ),
            new Response(200)
        ]);

        $r = new LambdaRuntime('localhost:8126', $handler);
        $r->processNextEvent(
            function ($event) {
                return "\xB1\x31";
            }
        );
        $requests = Server::received();
        $this->assertCount(2, $requests);

        /** @var Request $eventRequest */
        $eventRequest = $requests[0];
        /** @var Request $eventFailureLog */
        $eventFailureLog = $requests[1];
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventFailureLog->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/error', $eventFailureLog->getUri()->__toString());

        $error = json_decode($eventFailureLog->getBody());
        $this->expectOutputRegex('/^Fatal error: Uncaught Exception: Failed encoding Lambda JSON response: Malformed UTF-8 characters, possibly incorrectly encoded/');
        $this->assertSame('Failed encoding Lambda JSON response: Malformed UTF-8 characters, possibly incorrectly encoded', $error->errorMessage);
    }

    /**
     * @dataProvider getHandlers
     */
    public function testPerf(LambdaHandler $handler = null)
    {
        $maxEvents = self::MAX_EVENTS;
        $responses = [];
        for ($i = 1; $i <= $maxEvents; $i++) {
            $responses[] = new Response( // lambda event
                    200,
                    [
                        'lambda-runtime-aws-request-id' => $i
                    ],
                    "{ \"i\": \"$i\"}"
            );
            $responses[] = new Response(200);
        }
        Server::enqueue($responses);

        $r = new LambdaRuntime('localhost:8126', $handler);

        $start = microtime(true);
        for ($i = 1; $i <= $maxEvents; $i++) {
            $r->processNextEvent(
                function ($event) {
                    return ['n' => $event['i']];
                }
            );
        }
        $end = microtime(true);

        $requests = Server::received();
        for ($i = 0; $i < $maxEvents; $i++) {
            /** @var Request $eventRequest */
            $eventRequest = $requests[$i * 2];
            /** @var Request $eventResponse */
            $eventResponse = $requests[($i * 2) + 1];
            $this->assertSame('GET', $eventRequest->getMethod());
            $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
            $this->assertSame('POST', $eventResponse->getMethod());
            $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/' . ($i+1) . '/response', $eventResponse->getUri()->__toString());
            $this->assertJsonStringEqualsJsonString('{"n": "' . ($i+1) . '"}', $eventResponse->getBody()->__toString());
        }
        echo "#events: " . $maxEvents . " time: " . ($end - $start) . " handler: " . (!is_null($handler) ? get_class($handler) : 'default') . PHP_EOL;
    }
}
