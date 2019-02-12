<?php

namespace Bref\Runtime;

use GuzzleHttp\Client;

class GuzzleHandler implements LambdaHandler
{
    private $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    /**
     * @inheritdoc
     */
    public function waitNextInvocation(string $url): array
    {
        try {
            $response = $this->client->get($url);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to fetch next Lambda invocation: ' . $e->getMessage());
        }

        $invocationId = current($response->getHeader('lambda-runtime-aws-request-id'));
        if ($invocationId == '') {
            throw new \Exception('Failed to determine the Lambda invocation ID');
        }
        $body = (string)$response->getBody();
        if ( $body === '') {
            throw new \Exception('Empty Lambda runtime API response');
        }

        $event = json_decode($body, true);

        return [$invocationId, $event];
    }

    /**
     * @inheritdoc
     */
    public function postJson(string $url, $data): void
    {
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new \Exception('Failed encoding Lambda JSON response: ' . json_last_error_msg());
        }
        try {
            $this->client->post($url, ['body' => $jsonData]);
        } catch (\Throwable $e) {
            throw new \Exception('Error while calling the Lambda runtime API: ' . $e->getMessage());
        }
    }
}
