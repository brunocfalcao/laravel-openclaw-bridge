# Changelog

All notable changes to this package will be documented in this file.

## 1.5.0 - 2026-02-15

### Fixes

- [BUG FIX] WebSocket `receive()` blocked PCNTL signals for entire timeout duration — now uses 30-second per-receive timeout with deadline loop and `TimeoutException` catch, allowing queue workers to process signals between reads

## 1.4.0 - 2026-02-15

### Features

- [NEW FEATURE] `agent:install` artisan command — automated installation wizard with pre-flight checks, environment validation, auto-detection of OpenClaw config from `~/.openclaw/openclaw.json`, Chrome/Chromium installation and systemd service setup, gateway connectivity check, and smoke test
- [NEW FEATURE] Auto-configures `OC_GATEWAY_TOKEN` in `.env` when detected from local OpenClaw config

## 1.3.0 - 2026-02-13

### Features

- [NEW FEATURE] `agent:message` artisan command for CLI-based gateway testing
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
