<?php

namespace DevPulse\Laravel\Tests\Feature;

use DevPulse\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ReleaseCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A DSN with a real-looking host so baseUrlFromDsn() has something to parse.
        $app['config']->set('devpulse.dsn', 'https://devpulse.test/api/ingest/some-api-key');
    }

    public function test_registers_a_release_via_the_admin_api(): void
    {
        config([
            'devpulse.project_id'  => 'project-uuid',
            'devpulse.admin_token' => 'super-secret-admin-token',
        ]);

        Http::fake([
            'devpulse.test/api/projects/project-uuid/releases' => Http::response(['id' => 'rel-1', 'version' => '1.4.2'], 200),
        ]);

        $this->artisan('devpulse:release', ['version' => '1.4.2', '--ref' => 'abc1234'])
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://devpulse.test/api/projects/project-uuid/releases'
                && $request->hasHeader('Authorization', 'Bearer super-secret-admin-token')
                && $request['version'] === '1.4.2'
                && $request['ref'] === 'abc1234';
        });
    }

    public function test_fails_cleanly_when_project_id_is_missing(): void
    {
        config([
            'devpulse.project_id'  => null,
            'devpulse.admin_token' => 'super-secret-admin-token',
        ]);

        $this->artisan('devpulse:release', ['version' => '1.4.2'])->assertFailed();
    }

    public function test_fails_cleanly_when_admin_token_is_missing(): void
    {
        config([
            'devpulse.project_id'  => 'project-uuid',
            'devpulse.admin_token' => null,
        ]);

        $this->artisan('devpulse:release', ['version' => '1.4.2'])->assertFailed();
    }

    public function test_fails_cleanly_when_the_server_rejects_the_release(): void
    {
        config([
            'devpulse.project_id'  => 'project-uuid',
            'devpulse.admin_token' => 'wrong-token',
        ]);

        Http::fake([
            'devpulse.test/api/projects/project-uuid/releases' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->artisan('devpulse:release', ['version' => '1.4.2'])->assertFailed();
    }
}
