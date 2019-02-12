<?php

namespace Bref\Runtime;

class CurlReuseHandler implements LambdaHandler
{
    private $handler;
    private $returnHandler;

    public function __destruct()
    {
        $this->closeHandler();
        $this->closeReturnHandler();
    }

    /**
     * @inheritdoc
     */
    public function waitNextInvocation(string $url): array
    {
        if (is_null($this->handler)) {
            $this->handler = curl_init($url);
            curl_setopt($this->handler, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->handler, CURLOPT_FAILONERROR, true);
        }

        // Retrieve invocation ID
        $invocationId = '';
        curl_setopt($this->handler, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$invocationId) {
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
        curl_setopt($this->handler, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body) {
            $body .= $chunk;

            return strlen($chunk);
        });

        curl_exec($this->handler);
        if (curl_errno($this->handler) > 0) {
            $message = curl_error($this->handler);
            $this->closeHandler();
            throw new \Exception('Failed to fetch next Lambda invocation: ' . $message);
        }
        if ($invocationId === '') {
            throw new \Exception('Failed to determine the Lambda invocation ID');
        }
        if ($body === '') {
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

        if (is_null($this->returnHandler)) {
            $this->returnHandler = curl_init();
            curl_setopt($this->returnHandler, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($this->returnHandler, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->returnHandler, CURLOPT_FAILONERROR, true);
        }

        curl_setopt($this->returnHandler, CURLOPT_URL, $url);
        curl_setopt($this->returnHandler, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($this->returnHandler, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
        ]);
        curl_exec($this->returnHandler);
        if (curl_errno($this->returnHandler) > 0) {
            $errorMessage = curl_error($this->returnHandler);
            $this->closeReturnHandler();
            throw new \Exception('Error while calling the Lambda runtime API: ' . $errorMessage);
        }
    }

    private function closeHandler()
    {
        if (!is_null($this->handler)) {
            curl_close($this->handler);
            $this->handler = null;
        }
    }

    private function closeReturnHandler()
    {
        if (!is_null($this->returnHandler)) {
            curl_close($this->returnHandler);
            $this->returnHandler = null;
        }
    }
}
