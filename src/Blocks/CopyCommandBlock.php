<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Blocks;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Bordered pill that displays a shell command (or any short string) alongside
 * a click-to-copy button. The rendered markup is compatible with the theme's
 * existing `.ap-clipboard` chip and its `clipboard.js` handler.
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
