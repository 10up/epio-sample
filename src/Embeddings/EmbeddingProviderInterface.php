<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Embeddings;

/**
 * Contract for vector embedding providers.
 *
 * Implementations must be stateless so callers can embed single texts or batches
 * without caring which underlying API is used (OpenAI, Ollama, Cohere, etc.).
 */
interface EmbeddingProviderInterface
{
    /**
     * Generate a vector embedding for a single text string.
     *
     * @param string $text Input text to embed
     * @return float[] Dense float vector
     */
    public function embed(string $text): array;

    /**
     * Generate vector embeddings for multiple texts in one API round-trip.
     *
     * The returned array is indexed parallel to $texts:
     * $result[0] is the embedding for $texts[0], etc.
     *
     * @param string[] $texts Input texts
     * @return float[][] Array of dense float vectors
     */
    public function embedBatch(array $texts): array;

    /**
     * Number of dimensions this provider produces per vector.
     * Must match the dense_vector mapping in Elasticsearch.
     */
    public function getDimensions(): int;
}
