<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Embeddings;

use ElasticPressIO\Sample\Client\ElasticsearchClient;

/**
 * Updates existing Elasticsearch documents with vector embeddings.
 *
 * Uses the Elasticsearch Scroll API to page through an index and bulk-updates
 * each document's motivation_embedding field without overwriting other fields.
 *
 * ElasticPress.io bulk constraint: action metadata must NOT include _index.
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/scroll-api.html
 */
class EmbeddingUpdater
{
    public function __construct(
        private readonly ElasticsearchClient $esClient,
        private readonly EmbeddingService $embeddingService,
        private readonly int $scrollBatchSize = 100,
        private readonly int $embeddingBatchSize = 50
    ) {}

    /**
     * Scroll through the index and update every document's motivation_embedding field.
     *
     * @param string $indexName Fully-qualified index name (with prefix)
     * @param bool $skipExisting When true, only process docs missing motivation_embedding
     * @param callable|null $progressCallback fn(int $done, int $skipped)
     * @return array{processed: int, updated: int, failed: int, skipped: int}
     */
    public function updateEmbeddings(
        string $indexName,
        bool $skipExisting = true,
        ?callable $progressCallback = null
    ): array {
        $stats = ['processed' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];
        $buffer = [];

        foreach ($this->scrollDocuments($indexName, $skipExisting) as $hit) {
            $stats['processed']++;
            $buffer[] = $hit;

            if (count($buffer) >= $this->embeddingBatchSize) {
                $batchStats = $this->processBuffer($indexName, $buffer);
                $this->mergeStats($stats, $batchStats);
                $buffer = [];

                if ($progressCallback !== null) {
                    $progressCallback($stats['processed'], $stats['skipped']);
                }
            }
        }

        // Process remaining documents
        if (!empty($buffer)) {
            $batchStats = $this->processBuffer($indexName, $buffer);
            $this->mergeStats($stats, $batchStats);

            if ($progressCallback !== null) {
                $progressCallback($stats['processed'], $stats['skipped']);
            }
        }

        return $stats;
    }

    /**
     * Scroll through all documents in the index using the Elasticsearch Scroll API.
     *
     * @param string $indexName
     * @param bool $skipExisting When true, filters to docs without motivation_embedding
     * @return \Generator<array> yields Elasticsearch hit arrays
     */
    private function scrollDocuments(string $indexName, bool $skipExisting): \Generator
    {
        $body = [
            'size'    => $this->scrollBatchSize,
            '_source' => true,
        ];

        if ($skipExisting) {
            $body['query'] = [
                'bool' => [
                    'must_not' => [
                        ['exists' => ['field' => 'motivation_embedding']],
                    ],
                ],
            ];
        } else {
            $body['query'] = ['match_all' => new \stdClass()];
        }

        // Initial scroll request — scroll context kept alive for 2 minutes
        $response = $this->esClient->post("/{$indexName}/_search", $body, ['scroll' => '2m']);
        $scrollId = $response['_scroll_id'] ?? null;
        $hits     = $response['hits']['hits'] ?? [];

        while (!empty($hits)) {
            foreach ($hits as $hit) {
                yield $hit;
            }

            if (!$scrollId) {
                break;
            }

            // Fetch next scroll page
            $response = $this->esClient->post('/_search/scroll', [
                'scroll'    => '2m',
                'scroll_id' => $scrollId,
            ]);

            $scrollId = $response['_scroll_id'] ?? $scrollId;
            $hits     = $response['hits']['hits'] ?? [];
        }
    }

    /**
     * Generate embeddings for a buffer of hits and bulk-update them in Elasticsearch.
     *
     * @param string $indexName
     * @param array[] $hits Elasticsearch hit arrays (with _id and _source)
     * @return array{updated: int, failed: int, skipped: int}
     */
    private function processBuffer(string $indexName, array $hits): array
    {
        $stats = ['updated' => 0, 'failed' => 0, 'skipped' => 0];

        // Build the document array EmbeddingService expects
        $documents = [];
        foreach ($hits as $hit) {
            $source       = $hit['_source'];
            $source['id'] = $source['id'] ?? $hit['_id'];
            $documents[]  = $source;
        }

        // Generate all embeddings in one batched API call
        try {
            $idVectorMap = $this->embeddingService->embedDocuments($documents);
        } catch (\RuntimeException $e) {
            $stats['failed'] += count($documents);
            fwrite(STDERR, "   Embedding error: " . $e->getMessage() . "\n");
            return $stats;
        }

        if (empty($idVectorMap)) {
            $stats['skipped'] += count($documents);
            return $stats;
        }

        // Build NDJSON bulk update payload
        // Uses 'update' action (not 'index') to preserve all existing fields.
        // No '_index' in action metadata — required by ElasticPress.io.
        $ndjson = '';
        foreach ($idVectorMap as $docId => $vector) {
            $ndjson .= json_encode(['update' => ['_id' => $docId]]) . "\n";
            $ndjson .= json_encode(['doc' => ['motivation_embedding' => $vector]]) . "\n";
        }

        // Execute bulk update
        try {
            $bulkResponse = $this->esClient->bulk($ndjson, $indexName);
            foreach ($bulkResponse['items'] ?? [] as $item) {
                $op     = $item['update'] ?? [];
                $status = $op['status'] ?? 0;
                if ($status >= 200 && $status < 300) {
                    $stats['updated']++;
                } else {
                    $stats['failed']++;
                    $reason = $op['error']['reason'] ?? 'unknown';
                    fwrite(STDERR, "   Update failed for {$op['_id']}: {$reason}\n");
                }
            }
        } catch (\RuntimeException $e) {
            $stats['failed'] += count($idVectorMap);
            fwrite(STDERR, "   Bulk error: " . $e->getMessage() . "\n");
        }

        return $stats;
    }

    private function mergeStats(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            $target[$key] = ($target[$key] ?? 0) + $value;
        }
    }
}
