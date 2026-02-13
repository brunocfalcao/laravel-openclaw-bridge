<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Contracts;

/**
 * Headless browser contract for CDP-based operations.
 *
 * Provides an interface for browser automation via Chrome DevTools Protocol:
 * opening pages, navigating, taking screenshots, and managing tabs.
 */
interface Browser
{
    /**
     * Open a URL in a browser tab (reuses existing tabs on the same domain).
     *
     * @return string The browser tab/target ID.
     */
    public function open(string $url): string;

    /** Navigate to a URL in the current tab. */
    public function navigate(string $url): void;

    /**
     * Take a screenshot of the current page.
     *
     * @param  string|null $path      File path to save the PNG. Null returns base64 data.
     * @param  bool        $fullPage  Capture the full scrollable page (true) or viewport only (false).
     * @return string File path if $path was given, otherwise base64-encoded PNG data.
     */
    public function screenshot(?string $path = null, bool $fullPage = true): string;

    /**
     * Type text into a focused element identified by CSS selector.
     *
     * Clears existing content first, then inserts the new text via CDP.
     */
    public function type(string $selector, string $text): void;

    /**
     * Click an element identified by CSS selector.
     *
     * Calculates the element's center position and dispatches mouse events.
     */
    public function click(string $selector): void;

    /**
     * Wait for an element matching the CSS selector to appear in the DOM.
     *
     * @param  int  $timeoutSeconds  Maximum seconds to wait before returning false.
     * @return bool True if the element appeared, false on timeout.
     */
    public function waitForSelector(string $selector, int $timeoutSeconds = 30): bool;

    /**
     * Get the full HTML content of the current page.
     */
    public function getContent(): string;

    /**
     * Evaluate a JavaScript expression in the page context.
     *
     * @return mixed The return value of the expression.
     */
    public function evaluateJavaScript(string $expression): mixed;

    /**
     * Wait for the page to be fully loaded and JS frameworks initialized.
     */
    public function waitForPageReady(int $timeoutSeconds = 15): void;

    /** Test whether headless Chrome is running and reachable. */
    public function testConnection(): bool;

    /** Close the current browser tab and clean up resources. */
    public function close(): void;
}
