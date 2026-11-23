<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Services\Recap\MarkdownBlocks;

/**
 * The recap draft's Markdown → visual editor blocks (#63).
 */

/**
 * Blocks without their random client ids, for comparing.
 *
 * @param  list<array<string, mixed>>  $blocks
 *
 * @return list<array<string, mixed>>
 */
function withoutClientIds(array $blocks): array
{
    return array_map(static fn (array $block): array => [
        'name'        => $block['name'],
        'attributes'  => $block['attributes'],
        'innerBlocks' => withoutClientIds($block['innerBlocks']),
    ], $blocks);
}

it('turns headings, paragraphs and inline formatting into blocks', function (): void {
    $blocks = MarkdownBlocks::convert("# Big\n\nIntro with **bold**, `code` and [a link](https://github.com/x).\n\n### Detail\n\n#### Deep\n\n##### Deeper");

    expect(withoutClientIds($blocks))->toBe([
        ['name' => 'artisanpack/heading', 'attributes' => ['level' => 2, 'content' => 'Big'], 'innerBlocks' => []],
        ['name' => 'artisanpack/paragraph', 'attributes' => ['content' => 'Intro with <strong>bold</strong>, <code>code</code> and <a href="https://github.com/x">a link</a>.', 'dropCap' => false], 'innerBlocks' => []],
        ['name' => 'artisanpack/heading', 'attributes' => ['level' => 3, 'content' => 'Detail'], 'innerBlocks' => []],
        ['name' => 'artisanpack/heading', 'attributes' => ['level' => 4, 'content' => 'Deep'], 'innerBlocks' => []],
        ['name' => 'artisanpack/heading', 'attributes' => ['level' => 4, 'content' => 'Deeper'], 'innerBlocks' => []],
    ])
        ->and($blocks[0]['isValid'])->toBeTrue()
        ->and($blocks[0]['clientId'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('turns lists into list blocks with nested lists inside their item', function (): void {
    $blocks = withoutClientIds(MarkdownBlocks::convert("- One\n- Two\n  - Two A\n\n3. Third\n4. Fourth"));

    expect($blocks)->toBe([
        ['name' => 'artisanpack/list', 'attributes' => ['ordered' => false, 'values' => ''], 'innerBlocks' => [
            ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'One'], 'innerBlocks' => []],
            ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'Two'], 'innerBlocks' => [
                ['name' => 'artisanpack/list', 'attributes' => ['ordered' => false, 'values' => ''], 'innerBlocks' => [
                    ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'Two A'], 'innerBlocks' => []],
                ]],
            ]],
        ]],
        ['name' => 'artisanpack/list', 'attributes' => ['ordered' => true, 'values' => '', 'start' => 3], 'innerBlocks' => [
            ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'Third'], 'innerBlocks' => []],
            ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'Fourth'], 'innerBlocks' => []],
        ]],
    ]);
});

it('keeps a list item whose text is 0', function (): void {
    $blocks = withoutClientIds(MarkdownBlocks::convert("- 0\n- One"));

    expect(array_column(array_column($blocks[0]['innerBlocks'], 'attributes'), 'content'))->toBe(['0', 'One']);
});

it('turns code blocks into code blocks, escaped', function (): void {
    $blocks = withoutClientIds(MarkdownBlocks::convert("```bash\ncomposer require <pkg>\n```"));

    expect($blocks)->toBe([
        ['name' => 'artisanpack/code', 'attributes' => ['content' => 'composer require &lt;pkg&gt;', 'language' => 'bash'], 'innerBlocks' => []],
    ]);
});

it('escapes raw HTML and drops unsafe links', function (): void {
    $blocks = withoutClientIds(MarkdownBlocks::convert("<script>alert(1)</script>\n\nSee [this](javascript:alert(1)) <b>now</b>."));

    expect($blocks[0]['attributes']['content'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($blocks[1]['attributes']['content'])->not->toContain('javascript:')
        ->and($blocks[1]['attributes']['content'])->toContain('&lt;b&gt;now&lt;/b&gt;');
});

it('keeps block quote paragraphs and drops horizontal rules', function (): void {
    $blocks = withoutClientIds(MarkdownBlocks::convert("> Quoted\n\n---\n\nAfter"));

    expect(array_column($blocks, 'name'))->toBe(['artisanpack/paragraph', 'artisanpack/paragraph'])
        ->and($blocks[0]['attributes']['content'])->toBe('Quoted');
});
