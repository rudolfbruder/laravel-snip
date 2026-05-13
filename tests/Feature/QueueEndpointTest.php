<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    config()->set('queue.failed', [
        'driver' => 'database-uuids',
        'database' => 'testing',
        'table' => 'failed_jobs',
    ]);

    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Schema::connection('testing')->create('jobs', function ($table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::connection('testing')->create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
});

it('returns failed jobs filtered by name', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    DB::connection('testing')->table('failed_jobs')->insert([
        [
            'uuid' => 'a',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendEmail', 'attempts' => 3]),
            'exception' => "RuntimeException: oops\nat ...",
            'failed_at' => now(),
        ],
        [
            'uuid' => 'b',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessImage', 'attempts' => 1]),
            'exception' => "TypeError: bad\nat ...",
            'failed_at' => now(),
        ],
    ]);

    $response = $this->getJson('/_snip/queue?state=failed&q=email');

    $response->assertOk();
    expect($response->json('total'))->toBe(1)
        ->and($response->json('items.0.name'))->toBe('App\\Jobs\\SendEmail')
        ->and($response->json('items.0.attempts'))->toBe(3)
        ->and($response->json('items.0.exception'))->toContain('RuntimeException');
});

it('returns aggregated all state with counts', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    DB::connection('testing')->table('failed_jobs')->insert([
        'uuid' => 'fa',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Failer']),
        'exception' => "Oops\nat ...",
        'failed_at' => now(),
    ]);

    DB::connection('testing')->table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\NowJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 10,
            'created_at' => time() - 10,
        ],
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\LaterJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() + 600,
            'created_at' => time(),
        ],
    ]);

    $response = $this->getJson('/_snip/queue?state=all')->json();

    expect($response['total'])->toBe(3)
        ->and($response['counts']['failed'])->toBe(1)
        ->and($response['counts']['pending'])->toBe(1)
        ->and($response['counts']['scheduled'])->toBe(1)
        ->and(array_column($response['items'], 'state'))
        ->toContain('failed', 'pending', 'scheduled');
});

it('returns counts on every state response', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    DB::connection('testing')->table('failed_jobs')->insert([
        'uuid' => 'fa',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Failer']),
        'exception' => "Oops\nat ...",
        'failed_at' => now(),
    ]);

    $response = $this->getJson('/_snip/queue?state=failed')->json();

    expect($response['counts'])->toHaveKey('failed')
        ->and($response['counts']['failed'])->toBe(1);
});

it('returns pending jobs for database queue', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    DB::connection('testing')->table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\NowJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 60,
            'created_at' => time() - 60,
        ],
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\LaterJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() + 3600,
            'created_at' => time(),
        ],
    ]);

    $pending = $this->getJson('/_snip/queue?state=pending')->json();
    expect($pending['total'])->toBe(1)
        ->and($pending['items'][0]['name'])->toBe('App\\Jobs\\NowJob');

    $scheduled = $this->getJson('/_snip/queue?state=scheduled')->json();
    expect($scheduled['total'])->toBe(1)
        ->and($scheduled['items'][0]['name'])->toBe('App\\Jobs\\LaterJob');
});

it('returns 403 when gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    $response = $this->getJson('/_snip/queue?state=failed');

    $response->assertStatus(403);
});

it('returns 404 when queue snapshot disabled', function () {
    config()->set('snip.queue.enabled', false);
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/queue?state=failed');

    $response->assertNotFound();
});

it('returns 400 for invalid state', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/queue?state=garbage');

    $response->assertStatus(400);
});

it('reports completed as unsupported when Horizon absent', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/queue?state=completed');

    $response->assertOk();
    expect($response->json('supported'))->toBeFalse()
        ->and($response->json('message'))->toContain('Horizon');
});

it('merges silenced jobs into completed when include_silenced flag set', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $repository = new class {
        public function getCompleted(): array
        {
            return [
                (object) [
                    'id' => 'c1',
                    'name' => 'App\\Jobs\\Completed',
                    'queue' => 'default',
                    'connection' => 'redis',
                    'payload' => json_encode(['displayName' => 'App\\Jobs\\Completed', 'attempts' => 1]),
                    'completed_at' => time(),
                    'reserved_at' => null,
                ],
            ];
        }

        public function getSilenced(): array
        {
            return [
                (object) [
                    'id' => 's1',
                    'name' => 'App\\Jobs\\Silenced',
                    'queue' => 'default',
                    'connection' => 'redis',
                    'payload' => json_encode(['displayName' => 'App\\Jobs\\Silenced', 'attempts' => 1]),
                    'completed_at' => time(),
                    'reserved_at' => null,
                ],
            ];
        }
    };

    if (! interface_exists('Laravel\\Horizon\\Contracts\\JobRepository')) {
        eval('namespace Laravel\\Horizon\\Contracts; interface JobRepository {}');
    }

    app()->instance('Laravel\\Horizon\\Contracts\\JobRepository', $repository);

    $plain = $this->getJson('/_snip/queue?state=completed')->json();
    expect($plain['supported'])->toBeTrue()
        ->and($plain['total'])->toBe(1)
        ->and($plain['items'][0]['name'])->toBe('App\\Jobs\\Completed')
        ->and($plain['items'][0]['silenced'])->toBeFalse();

    $merged = $this->getJson('/_snip/queue?state=completed&include_silenced=1')->json();
    expect($merged['total'])->toBe(2);
    $names = array_column($merged['items'], 'name');
    expect($names)->toContain('App\\Jobs\\Silenced');

    $silencedRow = collect($merged['items'])->firstWhere('name', 'App\\Jobs\\Silenced');
    expect($silencedRow['silenced'])->toBeTrue();
});
