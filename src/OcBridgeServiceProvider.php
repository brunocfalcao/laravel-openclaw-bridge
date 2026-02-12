<?php

declare(strict_types=1);

namespace Brunocfalcao\OcBridge;

use Brunocfalcao\OcBridge\Services\BrowserService;
use Brunocfalcao\OcBridge\Services\OpenClawGateway;
use Illuminate\Support\ServiceProvider;

class OcBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/oc-bridge.php', 'oc-bridge');

        $this->app->singleton(OpenClawGateway::class);

        $this->app->singleton(BrowserService::class, function () {
            return new BrowserService(config('oc-bridge.browser.url', 'http://127.0.0.1:9222'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/oc-bridge.php' => config_path('oc-bridge.php'),
            ], 'oc-bridge-config');
        }
    }
}
