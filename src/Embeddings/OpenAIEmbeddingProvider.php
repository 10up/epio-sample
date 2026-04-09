<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Embeddings;

use ElasticPressIO\Sample\Shared\HttpClientFactory;

/**
 * Embedding provider backed by any OpenAI-compatible /v1/embeddings endpoint.
 *
 * Works with OpenAI, Ollama, LM Studio, Groq, Azure OpenAI, and any other service
 * that implements the OpenAI embeddings API contract.
 *
 * @see https://platform.openai.com/docs/api-reference/embeddings
 */
class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    /**
     * Maximum number of inputs per API call (OpenAI limit).
     * Local providers (Ollama, LM Studio) may accept larger batches,
     * but this value is safe for all providers.
     */
    private const MAX_BATCH_SIZE = 100;

    private \GuzzleHttp\Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions
    ) {
        $this->client = HttpClientFactory::openAI($this->baseUrl, $this->apiKey, 60);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($texts, self::MAX_BATCH_SIZE);

        foreach ($chunks as $chunkIndex => $chunk) {
            $vectors = $this->requestWithRetry($chunk);

            foreach ($vectors as $vector) {
                $results[] = $vector;
            }

            // Brief pause between chunks to avoid rate-limit bursts
            if ($chunkIndex < count($chunks) - 1) {
                usleep(200_000); // 200ms
            }
        }

        return $results;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Send an embeddings request with exponential backoff on HTTP 429.
     *
     * @param string[] $inputs
     * @return float[][]
     */
    private function requestWithRetry(array $inputs): array
    {
        $maxRetries = 3;
        $delaySeconds = 1;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $body = [
                'model' => $this->model,
                'input' => $inputs,
            ];

            // Pass dimensions only when the model supports it (OpenAI text-embedding-3-*)
            // Other providers ignore unknown fields, so this is safe to always include.
            if ($this->dimensions !== 1536) {
                $body['dimensions'] = $this->dimensions;
            }

            $response = $this->client->post('embeddings', [
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 429 && $attempt < $maxRetries) {
                // Respect Retry-After header if present, otherwise use exponential backoff
                $retryAfter = $response->getHeaderLine('Retry-After');
                $waitSeconds = $retryAfter ? (int) $retryAfter : $delaySeconds;
                sleep($waitSeconds);
                $delaySeconds *= 2;
                continue;
            }

            $data = json_decode($response->getBody()->getContents(), true);

            if ($statusCode >= 400) {
                $error = $data['error']['message'] ?? "HTTP {$statusCode}";
                throw new \RuntimeException("Embedding API error: {$error}");
            }

            // Sort by index to preserve input order (API may return in any order)
            $embeddings = $data['data'] ?? [];
            usort($embeddings, fn($a, $b) => $a['index'] <=> $b['index']);

            return array_map(fn($item) => $item['embedding'], $embeddings);
        }

        throw new \RuntimeException("Embedding API failed after {$maxRetries} retries (rate limited).");
    }
}
