<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Facades;

use Brunocfalcao\OCBridge\Services\OpenClawGateway;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array sendMessage(string $message, ?string $memoryId = null, ?string $agentId = null)
 * @method static void streamMessage(string $message, ?string $memoryId, callable $onEvent, ?callable $onIdle = null, ?string $agentId = null)
 *
 * @see \Brunocfalcao\OCBridge\Services\OpenClawGateway
 */
class OcBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpenClawGateway::class;
    }
}
