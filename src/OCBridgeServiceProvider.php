<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge;

use Brunocfalcao\OCBridge\Contracts\Browser;
use Brunocfalcao\OCBridge\Contracts\Gateway;
use Brunocfalcao\OCBridge\Services\BrowserService;
use Brunocfalcao\OCBridge\Services\OpenClawGateway;
use Illuminate\Support\ServiceProvider;

class OCBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/oc-bridge.php', 'oc-bridge');

        $this->app->singleton(Gateway::class, function () {
            return new OpenClawGateway(
                wsUrl: (string) config('oc-bridge.gateway.url', 'ws://127.0.0.1:18789'),
                token: (string) config('oc-bridge.gateway.token', ''),
                timeoutSeconds: (int) config('oc-bridge.gateway.timeout', 600),
                sessionPrefix: (string) config('oc-bridge.session_prefix', 'market-studies'),
                defaultAgent: (string) config('oc-bridge.default_agent', 'main'),
                clientName: (string) config('app.name', 'Laravel'),
            );
        });

        $this->app->singleton(Browser::class, function () {
            return new BrowserService(
                browserUrl: (string) config('oc-bridge.browser.url', 'http://127.0.0.1:9222'),
            );
        });

        // Alias concrete classes to their interfaces for backward compatibility.
        $this->app->alias(Gateway::class, OpenClawGateway::class);
        $this->app->alias(Browser::class, BrowserService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\MessageCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/oc-bridge.php' => config_path('oc-bridge.php'),
            ], 'oc-bridge-config');
        }
    }
}
