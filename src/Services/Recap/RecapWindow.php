<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use Carbon\CarbonImmutable;

/**
 * The stretch of time a recap covers: from the previous recap's publish
 * time (or seven days before the publish date, when there is no earlier
 * recap) to the end of the publish date, in the site's timezone.
 *
 * @since 1.6.0
 */
final class RecapWindow
{
    /**
     * @param  array{id: int, title: string, publishedAt: string}|null  $previousRecap
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?array $previousRecap = null,
    ) {}

    /**
     * Whether `$moment` falls inside the window, both ends included.
     */
    public function contains(CarbonImmutable $moment): bool
    {
        return $moment->greaterThanOrEqualTo($this->start) && $moment->lessThanOrEqualTo($this->end);
    }

    /**
     * @return array{start: string, end: string, previousRecap: array{id: int, title: string, publishedAt: string}|null}
     */
    public function toArray(): array
    {
        return [
            'start'         => $this->start->toIso8601String(),
            'end'           => $this->end->toIso8601String(),
            'previousRecap' => $this->previousRecap,
        ];
    }

    /**
     * Rebuild a window saved with {@see self::toArray()}.
     *
     * @param  array{start: string, end: string, previousRecap?: array{id: int, title: string, publishedAt: string}|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            CarbonImmutable::parse($data['start']),
            CarbonImmutable::parse($data['end']),
            $data['previousRecap'] ?? null,
        );
    }
}
