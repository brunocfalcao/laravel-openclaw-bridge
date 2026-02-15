# Changelog

All notable changes to this package will be documented in this file.

## 1.7.0 - 2026-02-15

### Improvements

- [IMPROVED] Renamed artisan commands from `agent:install` → `oc-bridge:install` and `agent:message` → `oc-bridge:message` for clearer namespace ownership
- [IMPROVED] Updated all internal references, CHANGELOG, and README to use new command names

## 1.6.0 - 2026-02-15

### Features

- [NEW FEATURE] `oc-bridge:install` now writes a documented `# LARAVEL OPENCLAW BRIDGE` section in `.env` with all 6 bridge keys (`OC_GATEWAY_URL`, `OC_GATEWAY_TOKEN`, `OC_GATEWAY_TIMEOUT`, `OC_DEFAULT_AGENT`, `OC_SESSION_PREFIX`, `OC_BROWSER_URL`), each preceded by a `# description` comment
- [NEW FEATURE] Auto-detects `OC_DEFAULT_AGENT` from OpenClaw agent directories — picks the custom agent when exactly one exists alongside `main`
- [NEW FEATURE] Auto-detects `OC_GATEWAY_URL` from the gateway port in `openclaw.json`
- [NEW FEATURE] Agent discovery during install — scans `~/.openclaw/agents/` for configured agents

### Improvements

- [IMPROVED] `oc-bridge:install` cleans up legacy env var names (`OPENCLAW_AUTH_TOKEN`, `OPENCLAW_GATEWAY_URL`, `JARVIS_SESSION_PREFIX`, etc.) when writing the new section
- [IMPROVED] Sensitive keys (`OC_GATEWAY_TOKEN`) are masked as `***` in install output
- [IMPROVED] Existing `.env` values are preserved — install never overwrites a value that's already set

## 1.5.1 - 2026-02-15

### Improvements

- [IMPROVED] `oc-bridge:install` now aborts with a clear error if OpenClaw is not installed on the system (no `~/.openclaw/` or `~/.openclaw-dev/` config found), preventing partial installs

## 1.5.0 - 2026-02-15

### Fixes

- [BUG FIX] WebSocket `receive()` blocked PCNTL signals for entire timeout duration — now uses 30-second per-receive timeout with deadline loop and `TimeoutException` catch, allowing queue workers to process signals between reads

## 1.4.0 - 2026-02-15

### Features

- [NEW FEATURE] `oc-bridge:install` artisan command — automated installation wizard with pre-flight checks, environment validation, auto-detection of OpenClaw config from `~/.openclaw/openclaw.json`, Chrome/Chromium installation and systemd service setup, gateway connectivity check, and smoke test
- [NEW FEATURE] Auto-configures `OC_GATEWAY_TOKEN` in `.env` when detected from local OpenClaw config

## 1.3.0 - 2026-02-13

### Features

- [NEW FEATURE] `oc-bridge:message` artisan command for CLI-based gateway testing
- [NEW FEATURE] `--agent` option to route test messages to specific agents

### Fixes

- [BUG FIX] Gateway auth: `client.id` changed from `laravel-openclaw-bridge` to `gateway-client` to match gateway whitelist

## 1.2.0 - 2026-02-13

### Features

- [NEW FEATURE] Browser automation methods: `type()`, `click()`, `waitForSelector()`, `getContent()`, `evaluateJavaScript()`, `waitForPageReady()`
- [NEW FEATURE] `Browser` contract extended with full page interaction API

### Improvements

- [IMPROVED] README updated with Browser Automation section, method reference table, and contract-based examples

## 1.1.0 - 2026-02-13

### Improvements

- [IMPROVED] Full architecture overhaul for state-of-the-art Laravel package design
- [IMPROVED] Added `Gateway` and `Browser` contracts (interfaces) for testability and dependency injection
- [IMPROVED] `sendMessage()` now returns `GatewayResponse` readonly DTO instead of raw array — typed `->text` and `->sessionKey` properties
- [IMPROVED] `streamMessage()` callback receives `StreamEvent` enum instead of magic strings
- [IMPROVED] Custom exception hierarchy: `OcBridgeException` → `ConnectionException`, `GatewayException`, `BrowserException`
- [IMPROVED] `OpenClawGateway` uses constructor injection — no longer reads `config()` internally, fully unit-testable without Laravel
- [IMPROVED] `BrowserService` implements `Browser` interface, uses `BrowserException`, extracted helper methods
- [IMPROVED] Removed dead `Log` import from `BrowserService`
- [IMPROVED] Hardcoded `'Market Studies Bridge'` replaced with configurable `$clientName` parameter
- [IMPROVED] Hardcoded `'linux'` platform replaced with `PHP_OS_FAMILY`
- [IMPROVED] Comprehensive PHPDoc comments on all classes, methods, and properties
- [IMPROVED] README rewritten for Laravel News — badges, architecture docs, DI examples, protocol diagram

### Breaking Changes

- `sendMessage()` returns `GatewayResponse` DTO instead of `array` — use `->text` instead of `['reply']`
- `streamMessage()` callback receives `StreamEvent` enum instead of `string` — use `StreamEvent::Delta` instead of `'delta'`

## 1.0.1 - 2026-02-13

### Improvements

- [IMPROVED] Renamed package from `oc-bridge` to `laravel-openclaw-bridge` (GitHub repo, Composer name, README)

## 1.0.0 - 2026-02-13

### Features

- [NEW FEATURE] Initial release: OpenClaw WebSocket client, SSE streaming, CDP screenshots, memory management
- [NEW FEATURE] Multi-agent routing via `$agentId` parameter and `default_agent` config
