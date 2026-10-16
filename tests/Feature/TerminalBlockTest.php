<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Blocks\TerminalBlock;
use Illuminate\Support\Facades\View;

/**
 * The terminal block exposes color, border and shadow supports, and renders
 * its root with the block class those supports and variations attach to.
 */

beforeEach(function (): void {
    View::addNamespace('site', dirname(__DIR__, 2) . '/resources/views');
});

it('puts the block class on the root so supports and variations land on the panel', function (): void {
    $html = app(TerminalBlock::class)->render(['chromeStyle' => 'browser', 'lines' => '$ php artisan serve']);

    expect($html)->toContain('<div class="wp-block-artisanpack-terminal ap-terminal ap-terminal--browser">');
});

it('declares color, gradient border and shadow supports', function (): void {
    $supports = app(TerminalBlock::class)->metadata()['supports'];

    expect($supports['color'])->toMatchArray(['gradients' => true])
        ->and($supports['__experimentalBorder'])->toMatchArray(['color' => true, 'radius' => true, 'width' => true, 'gradient' => true])
        ->and($supports['shadow'])->toBeTrue();
});
