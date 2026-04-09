<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\MCP;

/**
 * MCP (Model Context Protocol) server implementation over JSON-RPC 2.0 / stdio.
 *
 * Listens on stdin for JSON-RPC requests, routes them to registered tool handlers,
 * and writes JSON-RPC responses to stdout.
 *
 * Critical: stdout is exclusively for JSON-RPC messages. All debug/progress output
 * must go to stderr so it does not corrupt the protocol stream.
 *
 * @see https://modelcontextprotocol.io/docs/concepts/architecture
 * @see https://www.jsonrpc.org/specification
 */
class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'epio-sample';
    private const SERVER_VERSION   = '1.0.0';

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly string $serverName = self::SERVER_NAME,
        private readonly string $serverVersion = self::SERVER_VERSION
    ) {
        $this->stdin  = fopen('php://stdin', 'r');
        $this->stdout = fopen('php://stdout', 'w');
    }

    /**
     * Start the server main loop.
     *
     * Reads newline-delimited JSON-RPC messages from stdin until EOF.
     * Each line is expected to be a complete JSON-RPC message.
     */
    public function run(): void
    {
        $this->log("MCP server starting ({$this->serverName} v{$this->serverVersion})");
        $this->log("Waiting for messages on stdin...");

        while (!feof($this->stdin)) {
            $line = fgets($this->stdin);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $this->handleMessage(trim($line));
        }

        $this->log("stdin closed, shutting down.");
    }

    /**
     * Handle a single JSON-RPC message.
     */
    private function handleMessage(string $raw): void
    {
        $message = json_decode($raw, true);

        if ($message === null) {
            $this->sendError(null, -32700, 'Parse error: invalid JSON');
            return;
        }

        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0') {
            $id = $message['id'] ?? null;
            $this->sendError($id, -32600, 'Invalid Request: missing or wrong jsonrpc version');
            return;
        }

        $id     = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        // Notifications (no id) — process but do not send a response
        $isNotification = !array_key_exists('id', $message);

        try {
            $result = $this->dispatch($method, $params);

            if (!$isNotification && $result !== null) {
                $this->sendResult($id, $result);
            }
        } catch (\InvalidArgumentException $e) {
            if (!$isNotification) {
                $this->sendError($id, -32601, 'Method not found: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->log("Error handling {$method}: " . $e->getMessage());
            if (!$isNotification) {
                $this->sendError($id, -32603, 'Internal error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Route a JSON-RPC method to the appropriate handler.
     *
     * @return mixed Result to send back, or null for notifications
     */
    private function dispatch(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize'    => $this->handleInitialize($params),
            'initialized'   => null, // notification, no response needed
            'ping'          => [],   // simple liveness check
            'tools/list'    => $this->handleToolsList($params),
            'tools/call'    => $this->handleToolsCall($params),
            default         => throw new \InvalidArgumentException($method),
        };
    }

    private function handleInitialize(array $params): array
    {
        $this->log("Client connected: " . ($params['clientInfo']['name'] ?? 'unknown') .
            ' v' . ($params['clientInfo']['version'] ?? '?'));

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => $this->serverName,
                'version' => $this->serverVersion,
            ],
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
        ];
    }

    private function handleToolsList(array $params): array
    {
        return [
            'tools' => $this->toolRegistry->getToolDescriptors(),
        ];
    }

    private function handleToolsCall(array $params): array
    {
        $name      = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($name)) {
            throw new \InvalidArgumentException('tools/call requires a name parameter');
        }

        $this->log("Tool call: {$name}");

        $result = $this->toolRegistry->dispatch($name, $arguments);

        // MCP tool results must be wrapped in a content array
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    // ── Transport helpers ─────────────────────────────────────────────────────

    private function sendResult(mixed $id, mixed $result): void
    {
        $this->write([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->write([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
    }

    private function write(array $payload): void
    {
        fwrite($this->stdout, json_encode($payload) . "\n");
        fflush($this->stdout);
    }

    /** Write a debug message to stderr (never to stdout — that's reserved for JSON-RPC). */
    private function log(string $message): void
    {
        fwrite(STDERR, "[{$this->serverName}] {$message}\n");
    }
}
