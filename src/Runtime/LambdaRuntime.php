<?php declare(strict_types=1);

namespace Bref\Runtime;

/**
 * Client for the AWS Lambda runtime API.
 *
 * This allows to interact with the API and:
 *
 * - fetch events to process
 * - signal errors
 * - send invocation responses
 *
 * It is intentionally dependency-free to keep cold starts as low as possible.
 *
 * Usage example:
 *
 *     $lambdaRuntime = LambdaRuntime::fromEnvironmentVariable();
 *     $lambdaRuntime->processNextEvent(function ($event) {
 *         return <response>;
 *     });
 */
class LambdaRuntime
{
    /** @var string */
    private $apiUrl;

    /**
     * @var LambdaHandler
     */
    private $handler;

    public static function fromEnvironmentVariable(): self
    {
        return new self(getenv('AWS_LAMBDA_RUNTIME_API'));
    }

    public function __construct(string $apiUrl, LambdaHandler $handler = null)
    {
        if ($apiUrl === '') {
            die('At the moment lambdas can only be executed in an Lambda environment');
        }

        $this->apiUrl = $apiUrl;
        if (is_null($handler)) {
            $handler = new CurlHandler();
        }
        $this->handler = $handler;
    }

    /**
     * Process the next event.
     *
     * @param callable $handler This callable takes a $event parameter (array) and must return anything serializable to JSON.
     *
     * Example:
     *
     *     $lambdaRuntime->processNextEvent(function (array $event) {
     *         return 'Hello ' . $event['name'];
     *     });
     * @throws \Exception
     */
    public function processNextEvent(callable $handler): void
    {
        [$invocationId, $event] = $this->handler->waitNextInvocation(
            "http://{$this->apiUrl}/2018-06-01/runtime/invocation/next"
        );

        try {
            $this->sendResponse($invocationId, $handler($event));
        } catch (\Throwable $e) {
            $this->signalFailure($invocationId, $e);
        }
    }

    /**
     * @param mixed $responseData
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-response
     * @throws \Exception
     */
    private function sendResponse(string $invocationId, $responseData): void
    {
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/response";
        $this->handler->postJson($url, $responseData);
    }

    /**
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-invokeerror
     * @throws \Exception
     */
    private function signalFailure(string $invocationId, \Throwable $error): void
    {
        if ($error instanceof \Exception) {
            $errorMessage = 'Uncaught ' . get_class($error) . ': ' . $error->getMessage();
        } else {
            $errorMessage = $error->getMessage();
        }

        // Log the exception in CloudWatch
        printf(
            "Fatal error: %s in %s:%d\nStack trace:\n%s",
            $errorMessage,
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString()
        );

        // Send an "error" Lambda response
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/error";
        $this->handler->postJson($url, [
            'errorMessage' => $error->getMessage(),
            'errorType' => get_class($error),
            'stackTrace' => explode(PHP_EOL, $error->getTraceAsString()),
        ]);
    }
}
