<?php

namespace DevPulse\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Registers a release with the DevPulse server so it shows up on the
 * project's Releases timeline — intended to run as part of a deploy
 * pipeline, e.g. `php artisan devpulse:release $(git rev-parse HEAD)`.
 *
 * Deliberately separate from the DSN-based ingest path: the server's
 * releases endpoint sits behind the SAME admin bearer token used to sign
 * in to the DevPulse dashboard, which grants access to every project on
 * the instance — not just this one. DEVPULSE_ADMIN_TOKEN is therefore a
 * meaningfully more sensitive secret than DEVPULSE_DSN and should be
 * treated accordingly (scoped .env access, not committed, rotated like
 * any other production credential). If DevPulse ever exposes a
 * release-scoped, per-project credential, this command should move to it.
 */
class ReleaseCommand extends Command
{
    protected $signature = 'devpulse:release
        {version : The release/version identifier, e.g. 1.4.2 or a git SHA}
        {--ref= : Git ref or commit SHA (optional, informational)}
        {--url= : Deploy/build URL for this release (optional)}';

    protected $description = 'Register a release with your DevPulse server so it appears on the Releases timeline';

    public function handle(): int
    {
        $dsn        = (string) config('devpulse.dsn', '');
        $projectId  = config('devpulse.project_id');
        $adminToken = config('devpulse.admin_token');

        if (empty($dsn)) {
            $this->error('DEVPULSE_DSN is not configured.');

            return self::FAILURE;
        }

        if (empty($projectId)) {
            $this->error('DEVPULSE_PROJECT_ID is not configured. Find your project ID in the DevPulse dashboard URL (/projects/{id}/issues).');

            return self::FAILURE;
        }

        if (empty($adminToken)) {
            $this->error(
                'DEVPULSE_ADMIN_TOKEN is not configured. This is the same admin token used to sign in to '
                . 'the DevPulse dashboard — it grants access to every project on your DevPulse instance, so '
                . 'treat it like any other production secret.'
            );

            return self::FAILURE;
        }

        $baseUrl = $this->baseUrlFromDsn($dsn);

        if ($baseUrl === null) {
            $this->error("Could not determine your DevPulse server's URL from DEVPULSE_DSN: {$dsn}");

            return self::FAILURE;
        }

        $version = $this->argument('version');

        $response = Http::withToken($adminToken)
            ->timeout((int) config('devpulse.timeout', 2) + 3)
            ->post("{$baseUrl}/api/projects/{$projectId}/releases", array_filter([
                'version' => $version,
                'ref'     => $this->option('ref'),
                'url'     => $this->option('url'),
            ]));

        if (!$response->successful()) {
            $this->error("Failed to register release: HTTP {$response->status()} — {$response->body()}");

            return self::FAILURE;
        }

        $this->info("Registered release v{$version} with DevPulse.");

        return self::SUCCESS;
    }

    /**
     * Derive the server's base URL from the ingest DSN
     * (https://host[:port]/api/ingest/{key} -> https://host[:port]).
     */
    private function baseUrlFromDsn(string $dsn): ?string
    {
        $parts = parse_url($dsn);

        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }
}
