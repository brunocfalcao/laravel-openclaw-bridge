<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="MIT License">
</p>

# Laravel OpenClaw Bridge

**Connect your Laravel app to AI agents with persistent memory, real-time streaming, and browser automation — all through a clean, expressive API.**

Laravel OpenClaw Bridge is a first-party Laravel package that provides a seamless interface to the [OpenClaw](https://openclaw.ai) AI gateway. Send messages to AI agents that remember previous conversations, stream responses in real-time, and automate browser interactions — all with the elegance you expect from a Laravel package.

---

## Highlights

- **Persistent Memory** — AI agents remember context across multiple calls. Build multi-step workflows where each step builds on the last.
- **Real-Time Streaming** — Stream AI responses token-by-token with event callbacks. Perfect for live UIs and progress indicators.
- **Browser Automation** — Full browser control via Chrome DevTools Protocol. Navigate pages, type into forms, click elements, execute JavaScript, take screenshots — all pure PHP, no Puppeteer or Node.js.
- **Clean Facade API** — `sendMessage()`, `streamMessage()`, and a full `Browser` contract. Everything else is configuration.
- **Proper Architecture** — Interfaces for testability, DTOs for type safety, custom exceptions for precise error handling, and a `StreamEvent` enum instead of magic strings.
- **Zero Lock-In** — Standard WebSocket protocol, environment-based config, and Laravel conventions throughout.

---

## Installation

```bash
composer require brunocfalcao/laravel-openclaw-bridge
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=oc-bridge-config
```

Add your credentials to `.env`:

```env
OC_GATEWAY_URL=ws://127.0.0.1:18789
OC_GATEWAY_TOKEN=your-gateway-token
```

That's it. The service provider and facade are auto-discovered.

---

## Quick Start

```php
use Brunocfalcao\OCBridge\Facades\OcBridge;

// Send a message and get a response
$result = OcBridge::sendMessage('What are the key trends in the SaaS market?');

echo $result->text;
```

---

## Sending Messages

The `sendMessage` method sends a prompt to an OpenClaw agent and returns the complete response.

```php
$result = OcBridge::sendMessage(
    message: 'Analyze the competitive landscape for food delivery apps',
);

$result->text;       // The agent's full response
$result->sessionKey; // The session key used (for logging/debugging)
```

The response is a `GatewayResponse` DTO — a readonly object with typed properties, full IDE autocomplete, and no guessing.

You can also inject the `Gateway` interface directly instead of using the facade:

```php
use Brunocfalcao\OCBridge\Contracts\Gateway;

class MarketAnalysisController extends Controller
{
    public function analyze(Gateway $gateway): JsonResponse
    {
        $result = $gateway->sendMessage('Analyze the SaaS market');

        return response()->json(['analysis' => $result->text]);
    }
}
```

### Routing to Different Agents

OpenClaw can host multiple specialized agents. Route your message to any of them:

```php
// Uses the default agent (configured in OC_DEFAULT_AGENT)
$result = OcBridge::sendMessage('General analysis request');

// Route to a specific agent
$result = OcBridge::sendMessage(
    message: 'Analyze customer sentiment from these reviews',
    agentId: 'sentiment-analyzer',
);
```

---

## Persistent Memory

This is where it gets powerful. Pass a `memoryId` and the agent **remembers everything** from previous calls with that same ID. No re-sending context. No token waste. The agent simply knows.

```php
use Illuminate\Support\Str;

$memoryId = Str::uuid()->toString();

// Step 1 — The agent learns about your market
$step1 = OcBridge::sendMessage(
    message: 'The market is online pet food in Europe. What is the market size?',
    memoryId: $memoryId,
);

// Step 2 — The agent already knows the market. Just ask the next question.
$step2 = OcBridge::sendMessage(
    message: 'Who are the top 5 competitors?',
    memoryId: $memoryId,
);

// Step 3 — The agent has full context from Steps 1 and 2
$step3 = OcBridge::sendMessage(
    message: 'Based on the market size and competitors, what pricing strategy do you recommend?',
    memoryId: $memoryId,
);
```

### How It Works Under the Hood

Each `memoryId` maps to a unique session on the OpenClaw gateway:

```
agent:{agentId}:{prefix}-{memoryId}
```

All messages sharing the same `memoryId` connect to the **same session**. The agent maintains full conversational context — no re-prompting, no context windows to manage, no retrieval hacks.

### Building Multi-Step Workflows

Memory makes it trivial to build workflows where each step depends on the last:

```php
$memoryId = Str::uuid()->toString();

$chapters = [
    'Analyze the market size and growth trajectory',
    'Map the competitive landscape',
    'Profile the ideal customer segments',
    'Identify market entry opportunities',
    'Develop a go-to-market strategy',
    'Synthesize everything into an executive summary',
];

$results = [];

foreach ($chapters as $prompt) {
    $results[] = OcBridge::sendMessage($prompt, $memoryId);
}

// The final chapter references findings from ALL previous chapters
// because the agent has full memory of the entire conversation.
```

---

## Real-Time Streaming

For live UIs, progress indicators, or long-running analysis, stream the response token-by-token instead of waiting for the full result.

The `$onEvent` callback receives a `StreamEvent` enum — no magic strings, full IDE autocomplete:

```php
use Brunocfalcao\OCBridge\Enums\StreamEvent;

OcBridge::streamMessage(
    message: 'Write a comprehensive market analysis for electric vehicles',
    memoryId: $memoryId,
    onEvent: function (StreamEvent $type, array $data) {
        match ($type) {
            StreamEvent::Delta    => echo $data['delta'],           // Each new token
            StreamEvent::Complete => saveToDatabase($data['text']), // Full response
            StreamEvent::Error    => Log::error($data['message']),  // Something went wrong
        };
    },
);
```

### With Keepalive Callbacks

For very long responses, pass an `onIdle` callback to keep connections alive:

```php
OcBridge::streamMessage(
    message: $prompt,
    memoryId: $memoryId,
    onEvent: function (StreamEvent $type, array $data) {
        if ($type === StreamEvent::Delta) {
            broadcast(new TokenReceived($data['delta']));
        }
    },
    onIdle: function () {
        // Called periodically while waiting for the next token.
        // Use it for heartbeats, progress bars, or connection keepalives.
        logger()->debug('Still processing...');
    },
);
```

### Event Types

| Event | Payload | When |
|-------|---------|------|
| `StreamEvent::Delta` | `['delta' => string, 'text' => string]` | Each new token arrives |
| `StreamEvent::Complete` | `['text' => string, 'session_key' => string]` | Response is finished |
| `StreamEvent::Error` | `['message' => string]` | Something went wrong |

---

## Browser Automation

Full browser control via Chrome DevTools Protocol — entirely in PHP, no Node.js or Puppeteer dependencies. Navigate pages, interact with elements, execute JavaScript, and take screenshots.

```php
use Brunocfalcao\OCBridge\Contracts\Browser;

$browser = app(Browser::class);

$browser->open('https://example.com');
$browser->screenshot('/path/to/screenshot.png');
$browser->close();
```

### Full API

```php
$browser = app(Browser::class);

// Check if Chrome is running
if ($browser->testConnection()) {

    // Open a URL (returns the browser tab ID)
    $tabId = $browser->open('https://example.com');

    // Navigate to a different page in the same tab
    $browser->navigate('https://example.com/pricing');

    // Full-page screenshot saved to disk
    $browser->screenshot('/tmp/full-page.png');

    // Viewport-only screenshot (no scrolling)
    $browser->screenshot('/tmp/viewport.png', fullPage: false);

    // Get raw base64 PNG data (no file saved)
    $base64 = $browser->screenshot();

    // Clean up
    $browser->close();
}
```

### Page Interaction

Type into form fields, click buttons, and wait for elements — enabling automated workflows on any web page.

```php
$browser->open('https://app.example.com/login');

// Wait for the login form to render
$browser->waitForSelector('#email');

// Fill in credentials and submit
$browser->type('#email', 'user@example.com');
$browser->type('#password', 'secret');
$browser->click('#login-button');

// Wait for the dashboard to load
$found = $browser->waitForSelector('.dashboard', timeoutSeconds: 10);

if ($found) {
    $browser->screenshot('/tmp/dashboard.png');
}

$browser->close();
```

### JavaScript Evaluation

Execute arbitrary JavaScript in the page context — useful for reading page state, triggering client-side actions, or extracting data.

```php
$browser->open('https://example.com');

// Read a value from the page
$title = $browser->evaluateJavaScript('document.title');

// Get the full HTML content of the page
$html = $browser->getContent();

// Trigger a client-side action
$browser->evaluateJavaScript('window.scrollTo(0, document.body.scrollHeight)');

// Async expressions are supported (awaitPromise is enabled)
$data = $browser->evaluateJavaScript('fetch("/api/status").then(r => r.json())');

$browser->close();
```

### Page Ready Detection

The `waitForPageReady()` method waits for `document.readyState === 'complete'` and for Alpine.js to finish initializing (if present). It is called automatically after `open()` and `navigate()`, but you can call it manually after client-side navigation.

```php
$browser->click('#spa-link');
$browser->waitForPageReady(timeoutSeconds: 15);
$browser->screenshot('/tmp/after-navigation.png');
```

### Method Reference

| Method | Returns | Description |
|--------|---------|-------------|
| `open(string $url)` | `string` | Open a URL in a browser tab (reuses tabs on the same domain). Returns the tab ID. |
| `navigate(string $url)` | `void` | Navigate the current tab to a different URL. |
| `screenshot(?string $path, bool $fullPage)` | `string` | Capture a screenshot. Returns file path or base64 data. |
| `type(string $selector, string $text)` | `void` | Type text into a form element (clears existing content first). |
| `click(string $selector)` | `void` | Click an element by CSS selector. |
| `waitForSelector(string $selector, int $timeoutSeconds)` | `bool` | Wait for an element to appear in the DOM. Returns false on timeout. |
| `getContent()` | `string` | Get the full HTML content of the current page. |
| `evaluateJavaScript(string $expression)` | `mixed` | Evaluate a JS expression in the page context (supports async). |
| `waitForPageReady(int $timeoutSeconds)` | `void` | Wait for page load and Alpine.js initialization. |
| `testConnection()` | `bool` | Check if headless Chrome is running and reachable. |
| `close()` | `void` | Close the current browser tab and release resources. |

### Smart Tab Reuse

The browser service intelligently manages tabs. If you open multiple URLs on the same domain, it reuses the existing tab instead of creating new ones — reducing memory and overhead.

```php
$browser->open('https://competitor.com');          // Opens new tab
$browser->open('https://competitor.com/pricing');   // Reuses same tab, navigates
$browser->open('https://other-site.com');           // Opens new tab (different domain)
```

### Prerequisites

Browser automation requires headless Chrome running with remote debugging enabled:

```bash
google-chrome --headless --remote-debugging-port=9222 --no-sandbox
```

Then set the endpoint in your `.env`:

```env
OC_BROWSER_URL=http://127.0.0.1:9222
```

---

## Configuration

All configuration lives in `config/oc-bridge.php` and is driven by environment variables:

```env
# Gateway connection
OC_GATEWAY_URL=ws://127.0.0.1:18789    # WebSocket endpoint
OC_GATEWAY_TOKEN=your-token             # Authentication token
OC_GATEWAY_TIMEOUT=600                  # Response timeout in seconds (default: 10 min)

# Session management
OC_SESSION_PREFIX=my-app                # Namespace to isolate your app's sessions
OC_DEFAULT_AGENT=main                   # Default agent to route messages to

# Browser automation (optional)
OC_BROWSER_URL=http://127.0.0.1:9222   # Chrome DevTools Protocol endpoint
```

| Variable | Default | Description |
|----------|---------|-------------|
| `OC_GATEWAY_URL` | `ws://127.0.0.1:18789` | WebSocket endpoint for the OpenClaw gateway |
| `OC_GATEWAY_TOKEN` | — | Your authentication token |
| `OC_GATEWAY_TIMEOUT` | `600` | Max seconds to wait for a response |
| `OC_SESSION_PREFIX` | `market-studies` | Prefix for session keys (isolates your app) |
| `OC_DEFAULT_AGENT` | `main` | Agent ID used when none is specified |
| `OC_BROWSER_URL` | `http://127.0.0.1:9222` | Chrome DevTools Protocol endpoint |

---

## Installation Wizard

Run the interactive installer to set up everything automatically:

```bash
php artisan agent:install
```

The installer will:

1. **Pre-flight checks** — PHP version, Laravel version, database connection
2. **Publish config** — copies `config/oc-bridge.php` to your project
3. **Validate environment** — checks for `OC_GATEWAY_TOKEN` and auto-detects it from `~/.openclaw/openclaw.json` if missing
4. **Install Chrome** — offers to install Chromium and create a systemd service for headless mode
5. **Connectivity check** — verifies the OpenClaw gateway is reachable
6. **Smoke test** — sends a test message to the agent and confirms a response

For non-interactive environments (CI/CD), use `--no-interaction` — the installer will skip prompts and report warnings instead.

---

## CLI Testing

The package ships with an artisan command to quickly test your gateway connection from the terminal:

```bash
# Send a message to the default agent
php artisan agent:message "Hello, are you there?"

# Route to a specific agent
php artisan agent:message "Analyze this dataset" --agent=research
```

The command streams the response in real-time to stdout — useful for verifying connectivity, debugging agent behavior, or testing new agents.

---

## Protocol

The bridge implements OpenClaw's WebSocket protocol v3. Every request follows this flow:

```
┌─────────────┐         ┌──────────────────┐
│  Your App   │         │  OpenClaw Gateway │
└──────┬──────┘         └────────┬─────────┘
       │                         │
       │   1. Connect (WS)       │
       │────────────────────────>│
       │                         │
       │   2. Nonce challenge    │
       │<────────────────────────│
       │                         │
       │   3. Authenticate       │
       │────────────────────────>│
       │                         │
       │   4. ACK                │
       │<────────────────────────│
       │                         │
       │   5. chat.send          │
       │────────────────────────>│
       │                         │
       │   6. Stream deltas      │
       │<────────────────────────│
       │   ...                   │
       │   7. Final response     │
       │<────────────────────────│
       │                         │
       │   8. Close              │
       │────────────────────────>│
       │                         │
```

Every message includes a UUID-based idempotency key, so duplicate requests are safely ignored.

---

## Error Handling

The package provides a focused exception hierarchy — catch exactly what you need:

```php
use Brunocfalcao\OCBridge\Exceptions\ConnectionException;
use Brunocfalcao\OCBridge\Exceptions\GatewayException;
use Brunocfalcao\OCBridge\Exceptions\OcBridgeException;

try {
    $result = OcBridge::sendMessage('Analyze this market');
} catch (ConnectionException $e) {
    // Can't reach or authenticate with the gateway.
    // Retry later or check your credentials.
    Log::error('Gateway unreachable', ['error' => $e->getMessage()]);
} catch (GatewayException $e) {
    // Connected, but the request failed — agent error, timeout, etc.
    Log::error('Agent request failed', ['error' => $e->getMessage()]);
}

// Or catch everything from the package at once:
try {
    $result = OcBridge::sendMessage('Analyze this market');
} catch (OcBridgeException $e) {
    // Any package-level error (connection, gateway, or browser).
}
```

| Exception | When |
|-----------|------|
| `ConnectionException` | Gateway unreachable, auth failed, nonce timeout |
| `GatewayException` | Agent error, response timeout, chat.send rejected |
| `BrowserException` | Chrome not running, CDP command failed, screenshot error |
| `OcBridgeException` | Base class — catches all of the above |

---

## Requirements

| Requirement | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 12+ |
| OpenClaw Gateway | Running on accessible endpoint |
| Chrome/Chromium | With `--remote-debugging-port` (only for browser automation) |

---

## License

MIT License. See [LICENSE](LICENSE) for details.
