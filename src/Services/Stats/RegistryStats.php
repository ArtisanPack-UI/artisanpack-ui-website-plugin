<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Stats;

use Illuminate\Support\Carbon;

/**
 * What Packagist or npm says about a package: downloads, dependents, the
 * latest stable release and that release's requirements.
 *
 * `requires` holds the constraints the compatibility panel shows: for
 * Packagist, the latest release's `php`, `laravel/framework` and
 * `illuminate/*` requirements; for npm, its `peerDependencies`. npm has no
 * dependents count, so `dependents` is null there.
 *
 * @since 0.4.0
 */
final class RegistryStats
{
    /**
     * @param  array<string, string>  $requires  Package → version constraint.
     */
    public function __construct(
        public readonly string $registry,
        public readonly ?int $downloadsDaily,
        public readonly ?int $downloadsMonthly,
        public readonly ?int $downloadsTotal,
        public readonly ?int $dependents,
        public readonly ?string $latestRelease,
        public readonly ?Carbon $latestReleaseAt,
        public readonly array $requires,
    ) {}
}
