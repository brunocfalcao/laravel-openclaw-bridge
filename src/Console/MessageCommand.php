<?php

declare(strict_types=1);

namespace Brunocfalcao\OCBridge\Console;

use Brunocfalcao\OCBridge\Contracts\Gateway;
use Brunocfalcao\OCBridge\Enums\StreamEvent;
use Illuminate\Console\Command;

class MessageCommand extends Command
{
    protected $signature = 'oc-bridge:message
        {message : The message to send to the agent}
        {--agent= : Agent ID to route to (defaults to config)}';

    protected $description = 'Send a message to the agent and display the response (CLI test)';

    public function handle(Gateway $gateway): int
    {
        $message = $this->argument('message');
        $agent = $this->option('agent');

        $this->info('Sending message to agent: '.($agent ?? config('oc-bridge.default_agent')));
        $this->newLine();

        try {
            $gateway->streamMessage(
                message: $message,
                memoryId: 'cli-test',
                onEvent: function (StreamEvent $type, array $data) {
                    match ($type) {
                        StreamEvent::Delta => $this->output->write($data['delta']),
                        StreamEvent::Complete => $this->completedResponse(),
                        StreamEvent::Error => $this->errorResponse($data['message']),
                    };
                },
                agentId: $agent,
            );
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function completedResponse(): void
    {
        $this->newLine(2);
        $this->info('Done.');
    }

    private function errorResponse(string $message): void
    {
        $this->newLine();
        $this->error('Error: '.$message);
    }
}
