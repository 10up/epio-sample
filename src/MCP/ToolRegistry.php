<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\MCP;

/**
 * Registry for MCP tool handlers.
 *
 * Each tool has a name, a JSON Schema describing its input parameters,
 * a human-readable description, and a callable handler.
 */
class ToolRegistry
{
    /** @var array<string, array{description: string, inputSchema: array, handler: callable}> */
    private array $tools = [];

    /**
     * Register a tool.
     *
     * @param string $name Tool name (must be unique and match [a-zA-Z0-9_-]+)
     * @param string $description Human-readable description shown to the AI model
     * @param array $inputSchema JSON Schema for the tool's input parameters
     * @param callable $handler fn(array $arguments): mixed
     */
    public function register(string $name, string $description, array $inputSchema, callable $handler): void
    {
        $this->tools[$name] = [
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler'     => $handler,
        ];
    }

    /**
     * Get all registered tools as MCP tool descriptors.
     *
     * @return array[]
     */
    public function getToolDescriptors(): array
    {
        $descriptors = [];
        foreach ($this->tools as $name => $tool) {
            $descriptors[] = [
                'name'        => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }
        return $descriptors;
    }

    /**
     * Dispatch a tool call.
     *
     * @param string $name Tool name
     * @param array $arguments Tool input arguments
     * @return mixed Tool result (will be JSON-encoded by the caller)
     * @throws \InvalidArgumentException If the tool is not registered
     */
    public function dispatch(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        return ($this->tools[$name]['handler'])($arguments);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
}
