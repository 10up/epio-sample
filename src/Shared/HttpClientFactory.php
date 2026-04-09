<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Shared;

use GuzzleHttp\Client;

/**
 * Factory for Guzzle HTTP clients pre-configured for OpenAI-compatible APIs.
 */
class HttpClientFactory
{
    /**
     * Create a Guzzle client for any OpenAI-compatible endpoint.
     *
     * Sets standard headers (Authorization, Content-Type, Accept) and disables
     * Guzzle's built-in exception throwing so callers can inspect the status code
     * and response body directly.
     *
     * @param string $baseUrl   Base URL including the API version path (e.g. https://api.openai.com/v1)
     * @param string $apiKey    Bearer token
     * @param int    $timeout   Request timeout in seconds
     */
    public static function openAI(string $baseUrl, string $apiKey, int $timeout = 60): Client
    {
        return new Client([
            'base_uri'    => rtrim($baseUrl, '/') . '/',
            'headers'     => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'     => $timeout,
            'http_errors' => false,
        ]);
    }
}
