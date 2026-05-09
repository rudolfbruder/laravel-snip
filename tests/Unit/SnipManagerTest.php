<?php

declare(strict_types=1);

use RudolfBruder\LaravelSnip\SnipManager;

it('captures values with label and caller info', function () {
    /** @var SnipManager $manager */
    $manager = app(SnipManager::class)->clear();

    $manager->add(['hello' => 'world'], 'demo');

    expect($manager->count())->toBe(1);

    [$entry] = $manager->entries();

    expect($entry['label'])->toBe('demo')
        ->and($entry['file'])->toBeString()
        ->and($entry['line'])->toBeInt()
        ->and($entry['value']['type'])->toBe('array')
        ->and($entry['value']['children'][0]['key'])->toBe('hello')
        ->and($entry['value']['children'][0]['preview'])->toBe('world');
});

it('preserves order of multiple entries', function () {
    $manager = app(SnipManager::class)->clear();

    $manager->add(1, 'first');
    $manager->add(2, 'second');
    $manager->add(3, 'third');

    expect($manager->count())->toBe(3);
    expect(array_column($manager->entries(), 'label'))->toBe(['first', 'second', 'third']);
});

it('clear empties entries', function () {
    $manager = app(SnipManager::class)->clear();
    $manager->add('x');

    $manager->clear();

    expect($manager->count())->toBe(0)->and($manager->entries())->toBe([]);
});

it('is a no-op when disabled', function () {
    config()->set('snip.enabled', false);

    $manager = app(SnipManager::class)->clear();
    $manager->add('hidden', 'wont-store');

    expect($manager->count())->toBe(0);
});

it('respects max_entries_per_request limit', function () {
    config()->set('snip.limits.max_entries_per_request', 3);

    $manager = app(SnipManager::class)->clear();

    for ($i = 0; $i < 10; $i++) {
        $manager->add($i);
    }

    expect($manager->count())->toBe(3);
});

it('redacts configured keys', function () {
    config()->set('snip.redact_keys', ['password', 'token']);

    $manager = app(SnipManager::class)->clear();
    $manager->add(['password' => 'super-secret', 'token' => 'abc', 'name' => 'Rudo']);

    $children = $manager->entries()[0]['value']['children'];
    $byKey = array_column($children, null, 'key');

    expect($byKey['password']['preview'])->toBe('***REDACTED***')
        ->and($byKey['password']['redacted'])->toBeTrue()
        ->and($byKey['token']['preview'])->toBe('***REDACTED***')
        ->and($byKey['name']['preview'])->toBe('Rudo');
});

it('truncates oversized strings', function () {
    config()->set('snip.limits.max_string_length', 10);

    $manager = app(SnipManager::class)->clear();
    $manager->add(str_repeat('a', 30));

    $preview = $manager->entries()[0]['value']['preview'];

    expect($preview)->toContain('… [20 more chars]');
});

it('truncates oversized arrays', function () {
    config()->set('snip.limits.max_array_items', 2);

    $manager = app(SnipManager::class)->clear();
    $manager->add(['a', 'b', 'c', 'd', 'e']);

    $children = $manager->entries()[0]['value']['children'];
    $last = end($children);

    expect($last['type'])->toBe('truncated')
        ->and($last['preview'])->toContain('3 more items');
});

it('handles circular object references without infinite recursion', function () {
    $a = new stdClass;
    $b = new stdClass;
    $a->b = $b;
    $b->a = $a;

    $manager = app(SnipManager::class)->clear();
    $manager->add($a);

    $tree = $manager->entries()[0]['value'];

    expect($tree['type'])->toBe('object');

    $bChild = $tree['children'][0];
    expect($bChild['type'])->toBe('object');

    $aRef = $bChild['children'][0];
    expect($aRef['type'])->toBe('circular');
});

it('records timing relative to request start when no Snip::start mark exists', function () {
    $manager = app(SnipManager::class)->clear();

    usleep(2000); // 2ms

    $manager->timing('checkpoint');

    expect($manager->timingsCount())->toBe(1);

    [$timing] = $manager->timings();

    expect($timing['label'])->toBe('checkpoint')
        ->and($timing['file'])->toBeString()
        ->and($timing['line'])->toBeInt()
        ->and($timing['start_ms'])->toBe(0.0)
        ->and($timing['duration_ms'])->toBeGreaterThan(0.0);
});

it('Snip::start shifts the origin for a labeled timing', function () {
    $manager = app(SnipManager::class)->clear();

    usleep(5000);             // 5ms after request start
    $manager->start('block'); // mark origin here
    usleep(2000);             // 2ms inside the block
    $manager->timing('block');

    [$timing] = $manager->timings();

    // start_ms is approximately the offset from request start to when start() ran
    expect($timing['start_ms'])->toBeGreaterThanOrEqual(4.0)
        ->and($timing['duration_ms'])->toBeGreaterThanOrEqual(1.5)
        ->and($timing['duration_ms'])->toBeLessThan($timing['start_ms'] + $timing['duration_ms'] + 50);
});

it('respects max_timings_per_request limit', function () {
    config()->set('snip.limits.max_timings_per_request', 3);

    $manager = app(SnipManager::class)->clear();

    for ($i = 0; $i < 10; $i++) {
        $manager->timing('lap-'.$i);
    }

    expect($manager->timingsCount())->toBe(3);
});

it('timing is a no-op when disabled', function () {
    config()->set('snip.enabled', false);

    $manager = app(SnipManager::class)->clear();
    $manager->start('block');
    $manager->timing('block');

    expect($manager->timingsCount())->toBe(0)->and($manager->timings())->toBe([]);
});

it('clear empties timings and start marks', function () {
    $manager = app(SnipManager::class)->clear();
    $manager->start('block');
    $manager->timing('block');
    $manager->timing('after');

    expect($manager->timingsCount())->toBe(2);

    $manager->clear();

    expect($manager->timingsCount())->toBe(0)->and($manager->timings())->toBe([]);
});

it('records a milestone with label, caller, and ms-from-request-start', function () {
    $manager = app(SnipManager::class)->clear();

    $manager->milestone('alpha');

    expect($manager->milestonesCount())->toBe(1);

    [$milestone] = $manager->milestones();

    expect($milestone['label'])->toBe('alpha')
        ->and($milestone['file'])->toBeString()
        ->and($milestone['line'])->toBeInt()
        ->and($milestone['time_ms'])->toBeNumeric()
        ->and($milestone['time_ms'])->toBeGreaterThanOrEqual(0.0);
});

it('preserves order of repeated milestone calls', function () {
    $manager = app(SnipManager::class)->clear();

    $manager->milestone('first');
    $manager->milestone('second');
    $manager->milestone('first'); // duplicate label, still recorded

    expect($manager->milestonesCount())->toBe(3);
    expect(array_column($manager->milestones(), 'label'))->toBe(['first', 'second', 'first']);
});

it('respects max_milestones_per_request limit', function () {
    config()->set('snip.limits.max_milestones_per_request', 3);

    $manager = app(SnipManager::class)->clear();

    for ($i = 0; $i < 10; $i++) {
        $manager->milestone('lap-'.$i);
    }

    expect($manager->milestonesCount())->toBe(3);
});

it('milestone is a no-op when disabled', function () {
    config()->set('snip.enabled', false);

    $manager = app(SnipManager::class)->clear();
    $manager->milestone('hidden');

    expect($manager->milestonesCount())->toBe(0)->and($manager->milestones())->toBe([]);
});

it('clear empties milestones', function () {
    $manager = app(SnipManager::class)->clear();
    $manager->milestone('a');
    $manager->milestone('b');

    expect($manager->milestonesCount())->toBe(2);

    $manager->clear();

    expect($manager->milestonesCount())->toBe(0)->and($manager->milestones())->toBe([]);
});

it('captures approximate memory size for snip entries', function () {
    $manager = app(SnipManager::class)->clear();

    $manager->add(['hello' => str_repeat('x', 100)], 'big-string');

    $entry = $manager->entries()[0];

    expect($entry['bytes'])->toBeInt()->toBeGreaterThan(100);
});

it('omits bytes when show_memory is disabled', function () {
    config()->set('snip.show_memory', false);

    $manager = app(SnipManager::class)->clear();
    $manager->add(['hello' => 'world'], 'demo');

    expect($manager->entries()[0]['bytes'])->toBeNull();
});

it('returns null bytes when value cannot be serialized', function () {
    $manager = app(SnipManager::class)->clear();

    $closure = fn () => 'cannot serialize me';
    $manager->add($closure, 'closure');

    expect($manager->entries()[0]['bytes'])->toBeNull();
});
