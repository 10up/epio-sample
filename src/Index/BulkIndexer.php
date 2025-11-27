<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Index;

use ElasticPressIO\Sample\Client\ElasticsearchClient;

/**
 * Handles bulk indexing operations for Elasticsearch.
 *
 * The Bulk API allows indexing multiple documents in a single request,
 * which is much more efficient than individual index operations.
 *
 * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-bulk
 */
class BulkIndexer
{
    private ElasticsearchClient $client;
    private int $batchSize;

    /**
     * @param ElasticsearchClient|null $client Elasticsearch client
     * @param int $batchSize Number of documents to index per batch
     */
    public function __construct(?ElasticsearchClient $client = null, int $batchSize = 500)
    {
        $this->client = $client ?? new ElasticsearchClient();
        $this->batchSize = $batchSize;
    }

    /**
     * Index multiple documents using the Bulk API.
     *
     * Documents are processed in batches for optimal performance.
     * The Bulk API uses NDJSON format (newline-delimited JSON) where each
     * operation consists of two lines:
     * 1. Action metadata (index, create, update, delete)
     * 2. Document source (for index/create/update operations)
     *
     * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-bulk
     *
     * @param string $indexName Name of the index
     * @param array $documents Array of documents to index
     * @param string|null $idField Field name to use as document ID (null = auto-generate)
     * @return array{total: int, successful: int, failed: int, errors: array}
     */
    public function indexDocuments(string $indexName, array $documents, ?string $idField = 'id'): array
    {
        $total = count($documents);
        $successful = 0;
        $failed = 0;
        $errors = [];

        echo "Indexing {$total} documents to '{$indexName}'...\n";

        // Process in batches
        $batches = array_chunk($documents, $this->batchSize);
        $batchCount = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            echo "Processing batch {$batchNum}/{$batchCount} (" . count($batch) . " documents)...\n";

            try {
                $result = $this->indexBatch($indexName, $batch, $idField);
                $successful += $result['successful'];
                $failed += $result['failed'];
                $errors = array_merge($errors, $result['errors']);
            } catch (\Exception $e) {
                echo "Batch {$batchNum} failed: " . $e->getMessage() . "\n";
                $failed += count($batch);
                $errors[] = [
                    'batch' => $batchNum,
                    'error' => $e->getMessage(),
                ];
            }
        }

        echo "Indexing complete: {$successful} successful, {$failed} failed\n";

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Index a single batch of documents.
     *
     * @param string $indexName Index name
     * @param array $documents Batch of documents
     * @param string|null $idField Document ID field
     * @return array{successful: int, failed: int, errors: array}
     */
    private function indexBatch(string $indexName, array $documents, ?string $idField): array
    {
        $ndjson = $this->buildBulkRequest($indexName, $documents, $idField);

        $response = $this->client->bulk($ndjson, $indexName);

        return $this->parseBulkResponse($response);
    }

    /**
     * Build NDJSON formatted bulk request body.
     *
     * Format for ElasticPress.io (no explicit index in action):
     * {"index":{"_id":"doc_id"}}
     * {"field1":"value1","field2":"value2"}
     * {"index":{"_id":"doc_id"}}
     * {"field1":"value1","field2":"value2"}
     *
     * @param string $indexName Index name (used in URL, not in body)
     * @param array $documents Documents to index
     * @param string|null $idField Field to use as document ID
     * @return string NDJSON formatted string
     */
    private function buildBulkRequest(string $indexName, array $documents, ?string $idField): string
    {
        $lines = [];

        foreach ($documents as $document) {
            // Build action metadata without explicit index (ElasticPress.io requirement)
            $action = ['index' => []];

            // Add document ID if field is specified
            if ($idField && isset($document[$idField])) {
                $action['index']['_id'] = $document[$idField];
            }

            // If action['index'] is empty, we need at least an empty object
            if (empty($action['index'])) {
                $action['index'] = new \stdClass();
            }

            // Add action line
            $lines[] = json_encode($action);

            // Add document source line
            $lines[] = json_encode($document);
        }

        // NDJSON requires newline at the end
        return implode("\n", $lines) . "\n";
    }

    /**
     * Parse bulk API response and extract success/failure statistics.
     *
     * @param array $response Bulk API response
     * @return array{successful: int, failed: int, errors: array}
     */
    private function parseBulkResponse(array $response): array
    {
        $successful = 0;
        $failed = 0;
        $errors = [];

        $items = $response['items'] ?? [];

        foreach ($items as $item) {
            // Each item has a single key (index, create, update, delete)
            $operation = $item['index'] ?? $item['create'] ?? $item['update'] ?? $item['delete'] ?? null;

            if (!$operation) {
                continue;
            }

            $status = $operation['status'] ?? 0;

            // 200 or 201 = success
            if ($status >= 200 && $status < 300) {
                $successful++;
            } else {
                $failed++;
                $errors[] = [
                    'id' => $operation['_id'] ?? 'unknown',
                    'status' => $status,
                    'error' => $operation['error'] ?? 'Unknown error',
                ];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Delete multiple documents by ID using the Bulk API.
     *
     * @param string $indexName Index name
     * @param array $documentIds Array of document IDs to delete
     * @return array{successful: int, failed: int, errors: array}
     */
    public function deleteDocuments(string $indexName, array $documentIds): array
    {
        $lines = [];

        foreach ($documentIds as $id) {
            $lines[] = json_encode([
                'delete' => [
                    '_index' => $indexName,
                    '_id' => $id,
                ],
            ]);
        }

        $ndjson = implode("\n", $lines) . "\n";
        $response = $this->client->bulk($ndjson, $indexName);

        return $this->parseBulkResponse($response);
    }

    /**
     * Set the batch size for bulk operations.
     *
     * @param int $batchSize Number of documents per batch
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Get the current batch size.
     *
     * @return int Batch size
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }
}
