<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Client;

use ElasticPressIO\Sample\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for interacting with ElasticPress.io / Elasticsearch API.
 *
 * This client handles authentication and provides methods for common
 * Elasticsearch operations compatible with ElasticPress.io.
 *
 * @see https://www.elastic.co/docs/api/doc/elasticsearch Elasticsearch API Reference
 * @see https://www.elasticpress.io/resources/articles/ ElasticPress.io Documentation
 */
class ElasticsearchClient
{
    private Client $client;
    private Config $config;
    private string $host;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->config->validate();

        $this->host = rtrim($this->config->getElasticsearchHost(), '/');

        // Initialize Guzzle HTTP client with authentication
        // ElasticPress.io uses HTTP Basic Authentication with Subscription ID and Token
        $auth = $this->config->getElasticsearchAuth();

        $this->client = new Client([
            'base_uri' => $this->host,
            'auth' => [$auth['subscription_id'], $auth['subscription_token']],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
            'http_errors' => false, // We'll handle errors manually
        ]);
    }

    /**
     * Perform a GET request to the Elasticsearch API.
     *
     * @param string $path API endpoint path
     * @param array $query Query parameters
     * @return array Response body as associative array
     * @throws \RuntimeException On request failure
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client->get($path, [
                'query' => $query,
            ]);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("GET request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Perform a POST request to the Elasticsearch API.
     *
     * @param string $path API endpoint path
     * @param array $body Request body
     * @param array $query Query parameters
     * @return array Response body as associative array
     * @throws \RuntimeException On request failure
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        try {
            $response = $this->client->post($path, [
                'json' => $body,
                'query' => $query,
            ]);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("POST request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Perform a PUT request to the Elasticsearch API.
     *
     * @param string $path API endpoint path
     * @param array $body Request body
     * @param array $query Query parameters
     * @return array Response body as associative array
     * @throws \RuntimeException On request failure
     */
    public function put(string $path, array $body = [], array $query = []): array
    {
        try {
            $response = $this->client->put($path, [
                'json' => $body,
                'query' => $query,
            ]);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("PUT request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Perform a DELETE request to the Elasticsearch API.
     *
     * @param string $path API endpoint path
     * @param array $query Query parameters
     * @return array Response body as associative array
     * @throws \RuntimeException On request failure
     */
    public function delete(string $path, array $query = []): array
    {
        try {
            $response = $this->client->delete($path, [
                'query' => $query,
            ]);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("DELETE request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Perform a bulk operation.
     *
     * The bulk API allows you to perform multiple index/delete operations in a single request.
     *
     * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-bulk
     *
     * @param string $body NDJSON formatted bulk request body
     * @param string|null $index Optional index name to target
     * @return array Response body as associative array
     * @throws \RuntimeException On request failure
     */
    public function bulk(string $body, ?string $index = null): array
    {
        try {
            $path = $index ? "/{$index}/_bulk" : '/_bulk';

            $response = $this->client->post($path, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-ndjson',
                ],
            ]);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Bulk request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle the HTTP response and extract the body.
     *
     * @param ResponseInterface $response HTTP response
     * @return array Response body as associative array
     * @throws \RuntimeException If response indicates an error
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        // Decode JSON response
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Failed to decode JSON response: " . json_last_error_msg() . "\nBody: {$body}"
            );
        }

        // Check for HTTP errors
        if ($statusCode >= 400) {
            $error = $data['error'] ?? ['type' => 'unknown', 'reason' => 'Unknown error'];
            $errorMessage = is_array($error)
                ? ($error['reason'] ?? json_encode($error))
                : $error;

            throw new \RuntimeException(
                "Elasticsearch returned error {$statusCode}: {$errorMessage}"
            );
        }

        return $data;
    }

    /**
     * Get the configured Elasticsearch host URL.
     */
    public function getHost(): string
    {
        return $this->host;
    }
}
