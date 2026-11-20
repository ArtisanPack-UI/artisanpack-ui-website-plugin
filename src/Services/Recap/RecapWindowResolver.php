<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use Carbon\CarbonImmutable;

/**
 * Works out the {@see RecapWindow} for a publish date: everything since the
 * previous recap went out, so a skipped week leaves no gap, or the seven
 * days before the publish date when there is no earlier recap.
 *
 * @since 1.6.0
 */
final class RecapWindowResolver
{
    /**
     * Days covered when there is no earlier recap.
     */
    public const FALLBACK_DAYS = 7;

    public function __construct(private readonly RecapPosts $posts) {}

    /**
     * @param  CarbonImmutable  $publishDate  Any time on the publish date, in the app's timezone.
     */
    public function for(CarbonImmutable $publishDate): RecapWindow
    {
        $end      = $publishDate->endOfDay();
        $previous = $this->posts->latestBefore($publishDate);
        $start    = null === $previous ? null : CarbonImmutable::parse($previous['publishedAt'])->setTimezone($publishDate->getTimezone());

        if (null === $start || $start->greaterThanOrEqualTo($end)) {
            return new RecapWindow($publishDate->subDays(self::FALLBACK_DAYS)->startOfDay(), $end, null);
        }

        return new RecapWindow($start, $end, $previous);
    }
}
