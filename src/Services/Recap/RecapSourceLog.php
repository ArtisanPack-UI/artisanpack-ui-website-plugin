<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

/**
 * What the AI's tools fetched during one run, so it can be saved on the
 * recap session and handed to later turns and to the post draft, which
 * would otherwise never see it.
 *
 * @since 1.6.0
 */
final class RecapSourceLog
{
    /**
     * The longest text kept per source.
     */
    public const TEXT_LIMIT = 8000;

    /** @var array<string, array{url: string, kind: string, title: string, text: string}> */
    private array $sources = [];

    /**
     * Record a source. A later fetch of the same URL replaces the earlier one.
     *
     * @param  array{url: string, kind: string, title: string, text: string}  $source
     */
    public function add(array $source): void
    {
        $source['text']                = mb_substr($source['text'], 0, self::TEXT_LIMIT);
        $this->sources[$source['url']] = $source;
    }

    /**
     * @return list<array{url: string, kind: string, title: string, text: string}>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
