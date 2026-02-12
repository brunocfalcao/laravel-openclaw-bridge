<?php

declare(strict_types=1);

namespace Brunocfalcao\OcBridge\Facades;

use Brunocfalcao\OcBridge\Services\OpenClawGateway;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array sendMessage(string $message, ?string $memoryId = null)
 * @method static void streamMessage(string $message, ?string $memoryId, callable $onEvent, ?callable $onIdle = null)
 *
 * @see \Brunocfalcao\OcBridge\Services\OpenClawGateway
 */
class OcBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpenClawGateway::class;
    }
}
