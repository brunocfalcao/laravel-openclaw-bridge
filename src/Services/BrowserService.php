<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Services;

use Brunocfalcao\OCBridge\Contracts\Browser;
use Brunocfalcao\OCBridge\Exceptions\BrowserException;
use Illuminate\Support\Facades\Http;

/**
 * Headless Chrome browser automation via Chrome DevTools Protocol.
 *
 * Provides a pure-PHP CDP client — no Node.js or Puppeteer required.
 * Manages its own raw WebSocket connection to Chrome for sending
 * CDP commands (navigate, screenshot, evaluate JS).
 *
 * Tab management is intelligent: opening a URL on a domain that already
 * has an open tab reuses that tab instead of creating a new one.
 */
class BrowserService implements Browser
{
    private ?string $targetId = null;

    private int $commandId = 0;

    /** @var resource|null Raw TCP socket for CDP WebSocket communication. */
    private $wsConnection = null;

    private ?string $wsDebuggerUrl = null;

    public function __construct(
        private readonly string $browserUrl = 'http://127.0.0.1:9222',
    ) {}

    /**
     * Open a URL in a browser tab.
     *
     * If a tab already exists on the same domain, it is reused (navigated
     * to the new URL). Otherwise a new tab is created.
     *
     * @return string The browser target/tab ID.
     *
     * @throws BrowserException If Chrome is unreachable or refuses the request.
     */
    public function open(string $url): string
    {
        $parsedHost = parse_url($url, PHP_URL_HOST);

        // Try to reuse an existing tab on the same domain.
        if ($target = $this->findTabByHost($parsedHost)) {
            $this->targetId = $target['id'];
            $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
            $this->disconnectWebSocket();

            if (($target['url'] ?? '') !== $url) {
                $this->navigate($url);
            }

            return $this->targetId;
        }

        // No matching tab — open a new one.
        $encodedUrl = urlencode($url);
        $response = Http::put("{$this->browserUrl}/json/new?{$encodedUrl}");

        if (! $response->successful()) {
            throw new BrowserException('Failed to open browser tab: '.$response->body());
        }

        $data = $response->json();
        $this->targetId = $data['id'] ?? null;
        $this->wsDebuggerUrl = $data['webSocketDebuggerUrl'] ?? null;

        if (! $this->targetId) {
            throw new BrowserException('No target ID returned from Chrome');
        }

        $this->disconnectWebSocket();
        $this->waitForPageReady();

        return $this->targetId;
    }

    /**
     * Navigate to a URL in the current tab.
     *
     * @throws BrowserException If no tab is open or navigation fails.
     */
    public function navigate(string $url): void
    {
        $this->ensureTarget();
        $this->sendCommand('Page.navigate', ['url' => $url]);
        $this->waitForPageReady();
    }

    /**
     * Take a screenshot of the current page.
     *
     * @param  string|null $path      Save PNG to this path. Null = return base64 data.
     * @param  bool        $fullPage  Capture entire scrollable page (true) or viewport only.
     * @return string File path if $path was given, otherwise base64-encoded PNG.
     *
     * @throws BrowserException If no tab is open or the screenshot fails.
     */
    public function screenshot(?string $path = null, bool $fullPage = true): string
    {
        $this->ensureTarget();

        $params = ['format' => 'png'];

        if ($fullPage) {
            $params = array_merge($params, $this->fullPageClip());
        }

        $result = $this->sendCommand('Page.captureScreenshot', $params);
        $imageData = $result['data'] ?? null;

        if (! $imageData) {
            throw new BrowserException('Screenshot capture returned no data');
        }

        if ($path) {
            file_put_contents($path, base64_decode($imageData));

            return $path;
        }

        return $imageData;
    }

    /**
     * Test whether headless Chrome is running and reachable.
     */
    public function testConnection(): bool
    {
        try {
            return Http::timeout(5)
                ->get("{$this->browserUrl}/json/version")
                ->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Type text into a focused element identified by CSS selector.
     *
     * Focuses the element, selects all existing content (Ctrl+A), then
     * inserts the new text via CDP Input.insertText to trigger DOM events.
     *
     * @throws BrowserException If no tab is open.
     */
    public function type(string $selector, string $text): void
    {
        $this->ensureTarget();

        // Focus the element via JavaScript.
        $this->sendCommand('Runtime.evaluate', [
            'expression' => 'document.querySelector('.json_encode($selector).').focus()',
        ]);
        usleep(300000);

        // Select all existing text (Ctrl+A) to clear the field.
        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'key' => 'a',
            'code' => 'KeyA',
            'modifiers' => 2, // Ctrl
        ]);
        $this->sendCommand('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => 'a',
            'code' => 'KeyA',
            'modifiers' => 2,
        ]);
        usleep(100000);

        // Insert text — properly triggers DOM input/change events.
        $this->sendCommand('Input.insertText', ['text' => $text]);
        usleep(200000);
    }

    /**
     * Click an element identified by CSS selector.
     *
     * Calculates the element's center position and dispatches
     * mousePressed + mouseReleased events via CDP.
     *
     * @throws BrowserException If no tab is open or the element is not found.
     */
    public function click(string $selector): void
    {
        $this->ensureTarget();

        $element = $this->querySelector($selector);

        if (! $element) {
            throw new BrowserException("Element not found: {$selector}");
        }

        $centerX = $element['x'] + ($element['width'] / 2);
        $centerY = $element['y'] + ($element['height'] / 2);

        $this->sendCommand('Input.dispatchMouseEvent', [
            'type' => 'mousePressed',
            'x' => $centerX,
            'y' => $centerY,
            'button' => 'left',
            'clickCount' => 1,
        ]);
        usleep(100000);

        $this->sendCommand('Input.dispatchMouseEvent', [
            'type' => 'mouseReleased',
            'x' => $centerX,
            'y' => $centerY,
            'button' => 'left',
            'clickCount' => 1,
        ]);
        sleep(1);
    }

    /**
     * Wait for an element matching the CSS selector to appear in the DOM.
     *
     * Polls once per second until the element is found or the timeout expires.
     *
     * @param  int  $timeoutSeconds  Maximum seconds to wait.
     * @return bool True if element appeared, false on timeout.
     *
     * @throws BrowserException If no tab is open.
     */
    public function waitForSelector(string $selector, int $timeoutSeconds = 30): bool
    {
        $this->ensureTarget();

        $startTime = time();

        while (time() - $startTime < $timeoutSeconds) {
            if ($this->querySelector($selector)) {
                return true;
            }

            sleep(1);
        }

        return false;
    }

    /**
     * Get the full HTML content of the current page.
     *
     * @throws BrowserException If no tab is open.
     */
    public function getContent(): string
    {
        $this->ensureTarget();

        $result = $this->sendCommand('Runtime.evaluate', [
            'expression' => 'document.documentElement.outerHTML',
            'returnByValue' => true,
        ]);

        return $result['result']['value'] ?? '';
    }

    /**
     * Evaluate a JavaScript expression in the page context.
     *
     * Supports async expressions (awaitPromise is enabled).
     *
     * @return mixed The return value of the expression.
     *
     * @throws BrowserException If the expression throws or no tab is open.
     */
    public function evaluateJavaScript(string $expression): mixed
    {
        $this->ensureTarget();

        $result = $this->sendCommand('Runtime.evaluate', [
            'expression' => $expression,
            'returnByValue' => true,
            'awaitPromise' => true,
        ]);

        if (isset($result['exceptionDetails'])) {
            $message = $result['exceptionDetails']['exception']['description']
                ?? $result['exceptionDetails']['text']
                ?? 'JavaScript evaluation error';

            throw new BrowserException("JS error: {$message}");
        }

        return $result['result']['value'] ?? null;
    }

    /**
     * Close the current browser tab and release resources.
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

    // -----------------------------------------------------------------------
    //  CDP command layer
    // -----------------------------------------------------------------------

    /**
     * Send a Chrome DevTools Protocol command and wait for the response.
     *
     * @throws BrowserException If the command fails, times out, or the connection drops.
     */
    private function sendCommand(string $method, array $params = []): array
    {
        $this->ensureTarget();
        $this->ensureWebSocket();

        $id = ++$this->commandId;

        $this->wsSend(json_encode([
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ]));

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $frame = $this->wsReceive();

            if ($frame === null) {
                throw new BrowserException("WebSocket closed while waiting for CDP response to {$method}");
            }

            $data = json_decode($frame, true);

            if (! is_array($data)) {
                continue;
            }

            // Skip CDP event notifications (no id field).
            if (isset($data['method']) && ! isset($data['id'])) {
                continue;
            }

            if (isset($data['id']) && $data['id'] === $id) {
                if (isset($data['error'])) {
                    $msg = $data['error']['message'] ?? json_encode($data['error']);
                    throw new BrowserException("CDP error for {$method}: {$msg}");
                }

                return $data['result'] ?? [];
            }
        }

        throw new BrowserException("Timeout waiting for CDP response to {$method}");
    }

    // -----------------------------------------------------------------------
    //  Tab/target management
    // -----------------------------------------------------------------------

    /**
     * Find an existing page tab whose URL matches the given host.
     *
     * @return array|null The matching target data, or null if none found.
     */
    private function findTabByHost(?string $host): ?array
    {
        if (! $host) {
            return null;
        }

        try {
            $response = Http::get("{$this->browserUrl}/json");

            if (! $response->successful()) {
                return null;
            }

            foreach ($response->json() as $target) {
                if (($target['type'] ?? '') !== 'page') {
                    continue;
                }

                if (parse_url($target['url'] ?? '', PHP_URL_HOST) === $host) {
                    return $target;
                }
            }
        } catch (\Exception) {
            // Chrome unreachable — caller will try to create a new tab.
        }

        return null;
    }

    /**
     * Ensure we have an active target tab, or adopt the first available one.
     *
     * @throws BrowserException If no tab is open and none can be found.
     */
    private function ensureTarget(): void
    {
        if ($this->targetId) {
            return;
        }

        try {
            $response = Http::get("{$this->browserUrl}/json");

            if ($response->successful()) {
                foreach ($response->json() as $target) {
                    if (($target['type'] ?? '') === 'page') {
                        $this->targetId = $target['id'];
                        $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
                        $this->disconnectWebSocket();

                        return;
                    }
                }
            }
        } catch (\Exception) {
            // Fall through to the exception below.
        }

        throw new BrowserException('No browser tab open. Call open() first.');
    }

    /**
     * Find an element by CSS selector and return its bounding rectangle.
     *
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function querySelector(string $selector): ?array
    {
        try {
            $result = $this->sendCommand('Runtime.evaluate', [
                'expression' => '(function() {
                    const el = document.querySelector('.json_encode($selector).');
                    if (!el) return null;
                    const rect = el.getBoundingClientRect();
                    return { x: rect.left, y: rect.top, width: rect.width, height: rect.height };
                })()',
                'returnByValue' => true,
            ]);

            return $result['result']['value'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Wait for the page to be fully loaded and JS frameworks initialized.
     *
     * Waits for document.readyState === 'complete' and for Alpine.js
     * to finish initializing (if present) by checking for x-cloak removal.
     */
    public function waitForPageReady(int $timeoutSeconds = 15): void
    {
        sleep(1); // Brief pause for Chrome to start loading.

        $this->sendCommand('Runtime.evaluate', [
            'expression' => "new Promise((resolve) => {
                const timeout = setTimeout(() => resolve('timeout'), ".($timeoutSeconds * 1000).");
                const check = () => {
                    const ready = document.readyState === 'complete';
                    const alpineEl = document.querySelector('[x-data]');
                    const alpineReady = !alpineEl || !alpineEl.hasAttribute('x-cloak');
                    if (ready && alpineReady) {
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

    /**
     * Calculate full-page clip dimensions from page layout metrics.
     */
    private function fullPageClip(): array
    {
        $layout = $this->sendCommand('Page.getLayoutMetrics');
        $contentSize = $layout['cssContentSize'] ?? $layout['contentSize'] ?? null;
        $viewport = $layout['cssLayoutViewport'] ?? $layout['layoutViewport'] ?? null;

        if (! $contentSize && ! $viewport) {
            return [];
        }

        $width = max(
            (float) ($contentSize['width'] ?? 0),
            (float) ($viewport['clientWidth'] ?? 0),
        );

        $height = max(
            (float) ($contentSize['height'] ?? 0),
            (float) ($viewport['clientHeight'] ?? 0),
        );

        return [
            'captureBeyondViewport' => true,
            'clip' => [
                'x' => 0,
                'y' => 0,
                'width' => $width,
                'height' => $height,
                'scale' => 1,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    //  Raw WebSocket layer (CDP communication)
    // -----------------------------------------------------------------------

    /** Ensure we have an active WebSocket connection to the target's debugger. */
    private function ensureWebSocket(): void
    {
        if ($this->wsConnection && is_resource($this->wsConnection)) {
            return;
        }

        if (! $this->wsDebuggerUrl) {
            $response = Http::get("{$this->browserUrl}/json");

            if (! $response->successful()) {
                throw new BrowserException('Failed to fetch browser targets');
            }

            foreach ($response->json() as $target) {
                if (($target['id'] ?? null) === $this->targetId) {
                    $this->wsDebuggerUrl = $target['webSocketDebuggerUrl'] ?? null;
                    break;
                }
            }

            if (! $this->wsDebuggerUrl) {
                throw new BrowserException("No webSocketDebuggerUrl found for target {$this->targetId}");
            }
        }

        $this->connectWebSocket();
    }

    /** Open a raw WebSocket connection to Chrome's debugger endpoint. */
    private function connectWebSocket(): void
    {
        $parsed = parse_url($this->wsDebuggerUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 9222;
        $path = $parsed['path'] ?? '/';

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);

        if (! $socket) {
            throw new BrowserException("WebSocket TCP connect failed: [{$errno}] {$errstr}");
        }

        $key = base64_encode(random_bytes(16));

        fwrite($socket, implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]));

        $header = '';
        while (($line = fgets($socket)) !== false) {
            $header .= $line;
            if (rtrim($line) === '') {
                break;
            }
        }

        if (! str_contains($header, '101')) {
            fclose($socket);
            throw new BrowserException('WebSocket handshake failed: '.strtok($header, "\r\n"));
        }

        stream_set_timeout($socket, 30);
        $this->wsConnection = $socket;
    }

    /** Send a masked WebSocket text frame. */
    private function wsSend(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81); // FIN + text opcode

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
            throw new BrowserException('Failed to write WebSocket frame');
        }
    }

    /** Receive and decode a WebSocket frame (handles ping/pong automatically). */
    private function wsReceive(): ?string
    {
        $header = $this->wsReadExact(2);

        if ($header === null) {
            return null;
        }

        $opcode = ord($header[0]) & 0x0F;

        // Close frame.
        if ($opcode === 0x08) {
            return null;
        }

        $secondByte = ord($header[1]);
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

        // Respond to pings automatically, then read the next real frame.
        if ($opcode === 0x09) {
            $this->wsSendPong($payload);

            return $this->wsReceive();
        }

        return $payload;
    }

    /** Read exactly $length bytes from the WebSocket connection. */
    private function wsReadExact(int $length): ?string
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->wsConnection, $remaining);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->wsConnection);
                if ($meta['timed_out']) {
                    throw new BrowserException('WebSocket read timed out');
                }

                return null;
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    /** Send a WebSocket pong frame in response to a ping. */
    private function wsSendPong(string $payload): void
    {
        $mask = random_bytes(4);
        $frame = chr(0x8A).chr(0x80 | strlen($payload)).$mask;

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($this->wsConnection, $frame);
    }

    /** Close the WebSocket connection gracefully. */
    private function disconnectWebSocket(): void
    {
        if ($this->wsConnection && is_resource($this->wsConnection)) {
            $mask = random_bytes(4);
            @fwrite($this->wsConnection, chr(0x88).chr(0x80).$mask);
            @fclose($this->wsConnection);
        }

        $this->wsConnection = null;
    }
}
