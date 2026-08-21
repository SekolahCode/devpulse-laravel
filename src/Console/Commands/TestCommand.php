<?php

namespace DevPulse\Laravel\Console\Commands;

use Illuminate\Console\Command;

/**
 * Send a real test event through the configured DSN so a user can confirm
 * their setup works before waiting for a real error. Runs under the CLI
 * SAPI, where devpulse/core's Transport always sends synchronously (its
 * fire-and-forget path is explicitly skipped for `PHP_SAPI === 'cli'`), so
 * the boolean this command reports back reflects the real HTTP outcome.
 */
class TestCommand extends Command
{
    protected $signature = 'devpulse:test';

    protected $description = 'Send a test event to your configured DevPulse DSN to confirm the connection works';

    public function handle(): int
    {
        $dsn = (string) config('devpulse.dsn', '');

        if (empty($dsn)) {
            $this->error('DEVPULSE_DSN is not configured — set it in your .env file, then re-run this command.');

            return self::FAILURE;
        }

        if (!config('devpulse.enabled', true)) {
            $this->warn('DEVPULSE_ENABLED is false — DevPulse will build the event but the underlying client is a no-op, so nothing will actually be sent.');
        }

        $this->info("Sending a test event to: {$dsn}");

        $sent = app('devpulse')->captureMessage(
            'DevPulse test event — if you can see this in your Issues list, your Laravel app is wired up correctly.',
            'info',
            ['platform' => 'laravel', 'source' => 'devpulse:test']
        );

        if ($sent) {
            $this->info('Test event sent successfully. Check your DevPulse Issues list — it should show up in a few seconds.');

            return self::SUCCESS;
        }

        $this->error('The client reported failure sending the test event. Double-check DEVPULSE_DSN and that your DevPulse server is reachable.');

        return self::FAILURE;
    }
}
