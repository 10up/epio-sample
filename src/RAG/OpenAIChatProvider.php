<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\RAG;

use ElasticPressIO\Sample\Shared\HttpClientFactory;

/**
 * Chat completion provider backed by any OpenAI-compatible /v1/chat/completions endpoint.
 *
 * Works with OpenAI, Ollama, LM Studio, Groq, Azure OpenAI, and any other service
 * that implements the OpenAI chat completions API contract.
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
class OpenAIChatProvider implements ChatProviderInterface
{
    private const MAX_RETRIES = 3;

    private \GuzzleHttp\Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model
    ) {
        $this->client = HttpClientFactory::openAI($this->baseUrl, $this->apiKey, 120);
    }

    public function complete(array $messages, array $options = []): string
    {
        $result = $this->send($messages, [], $options);
        return $result['content'] ?? '';
    }

    public function completeWithTools(array $messages, array $tools, array $options = []): array
    {
        return $this->send($messages, $tools, $options);
    }

    /**
     * Core HTTP call with retry on rate-limit (429) and server errors (5xx).
     *
     * @return array{content: string|null, tool_calls: array|null, finish_reason: string}
     */
    private function send(array $messages, array $tools, array $options): array
    {
        $body = array_merge([
            'model'                 => $this->model,
            'messages'              => $messages,
            'temperature'           => 0.2,
            'max_completion_tokens' => 16000,
        ], $options);

        if (isset($body['max_tokens']) && !isset($body['max_completion_tokens'])) {
            $body['max_completion_tokens'] = $body['max_tokens'];
        }
        unset($body['max_tokens']);

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $delaySeconds = 1;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response   = $this->client->post('chat/completions', ['json' => $body]);
            $statusCode = $response->getStatusCode();
            $data       = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 429 || ($statusCode >= 500 && $attempt < self::MAX_RETRIES)) {
                $retryAfter = $response->getHeaderLine('Retry-After');
                $wait = $retryAfter ? (int) $retryAfter : $delaySeconds;
                sleep($wait);
                $delaySeconds *= 2;
                continue;
            }

            if ($statusCode >= 400) {
                $error = $data['error']['message'] ?? "HTTP {$statusCode}";
                throw new \RuntimeException("Chat API error: {$error}");
            }

            $message      = $data['choices'][0]['message'] ?? [];
            $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';

            return [
                'content'      => $message['content'] ?? null,
                'tool_calls'   => $message['tool_calls'] ?? null,
                'finish_reason' => $finishReason,
            ];
        }

        throw new \RuntimeException("Chat API failed after " . self::MAX_RETRIES . " retries (rate limited).");
    }
}
