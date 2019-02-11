<?php

namespace Bref\Test\Runtime;

use Bref\Runtime\LambdaRuntime;
use Bref\Runtime\LambdaRuntimeReuse;
use Bref\Test\Server;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use function GuzzleHttp\Psr7\str;
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

    public function testRuntime()
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

        $r = new LambdaRuntime('localhost:8126');
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

    public function testFailedHandler()
    {
        $this->expectExceptionMessage('Failed to fetch next Lambda invocation: The requested URL returned error: 404 Not Found');
        Server::enqueue([
            new Response( // lambda event
                404,
                [
                    'lambda-runtime-aws-request-id' => 1
                ],
                '{ "Hello": "world!"}'
            ),
        ]);

        $r = new LambdaRuntime('localhost:8126');
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    public function testMissingInvocationId()
    {
        $this->expectExceptionMessage('Failed to determine the Lambda invocation ID');
        Server::enqueue([
            new Response( // lambda event
                200,
                [],
                '{ "Hello": "world!"}'
            ),
        ]);

        $r = new LambdaRuntime('localhost:8126');
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    public function testEmptyBody()
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

        $r = new LambdaRuntime('localhost:8126');
        $r->processNextEvent(
            function ($event) {
                return ['hello' => 'world'];
            }
        );
    }

    public function testErrorOnResponse()
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

        $r = new LambdaRuntime('localhost:8126');
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
        $this->expectOutputRegex('/^Fatal error: Uncaught Exception: Error while calling the Lambda runtime API: The requested URL returned error: 400 Bad Request/');
        $this->assertSame('Error while calling the Lambda runtime API: The requested URL returned error: 400 Bad Request', $error->errorMessage);
    }

    public function testInvalidResponse()
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

        $r = new LambdaRuntime('localhost:8126');
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

    const MAX_EVENTS = 10;

    public function testPerf()
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

        $r = new LambdaRuntime('localhost:8126');

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
        var_dump("#events: " . $maxEvents . " time: " . ($end - $start) . " handle: init");
    }

    public function testPerfNew()
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

        $r = new LambdaRuntimeReuse('localhost:8126');

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
        var_dump("#events: " . $maxEvents . " time: " . ($end - $start) . " handle: reuse");
    }
}
