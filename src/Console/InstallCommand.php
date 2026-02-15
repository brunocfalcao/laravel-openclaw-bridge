<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InstallCommand extends Command
{
    protected $signature = 'agent:install';

    protected $description = 'Install and configure the OpenClaw Bridge';

    private array $warnings = [];

    private array $autoConfigured = [];

    public function handle(): int
    {
        $this->info('Installing OpenClaw Bridge...');
        $this->newLine();

        // 0. Pre-flight checks
        if (! $this->preFlightChecks()) {
            return self::FAILURE;
        }

        // 1. Publish config
        $this->publishConfig();

        // 2. Validate critical environment variables
        $this->validateEnvironment();

        // 3. Check optional services (Chrome, OpenClaw gateway)
        $this->checkServices();

        // 4. Smoke test
        $this->smokeTest();

        // 5. Summary
        $this->printSummary();

        return self::SUCCESS;
    }

    protected function preFlightChecks(): bool
    {
        $this->line('  Pre-flight checks...');

        // Check .env exists
        if (! file_exists(base_path('.env'))) {
            $this->error('  [!!] .env file not found — copy .env.example first:');
            $this->line('       cp .env.example .env && php artisan key:generate');

            return false;
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $this->error('  [!!] PHP 8.2+ required, found '.PHP_VERSION);

            return false;
        }

        // Check Laravel version
        $laravelVersion = app()->version();
        if (version_compare($laravelVersion, '12.0.0', '<')) {
            $this->warn('  [!!] Laravel 12+ recommended, found '.$laravelVersion);
        }

        // Check database connection
        try {
            \DB::connection()->getPdo();
            $this->line('  [ok] Database connection');
        } catch (\Exception $e) {
            $this->error('  [!!] Database connection failed: '.$e->getMessage());

            return false;
        }

        $this->line('  [ok] PHP '.PHP_VERSION);
        $this->line('  [ok] Laravel '.$laravelVersion);

        return true;
    }

    protected function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'oc-bridge-config']);
        $this->callSilently('config:clear');
        $this->line('  [ok] Published config/oc-bridge.php');
    }

    protected function validateEnvironment(): void
    {
        $this->newLine();
        $this->line('  Checking environment...');

        $required = [
            'OC_GATEWAY_TOKEN' => 'OpenClaw gateway authentication token',
        ];

        // Auto-detect missing values from OpenClaw config
        $missing = array_filter(
            array_keys($required),
            fn ($key) => empty(env($key))
        );

        if (! empty($missing)) {
            $detected = $this->detectOpenClawConfig();

            if ($detected !== null) {
                $this->line("  [>>] Found OpenClaw config ({$detected['source']})");

                $keyMap = [
                    'OC_GATEWAY_TOKEN' => $detected['token'],
                ];

                foreach ($missing as $key) {
                    $value = $keyMap[$key] ?? null;

                    if (empty($value)) {
                        continue;
                    }

                    if ($this->writeEnvValue($key, $value)) {
                        putenv("{$key}={$value}");
                        $this->autoConfigured[] = $key;
                        $this->line("  [ok] Auto-configured {$key} from OpenClaw config");
                    } else {
                        $this->warnings[] = "Could not write {$key} to .env — set it manually";
                        $this->warn("  [!!] Could not write {$key} to .env");
                    }
                }

                // Reload config so subsequent calls see the new values
                $this->callSilently('config:clear');
            }
        }

        foreach ($required as $key => $description) {
            // Skip validation for keys we just auto-configured
            if (in_array($key, $this->autoConfigured, true)) {
                continue;
            }

            if (empty(env($key))) {
                $this->warnings[] = "Set {$key} in .env ({$description})";
                $this->warn("  [!!] {$key} is not set — {$description}");
            } else {
                $this->line("  [ok] {$key}");
            }
        }

        // Check gateway URL (has sensible default, so just inform)
        $gatewayUrl = env('OC_GATEWAY_URL', 'ws://127.0.0.1:18789');
        $this->line("  [ok] OC_GATEWAY_URL: {$gatewayUrl}");

        // Check browser URL (optional)
        if (empty(env('OC_BROWSER_URL'))) {
            $this->line('  [--] OC_BROWSER_URL not set — using default port 9222');
        } else {
            $this->line('  [ok] OC_BROWSER_URL: '.env('OC_BROWSER_URL'));
        }
    }

    protected function checkServices(): void
    {
        $this->newLine();
        $this->line('  Checking services...');

        // Chrome (required for screenshots)
        $chromeBinary = $this->findChromeBinary();

        if ($chromeBinary) {
            $this->line("  [ok] Chrome/Chromium found: {$chromeBinary}");
            $this->ensureChromeRunning($chromeBinary);
        } else {
            $this->warn('  [!!] Chrome not found — required for screenshot support');

            if ($this->input->isInteractive() && $this->confirm('  Install Chromium now?', true)) {
                $this->installChrome();

                // Re-check after install
                $chromeBinary = $this->findChromeBinary();
                if ($chromeBinary) {
                    $this->ensureChromeRunning($chromeBinary);
                }
            } else {
                $this->warnings[] = 'Install Chrome manually for screenshot support';
                $this->warn('  [skip] Screenshot feature will be unavailable');
            }
        }

        // OpenClaw gateway connectivity check
        $token = env('OC_GATEWAY_TOKEN') ?: config('oc-bridge.gateway.token');
        $url = config('oc-bridge.gateway.url', 'ws://127.0.0.1:18789');

        if (! empty($token) && ! empty($url)) {
            $this->line("  [ok] OpenClaw configured: {$url}");

            // Quick connectivity check via TCP
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '127.0.0.1';
            $port = $parsed['port'] ?? 18789;
            $conn = @fsockopen($host, (int) $port, $errno, $errstr, 3);

            if ($conn) {
                fclose($conn);
                $this->line('  [ok] OpenClaw gateway is reachable');
            } else {
                $this->warn("  [!!] OpenClaw gateway not reachable at {$host}:{$port}");
                $this->warnings[] = "OpenClaw gateway not reachable at {$host}:{$port}";
            }
        } else {
            $this->warn('  [!!] OpenClaw not fully configured — set OC_GATEWAY_TOKEN');
        }
    }

    protected function findChromeBinary(): ?string
    {
        $paths = [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        $result = trim((string) shell_exec('which google-chrome chromium chromium-browser 2>/dev/null'));

        return $result !== '' ? strtok($result, "\n") : null;
    }

    protected function ensureChromeRunning(string $chromeBinary): void
    {
        $browserUrl = config('oc-bridge.browser.url', 'http://127.0.0.1:9222');

        // Check if Chrome is already responding
        try {
            $response = Http::timeout(3)->get("{$browserUrl}/json/version");

            if ($response->successful()) {
                $this->line('  [ok] Headless Chrome is running');

                return;
            }
        } catch (\Exception) {
            // Not reachable — continue to offer startup
        }

        // Inside a container, skip systemd setup
        if (file_exists('/.dockerenv') || file_exists('/run/.containerenv')) {
            $this->warnings[] = 'Headless Chrome is not running — start it in your container entrypoint';
            $this->warn('  [!!] Headless Chrome is not running (container — skip systemd)');

            return;
        }

        if (! $this->input->isInteractive()) {
            $this->warnings[] = 'Headless Chrome is not running — create a systemd user service or start it manually';
            $this->warn('  [!!] Headless Chrome is not running');

            return;
        }

        if (! $this->confirm('  Headless Chrome is not running. Create a systemd user service to start it?', true)) {
            $this->warnings[] = 'Headless Chrome is not running — start it manually';
            $this->warn('  [skip] Headless Chrome not started');

            return;
        }

        // Extract port from configured URL
        $parsed = parse_url($browserUrl);
        $port = $parsed['port'] ?? 9222;

        $serviceDir = getenv('HOME').'/.config/systemd/user';
        $servicePath = $serviceDir.'/chromium-headless.service';

        $serviceContent = <<<UNIT
[Unit]
Description=Headless Chrome (remote debugging on port {$port})
After=network.target

[Service]
ExecStart={$chromeBinary} --headless --disable-gpu --no-sandbox --remote-debugging-port={$port} --remote-debugging-address=127.0.0.1
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
UNIT;

        if (! is_dir($serviceDir)) {
            mkdir($serviceDir, 0755, true);
        }

        file_put_contents($servicePath, $serviceContent);
        $this->line("  [ok] Wrote {$servicePath}");

        // Enable and start the service
        exec('systemctl --user daemon-reload 2>&1', $output, $code);

        if ($code !== 0) {
            $this->warnings[] = 'systemctl --user daemon-reload failed — start Chrome manually';
            $this->warn('  [!!] systemctl --user daemon-reload failed');

            return;
        }

        exec('systemctl --user enable --now chromium-headless.service 2>&1', $output, $code);

        if ($code !== 0) {
            $this->warnings[] = 'Failed to start chromium-headless.service — check: systemctl --user status chromium-headless';
            $this->warn('  [!!] Failed to start chromium-headless.service');

            return;
        }

        // Enable linger so the service survives logout
        $user = get_current_user() ?: trim((string) shell_exec('whoami'));
        exec("loginctl enable-linger {$user} 2>&1");

        // Wait briefly and verify
        sleep(2);

        try {
            $response = Http::timeout(3)->get("{$browserUrl}/json/version");

            if ($response->successful()) {
                $this->line('  [ok] Headless Chrome started via systemd');

                return;
            }
        } catch (\Exception) {
            // Fall through to warning
        }

        $this->warnings[] = 'chromium-headless.service started but Chrome is not responding — check: systemctl --user status chromium-headless';
        $this->warn('  [!!] Service started but Chrome is not responding yet');
        $this->line('       Check: systemctl --user status chromium-headless');
    }

    private function installChrome(): void
    {
        // Container detection
        if (file_exists('/.dockerenv') || file_exists('/run/.containerenv')) {
            $this->warn('  [!!] Container detected — install Chrome in your Dockerfile instead');
            $this->warnings[] = 'Container detected — add Chrome to your Dockerfile';

            return;
        }

        if ($this->commandExists('apt-get')) {
            $this->line('  [>>] Installing Chromium via apt...');
            $success = $this->installChromeApt();
        } elseif ($this->commandExists('yum')) {
            $this->line('  [>>] Installing Chromium via yum...');
            $success = $this->installChromeYum();
        } elseif ($this->commandExists('brew')) {
            $this->line('  [>>] Installing Chromium via Homebrew...');
            $success = $this->installChromeBrew();
        } else {
            $this->warnings[] = 'Could not detect package manager — install Chrome manually';
            $this->warn('  [!!] Unknown package manager — install Chrome manually');

            return;
        }

        if ($success) {
            $this->line('  [ok] Chromium installed successfully');
        } else {
            $this->warnings[] = 'Chrome installation failed — install manually';
            $this->warn('  [!!] Installation failed — check permissions or install manually');
        }
    }

    private function installChromeApt(): bool
    {
        exec('sudo apt-get update -qq 2>&1', $output, $updateCode);

        if ($updateCode !== 0) {
            return false;
        }

        $packages = ['chromium-browser', 'chromium'];

        foreach ($packages as $package) {
            exec("sudo apt-get install -y {$package} 2>&1", $output, $installCode);

            if ($installCode === 0 && $this->findChromeBinary()) {
                return true;
            }
        }

        return false;
    }

    private function installChromeYum(): bool
    {
        exec('sudo yum install -y chromium 2>&1', $output, $code);

        return $code === 0 && $this->findChromeBinary() !== null;
    }

    private function installChromeBrew(): bool
    {
        exec('brew install --cask chromium 2>&1', $output, $code);

        return $code === 0 && $this->findChromeBinary() !== null;
    }

    private function commandExists(string $command): bool
    {
        $result = trim((string) shell_exec("which {$command} 2>/dev/null"));

        return $result !== '';
    }

    private function detectOpenClawConfig(): ?array
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/root');

        $candidates = [
            $home.'/.openclaw/openclaw.json',
            $home.'/.openclaw-dev/openclaw.json',
        ];

        foreach ($candidates as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $json = json_decode(file_get_contents($path), true);

            if (! is_array($json)) {
                continue;
            }

            $token = data_get($json, 'gateway.auth.token');

            if ($token) {
                return [
                    'token' => $token,
                    'source' => $path,
                ];
            }
        }

        return null;
    }

    private function writeEnvValue(string $key, string $value): bool
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return false;
        }

        $content = file_get_contents($envPath);
        $escaped = addcslashes($value, '"\\');
        $newLine = "{$key}=\"{$escaped}\"";

        // Replace existing key (even if empty)
        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", $newLine, $content);
        } else {
            $content = rtrim($content)."\n{$newLine}\n";
        }

        return file_put_contents($envPath, $content) !== false;
    }

    protected function smokeTest(): void
    {
        $token = env('OC_GATEWAY_TOKEN') ?: config('oc-bridge.gateway.token');
        $url = config('oc-bridge.gateway.url');

        if (empty($token) || empty($url)) {
            $this->line('  [skip] Smoke test — OpenClaw not configured');

            return;
        }

        $this->newLine();
        $this->line('  Running smoke test...');

        try {
            $exitCode = $this->call('agent:message', ['message' => 'Hello']);

            if ($exitCode === 0) {
                $this->line('  [ok] Smoke test passed — agent responded');
            } else {
                $this->warnings[] = 'Smoke test failed — agent:message returned a non-zero exit code';
                $this->warn('  [!!] Smoke test failed — check gateway configuration');
            }
        } catch (\Exception $e) {
            $this->warnings[] = 'Smoke test failed: '.$e->getMessage();
            $this->warn('  [!!] Smoke test failed: '.$e->getMessage());
        }
    }

    protected function printSummary(): void
    {
        $this->newLine();

        if (empty($this->warnings)) {
            $this->info('OpenClaw Bridge installed successfully!');
        } else {
            $this->info('OpenClaw Bridge installed with '.count($this->warnings).' warning(s):');
            $this->newLine();

            foreach ($this->warnings as $i => $warning) {
                $this->warn('  '.($i + 1).'. '.$warning);
            }
        }

        if (! empty($this->autoConfigured)) {
            $this->newLine();
            $this->line('  Auto-configured from OpenClaw:');
            foreach ($this->autoConfigured as $key) {
                $this->line("    - {$key}");
            }
        }

        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. Verify .env has OC_GATEWAY_TOKEN set');
        $this->line('  2. Test the connection: php artisan agent:message "Hello"');
        $this->newLine();
    }
}
