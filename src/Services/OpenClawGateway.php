<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Services;

use Illuminate\Support\Str;
use RuntimeException;
use WebSocket\Client;

class OpenClawGateway
{
    private string $wsUrl;
    private string $token;
    private int $timeoutSeconds;
    private string $sessionPrefix;
    private string $defaultAgent;

    public function __construct()
    {
        $this->wsUrl = (string) config('oc-bridge.gateway.url', 'ws://127.0.0.1:18789');
        $this->token = (string) config('oc-bridge.gateway.token', '');
        $this->timeoutSeconds = (int) config('oc-bridge.gateway.timeout', 600);
        $this->sessionPrefix = (string) config('oc-bridge.session_prefix', 'market-studies');
        $this->defaultAgent = (string) config('oc-bridge.default_agent', 'main');
    }

    /**
     * Send a message to OpenClaw and wait for the complete response.
     *
     * Memory ID mechanism:
     * When a memoryId is provided, the session key includes that ID,
     * allowing OpenClaw to maintain memory continuity across multiple
     * calls. All calls with the same memoryId share the same agent
     * session and accumulated context.
     *
     * @param  string  $message  The prompt to send to the agent
     * @param  string|null  $memoryId  UUID for memory continuity across calls
     * @param  string|null  $agentId  OpenClaw agent to route to (null = default from config)
     * @return array{reply: string, session_key: string}
     */
    public function sendMessage(string $message, ?string $memoryId = null, ?string $agentId = null): array
    {
        $client = new Client($this->wsUrl, [
            'timeout' => $this->timeoutSeconds,
        ]);

        try {
            $runId = Str::uuid()->toString();
            $sessionKey = $this->buildSessionKey($memoryId, $agentId);

            $this->readNonce($client);
            $this->authenticate($client, $runId);
            $this->sendChatRequest($client, $sessionKey, $message, $runId);

            $reply = $this->waitForResponse($client, $runId);

            return ['reply' => $reply, 'session_key' => $sessionKey];
        } finally {
            $client->close();
        }
    }

    /**
     * Stream message events via callback.
     *
     * @param  callable(string $type, array $data): void  $onEvent  Types: 'delta', 'complete', 'error'
     * @param  callable(): void|null  $onIdle  Called periodically during wait (keepalive)
     * @param  string|null  $agentId  OpenClaw agent to route to (null = default from config)
     */
    public function streamMessage(string $message, ?string $memoryId, callable $onEvent, ?callable $onIdle = null, ?string $agentId = null): void
    {
        $client = new Client($this->wsUrl, [
            'timeout' => 30,
        ]);

        try {
            $runId = Str::uuid()->toString();
            $sessionKey = $this->buildSessionKey($memoryId, $agentId);

            $this->readNonce($client);
            $this->authenticate($client, $runId);
            $this->sendChatRequest($client, $sessionKey, $message, $runId);

            $deadline = time() + $this->timeoutSeconds;
            $lastKeepalive = time();

            while (time() < $deadline) {
                try {
                    $raw = $client->receive();
                } catch (\WebSocket\TimeoutException $e) {
                    if ($onIdle) {
                        $onIdle();
                        $lastKeepalive = time();
                    }
                    continue;
                }

                if ($onIdle && (time() - $lastKeepalive) >= 25) {
                    $onIdle();
                    $lastKeepalive = time();
                }

                $frame = json_decode($raw, true);

                if (! $frame || ($frame['type'] ?? '') !== 'event') {
                    continue;
                }

                $data = $frame['data'] ?? $frame['payload'] ?? $frame;
                $eventRunId = $data['runId'] ?? null;

                if ($eventRunId !== $runId) {
                    continue;
                }

                $stream = $data['stream'] ?? null;
                $state = $data['state'] ?? null;

                if ($stream === 'assistant') {
                    $inner = $data['data'] ?? [];
                    $onEvent('delta', [
                        'delta' => $inner['delta'] ?? '',
                        'text' => $inner['text'] ?? '',
                    ]);
                    $lastKeepalive = time();
                    continue;
                }

                if ($state === 'error') {
                    $errorMsg = $data['errorMessage'] ?? 'agent error';
                    $onEvent('error', ['message' => $errorMsg]);
                    return;
                }

                if ($state === 'final') {
                    $text = $this->extractText($data);
                    $onEvent('complete', [
                        'text' => $text,
                        'session_key' => $sessionKey,
                    ]);
                    return;
                }
            }

            $onEvent('error', ['message' => 'Gateway response timeout']);
        } finally {
            $client->close();
        }
    }

    /**
     * Build the session key for OpenClaw.
     *
     * Format: agent:{agentId}:{prefix}-{memoryId}
     *
     * The agent ID in the session key determines which OpenClaw agent
     * handles the request. The memoryId enables context continuity
     * across multiple calls for the same study.
     */
    private function buildSessionKey(?string $memoryId, ?string $agentId = null): string
    {
        $agent = $agentId ?? $this->defaultAgent;
        $suffix = $memoryId ?? 'default';

        return "agent:{$agent}:{$this->sessionPrefix}-{$suffix}";
    }

    /**
     * Read and discard the nonce challenge, skipping any broadcast events.
     */
    private function readNonce(Client $client): void
    {
        $deadline = time() + 10;
        while (time() < $deadline) {
            $frame = json_decode($client->receive(), true);

            if (($frame['event'] ?? null) === 'connect.challenge') {
                return;
            }
        }

        throw new RuntimeException('Gateway: no nonce challenge received');
    }

    /**
     * Authenticate with the gateway using token auth (protocol v3).
     */
    private function authenticate(Client $client, string $runId): void
    {
        $requestId = 'connect-'.$runId;

        $connectFrame = [
            'type' => 'req',
            'method' => 'connect',
            'id' => $requestId,
            'params' => [
                'minProtocol' => 3,
                'maxProtocol' => 3,
                'role' => 'operator',
                'auth' => [
                    'token' => $this->token,
                ],
                'client' => [
                    'id' => 'gateway-client',
                    'displayName' => 'Market Studies Bridge',
                    'mode' => 'backend',
                    'version' => '1.0.0',
                    'platform' => 'linux',
                ],
            ],
        ];

        $client->text(json_encode($connectFrame));

        $deadline = time() + 30;
        while (time() < $deadline) {
            $response = json_decode($client->receive(), true);

            if (($response['type'] ?? '') === 'event') {
                continue;
            }

            if (($response['id'] ?? '') === $requestId) {
                if (($response['ok'] ?? false) !== true) {
                    $error = $response['error']['message'] ?? json_encode($response);
                    throw new RuntimeException("Gateway auth failed: {$error}");
                }

                return;
            }
        }

        throw new RuntimeException('Gateway auth: no acknowledgement received');
    }

    /**
     * Send a chat.send request to the gateway.
     */
    private function sendChatRequest(Client $client, string $sessionKey, string $message, string $runId): void
    {
        $requestId = 'chat-'.$runId;

        $chatFrame = [
            'type' => 'req',
            'method' => 'chat.send',
            'id' => $requestId,
            'params' => [
                'sessionKey' => $sessionKey,
                'message' => $message,
                'idempotencyKey' => $runId,
            ],
        ];

        $client->text(json_encode($chatFrame));

        $deadline = time() + 30;
        while (time() < $deadline) {
            $response = json_decode($client->receive(), true);

            if (($response['type'] ?? '') === 'event') {
                continue;
            }

            if (($response['id'] ?? '') === $requestId) {
                if (($response['ok'] ?? false) !== true) {
                    $error = $response['error']['message'] ?? json_encode($response);
                    throw new RuntimeException("Gateway chat.send failed: {$error}");
                }

                return;
            }
        }

        throw new RuntimeException('Gateway chat.send: no acknowledgement received');
    }

    /**
     * Wait for the final response from the agent.
     */
    private function waitForResponse(Client $client, string $runId): string
    {
        $deadline = time() + $this->timeoutSeconds;

        while (time() < $deadline) {
            $raw = $client->receive();
            $frame = json_decode($raw, true);

            if (! $frame || ($frame['type'] ?? '') !== 'event') {
                continue;
            }

            $data = $frame['data'] ?? $frame['payload'] ?? $frame;
            $eventRunId = $data['runId'] ?? null;

            if ($eventRunId !== $runId) {
                continue;
            }

            $state = $data['state'] ?? null;

            if ($state === 'error') {
                $errorMsg = $data['errorMessage'] ?? 'agent error';
                throw new RuntimeException("Agent error: {$errorMsg}");
            }

            if ($state === 'final') {
                return $this->extractText($data);
            }
        }

        throw new RuntimeException('Gateway response timeout');
    }

    /**
     * Extract text content from the final response data.
     */
    private function extractText(array $data): string
    {
        $message = $data['message'] ?? null;

        if (! $message) {
            return 'No response generated';
        }

        $content = $message['content'] ?? [];
        $texts = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $texts[] = $block['text'];
            }
        }

        return implode("\n\n", $texts) ?: 'No response generated';
    }
}
