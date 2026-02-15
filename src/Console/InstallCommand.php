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

    /**
     * All environment keys managed by the bridge, with descriptions.
     *
     * @var array<string, string>
     */
    private array $envKeys = [
        'OC_GATEWAY_URL' => 'OpenClaw gateway WebSocket URL',
        'OC_GATEWAY_TOKEN' => 'Gateway authentication token',
        'OC_GATEWAY_TIMEOUT' => 'Maximum seconds to wait for a gateway response',
        'OC_DEFAULT_AGENT' => 'Default OpenClaw agent to route messages to',
        'OC_SESSION_PREFIX' => 'Session namespace prefix (isolates apps sharing a gateway)',
        'OC_BROWSER_URL' => 'Headless Chrome DevTools Protocol endpoint for screenshots',
    ];

    /**
     * Default values for env keys (when not auto-detected).
     *
     * @var array<string, string>
     */
    private array $envDefaults = [
        'OC_GATEWAY_URL' => 'ws://127.0.0.1:18789',
        'OC_GATEWAY_TOKEN' => '',
        'OC_GATEWAY_TIMEOUT' => '600',
        'OC_DEFAULT_AGENT' => 'main',
        'OC_SESSION_PREFIX' => 'assistant',
        'OC_BROWSER_URL' => 'http://127.0.0.1:9222',
    ];

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

        // 2. Write the .env section with all keys
        $this->writeEnvSection();

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

        // Check OpenClaw is installed
        if (! $this->detectOpenClawConfig()) {
            $this->error('  [!!] OpenClaw is not installed — no config found in ~/.openclaw/ or ~/.openclaw-dev/');
            $this->line('       Install OpenClaw first, then re-run this command.');

            return false;
        }

        $this->line('  [ok] OpenClaw detected');
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

    /**
     * Write a documented .env section with all bridge keys.
     *
     * Auto-detects values from the OpenClaw config file where possible.
     * Keys that already have a value in .env are preserved.
     */
    protected function writeEnvSection(): void
    {
        $this->newLine();
        $this->line('  Checking environment...');

        $detected = $this->detectOpenClawConfig();

        if ($detected) {
            $this->line("  [>>] Found OpenClaw config ({$detected['source']})");
        }

        // Build the values to write: auto-detect > existing > default.
        $values = [];

        foreach ($this->envKeys as $key => $description) {
            $existing = $this->readEnvValue($key);

            if ($existing !== null && $existing !== '') {
                // Already has a value — keep it
                $values[$key] = $existing;

                continue;
            }

            // Try auto-detection
            $autoValue = $this->autoDetectValue($key, $detected);

            if ($autoValue !== null) {
                $values[$key] = $autoValue;
                $this->autoConfigured[] = $key;

                continue;
            }

            // Use default
            $values[$key] = $this->envDefaults[$key] ?? '';
        }

        // Write the section to .env
        $this->writeEnvBlock($values);

        // Reload environment so the rest of the install sees the new values
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
        }

        $this->callSilently('config:clear');

        // Report results
        foreach ($this->envKeys as $key => $description) {
            $value = $values[$key];
            $source = in_array($key, $this->autoConfigured, true) ? ' (auto-detected)' : '';

            if ($value === '') {
                $this->line("  [--] {$key} not set — {$description}");
            } else {
                $display = $this->isSensitive($key) ? '***' : $value;
                $this->line("  [ok] {$key}: {$display}{$source}");
            }
        }
    }

    /**
     * Auto-detect a value for the given key from the OpenClaw config.
     */
    private function autoDetectValue(string $key, ?array $detected): ?string
    {
        if (! $detected) {
            return null;
        }

        return match ($key) {
            'OC_GATEWAY_TOKEN' => $detected['token'] ?? null,
            'OC_GATEWAY_URL' => $detected['url'] ?? null,
            'OC_DEFAULT_AGENT' => $this->detectDefaultAgent($detected),
            default => null,
        };
    }

    /**
     * Detect the best default agent from the OpenClaw config.
     *
     * Prefers a non-"main" agent if exactly one custom agent exists.
     */
    private function detectDefaultAgent(?array $detected): ?string
    {
        if (! $detected || empty($detected['agents'])) {
            return null;
        }

        $agents = $detected['agents'];

        // If there's only "main", nothing to auto-detect
        if ($agents === ['main']) {
            return null;
        }

        // Filter out "main" — if exactly one custom agent remains, use it
        $custom = array_values(array_filter($agents, fn ($a) => $a !== 'main'));

        if (count($custom) === 1) {
            return $custom[0];
        }

        // Multiple custom agents — don't guess, let the user choose
        return null;
    }

    /**
     * Read a single env value from .env (raw file read, not env()).
     */
    private function readEnvValue(string $key): ?string
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return null;
        }

        $content = file_get_contents($envPath);

        if (preg_match("/^{$key}=[\"']?(.*?)[\"']?$/m", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Write (or replace) the bridge .env section with documented keys.
     *
     * @param  array<string, string>  $values
     */
    private function writeEnvBlock(array $values): void
    {
        $envPath = base_path('.env');
        $content = file_get_contents($envPath);

        // Remove any existing bridge section (between markers)
        $sectionPattern = '/\n*# =+\n# LARAVEL OPENCLAW BRIDGE.*?(?=\n# =+\n[^#]|\n# =+\n*$|\z)/s';
        $content = preg_replace($sectionPattern, '', $content);

        // Also remove any standalone bridge keys that might exist outside the section
        foreach (array_keys($this->envKeys) as $key) {
            $content = preg_replace("/^#[^\n]*\n{$key}=.*\n?/m", '', $content);
            $content = preg_replace("/^{$key}=.*\n?/m", '', $content);
        }

        // Also clean up legacy key names
        $legacyKeys = [
            'OPENCLAW_GATEWAY_URL', 'OPENCLAW_AUTH_TOKEN', 'OPENCLAW_TIMEOUT',
            'OPENCLAW_WORKSPACE', 'JARVIS_SESSION_PREFIX', 'JARVIS_BROWSER_URL',
        ];

        foreach ($legacyKeys as $key) {
            $content = preg_replace("/^#[^\n]*\n{$key}=.*\n?/m", '', $content);
            $content = preg_replace("/^{$key}=.*\n?/m", '', $content);
        }

        // Build the new section
        $section = "\n# ==============================================================================\n";
        $section .= "# LARAVEL OPENCLAW BRIDGE\n";
        $section .= "# ==============================================================================\n";

        foreach ($this->envKeys as $key => $description) {
            $value = $values[$key] ?? '';
            $section .= "\n# {$description}\n";

            // Quote values that contain spaces or special chars
            if ($value !== '' && preg_match('/[\s"\\\\]/', $value)) {
                $section .= "{$key}=\"{$value}\"\n";
            } else {
                $section .= "{$key}={$value}\n";
            }
        }

        $content = rtrim($content)."\n".$section;

        file_put_contents($envPath, $content);
    }

    /**
     * Check if a key contains sensitive data that shouldn't be displayed.
     */
    private function isSensitive(string $key): bool
    {
        return in_array($key, ['OC_GATEWAY_TOKEN'], true);
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

    /**
     * Detect the OpenClaw config and extract gateway + agent info.
     *
     * @return array{token: string, url: string, source: string, agents: list<string>}|null
     */
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

            if (! $token) {
                continue;
            }

            $port = data_get($json, 'gateway.port', 18789);
            $url = "ws://127.0.0.1:{$port}";

            // Discover configured agents
            $agentsDir = dirname($path).'/agents';
            $agents = [];

            if (is_dir($agentsDir)) {
                foreach (scandir($agentsDir) as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    if (is_dir("{$agentsDir}/{$entry}")) {
                        $agents[] = $entry;
                    }
                }
            }

            return [
                'token' => $token,
                'url' => $url,
                'source' => $path,
                'agents' => $agents,
            ];
        }

        return null;
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
        $this->line('  1. Review .env — all bridge keys are in the LARAVEL OPENCLAW BRIDGE section');
        $this->line('  2. Test the connection: php artisan agent:message "Hello"');
        $this->newLine();
    }
}
