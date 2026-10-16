<?php

declare(strict_types=1);

use RudolfBruder\LaravelSnip\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/** The payload the panel was injected with, decoded; empty when the page has no panel. */
function snipPayloadFrom(string $body): array
{
    preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match);

    return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES, 'UTF-8'), true) ?? [];
}
