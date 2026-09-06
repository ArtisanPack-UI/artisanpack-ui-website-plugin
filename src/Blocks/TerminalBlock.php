<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Blocks;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Terminal / editor / browser chrome window with typed lines.
 *
 * Registered via VisualEditor::registerServerBlock() so the editor synthesizes
 * a preview + inspector without a client build. Lines are a single textarea
 * attribute; per-line styling is derived at render time from prefix markers.
 *
 * Prefixes: `$ ` = command, `✓ ` = success output, `# ` = comment, blank line
 * = blank; everything else renders as neutral output.
 */
final class TerminalBlock
{
    public const NAME = 'artisanpack/terminal';

    /** Default sample content shown when an unauthored block first mounts. */
    private const DEFAULT_LINES = <<<TXT
\$ composer require artisanpack-ui/core
# Using version ^1.2 for artisanpack-ui/core
✓ Installed

\$ php artisan vendor:publish --tag=artisanpack-config
✓ Published config/artisanpack.php
TXT;

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'title'       => 'Terminal',
            'category'    => 'media',
            'icon'        => 'editor-code',
            'description' => 'A terminal-, editor-, or browser-chrome window with typed lines. Prefix each line with `$ ` for a command, `✓ ` for success output, `# ` for a comment.',
            'keywords'    => ['terminal', 'code', 'shell', 'console'],
            'attributes'  => [
                'chromeStyle' => [
                    'type'      => 'string',
                    'enum'      => ['terminal', 'editor', 'browser'],
                    'default'   => 'terminal',
                    'apControl' => [
                        'label'   => 'Chrome style',
                        'options' => [
                            ['label' => 'Terminal', 'value' => 'terminal'],
                            ['label' => 'Editor', 'value' => 'editor'],
                            ['label' => 'Browser', 'value' => 'browser'],
                        ],
                    ],
                ],
                'showChrome' => [
                    'type'      => 'boolean',
                    'default'   => true,
                    'apControl' => ['label' => 'Show window chrome'],
                ],
                'label' => [
                    'type'      => 'string',
                    'default'   => '',
                    'apControl' => [
                        'label' => 'Window label',
                        'help'  => 'Defaults to the chrome style name when blank.',
                    ],
                ],
                'lines' => [
                    'type'      => 'string',
                    'default'   => self::DEFAULT_LINES,
                    'apControl' => [
                        'control' => 'textarea',
                        'label'   => 'Lines',
                        'help'    => 'One line per row. Prefix with "$ " for a command, "✓ " for success output, "# " for a comment.',
                    ],
                ],
            ],
            'supports' => [
                'align'  => ['wide', 'full'],
                'anchor' => true,
                'html'   => false,
                'spacing' => [
                    'margin'  => ['top', 'bottom'],
                    'padding' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function render(array $attrs): string
    {
        $chromeStyle = $this->normalizeChrome($attrs['chromeStyle'] ?? 'terminal');
        $label       = trim((string) ($attrs['label'] ?? ''));

        return $this->views->make('site::blocks.terminal', [
            'chromeStyle' => $chromeStyle,
            'showChrome'  => (bool) ($attrs['showChrome'] ?? true),
            'label'       => $label !== '' ? $label : $this->defaultLabel($chromeStyle),
            'lines'       => $this->parseLines((string) ($attrs['lines'] ?? '')),
        ])->render();
    }

    private function normalizeChrome(mixed $value): string
    {
        $value = is_string($value) ? $value : 'terminal';

        return in_array($value, ['terminal', 'editor', 'browser'], true) ? $value : 'terminal';
    }

    private function defaultLabel(string $chromeStyle): string
    {
        return match ($chromeStyle) {
            'editor'  => 'editor',
            'browser' => 'localhost',
            default   => 'terminal',
        };
    }

    /**
     * Split raw textarea input into typed line rows for the view. Prefixes are
     * stripped from the emitted content so the view can escape and render
     * cleanly with per-type CSS decorations.
     *
     * @return list<array{type:string,content:string}>
     */
    private function parseLines(string $raw): array
    {
        $out = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if ('' === trim($line)) {
                $out[] = ['type' => 'blank', 'content' => ''];

                continue;
            }

            if (str_starts_with($line, '$ ')) {
                $out[] = ['type' => 'command', 'content' => substr($line, 2)];

                continue;
            }

            if (str_starts_with($line, '✓ ')) {
                $out[] = ['type' => 'success', 'content' => substr($line, strlen('✓ '))];

                continue;
            }

            if (str_starts_with($line, '# ')) {
                $out[] = ['type' => 'comment', 'content' => substr($line, 2)];

                continue;
            }

            $out[] = ['type' => 'output', 'content' => $line];
        }

        return $out;
    }
}
