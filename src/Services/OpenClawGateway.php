<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Services;

use Brunocfalcao\OCBridge\Contracts\Gateway;
use Brunocfalcao\OCBridge\Data\GatewayResponse;
use Brunocfalcao\OCBridge\Enums\StreamEvent;
use Brunocfalcao\OCBridge\Exceptions\ConnectionException;
use Brunocfalcao\OCBridge\Exceptions\GatewayException;
use Illuminate\Support\Str;
use WebSocket\Client;

/**
 * OpenClaw AI gateway client.
 *
 * Communicates with the OpenClaw gateway over WebSocket protocol v3.
 * Supports synchronous (send-and-wait) and streaming (event-driven)
 * message delivery, with persistent memory across calls via session keys.
 *
 * Protocol flow:
 *   1. Connect → receive nonce challenge
 *   2. Authenticate → send token, receive ACK
 *   3. chat.send → send message with session key
 *   4. Receive events → stream deltas or wait for final response
 *   5. Close → clean disconnect
 */
class OpenClawGateway implements Gateway
{
    /**
     * @param  string $wsUrl          WebSocket endpoint for the gateway.
     * @param  string $token          Authentication token.
     * @param  int    $timeoutSeconds Maximum seconds to wait for a response.
     * @param  string $sessionPrefix  Namespace prefix for session keys (isolates apps).
     * @param  string $defaultAgent   Agent ID to route messages to when none is specified.
     * @param  string $clientName     Display name sent to the gateway during auth.
     */
    public function __construct(
        private readonly string $wsUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds,
        private readonly string $sessionPrefix,
        private readonly string $defaultAgent,
        private readonly string $clientName = 'Laravel OpenClaw Bridge',
    ) {}

    /**
     * Send a message to the agent and wait for the complete response.
     *
     * When a $memoryId is provided, all calls sharing that ID connect to the
     * same OpenClaw session — the agent remembers everything from prior calls.
     */
    public function sendMessage(string $message, ?string $memoryId = null, ?string $agentId = null): GatewayResponse
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

            $text = $this->waitForResponse($client, $runId);

            return new GatewayResponse(text: $text, sessionKey: $sessionKey);
        } finally {
            $client->close();
        }
    }

    /**
     * Stream message events via callback.
     *
     * Unlike sendMessage(), this method dispatches events as they arrive
     * instead of blocking until the full response is ready. The $onIdle
     * callback fires periodically during quiet periods (useful for
     * heartbeats or progress indicators).
     */
    public function streamMessage(
        string $message,
        ?string $memoryId,
        callable $onEvent,
        ?callable $onIdle = null,
        ?string $agentId = null,
    ): void {
        $client = new Client($this->wsUrl, [
            'timeout' => 30, // Short timeout per receive — we loop with our own deadline.
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
                } catch (\WebSocket\TimeoutException) {
                    if ($onIdle) {
                        $onIdle();
                        $lastKeepalive = time();
                    }

                    continue;
                }

                // Fire keepalive if enough time has passed between events.
                if ($onIdle && (time() - $lastKeepalive) >= 25) {
                    $onIdle();
                    $lastKeepalive = time();
                }

                $frame = json_decode($raw, true);

                if (! $frame || ($frame['type'] ?? '') !== 'event') {
                    continue;
                }

                $data = $frame['data'] ?? $frame['payload'] ?? $frame;

                // Ignore events from other runs (concurrent requests).
                if (($data['runId'] ?? null) !== $runId) {
                    continue;
                }

                $stream = $data['stream'] ?? null;
                $state = $data['state'] ?? null;

                if ($stream === 'assistant') {
                    $inner = $data['data'] ?? [];
                    $onEvent(StreamEvent::Delta, [
                        'delta' => $inner['delta'] ?? '',
                        'text' => $inner['text'] ?? '',
                    ]);
                    $lastKeepalive = time();

                    continue;
                }

                if ($state === 'error') {
                    $onEvent(StreamEvent::Error, [
                        'message' => $data['errorMessage'] ?? 'Agent error',
                    ]);

                    return;
                }

                if ($state === 'final') {
                    $onEvent(StreamEvent::Complete, [
                        'text' => self::extractText($data),
                        'session_key' => $sessionKey,
                    ]);

                    return;
                }
            }

            $onEvent(StreamEvent::Error, ['message' => 'Gateway response timeout']);
        } finally {
            $client->close();
        }
    }

    // -----------------------------------------------------------------------
    //  Helpers — protected for testability
    // -----------------------------------------------------------------------

    /**
     * Build the OpenClaw session key.
     *
     * Format: agent:{agentId}:{prefix}-{memoryId}
     *
     * The session key determines both which agent handles the request and
     * which memory context to use. All calls with the same memoryId share
     * the same conversational context.
     */
    protected function buildSessionKey(?string $memoryId, ?string $agentId = null): string
    {
        $agent = $agentId ?? $this->defaultAgent;
        $suffix = $memoryId ?? 'default';

        return "agent:{$agent}:{$this->sessionPrefix}-{$suffix}";
    }

    /**
     * Extract text content from the gateway's final response payload.
     *
     * The response may contain multiple content blocks — this method
     * filters for text blocks and joins them with double newlines.
     */
    protected static function extractText(array $data): string
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

    // -----------------------------------------------------------------------
    //  Protocol internals
    // -----------------------------------------------------------------------

    /**
     * Wait for the nonce challenge from the gateway (connection handshake).
     *
     * @throws ConnectionException If no challenge is received within 10 seconds.
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

        throw new ConnectionException('Gateway: no nonce challenge received');
    }

    /**
     * Authenticate with the gateway using token auth (protocol v3).
     *
     * @throws ConnectionException If authentication fails or no ACK is received.
     */
    private function authenticate(Client $client, string $runId): void
    {
        $requestId = "connect-{$runId}";

        $client->text(json_encode([
            'type' => 'req',
            'method' => 'connect',
            'id' => $requestId,
            'params' => [
                'minProtocol' => 3,
                'maxProtocol' => 3,
                'role' => 'operator',
                'auth' => ['token' => $this->token],
                'client' => [
                    'id' => 'gateway-client',
                    'displayName' => $this->clientName,
                    'mode' => 'backend',
                    'version' => '1.0.0',
                    'platform' => PHP_OS_FAMILY,
                ],
            ],
        ]));

        $deadline = time() + 30;

        while (time() < $deadline) {
            $response = json_decode($client->receive(), true);

            // Skip broadcast events while waiting for our ACK.
            if (($response['type'] ?? '') === 'event') {
                continue;
            }

            if (($response['id'] ?? '') === $requestId) {
                if (($response['ok'] ?? false) !== true) {
                    $error = $response['error']['message'] ?? json_encode($response);
                    throw new ConnectionException("Gateway auth failed: {$error}");
                }

                return;
            }
        }

        throw new ConnectionException('Gateway auth: no acknowledgement received');
    }

    /**
     * Send a chat.send request to the gateway.
     *
     * Each request includes a UUID-based idempotency key so duplicate
     * requests (e.g. from retries) are safely ignored by the gateway.
     *
     * @throws GatewayException If the request is rejected or no ACK is received.
     */
    private function sendChatRequest(Client $client, string $sessionKey, string $message, string $runId): void
    {
        $requestId = "chat-{$runId}";

        $client->text(json_encode([
            'type' => 'req',
            'method' => 'chat.send',
            'id' => $requestId,
            'params' => [
                'sessionKey' => $sessionKey,
                'message' => $message,
                'idempotencyKey' => $runId,
            ],
        ]));

        $deadline = time() + 30;

        while (time() < $deadline) {
            $response = json_decode($client->receive(), true);

            if (($response['type'] ?? '') === 'event') {
                continue;
            }

            if (($response['id'] ?? '') === $requestId) {
                if (($response['ok'] ?? false) !== true) {
                    $error = $response['error']['message'] ?? json_encode($response);
                    throw new GatewayException("Gateway chat.send failed: {$error}");
                }

                return;
            }
        }

        throw new GatewayException('Gateway chat.send: no acknowledgement received');
    }

    /**
     * Wait for the agent's final response (synchronous mode).
     *
     * Blocks until a 'final' state event is received for the current run,
     * or throws on error/timeout.
     *
     * @throws GatewayException If the agent returns an error or the timeout is exceeded.
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

            if (($data['runId'] ?? null) !== $runId) {
                continue;
            }

            $state = $data['state'] ?? null;

            if ($state === 'error') {
                throw new GatewayException('Agent error: '.($data['errorMessage'] ?? 'unknown'));
            }

            if ($state === 'final') {
                return self::extractText($data);
            }
        }

        throw new GatewayException('Gateway response timeout');
    }
}
