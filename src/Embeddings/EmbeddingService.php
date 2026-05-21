<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Embeddings;

/**
 * High-level embedding service for Nobel Prize documents and search queries.
 *
 * Responsible for:
 * - Composing the text representation of a laureate document for embedding
 * - Delegating vector generation to an EmbeddingProviderInterface implementation
 * - Batching document embedding with optional progress reporting
 */
class EmbeddingService
{
    public function __construct(
        private readonly EmbeddingProviderInterface $provider
    ) {}

    /**
     * Generate an embedding vector for a search query string.
     *
     * @param string $query The user's search query
     * @return float[] Dense float vector
     */
    public function embedQuery(string $query): array
    {
        return $this->provider->embed(trim($query));
    }

    /**
     * Generate an embedding vector for a single laureate document.
     *
     * @param array $document Laureate document (from NobelDataTransformer)
     * @return float[] Dense float vector
     */
    public function embedDocument(array $document): array
    {
        return $this->provider->embed($this->composeDocumentText($document));
    }

    /**
     * Generate embedding vectors for multiple laureate documents.
     *
     * Returns a map of document ID → vector.
     *
     * @param array[] $documents Array of laureate documents
     * @param callable|null $progressCallback fn(int $done, int $total) called after each batch
     * @return array<string, float[]> Map of document 'id' → embedding vector
     */
    public function embedDocuments(array $documents, ?callable $progressCallback = null): array
    {
        $total = count($documents);
        $results = [];

        // Compose all texts first, keeping the document IDs aligned
        $ids = [];
        $texts = [];
        foreach ($documents as $document) {
            $ids[]   = $document['id'] ?? '';
            $texts[] = $this->composeDocumentText($document);
        }

        // The provider handles its own internal batching (≤100 per call for OpenAI)
        $vectors = $this->provider->embedBatch($texts);

        foreach ($vectors as $i => $vector) {
            $results[$ids[$i]] = $vector;
        }

        if ($progressCallback !== null) {
            $progressCallback($total, $total);
        }

        return $results;
    }

    /**
     * Build the text string that will be embedded for a laureate document.
     *
     * Strategy: motivation-primary with domain anchoring.
     * - Category + year establish the scientific domain upfront
     * - Full name gives both first/family name context
     * - Motivation is the Nobel committee's precise statement of the contribution
     *   (the richest semantic signal)
     * - Affiliations add institutional context users often search for ("CERN", "MIT")
     * - Biography (birth/death/country) excluded — factual, not semantic
     *
     * Example output:
     *   "Physics Albert Einstein (1921): for his discovery of the law of the
     *    photoelectric effect. Affiliated with: Kaiser Wilhelm Institute, Berlin."
     *
     * @param array $document Laureate document
     * @return string Text to embed (stays well within token limits for all major models)
     */
    public function composeDocumentText(array $document): string
    {
        $category  = ucfirst($document['category'] ?? '');
        $fullname  = $document['fullname'] ?? trim(($document['firstname'] ?? '') . ' ' . ($document['surname'] ?? ''));
        $year      = $document['year'] ?? '';
        $motivation = $document['motivation'] ?? '';

        // Build affiliation context
        $affiliations = $document['affiliations'] ?? [];
        $affTexts = [];
        foreach ($affiliations as $aff) {
            $name = $aff['name'] ?? '';
            $city = $aff['city'] ?? '';
            if ($name) {
                $affTexts[] = $city ? "{$name}, {$city}" : $name;
            }
        }

        if ($motivation) {
            $text = "{$category} {$fullname} ({$year}): {$motivation}";
        } else {
            // Fallback for organisations or laureates without a recorded motivation
            $text = "{$category} {$fullname} ({$year})";
        }

        if (!empty($affTexts)) {
            $text .= '. Affiliated with: ' . implode('; ', $affTexts);
        }

        return $text;
    }

    /**
     * Number of dimensions the underlying provider produces.
     */
    public function getDimensions(): int
    {
        return $this->provider->getDimensions();
    }
}
