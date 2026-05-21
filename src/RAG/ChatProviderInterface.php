<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\RAG;

/**
 * Contract for chat completion providers.
 *
 * Implementations must be stateless so callers can generate completions
 * without caring which underlying API is used (OpenAI, Ollama, Groq, etc.).
 *
 * Messages follow the OpenAI chat format:
 *   [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']]
 */
interface ChatProviderInterface
{
    /**
     * Generate a chat completion for the given messages.
     *
     * @param array<array{role: string, content: string}> $messages Chat history
     * @param array $options Provider-specific options (e.g. max_tokens, temperature)
     * @return string The assistant's response text
     */
    public function complete(array $messages, array $options = []): string;

    /**
     * Generate a chat completion that may call tools (function calling).
     *
     * Returns the raw assistant turn so the caller can inspect tool_calls,
     * execute them, append results, and continue the conversation.
     *
     * @param array $messages Chat history
     * @param array $tools    OpenAI-format tool definitions [{type: "function", function: {...}}]
     * @param array $options  Provider-specific options
     * @return array{
     *   content: string|null,
     *   tool_calls: array|null,
     *   finish_reason: string
     * }
     */
    public function completeWithTools(array $messages, array $tools, array $options = []): array;
}
