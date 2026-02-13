<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrowserService
{
    private string $browserUrl;
    private ?string $targetId = null;
    private int $commandId = 0;
    /** @var resource|null */
    private $wsConnection = null;
    private ?string $wsDebuggerUrl = null;

    public function __construct(string $browserUrl = 'http://127.0.0.1:9222')
    {
        $this->browserUrl = $browserUrl;
    }

    /**
     * Open a new browser tab and navigate to URL.
     */
    public function open(string $url): string
    {
        $parsedHost = parse_url($url, PHP_URL_HOST);

        // Reuse existing tab on same domain
        try {
            $existingTargets = Http::get("{$this->browserUrl}/json");
            if ($existingTargets->successful()) {
                foreach ($existingTargets->json() as $target) {
                    if (($target['type'] ?? '') !== 'page') {
                        continue;
                    }
                    $targetHost = parse_url($target['url'] ?? '', PHP_URL_HOST);
                    if ($targetHost === $parsedHost) {
                        $this->targetId = $target['id'];
                        $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
                        $this->disconnectWebSocket();

                        if (($target['url'] ?? '') !== $url) {
                            $this->navigate($url);
                        }

                        return $this->targetId;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fall through to create a new tab
        }

        $encodedUrl = urlencode($url);
        $response = Http::put("{$this->browserUrl}/json/new?{$encodedUrl}");

        if (! $response->successful()) {
            throw new \Exception('Failed to open browser: '.$response->body());
        }

        $data = $response->json();
        $this->targetId = $data['id'] ?? null;
        $this->wsDebuggerUrl = $data['webSocketDebuggerUrl'] ?? null;

        if (! $this->targetId) {
            throw new \Exception('No target ID returned from browser');
        }

        $this->disconnectWebSocket();
        $this->waitForPageReady();

        return $this->targetId;
    }

    /**
     * Navigate to a URL in the current tab.
     */
    public function navigate(string $url): void
    {
        $this->ensureTarget();
        $this->sendCommand('Page.navigate', ['url' => $url]);
        $this->waitForPageReady();
    }

    /**
     * Take a screenshot (full page by default).
     *
     * @return string Base64 encoded PNG data, or the file path if $path is given
     */
    public function screenshot(?string $path = null, bool $fullPage = true): string
    {
        $this->ensureTarget();

        $screenshotParams = ['format' => 'png'];

        if ($fullPage) {
            $layout = $this->sendCommand('Page.getLayoutMetrics');
            $contentSize = $layout['cssContentSize'] ?? $layout['contentSize'] ?? null;
            $viewport = $layout['cssLayoutViewport'] ?? $layout['layoutViewport'] ?? null;

            if ($contentSize || $viewport) {
                $width = max(
                    (float) ($contentSize['width'] ?? 0),
                    (float) ($viewport['clientWidth'] ?? 0),
                );
                $height = max(
                    (float) ($contentSize['height'] ?? 0),
                    (float) ($viewport['clientHeight'] ?? 0),
                );

                $screenshotParams['captureBeyondViewport'] = true;
                $screenshotParams['clip'] = [
                    'x' => 0,
                    'y' => 0,
                    'width' => $width,
                    'height' => $height,
                    'scale' => 1,
                ];
            }
        }

        $result = $this->sendCommand('Page.captureScreenshot', $screenshotParams);
        $imageData = $result['data'] ?? null;

        if (! $imageData) {
            throw new \Exception('Screenshot failed');
        }

        if ($path) {
            file_put_contents($path, base64_decode($imageData));

            return $path;
        }

        return $imageData;
    }

    /**
     * Test connection to headless Chrome.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->browserUrl}/json/version");

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Close the browser tab.
     */
    public function close(): void
    {
        $this->disconnectWebSocket();

        if ($this->targetId) {
            Http::get("{$this->browserUrl}/json/close/{$this->targetId}");
            $this->targetId = null;
        }
    }

    public function __destruct()
    {
        $this->disconnectWebSocket();
    }

    /**
     * Send a Chrome DevTools Protocol command.
     */
    private function sendCommand(string $method, array $params = []): array
    {
        $this->ensureTarget();
        $this->ensureWebSocket();

        $id = ++$this->commandId;

        $payload = json_encode([
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ]);

        $this->wsSend($payload);

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $frame = $this->wsReceive();

            if ($frame === null) {
                throw new \Exception("WebSocket connection closed while waiting for CDP response to {$method}");
            }

            $data = json_decode($frame, true);

            if (! is_array($data)) {
                continue;
            }

            if (isset($data['method']) && ! isset($data['id'])) {
                continue;
            }

            if (isset($data['id']) && $data['id'] === $id) {
                if (isset($data['error'])) {
                    throw new \Exception("CDP error for {$method}: ".($data['error']['message'] ?? json_encode($data['error'])));
                }

                return $data['result'] ?? [];
            }
        }

        throw new \Exception("Timeout waiting for CDP response to {$method}");
    }

    private function ensureWebSocket(): void
    {
        if ($this->wsConnection && is_resource($this->wsConnection)) {
            return;
        }

        if (! $this->wsDebuggerUrl) {
            $response = Http::get("{$this->browserUrl}/json");

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch browser targets');
            }

            foreach ($response->json() as $target) {
                if (($target['id'] ?? null) === $this->targetId) {
                    $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
                    break;
                }
            }

            if (! $this->wsDebuggerUrl) {
                throw new \Exception("No webSocketDebuggerUrl found for target {$this->targetId}");
            }
        }

        $this->connectWebSocket();
    }

    private function connectWebSocket(): void
    {
        $parsed = parse_url($this->wsDebuggerUrl);

        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 9222;
        $path = $parsed['path'] ?? '/';

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            10,
        );

        if (! $socket) {
            throw new \Exception("WebSocket TCP connect failed: [{$errno}] {$errstr}");
        }

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."\r\n";

        fwrite($socket, $handshake);

        $responseHeader = '';
        while (($line = fgets($socket)) !== false) {
            $responseHeader .= $line;
            if (rtrim($line) === '') {
                break;
            }
        }

        if (strpos($responseHeader, '101') === false) {
            fclose($socket);
            throw new \Exception('WebSocket handshake failed: '.strtok($responseHeader, "\r\n"));
        }

        stream_set_timeout($socket, 30);
        $this->wsConnection = $socket;
    }

    private function wsSend(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        $written = @fwrite($this->wsConnection, $frame);

        if ($written === false || $written < strlen($frame)) {
            throw new \Exception('Failed to write WebSocket frame');
        }
    }

    private function wsReceive(): ?string
    {
        $header = $this->wsReadExact(2);

        if ($header === null) {
            return null;
        }

        $firstByte = ord($header[0]);
        $secondByte = ord($header[1]);
        $opcode = $firstByte & 0x0F;

        if ($opcode === 0x08) {
            return null;
        }

        $masked = ($secondByte & 0x80) !== 0;
        $payloadLength = $secondByte & 0x7F;

        if ($payloadLength === 126) {
            $ext = $this->wsReadExact(2);
            if ($ext === null) {
                return null;
            }
            $payloadLength = unpack('n', $ext)[1];
        } elseif ($payloadLength === 127) {
            $ext = $this->wsReadExact(8);
            if ($ext === null) {
                return null;
            }
            $payloadLength = unpack('J', $ext)[1];
        }

        $maskKey = null;
        if ($masked) {
            $maskKey = $this->wsReadExact(4);
            if ($maskKey === null) {
                return null;
            }
        }

        $payload = '';
        if ($payloadLength > 0) {
            $payload = $this->wsReadExact($payloadLength);
            if ($payload === null) {
                return null;
            }

            if ($maskKey !== null) {
                for ($i = 0; $i < $payloadLength; $i++) {
                    $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
                }
            }
        }

        if ($opcode === 0x09) {
            $this->wsSendPong($payload);

            return $this->wsReceive();
        }

        return $payload;
    }

    private function wsReadExact(int $length): ?string
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->wsConnection, $remaining);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->wsConnection);
                if ($meta['timed_out']) {
                    throw new \Exception('WebSocket read timed out');
                }

                return null;
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    private function wsSendPong(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x8A);

        $mask = random_bytes(4);
        $frame .= chr(0x80 | $length);
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($this->wsConnection, $frame);
    }

    private function disconnectWebSocket(): void
    {
        if ($this->wsConnection && is_resource($this->wsConnection)) {
            $mask = random_bytes(4);
            $closeFrame = chr(0x88).chr(0x80).$mask;
            @fwrite($this->wsConnection, $closeFrame);
            @fclose($this->wsConnection);
        }

        $this->wsConnection = null;
    }

    private function waitForPageReady(int $timeoutSeconds = 15): void
    {
        sleep(1);

        $this->sendCommand('Runtime.evaluate', [
            'expression' => "new Promise((resolve) => {
                const timeout = setTimeout(() => resolve('timeout'), ".($timeoutSeconds * 1000).");
                const check = () => {
                    if (document.readyState === 'complete') {
                        clearTimeout(timeout);
                        resolve('ready');
                    } else {
                        setTimeout(check, 100);
                    }
                };
                check();
            })",
            'awaitPromise' => true,
            'returnByValue' => true,
        ]);
    }

    private function ensureTarget(): void
    {
        if ($this->targetId) {
            return;
        }

        try {
            $response = Http::get("{$this->browserUrl}/json");

            if (! $response->successful()) {
                throw new \Exception('No browser tab open. Call open() first.');
            }

            $targets = $response->json();

            foreach ($targets as $target) {
                if (($target['type'] ?? '') === 'page') {
                    $this->targetId = $target['id'];
                    $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
                    $this->disconnectWebSocket();

                    return;
                }
            }
        } catch (\Exception $e) {
            // Fall through
        }

        throw new \Exception('No browser tab open. Call open() first.');
    }
}
