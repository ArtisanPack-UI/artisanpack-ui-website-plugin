<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Blocks;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Bordered pill that displays a shell command (or any short string) alongside
 * a click-to-copy button. The rendered markup is compatible with the theme's
 * existing `.ap-clipboard` chip and its `clipboard.js` handler.
 *
 * Declares color, border (including gradient borders) and shadow supports;
 * visual-editor merges them, plus Effects, into the root element on render.
 * The root carries `wp-block-artisanpack-copy-command` so block style
 * variations and theme defaults target the same element on the front end as
 * the editor's block wrapper does in the canvas.
 */
final class CopyCommandBlock
{
    public const NAME = 'artisanpack/copy-command';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'title'       => 'Copy Command',
            'category'    => 'design',
            'icon'        => 'clipboard',
            'description' => 'A bordered pill that shows a short command and a click-to-copy button.',
            'keywords'    => ['copy', 'clipboard', 'command', 'install'],
            'attributes'  => [
                'command' => [
                    'type'      => 'string',
                    'default'   => 'composer require artisanpack-ui/core',
                    'apControl' => [
                        'label' => 'Command',
                        'help'  => 'The exact value that will be copied to the clipboard when the button is clicked.',
                    ],
                ],
                'prefix' => [
                    'type'      => 'string',
                    'default'   => '$',
                    'apControl' => [
                        'label' => 'Prompt prefix',
                        'help'  => 'Displayed before the command; not included in the copied value. Leave blank for no prefix.',
                    ],
                ],
                'buttonLabel' => [
                    'type'      => 'string',
                    'default'   => 'Copy',
                    'apControl' => ['label' => 'Button label'],
                ],
                'copiedLabel' => [
                    'type'      => 'string',
                    'default'   => 'Copied',
                    'apControl' => ['label' => 'Copied-state label'],
                ],
            ],
            'supports' => [
                'align'  => ['wide'],
                'anchor' => true,
                'html'   => false,
                'spacing' => [
                    'margin'  => ['top', 'bottom'],
                    'padding' => false,
                ],
                'color' => [
                    'gradients'                     => true,
                    '__experimentalDefaultControls' => ['background' => true, 'text' => true],
                ],
                '__experimentalBorder' => [
                    'color'                         => true,
                    'radius'                        => true,
                    'style'                         => true,
                    'width'                         => true,
                    'gradient'                      => true,
                    '__experimentalDefaultControls' => ['color' => true, 'radius' => true, 'width' => true],
                ],
                'shadow' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function render(array $attrs): string
    {
        return $this->views->make('site::blocks.copy-command', [
            'command'     => (string) ($attrs['command'] ?? ''),
            'prefix'      => (string) ($attrs['prefix'] ?? ''),
            'buttonLabel' => (string) ($attrs['buttonLabel'] ?? 'Copy'),
            'copiedLabel' => (string) ($attrs['copiedLabel'] ?? 'Copied'),
        ])->render();
    }
}
