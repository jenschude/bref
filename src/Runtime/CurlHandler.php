<?php

namespace Bref\Runtime;

class CurlHandler implements LambdaHandler
{
    /**
     * @inheritdoc
     */
    public function waitNextInvocation(string $url): array
    {
        $handler = curl_init($url);
        curl_setopt($handler, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handler, CURLOPT_FAILONERROR, true);

        // Retrieve invocation ID
        $invocationId = '';
        curl_setopt($handler, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$invocationId) {
            if (! preg_match('/:\s*/', $header)) {
                return strlen($header);
            }
            [$name, $value] = preg_split('/:\s*/', $header, 2);
            if (strtolower($name) === 'lambda-runtime-aws-request-id') {
                $invocationId = trim($value);
            }

            return strlen($header);
        });

        // Retrieve body
        $body = '';
        curl_setopt($handler, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body) {
            $body .= $chunk;

            return strlen($chunk);
        });

        curl_exec($handler);
        if (curl_error($handler)) {
            throw new \Exception('Failed to fetch next Lambda invocation: ' . curl_error($handler));
        }
        if ($invocationId === '') {
            throw new \Exception('Failed to determine the Lambda invocation ID');
        }
        if ($body === '') {
            throw new \Exception('Empty Lambda runtime API response');
        }
        curl_close($handler);

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

        $handler = curl_init($url);
        curl_setopt($handler, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handler, CURLOPT_FAILONERROR, true);
        curl_setopt($handler, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($handler, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
        ]);
        curl_exec($handler);
        if (curl_error($handler)) {
            $errorMessage = curl_error($handler);
            curl_close($handler);
            throw new \Exception('Error while calling the Lambda runtime API: ' . $errorMessage);
        }
        curl_close($handler);
    }
}
