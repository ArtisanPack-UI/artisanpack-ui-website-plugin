<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns the Markdown the AI writes into the visual editor's blocks, the
 * shape the host stores in a post's `block_content`:
 *
 *   - headings become `artisanpack/heading` (levels 2–4; a `#` heading is
 *     treated as level 2, since the post title is the page's only h1),
 *   - paragraphs become `artisanpack/paragraph`,
 *   - lists become `artisanpack/list` with `artisanpack/list-item` children,
 *     nested lists inside their item,
 *   - code blocks become `artisanpack/code`,
 *   - block quotes become their paragraphs, and horizontal rules are dropped.
 *
 * Inline content (links, emphasis, inline code) is rendered to HTML, with
 * raw HTML in the Markdown escaped and unsafe links dropped, which is what
 * the blocks' rich-text attributes hold.
 *
 * The AI is asked for Markdown rather than block JSON because it writes
 * Markdown reliably; the block shapes stay this class's concern.
 *
 * @since 1.6.0
 */
final class MarkdownBlocks
{
    /**
     * @return list<array{name: string, isValid: bool, clientId: string, attributes: array<string, mixed>, innerBlocks: list<array<string, mixed>>}>
     */
    public static function convert(string $markdown): array
    {
        $environment = self::environment();
        $document    = (new MarkdownParser($environment))->parse($markdown);
        $renderer    = new HtmlRenderer($environment);

        return self::blocks($document->children(), $renderer);
    }

    /**
     * @param  iterable<Node>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(iterable $nodes, HtmlRenderer $renderer): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            array_push($blocks, ...self::block($node, $renderer));
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function block(Node $node, HtmlRenderer $renderer): array
    {
        return match (true) {
            $node instanceof Heading       => [self::make('artisanpack/heading', [
                'level'   => max(2, min(4, $node->getLevel())),
                'content' => self::inline($node, $renderer),
            ])],
            $node instanceof Paragraph     => self::paragraph($node, $renderer),
            $node instanceof ListBlock     => [self::listBlock($node, $renderer)],
            $node instanceof FencedCode    => [self::code($node->getLiteral(), self::language($node->getInfo()))],
            $node instanceof IndentedCode  => [self::code($node->getLiteral(), null)],
            $node instanceof BlockQuote    => self::blocks($node->children(), $renderer),
            $node instanceof HtmlBlock     => [self::make('artisanpack/paragraph', ['content' => e(trim($node->getLiteral())), 'dropCap' => false])],
            $node instanceof ThematicBreak => [],
            default                        => self::paragraph($node, $renderer),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function paragraph(Node $node, HtmlRenderer $renderer): array
    {
        $content = self::inline($node, $renderer);

        return '' === $content ? [] : [self::make('artisanpack/paragraph', ['content' => $content, 'dropCap' => false])];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listBlock(ListBlock $list, HtmlRenderer $renderer): array
    {
        $ordered    = ListBlock::TYPE_ORDERED === $list->getListData()->type;
        $attributes = ['ordered' => $ordered, 'values' => ''];
        $start      = $list->getListData()->start;

        if ($ordered && null !== $start && 1 !== $start) {
            $attributes['start'] = $start;
        }

        $items = [];

        foreach ($list->children() as $item) {
            if (! $item instanceof ListItem) {
                continue;
            }

            $text   = [];
            $nested = [];

            foreach ($item->children() as $child) {
                if ($child instanceof ListBlock) {
                    $nested[] = self::listBlock($child, $renderer);
                } else {
                    $text[] = $child instanceof Paragraph ? self::inline($child, $renderer) : trim(strip_tags($renderer->renderNodes([$child])));
                }
            }

            $items[] = self::make('artisanpack/list-item', ['content' => implode('<br>', array_filter($text, static fn (string $line): bool => '' !== $line))], $nested);
        }

        return self::make('artisanpack/list', $attributes, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private static function code(string $literal, ?string $language): array
    {
        $attributes = ['content' => e(rtrim($literal, "\n"))];

        if (null !== $language) {
            $attributes['language'] = $language;
        }

        return self::make('artisanpack/code', $attributes);
    }

    /**
     * A block node's inline children rendered as HTML.
     */
    private static function inline(Node $node, HtmlRenderer $renderer): string
    {
        return trim($renderer->renderNodes($node->children()));
    }

    private static function language(string $info): ?string
    {
        $language = strtolower(trim((string) strtok($info, " \t")));

        return 1 === preg_match('/^[a-z0-9+#._-]{1,30}$/', $language) ? $language : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $innerBlocks
     *
     * @return array{name: string, isValid: bool, clientId: string, attributes: array<string, mixed>, innerBlocks: list<array<string, mixed>>}
     */
    private static function make(string $name, array $attributes, array $innerBlocks = []): array
    {
        return [
            'name'        => $name,
            'isValid'     => true,
            'clientId'    => (string) Str::uuid(),
            'attributes'  => $attributes,
            'innerBlocks' => $innerBlocks,
        ];
    }

    private static function environment(): Environment
    {
        $environment = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 20,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new StrikethroughExtension);

        return $environment;
    }
}
