<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Tests;

use ElasticPressIO\Sample\Config\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testEnvMethodResolvesFromEnvSuperglobal(): void
    {
        $config = Config::getInstance();
        $method = new \ReflectionMethod($config, 'env');

        $_ENV['__TEST_CONFIG_VAR'] = 'from_env';

        try {
            $result = $method->invoke($config, '__TEST_CONFIG_VAR', 'default');
            $this->assertSame('from_env', $result);
        } finally {
            unset($_ENV['__TEST_CONFIG_VAR']);
        }
    }

    public function testEnvMethodResolvesFromServerSuperglobal(): void
    {
        $config = Config::getInstance();
        $method = new \ReflectionMethod($config, 'env');

        $_SERVER['__TEST_CONFIG_SERVER'] = 'from_server';

        try {
            $result = $method->invoke($config, '__TEST_CONFIG_SERVER', 'default');
            $this->assertSame('from_server', $result);
        } finally {
            unset($_SERVER['__TEST_CONFIG_SERVER']);
        }
    }

    public function testEnvMethodReturnsDefaultWhenNotSet(): void
    {
        $config = Config::getInstance();
        $method = new \ReflectionMethod($config, 'env');

        $result = $method->invoke($config, '__NONEXISTENT_VAR_XYZ', 'my_default');
        $this->assertSame('my_default', $result);
    }

    public function testGetWithDotNotation(): void
    {
        $config = Config::getInstance();

        $host = $config->get('elasticsearch.host');
        $this->assertIsString($host);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $config = Config::getInstance();

        $result = $config->get('nonexistent.deep.key', 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function testEmbeddingDimensionsIsInteger(): void
    {
        $config = Config::getInstance();

        $dims = $config->getOpenAIEmbeddingDimensions();
        $this->assertIsInt($dims);
        $this->assertGreaterThan(0, $dims);
    }
}
