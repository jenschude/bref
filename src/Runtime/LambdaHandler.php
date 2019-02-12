<?php

namespace Bref\Runtime;

interface LambdaHandler
{
    /**
     * Wait for the next lambda invocation and retrieve its data.
     *
     * This call is blocking because the Lambda runtime API is blocking.
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-next
     * @param string $url
     * @return array
     * @throws \Exception
     */
    public function waitNextInvocation(string $url): array;

    /**
     * @param string $url
     * @param mixed $data
     * @throws \Exception
     */
    public function postJson(string $url, $data): void;
}
