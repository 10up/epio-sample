<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Shared;

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Embeddings\EmbeddingService;
use ElasticPressIO\Sample\Embeddings\OpenAIEmbeddingProvider;
use ElasticPressIO\Sample\RAG\OpenAIChatProvider;
use ElasticPressIO\Sample\RAG\RagService;
use ElasticPressIO\Sample\Search\SearchService;

/**
 * Assembles application services from configuration.
 *
 * Both the web API and CLI scripts use this factory to ensure consistent
 * service construction without duplicating provider instantiation.
 */
class ServiceFactory
{
    /**
     * Build the embedding service using the configured OpenAI-compatible provider.
     *
     * @throws \RuntimeException if OPENAI_API_KEY is not configured
     */
    public static function embeddingService(Config $config): EmbeddingService
    {
        $apiKey = $config->getOpenAIApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Semantic search requires OPENAI_API_KEY to be set in .env.'
            );
        }

        $provider = new OpenAIEmbeddingProvider(
            $apiKey,
            $config->getOpenAIApiBaseUrl(),
            $config->getOpenAIEmbeddingModel(),
            $config->getOpenAIEmbeddingDimensions()
        );

        return new EmbeddingService($provider);
    }

    /**
     * Build the chat completion provider using the configured OpenAI-compatible endpoint.
     *
     * @throws \RuntimeException if OPENAI_API_KEY is not configured
     */
    public static function chatProvider(Config $config): OpenAIChatProvider
    {
        $apiKey = $config->getOpenAIApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'The Ask AI feature requires OPENAI_API_KEY to be set in .env.'
            );
        }

        return new OpenAIChatProvider(
            $apiKey,
            $config->getOpenAIApiBaseUrl(),
            $config->getOpenAIChatModel()
        );
    }

    /**
     * Build the RAG service with all its dependencies.
     *
     * @throws \RuntimeException if OPENAI_API_KEY is not configured
     */
    public static function ragService(Config $config): RagService
    {
        return new RagService(
            self::embeddingService($config),
            new SearchService(),
            self::chatProvider($config)
        );
    }
}
