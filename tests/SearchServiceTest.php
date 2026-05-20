<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Tests;

use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Search\SearchService;
use PHPUnit\Framework\TestCase;

class SearchServiceTest extends TestCase
{
    private function createServiceWithMock(array $esResponse): SearchService
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->method('post')->willReturn($esResponse);

        return new SearchService($client);
    }

    private function minimalEsResponse(array $hits = [], array $aggregations = [], int $total = 0): array
    {
        return [
            'hits' => [
                'total' => ['value' => $total ?: count($hits)],
                'hits' => $hits,
            ],
            'aggregations' => $aggregations,
        ];
    }

    // ── Query construction (verified via the mock's expected arguments) ────────

    public function testSearchWithEmptyQueryUsesMatchAll(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('/test-index/_search'),
                $this->callback(function (array $body) {
                    $query = $body['query']['bool']['must'][0] ?? [];
                    return isset($query['match_all']);
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '');
    }

    public function testSearchWithTextUsesMultiMatch(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $must = $body['query']['bool']['must'][0] ?? [];
                    return isset($must['multi_match'])
                        && $must['multi_match']['query'] === 'einstein';
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', 'einstein');
    }

    public function testSearchAppliesCategoryFilter(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filters = $body['query']['bool']['filter'] ?? [];
                    foreach ($filters as $f) {
                        if (isset($f['term']['category']) && $f['term']['category'] === 'Physics') {
                            return true;
                        }
                    }
                    return false;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', ['category' => 'Physics']);
    }

    public function testSearchAppliesGenderFilter(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filters = $body['query']['bool']['filter'] ?? [];
                    foreach ($filters as $f) {
                        if (isset($f['term']['gender']) && $f['term']['gender'] === 'female') {
                            return true;
                        }
                    }
                    return false;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', ['gender' => 'female']);
    }

    public function testSearchAppliesYearRangeFilter(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filters = $body['query']['bool']['filter'] ?? [];
                    foreach ($filters as $f) {
                        if (isset($f['range']['year'])) {
                            return $f['range']['year']['gte'] === 2000
                                && $f['range']['year']['lte'] === 2020;
                        }
                    }
                    return false;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', ['year_from' => 2000, 'year_to' => 2020]);
    }

    public function testSearchAppliesPrizeCountryFilter(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filters = $body['query']['bool']['filter'] ?? [];
                    foreach ($filters as $f) {
                        if (isset($f['term']['prize_countries']) && $f['term']['prize_countries'] === 'US') {
                            return true;
                        }
                    }
                    return false;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', ['prize_country' => 'US']);
    }

    public function testSearchCombinesMultipleFilters(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filters = $body['query']['bool']['filter'] ?? [];
                    return count($filters) === 3; // category + gender + year_range
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', [
            'category' => 'Physics',
            'gender' => 'female',
            'year_from' => 2000,
        ]);
    }

    public function testSearchIncludesFacetsbyDefault(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    return isset($body['aggs']['categories'])
                        && isset($body['aggs']['genders'])
                        && isset($body['aggs']['birth_countries'])
                        && isset($body['aggs']['prize_countries'])
                        && isset($body['aggs']['year_stats'])
                        && isset($body['aggs']['years']);
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '');
    }

    public function testSearchOmitsFacetsWhenDisabled(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    return !isset($body['aggs']);
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', [], 0, 20, false);
    }

    public function testSearchSortsByYearDescThenScore(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $sort = $body['sort'] ?? [];
                    return count($sort) === 2
                        && ($sort[0]['year']['order'] ?? '') === 'desc'
                        && $sort[1] === '_score';
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '');
    }

    // ── Response formatting ───────────────────────────────────────────────────

    public function testFormatsHitsCorrectly(): void
    {
        $service = $this->createServiceWithMock($this->minimalEsResponse(
            hits: [
                ['_id' => 'doc-1', '_score' => 5.2, '_source' => ['fullname' => 'Albert Einstein', 'year' => 1921]],
                ['_id' => 'doc-2', '_score' => 3.1, '_source' => ['fullname' => 'Niels Bohr', 'year' => 1922]],
            ],
            total: 2
        ));

        $result = $service->search('test-index', 'physics');

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
        $this->assertSame('doc-1', $result['results'][0]['id']);
        $this->assertSame(5.2, $result['results'][0]['score']);
        $this->assertSame('Albert Einstein', $result['results'][0]['source']['fullname']);
    }

    public function testFormatsFacetsCorrectly(): void
    {
        $service = $this->createServiceWithMock($this->minimalEsResponse(
            aggregations: [
                'categories' => [
                    'buckets' => [
                        ['key' => 'Physics', 'doc_count' => 220],
                        ['key' => 'Chemistry', 'doc_count' => 190],
                    ],
                ],
                'genders' => [
                    'buckets' => [
                        ['key' => 'male', 'doc_count' => 900],
                        ['key' => 'female', 'doc_count' => 68],
                    ],
                ],
                'year_stats' => [
                    'count' => 1000,
                    'min' => 1901.0,
                    'max' => 2025.0,
                    'avg' => 1975.5,
                ],
                'years' => [
                    'buckets' => [
                        ['key' => 1900.0, 'doc_count' => 30],
                        ['key' => 1910.0, 'doc_count' => 25],
                    ],
                ],
            ]
        ));

        $result = $service->search('test-index', '');
        $facets = $result['facets'];

        $this->assertSame('Physics', $facets['categories'][0]['value']);
        $this->assertSame(220, $facets['categories'][0]['count']);
        $this->assertSame('female', $facets['genders'][1]['value']);
        $this->assertSame(68, $facets['genders'][1]['count']);
        $this->assertSame(1901, $facets['year_range']['min']);
        $this->assertSame(2025, $facets['year_range']['max']);
        $this->assertSame(1900, $facets['year_histogram'][0]['year']);
    }

    public function testReturnsEmptyFacetsWhenNoAggregations(): void
    {
        $service = $this->createServiceWithMock($this->minimalEsResponse());

        $result = $service->search('test-index', '');

        $this->assertSame([], $result['facets']);
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function testSearchPassesPaginationParameters(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    return $body['from'] === 20 && $body['size'] === 10;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->search('test-index', '', [], 20, 10);
    }

    // ── Semantic search ───────────────────────────────────────────────────────

    public function testSemanticSearchBuildsKnnQuery(): void
    {
        $queryVector = array_fill(0, 1536, 0.1);

        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('/test-index/_search'),
                $this->callback(function (array $body) use ($queryVector) {
                    return isset($body['knn'])
                        && $body['knn']['field'] === 'motivation_embedding'
                        && $body['knn']['query_vector'] === $queryVector
                        && $body['knn']['k'] === 10
                        && $body['knn']['num_candidates'] === 100;
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->semanticSearch('test-index', $queryVector, [], 10, 100);
    }

    public function testSemanticSearchAppliesPreFilters(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $filter = $body['knn']['filter'] ?? [];
                    return !empty($filter)
                        && $filter[0]['term']['category'] === 'Physics';
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->semanticSearch('test-index', [0.1], ['category' => 'Physics']);
    }

    public function testSemanticSearchExcludesEmbeddingFromSource(): void
    {
        $client = $this->createMock(ElasticsearchClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $body) {
                    $excludes = $body['_source']['excludes'] ?? [];
                    return in_array('motivation_embedding', $excludes);
                })
            )
            ->willReturn($this->minimalEsResponse());

        $service = new SearchService($client);
        $service->semanticSearch('test-index', [0.1]);
    }
}
