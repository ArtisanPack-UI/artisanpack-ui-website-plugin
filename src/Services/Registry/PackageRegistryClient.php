<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Registry;

use ArtisanPackUI\Site\Exceptions\RegistryException;
use ArtisanPackUI\Site\Services\Stats\RegistryStats;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Reads a package's latest stable release, and its download and
 * compatibility stats, from Packagist or npm.
 *
 * Both registries are public, so no credentials are sent. A release counts
 * as stable when its version is plain `major.minor.patch` (an optional
 * leading `v` is dropped): anything with a pre-release or dev suffix is
 * skipped, so a `2.0.0-beta.1` published after `1.4.0` never wins.
 *
 * @since 1.0.0
 */
class PackageRegistryClient
{
    public const PACKAGIST_URL = 'https://repo.packagist.org';

    public const NPM_URL = 'https://registry.npmjs.org';

    /** Packagist's package API, which carries downloads and dependents. */
    public const PACKAGIST_API_URL = 'https://packagist.org';

    public const NPM_DOWNLOADS_URL = 'https://api.npmjs.org/downloads';

    /**
     * The first day npm has download counts for.
     */
    public const NPM_DOWNLOADS_START = '2015-01-10';

    /**
     * Days per npm download range query; npm caps a range at 18 months.
     */
    private const NPM_RANGE_DAYS = 540;

    /**
     * The latest stable version, or null when the registry lists none.
     *
     * @param  string  $registry  {@see PackageFields::REGISTRY_PACKAGIST} or {@see PackageFields::REGISTRY_NPM}.
     * @param  string  $name      `vendor/name` for Packagist, `@scope/name` or `name` for npm.
     *
     * @throws RegistryException When the registry can't be reached or doesn't know the package.
     */
    public function latestStableVersion(string $registry, string $name): ?string
    {
        return match ($registry) {
            PackageFields::REGISTRY_PACKAGIST => $this->packagist($name),
            PackageFields::REGISTRY_NPM       => $this->npm($name),
            default                           => throw new RegistryException(__('Unknown registry ":registry".', ['registry' => $registry])),
        };
    }

    /**
     * Downloads, dependents, the latest stable release and its
     * requirements.
     *
     * @throws RegistryException When the registry can't be reached or doesn't know the package.
     */
    public function stats(string $registry, string $name): RegistryStats
    {
        return match ($registry) {
            PackageFields::REGISTRY_PACKAGIST => $this->packagistStats($name),
            PackageFields::REGISTRY_NPM       => $this->npmStats($name),
            default                           => throw new RegistryException(__('Unknown registry ":registry".', ['registry' => $registry])),
        };
    }

    /**
     * The newest stable version in a list of version strings.
     *
     * @param  iterable<mixed>  $versions
     */
    public static function newestStable(iterable $versions): ?string
    {
        $newest = null;

        foreach ($versions as $version) {
            $normalized = self::stable($version);

            if (null !== $normalized && (null === $newest || version_compare($normalized, $newest, '>'))) {
                $newest = $normalized;
            }
        }

        return $newest;
    }

    /**
     * The version without a leading `v` when it is a stable release, or
     * null otherwise.
     */
    public static function stable(mixed $version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        $version = ltrim(trim($version), 'vV');

        return 1 === preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }

    /**
     * Composer v2 metadata (`/p2/{vendor}/{name}.json`) lists tagged
     * releases only; dev branches live in a separate `~dev` file.
     */
    private function packagist(string $name): ?string
    {
        self::assertComposerName($name);

        $response = $this->get(self::PACKAGIST_URL . '/p2/' . $name . '.json', 'Packagist', $name);
        $packages = $response->json('packages');
        $releases = is_array($packages) && is_array($packages[$name] ?? null) ? $packages[$name] : [];

        return self::newestStable(array_map(
            static fn (mixed $release): mixed => is_array($release) ? ($release['version'] ?? null) : null,
            $releases,
        ));
    }

    /**
     * The `latest` dist-tag when it is stable, otherwise the newest stable
     * version the package has published.
     */
    private function npm(string $name): ?string
    {
        return self::npmLatest($this->npmDocument($name));
    }

    /**
     * Packagist's package API: lifetime/monthly/daily downloads, the
     * dependents count, and every release with its `require` and `time`.
     */
    private function packagistStats(string $name): RegistryStats
    {
        self::assertComposerName($name);

        $package  = $this->get(self::PACKAGIST_API_URL . '/packages/' . $name . '.json', 'Packagist', $name)->json('package');
        $package  = is_array($package) ? $package : [];
        $releases = is_array($package['versions'] ?? null) ? array_filter($package['versions'], 'is_array') : [];
        $latest   = self::newestStable(array_map(static fn (array $release): mixed => $release['version'] ?? null, $releases));
        $release  = [];

        foreach ($releases as $candidate) {
            if (null !== $latest && self::stable($candidate['version'] ?? null) === $latest) {
                $release = $candidate;

                break;
            }
        }

        $require = is_array($release['require'] ?? null) ? $release['require'] : [];

        return new RegistryStats(
            registry: PackageFields::REGISTRY_PACKAGIST,
            downloadsDaily: self::nullableInt($package['downloads']['daily'] ?? null),
            downloadsMonthly: self::nullableInt($package['downloads']['monthly'] ?? null),
            downloadsTotal: self::nullableInt($package['downloads']['total'] ?? null),
            dependents: self::nullableInt($package['dependents'] ?? null),
            latestRelease: $latest,
            latestReleaseAt: self::nullableDate($release['time'] ?? null),
            requires: self::constraints(array_filter(
                $require,
                static fn (mixed $constraint, string $dependency): bool => 'php' === $dependency
                    || 'laravel/framework' === $dependency
                    || str_starts_with($dependency, 'illuminate/'),
                ARRAY_FILTER_USE_BOTH,
            )),
        );
    }

    /**
     * npm's registry document for the release and its peer dependencies,
     * and its downloads API for the counts. npm has no lifetime total, so
     * it is summed from range queries since the package was published.
     */
    private function npmStats(string $name): RegistryStats
    {
        $document = $this->npmDocument($name);
        $latest   = self::npmLatest($document);
        $release  = null === $latest ? [] : self::npmRelease($document, $latest);
        $times    = $document->json('time');
        $times    = is_array($times) ? $times : [];

        return new RegistryStats(
            registry: PackageFields::REGISTRY_NPM,
            downloadsDaily: $this->npmDownloads('last-day', $name),
            downloadsMonthly: $this->npmDownloads('last-month', $name),
            downloadsTotal: $this->npmTotalDownloads($name, self::nullableDate($times['created'] ?? null)),
            dependents: null,
            latestRelease: $latest,
            latestReleaseAt: isset($release['version']) ? self::nullableDate($times[$release['version']] ?? null) : null,
            requires: self::constraints(is_array($release['peerDependencies'] ?? null) ? $release['peerDependencies'] : []),
        );
    }

    private function npmDocument(string $name): Response
    {
        if (1 !== preg_match('#^(@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#', $name)) {
            throw new RegistryException(__('":name" isn\'t a valid npm package name.', ['name' => $name]));
        }

        return $this->get(self::NPM_URL . '/' . str_replace('%40', '@', rawurlencode($name)), 'npm', $name);
    }

    /**
     * The `latest` dist-tag when it is stable, otherwise the newest stable
     * version the package has published.
     */
    private static function npmLatest(Response $document): ?string
    {
        $latest = self::stable($document->json('dist-tags.latest'));

        if (null !== $latest) {
            return $latest;
        }

        $versions = $document->json('versions');

        return is_array($versions) ? self::newestStable(array_keys($versions)) : null;
    }

    /**
     * The manifest of the release whose stable form is `$version` (npm
     * keys releases by their published string, which may carry a `v`).
     *
     * @return array<string, mixed>
     */
    private static function npmRelease(Response $document, string $version): array
    {
        $versions = $document->json('versions');

        foreach (is_array($versions) ? $versions : [] as $published => $manifest) {
            if (is_array($manifest) && self::stable((string) $published) === $version) {
                return ['version' => (string) $published, ...$manifest];
            }
        }

        return [];
    }

    private function npmDownloads(string $period, string $name): ?int
    {
        return self::nullableInt($this->get(self::NPM_DOWNLOADS_URL . '/point/' . $period . '/' . $name, 'npm', $name)->json('downloads'));
    }

    private function npmTotalDownloads(string $name, ?Carbon $created): int
    {
        $start = Carbon::parse(self::NPM_DOWNLOADS_START)->max($created ?? Carbon::parse(self::NPM_DOWNLOADS_START))->startOfDay();
        $end   = Carbon::yesterday();
        $total = 0;

        while ($start->lte($end)) {
            $until = $start->copy()->addDays(self::NPM_RANGE_DAYS - 1)->min($end);
            $total += (int) $this->npmDownloads($start->toDateString() . ':' . $until->toDateString(), $name);
            $start = $until->copy()->addDay();
        }

        return $total;
    }

    /**
     * @throws RegistryException
     */
    private static function assertComposerName(string $name): void
    {
        if (1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name)) {
            throw new RegistryException(__('":name" isn\'t a valid Composer package name.', ['name' => $name]));
        }
    }

    /**
     * @param  array<mixed>  $constraints
     *
     * @return array<string, string>
     */
    private static function constraints(array $constraints): array
    {
        $clean = [];

        foreach ($constraints as $dependency => $constraint) {
            if (is_string($dependency) && is_string($constraint)) {
                $clean[$dependency] = $constraint;
            }
        }

        ksort($clean);

        return $clean;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableDate(mixed $value): ?Carbon
    {
        return is_string($value) && '' !== $value ? Carbon::parse($value) : null;
    }

    private function get(string $url, string $registry, string $name): Response
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->get($url);
        } catch (ConnectionException $exception) {
            throw new RegistryException(__('Could not reach :registry.', ['registry' => $registry]), 0, $exception);
        }

        if (404 === $response->status()) {
            throw new RegistryException(__(':registry has no package named ":name".', ['registry' => $registry, 'name' => $name]), 404);
        }

        if ($response->failed()) {
            throw new RegistryException(__(':registry returned HTTP :status for ":name".', [
                'registry' => $registry,
                'status'   => $response->status(),
                'name'     => $name,
            ]), $response->status());
        }

        return $response;
    }
}
