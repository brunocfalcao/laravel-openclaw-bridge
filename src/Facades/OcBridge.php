<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Facades;

use Brunocfalcao\OCBridge\Contracts\Gateway;
use Illuminate\Support\Facades\Facade;

/**
 * Gateway methods.
 *
 * @method static \Brunocfalcao\OCBridge\DTOs\GatewayResponse sendMessage(string $message, ?string $memoryId = null, ?string $agentId = null)
 * @method static void streamMessage(string $message, ?string $memoryId, callable $onEvent, ?callable $onIdle = null, ?string $agentId = null)
 *
 * Browser methods.
 * @method static string open(string $url)
 * @method static void navigate(string $url)
 * @method static string screenshot(?string $path = null, bool $fullPage = true)
 * @method static void type(string $selector, string $text)
 * @method static void click(string $selector)
 * @method static bool waitForSelector(string $selector, int $timeoutSeconds = 30)
 * @method static string getContent()
 * @method static mixed evaluateJavaScript(string $expression)
 * @method static void waitForPageReady(int $timeoutSeconds = 15)
 * @method static bool testConnection()
 * @method static void close()
 *
 * @see \Brunocfalcao\OCBridge\Services\OpenClawGateway
 * @see \Brunocfalcao\OCBridge\Services\BrowserService
 * @see \Brunocfalcao\OCBridge\Contracts\Gateway
 * @see \Brunocfalcao\OCBridge\Contracts\Browser
 */
class OcBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Gateway::class;
    }
}
