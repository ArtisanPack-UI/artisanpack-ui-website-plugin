<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Blocks\CopyCommandBlock;
use Illuminate\Support\Facades\View;

/**
 * The copy-command block's button names the command it copies, for
 * screen readers, and carries a live region for the "copied" status.
 */

beforeEach(function (): void {
    View::addNamespace('site', dirname(__DIR__, 2) . '/resources/views');
});

it('names the command in the copy button and offers a status region', function (): void {
    $html = app(CopyCommandBlock::class)->render(['command' => 'composer require artisanpack-ui/icons', 'buttonLabel' => 'Copy']);

    expect($html)->not->toContain('aria-label="Copy command"')
        ->toContain('<span class="sr-only"> composer require artisanpack-ui/icons</span>')
        ->toContain('<span class="sr-only" aria-live="polite" data-clipboard-status></span>');
});

it('escapes the command', function (): void {
    $html = app(CopyCommandBlock::class)->render(['command' => '<script>alert(1)</script>']);

    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;');
});
