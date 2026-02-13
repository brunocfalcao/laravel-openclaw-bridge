# oc-bridge

OpenClaw bridge for Laravel. Provides WebSocket communication with the OpenClaw AI gateway, SSE streaming, and CDP browser screenshots.

## Installation

```bash
composer require brunocfalcao/oc-bridge
```

Publish the config:

```bash
php artisan vendor:publish --tag=oc-bridge-config
```

## Configuration

Add to your `.env`:

```env
OC_GATEWAY_URL=ws://127.0.0.1:18789
OC_GATEWAY_TOKEN=your-gateway-token
OC_GATEWAY_TIMEOUT=600
OC_SESSION_PREFIX=market-studies
OC_BROWSER_URL=http://127.0.0.1:9222
```

## Usage

### Send a Message

```php
use Brunocfalcao\OCBridge\Facades\OcBridge;

$result = OcBridge::sendMessage('Analyze this market');
echo $result['reply'];
```

### Send with Memory (Critical for Multi-Step Studies)

```php
// Generate a memory ID for a new study
$memoryId = \Illuminate\Support\Str::uuid()->toString();

// Step 1: Market Sizing
$result = OcBridge::sendMessage($prompt1, $memoryId);

// Step 2: Competitive Landscape — same memoryId, agent remembers Step 1
$result = OcBridge::sendMessage($prompt2, $memoryId);

// Step 12: Executive Synthesis — agent has full context from all prior steps
$result = OcBridge::sendMessage($prompt12, $memoryId);
```

### Stream a Response

```php
OcBridge::streamMessage($prompt, $memoryId, function (string $type, array $data) {
    match ($type) {
        'delta' => echo $data['delta'],
        'complete' => handleComplete($data['text']),
        'error' => handleError($data['message']),
    };
});
```

### Take a Screenshot (CDP)

```php
use Brunocfalcao\OCBridge\Services\BrowserService;

$browser = app(BrowserService::class);
$browser->open('https://competitor.com');
$browser->screenshot('/path/to/screenshot.png');
$browser->close();
```

## How Memory Works

OpenClaw maintains native memory continuity via session keys. The session key format is:

```
agent:main:{prefix}-{memoryId}
```

When you pass the same `memoryId` across multiple calls:

1. All calls connect to the **same OpenClaw session**
2. The agent (Marlowe) **remembers everything** from prior calls
3. Each step builds on accumulated knowledge from previous steps
4. No need to re-send prior context — the agent has it in memory

### For Market Studies

Each study gets a unique `openclaw_memory_id` (UUID) stored in the database. All analytical steps for that study pass this ID, enabling Marlowe to:

- Reference findings from earlier chapters
- Maintain consistent terminology and framing
- Build a coherent narrative across all 12 chapters
- Synthesize everything in the final executive summary

**Important:** The full study generates a NEW memory ID (fresh start). It does NOT reuse the resumed study's memory.

## Protocol

The bridge uses OpenClaw's WebSocket protocol v3:

1. **Connect** → Receive nonce challenge
2. **Authenticate** → Send token, receive ACK
3. **Send message** → `chat.send` with session key and message
4. **Receive events** → Stream deltas, final response, or errors
5. **Close** → Clean disconnect

## Requirements

- PHP 8.2+
- Laravel 12+
- OpenClaw gateway running on localhost
- Chrome/Chromium with `--remote-debugging-port=9222` (for screenshots)
