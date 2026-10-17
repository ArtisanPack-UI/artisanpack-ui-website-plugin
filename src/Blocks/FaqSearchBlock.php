<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Blocks;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Str;

/**
 * Live search field that filters the accordion panels below it.
 *
 * Typing narrows the Accordion panels in the nearest enclosing section to
 * those whose question or answer contains the query (case-insensitive),
 * and shows an empty-state message when nothing matches. The behaviour is
 * `public/faq-search.js`, which the provider enqueues on every page through
 * `ap.themes.frontendScripts`; without it the field is inert and every
 * panel stays visible.
 *
 * The input has a real (visually hidden) label, and a polite live region
 * announces how many questions are shown, so the filtering is perceivable
 * without sight of the list.
 */
final class FaqSearchBlock
{
    public const NAME = 'artisanpack/faq-search';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'title'       => 'FAQ Search',
            'category'    => 'widgets',
            'icon'        => 'search',
            'description' => 'A live search field that filters the accordion questions below it, with a message when nothing matches.',
            'keywords'    => ['faq', 'search', 'filter', 'accordion', 'questions'],
            'attributes'  => [
                'label' => [
                    'type'      => 'string',
                    'default'   => 'Search questions',
                    'apControl' => [
                        'label' => 'Field label',
                        'help'  => 'Read by screen readers; not shown.',
                    ],
                ],
                'placeholder' => [
                    'type'      => 'string',
                    'default'   => 'Search questions…',
                    'apControl' => ['label' => 'Placeholder'],
                ],
                'emptyText' => [
                    'type'      => 'string',
                    'default'   => 'No questions match “%s”.',
                    'apControl' => [
                        'label' => 'No-results message',
                        'help'  => '%s is replaced with what the visitor typed.',
                    ],
                ],
            ],
            'supports' => [
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
        $label = trim((string) ($attrs['label'] ?? ''));

        return $this->views->make('site::blocks.faq-search', [
            'inputId'     => 'ap-faq-search-' . Str::lower(Str::random(8)),
            'label'       => $label !== '' ? $label : 'Search questions',
            'placeholder' => (string) ($attrs['placeholder'] ?? 'Search questions…'),
            'emptyText'   => (string) ($attrs['emptyText'] ?? 'No questions match “%s”.'),
        ])->render();
    }
}
