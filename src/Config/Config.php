<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Config;

use Dotenv\Dotenv;

/**
 * Configuration manager for the application.
 *
 * Loads environment variables and provides access to configuration values.
 *
 * @see https://www.elasticpress.io/resources/articles/ ElasticPress.io Documentation
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->loadEnvironment();
        $this->initializeConfig();
    }

    /**
     * Get the singleton instance of Config.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load environment variables from .env file.
     */
    private function loadEnvironment(): void
    {
        $rootPath = dirname(__DIR__, 2);

        if (file_exists($rootPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }
    }

    /**
     * Initialize configuration from environment variables.
     */
    private function initializeConfig(): void
    {
        $subscriptionId = $_ENV['ELASTICPRESS_SUBSCRIPTION_ID'] ?? '';

        $this->config = [
            'elasticsearch' => [
                'host' => $_ENV['ELASTICPRESS_HOST'] ?? '',
                'subscription_id' => $subscriptionId,
                'subscription_token' => $_ENV['ELASTICPRESS_SUBSCRIPTION_TOKEN'] ?? '',
            ],
            'index' => [
                // Default to subscription_id with trailing dash for ElasticPress.io naming
                'prefix' => $subscriptionId ? $subscriptionId . '-' : '',
            ],
        ];
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param string $key Configuration key (e.g., 'elasticsearch.host')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get the full ElasticPress.io Host URL.
     */
    public function getElasticsearchHost(): string
    {
        return $this->get('elasticsearch.host', '');
    }

    /**
     * Get ElasticPress.io authentication credentials.
     *
     * @return array{subscription_id: string, subscription_token: string}
     */
    public function getElasticsearchAuth(): array
    {
        return [
            'subscription_id' => $this->get('elasticsearch.subscription_id', ''),
            'subscription_token' => $this->get('elasticsearch.subscription_token', ''),
        ];
    }

    /**
     * Get the ElasticPress.io Subscription ID.
     */
    public function getSubscriptionId(): string
    {
        return $this->get('elasticsearch.subscription_id', '');
    }

    /**
     * Get the ElasticPress.io Subscription Token.
     */
    public function getSubscriptionToken(): string
    {
        return $this->get('elasticsearch.subscription_token', '');
    }

    /**
     * Get the index prefix for all Nobel Prize indexes.
     *
     * Returns the subscription ID with a trailing dash (e.g., "thorsten-gpt-")
     * for ElasticPress.io naming convention.
     */
    public function getIndexPrefix(): string
    {
        return $this->get('index.prefix', '');
    }

    /**
     * Check if we're in development mode.
     */
    public function isDevelopment(): bool
    {
        return $this->get('app.env') === 'development';
    }

    /**
     * Validate that all required configuration is present.
     *
     * @throws \RuntimeException If required configuration is missing
     */
    public function validate(): void
    {
        $required = [
            'elasticsearch.host' => 'ELASTICPRESS_HOST',
            'elasticsearch.subscription_id' => 'ELASTICPRESS_SUBSCRIPTION_ID',
            'elasticsearch.subscription_token' => 'ELASTICPRESS_SUBSCRIPTION_TOKEN',
        ];

        $missing = [];
        foreach ($required as $key => $envVar) {
            if (empty($this->get($key))) {
                $missing[] = $envVar;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required configuration: ' . implode(', ', $missing) .
                "\nPlease copy .env.example to .env and fill in your ElasticPress.io credentials."
            );
        }
    }
}
