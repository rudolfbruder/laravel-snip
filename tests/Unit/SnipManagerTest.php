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
