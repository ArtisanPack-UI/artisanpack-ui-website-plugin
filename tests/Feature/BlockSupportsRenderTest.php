<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Blocks\CopyCommandBlock;
use ArtisanPackUI\Site\Blocks\TerminalBlock;
use ArtisanPackUI\VisualEditor\Facades\VisualEditor;
use ArtisanPackUI\VisualEditor\VisualEditorServiceProvider;
use ArtisanPackUI\VisualEditorRendererBlade\BlockRenderer;
use ArtisanPackUI\VisualEditorRendererBlade\VisualEditorRendererBladeServiceProvider;
use Illuminate\Support\Facades\View;

/**
 * Rendered through visual-editor's Blade renderer, the blocks' declared
 * color, border and shadow supports land on their root element. This needs
 * visual-editor 1.15 or later, which compiles supports for dynamic blocks.
 */

beforeEach(function (): void {
    $this->app->register(VisualEditorServiceProvider::class);
    $this->app->register(VisualEditorRendererBladeServiceProvider::class);
    View::addNamespace('site', dirname(__DIR__, 2) . '/resources/views');

    foreach ([TerminalBlock::class, CopyCommandBlock::class] as $class) {
        $block = $this->app->make($class);
        VisualEditor::registerBlockType($class::NAME, $block->metadata());
        VisualEditor::registerDynamicBlock($class::NAME, ['render' => $block->render(...)]);
    }
});

it('applies color, border and shadow picks to the terminal root', function (): void {
    $renderer = app(BlockRenderer::class);
    $html     = $renderer->render([[
        'name'       => TerminalBlock::NAME,
        'attributes' => [
            'backgroundColor' => 'ink',
            'style'           => [
                'border' => ['radius' => '20px', 'width' => '3px'],
                'shadow' => 'var:preset|shadow|deep',
            ],
        ],
    ]]);
    $css      = $renderer->flushSupportAssets()['css'];

    expect($html)->toMatch('/<div class="wp-block-artisanpack-terminal ap-terminal [^"]*has-ink-background-color[^"]*ve-bs-[0-9a-f]+[^"]*" style="[^"]*border-radius: 20px;[^"]*border-width: 3px;/')
        ->and($css)->toContain('box-shadow:var(--wp--preset--shadow--deep)');
});

it('applies color and border picks to the copy command root', function (): void {
    $html = app(BlockRenderer::class)->render([[
        'name'       => CopyCommandBlock::NAME,
        'attributes' => [
            'command'         => 'composer require artisanpack-ui/core',
            'backgroundColor' => 'surface',
            'style'           => ['border' => ['radius' => '999px']],
        ],
    ]]);

    expect($html)->toMatch('/<div class="wp-block-artisanpack-copy-command ap-clipboard [^"]*has-surface-background-color[^"]*"[^>]*style="[^"]*border-radius: 999px;/');
});

