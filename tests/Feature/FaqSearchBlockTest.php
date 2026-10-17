<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Blocks\FaqSearchBlock;
use Illuminate\Support\Facades\View;

/**
 * The FAQ Search block's field is labelled for screen readers, carries the
 * hooks `faq-search.js` binds to, and escapes author-supplied copy.
 */

beforeEach(function (): void {
    View::addNamespace('site', dirname(__DIR__, 2) . '/resources/views');
});

it('labels the search input with a visually hidden label', function (): void {
    $html = app(FaqSearchBlock::class)->render(['label' => 'Search the FAQ']);

    preg_match('/<input\s+id="([^"]+)"/', $html, $input);

    expect($input)->toHaveCount(2)
        ->and($html)->toContain('<label class="screen-reader-text" for="' . $input[1] . '">Search the FAQ</label>')
        ->toContain('type="search"');
});

it('falls back to the default label when the attribute is blank', function (): void {
    $html = app(FaqSearchBlock::class)->render(['label' => '  ']);

    expect($html)->toMatch('/<label class="screen-reader-text" for="[^"]+">Search questions<\/label>/');
});

it('renders the hooks the filter script binds to', function (): void {
    $html = app(FaqSearchBlock::class)->render([]);

    expect($html)->toContain('<div class="wp-block-artisanpack-faq-search ap-faq-search" data-faq-search data-empty-text="No questions match “%s”.">')
        ->toContain('data-faq-search-input')
        ->toContain('<p class="ap-faq-search__empty" data-faq-search-empty hidden></p>')
        ->toContain('aria-live="polite" data-faq-search-status');
});

it('gives each instance its own input id', function (): void {
    $block = app(FaqSearchBlock::class);

    preg_match('/<input\s+id="([^"]+)"/', $block->render([]), $first);
    preg_match('/<input\s+id="([^"]+)"/', $block->render([]), $second);

    expect($first[1])->not->toBe($second[1]);
});

it('escapes the label, placeholder and empty-state copy', function (): void {
    $html = app(FaqSearchBlock::class)->render([
        'label'       => '<b>x</b>',
        'placeholder' => '"><script>alert(1)</script>',
        'emptyText'   => '"><img src=x onerror=alert(1)>',
    ]);

    expect($html)->not->toContain('<script>')
        ->not->toContain('<img')
        ->not->toContain('<b>');
});
