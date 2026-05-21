<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Tests;

use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Embeddings\EmbeddingService;
use ElasticPressIO\Sample\RAG\ChatProviderInterface;
use ElasticPressIO\Sample\RAG\RagService;
use PHPUnit\Framework\TestCase;

class RagServiceTest extends TestCase
{
    public function testNormalizeQueryConvertsEmptyMatchAllToObject(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'normalizeQuery');

        $input = ['query' => ['match_all' => []]];
        $result = $method->invoke($service, $input);

        $json = json_encode($result);
        $this->assertStringContainsString('"match_all":{}', $json);
    }

    public function testNormalizeQueryPreservesNonEmptyArrays(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'normalizeQuery');

        $input = ['query' => ['bool' => ['filter' => [['term' => ['category' => 'Physics']]]]]];
        $result = $method->invoke($service, $input);

        $this->assertSame('Physics', $result['query']['bool']['filter'][0]['term']['category']);
    }

    public function testNormalizeQueryHandlesNestedMatchAll(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'normalizeQuery');

        $input = ['query' => ['bool' => ['must' => [['match_all' => []]]]]];
        $result = $method->invoke($service, $input);

        $json = json_encode($result);
        $this->assertStringContainsString('"match_all":{}', $json);
    }

    public function testFormatAggregationsSkipsEmptyKeyBuckets(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'formatAggregations');

        $aggs = [
            'by_country' => [
                'buckets' => [
                    ['key' => '', 'doc_count' => 5],
                    ['key' => 'USA', 'doc_count' => 299],
                    ['key' => 'Germany', 'doc_count' => 50],
                ],
            ],
        ];

        $result = $method->invoke($service, $aggs, 0);

        $this->assertStringNotContainsString('- : 5', $result);
        $this->assertStringContainsString('USA: 299', $result);
        $this->assertStringContainsString('Germany: 50', $result);
    }

    public function testFormatAggregationsShowsNoResultsForEmptyBuckets(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'formatAggregations');

        $aggs = [
            'by_person' => [
                'buckets' => [],
            ],
        ];

        $result = $method->invoke($service, $aggs, 0);

        $this->assertStringContainsString('(no results)', $result);
    }

    public function testFormatAggregationsHandlesStatsAgg(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'formatAggregations');

        $aggs = [
            'year_stats' => [
                'count' => 1000,
                'min' => 1901,
                'max' => 2025,
                'avg' => 1975.3,
            ],
        ];

        $result = $method->invoke($service, $aggs, 0);

        $this->assertStringContainsString('count=1000', $result);
        $this->assertStringContainsString('min=1901', $result);
        $this->assertStringContainsString('max=2025', $result);
    }

    public function testRedactVectorReplacesLargeArray(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'redactVector');

        $query = [
            'knn' => [
                'field' => 'motivation_embedding',
                'query_vector' => array_fill(0, 1536, 0.1),
                'k' => 20,
            ],
        ];

        $result = $method->invoke($service, $query);

        $this->assertSame('[vector:1536d]', $result['knn']['query_vector']);
    }

    public function testResolveKnnReplacesPlaceholder(): void
    {
        $embedding = array_fill(0, 1536, 0.5);

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('embedQuery')->willReturn($embedding);

        $service = new RagService(
            $embeddingService,
            $this->createMock(ElasticsearchClient::class),
            $this->createMock(ChatProviderInterface::class),
            'test-index'
        );

        $method = new \ReflectionMethod($service, 'resolveKnn');

        $query = ['knn' => ['query_vector' => '[PLACEHOLDER]']];
        $result = $method->invoke($service, $query, 'test question');

        $this->assertSame($embedding, $result['knn']['query_vector']);
    }

    public function testResolveKnnLeavesRealVectorsAlone(): void
    {
        $service = $this->createRagService();
        $method = new \ReflectionMethod($service, 'resolveKnn');

        $vector = [0.1, 0.2, 0.3];
        $query = ['knn' => ['query_vector' => $vector]];
        $result = $method->invoke($service, $query, 'test');

        $this->assertSame($vector, $result['knn']['query_vector']);
    }

    public function testSchemaCachingAvoidsDuplicateCalls(): void
    {
        $esClient = $this->createMock(ElasticsearchClient::class);
        $esClient->expects($this->once())
            ->method('get')
            ->with('/test-index/_mapping')
            ->willReturn([
                'test-index' => [
                    'mappings' => [
                        'properties' => [
                            'title' => ['type' => 'text'],
                        ],
                    ],
                ],
            ]);

        $service = new RagService(
            $this->createMock(EmbeddingService::class),
            $esClient,
            $this->createMock(ChatProviderInterface::class),
            'test-index'
        );

        $method = new \ReflectionMethod($service, 'getSchema');

        $first = $method->invoke($service);
        $second = $method->invoke($service);

        $this->assertSame($first, $second);
    }

    private function createRagService(): RagService
    {
        return new RagService(
            $this->createMock(EmbeddingService::class),
            $this->createMock(ElasticsearchClient::class),
            $this->createMock(ChatProviderInterface::class),
            'test-index'
        );
    }
}
