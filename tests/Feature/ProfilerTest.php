<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RudolfBruder\LaravelSnip\Facades\Snip;
use RudolfBruder\LaravelSnip\Profiler;
use RudolfBruder\LaravelSnip\Support\ProfilerListeners;

/** @return array<string, mixed>|null */
function profilerPayload(string $body): ?array
{
    if (! preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match)) {
        return null;
    }

    return json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
}

class ProfilerTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        DB::select('select 1');

        if ($this->fail) {
            throw new RuntimeException('job broke');
        }
    }
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    Gate::define('viewSnip', fn ($user = null) => true);

    // Listeners are registered at boot only when the profiler is on, so switch it on and register
    // them by hand for this file.
    config()->set('snip.profiler.enabled', true);
    app(ProfilerListeners::class)->register();

    Route::get('/__profiler-test/measured', function () {
        $profiler = app(Profiler::class);
        $profiler->surface('category', ['categoryId' => 42]);
        $profiler->markControllerStarted();

        $profiler->measure('load products', function () use ($profiler) {
            DB::select('select 1');
            $profiler->measure('load prices', fn () => Cache::get('missing-key'));
        });

        return response('<html><body>products</body></html>')->header('Content-Type', 'text/html');
    });

    Route::get('/__profiler-test/facade', function () {
        Snip::surface('facade', ['page' => 2]);
        Snip::measure('outer', fn () => Snip::measure('inner', fn () => DB::select('select 1')));
        Snip::profiler()->addContext(['extra' => true]);

        return response('<html><body>facade</body></html>')->header('Content-Type', 'text/html');
    });

    Route::get('/__profiler-test/plain', function () {
        return response('<html><body>plain</body></html>')->header('Content-Type', 'text/html');
    });
});

it('ships a text report with phases, nested steps and the calls made in them', function () {
    $data = profilerPayload($this->get('/__profiler-test/measured')->getContent());

    expect($data['config']['profiler'])->toBeTrue()
        ->and($data['profile']['text'])->toBeString();

    $report = $data['profile']['text'];

    expect($report)
        ->toContain('[category] GET http://localhost/__profiler-test/measured 200')
        ->toContain('context: {"categoryId":42}')
        ->toContain('middleware before controller')
        ->toContain('load products  [sql 1 /')
        ->toContain('    load prices')
        ->toContain('cache hit 0 / miss 1 / write 0')
        ->toContain('slowest sql (1 of 1)')
        ->toContain('select 1  (in: load products)');
});

it('ships the same profile as structured data', function () {
    $profile = profilerPayload($this->get('/__profiler-test/measured')->getContent())['profile'];

    expect($profile['surface'])->toBe('category')
        ->and($profile['response']['status'])->toBe(200)
        ->and(array_column($profile['phases'], 'label'))->toContain('controller')
        ->and($profile['steps'][0])->toMatchArray(['label' => 'load products', 'depth' => 0])
        ->and($profile['steps'][0]['counters'][0])->toMatchArray(['kind' => 'sql', 'count' => 1])
        ->and($profile['steps'][1])->toMatchArray(['label' => 'load prices', 'depth' => 1])
        ->and($profile['cache'])->toBe(['hit' => 0, 'miss' => 1, 'write' => 0])
        ->and($profile['queries']['slowest'][0])->toMatchArray(['sql' => 'select 1', 'step' => 'load products']);
});

it('profiles through the Snip facade, marking the controller start and the viewer', function () {
    $profile = profilerPayload($this->get('/__profiler-test/facade')->getContent())['profile'];

    expect($profile['surface'])->toBe('facade')
        ->and($profile['user'])->toBe('guest')
        ->and($profile['context'])->toBe(['page' => 2, 'extra' => true])
        ->and(array_column($profile['phases'], 'label'))->toContain('middleware before controller', 'controller')
        ->and(array_column($profile['steps'], 'label'))->toBe(['outer', 'inner'])
        ->and($profile['steps'][1]['depth'])->toBe(1)
        ->and($profile['text'])->toContain('[facade] GET http://localhost/__profiler-test/facade 200 | guest |');
});

it('injects the panel for a profiled surface even without other captures', function () {
    expect(profilerPayload($this->get('/__profiler-test/measured')->getContent()))->not->toBeNull();
});

it('leaves unprofiled pages without other captures alone', function () {
    expect(profilerPayload($this->get('/__profiler-test/plain')->getContent()))->toBeNull();
});

it('ships no report when the profiler is off', function () {
    config()->set('snip.profiler.enabled', false);
    config()->set('snip.display_mode', 'always');

    $data = profilerPayload($this->get('/__profiler-test/measured')->getContent());

    expect($data['profile'])->toBeNull()
        ->and($data['config']['profiler'])->toBeFalse();
});

it('ships nothing when the gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    expect($this->get('/__profiler-test/measured')->getContent())->not->toContain('<laravel-snip');
});

it('only runs the closure when the profiler is not armed', function () {
    $profiler = app(Profiler::class);

    expect($profiler->measure('outside a request', fn () => 'value'))->toBe('value')
        ->and($profiler->hasCaptures())->toBeFalse()
        ->and($profiler->report(request()))->toBeNull();
});

it('files Inertia SSR renders under their own kind and explains a failed one', function () {
    config()->set('inertia.ssr.url', 'http://127.0.0.1:13714');

    Route::get('/__profiler-test/ssr', function () {
        $profiler = app(Profiler::class);
        $profiler->surface('ssr');
        $profiler->recordCall('http', 3.0, 'POST http://127.0.0.1:13714/render', 'connection failed');
        $profiler->recordCall('http', 2.0, 'POST http://127.0.0.1:13714/render', '200');
        $profiler->recordCall('http', 1.0, 'GET https://api.example.com/render', '200');

        return response('<html><body>ssr</body></html>')->header('Content-Type', 'text/html');
    });

    $profile = profilerPayload($this->get('/__profiler-test/ssr')->getContent())['profile'];

    expect(array_column($profile['calls'], 'kind'))->toBe(['ssr', 'ssr', 'http'])
        ->and($profile['calls'][0]['note'])->toContain('SSR server not reachable')
        ->and($profile['calls'][1]['note'])->toBeNull()
        ->and($profile['calls'][2]['note'])->toBeNull()
        ->and($profile['text'])->toContain('ssr     POST http://127.0.0.1:13714/render  connection failed')
        ->toContain('php artisan inertia:start-ssr');
});

it('leaves HTTP calls alone when the app has no Inertia SSR', function () {
    Route::get('/__profiler-test/no-ssr', function () {
        $profiler = app(Profiler::class);
        $profiler->surface('no-ssr');
        $profiler->recordCall('http', 1.0, 'POST http://127.0.0.1:13714/render', 'connection failed');

        return response('<html><body>no ssr</body></html>')->header('Content-Type', 'text/html');
    });

    $profile = profilerPayload($this->get('/__profiler-test/no-ssr')->getContent())['profile'];

    expect($profile['calls'][0])->toMatchArray(['kind' => 'http', 'note' => null]);
});

it('lists jobs pushed onto a queue with their connection, queue and delay', function () {
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Route::get('/__profiler-test/queued', function () {
        app(Profiler::class)->surface('queued');
        ProfilerTestJob::dispatch()->onConnection('database')->onQueue('emails')->delay(60);
        ProfilerTestJob::dispatch()->onConnection('database');

        return response('<html><body>queued</body></html>')->header('Content-Type', 'text/html');
    });

    $profile = profilerPayload($this->get('/__profiler-test/queued')->getContent())['profile'];
    $jobs = array_values(array_filter($profile['calls'], fn (array $call) => $call['kind'] === 'queue'));

    expect(array_column($jobs, 'label'))->toBe([ProfilerTestJob::class, ProfilerTestJob::class])
        ->and($profile['text'])->toContain('queue   '.ProfilerTestJob::class);

    // Laravel 10 fires JobQueued without the queue name and the delay, so only the connection shows.
    $expected = property_exists(Illuminate\Queue\Events\JobQueued::class, 'delay')
        ? ['database:emails, delay 60s', 'database:default']
        : ['database', 'database'];

    expect(array_column($jobs, 'extra'))->toBe($expected);
});

it('turns a job the sync driver runs into a step that owns its queries', function () {
    config()->set('queue.default', 'sync');
    config()->set('queue.failed.driver', 'null');

    Route::get('/__profiler-test/sync', function () {
        app(Profiler::class)->surface('sync');
        ProfilerTestJob::dispatch();

        try {
            ProfilerTestJob::dispatch(fail: true);
        } catch (RuntimeException) {
        }

        return response('<html><body>sync</body></html>')->header('Content-Type', 'text/html');
    });

    $profile = profilerPayload($this->get('/__profiler-test/sync')->getContent())['profile'];
    $jobs = array_values(array_filter($profile['calls'], fn (array $call) => $call['kind'] === 'queue'));

    expect(array_column($jobs, 'extra'))->toBe(['sync, ran inline', 'sync, failed'])
        ->and(array_column($jobs, 'label'))->toBe([ProfilerTestJob::class, ProfilerTestJob::class])
        ->and(array_column($profile['steps'], 'label'))->toBe(['job '.ProfilerTestJob::class, 'job '.ProfilerTestJob::class])
        ->and(array_column($profile['steps'][0]['counters'], 'kind'))->toContain('sql')
        ->and(array_column($profile['steps'], 'depth'))->toBe([0, 0]);
});
