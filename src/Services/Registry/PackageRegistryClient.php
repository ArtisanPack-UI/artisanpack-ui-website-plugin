<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Registry;

use ArtisanPackUI\Site\Exceptions\RegistryException;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads a package's latest stable release from Packagist or npm.
 *
 * Both registries are public, so no credentials are sent. A release counts
 * as stable when its version is plain `major.minor.patch` (an optional
 * leading `v` is dropped): anything with a pre-release or dev suffix is
 * skipped, so a `2.0.0-beta.1` published after `1.4.0` never wins.
 *
 * @since 0.3.0
 */
class PackageRegistryClient
{
    public const PACKAGIST_URL = 'https://repo.packagist.org';

    public const NPM_URL = 'https://registry.npmjs.org';

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
        if (1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name)) {
            throw new RegistryException(__('":name" isn\'t a valid Composer package name.', ['name' => $name]));
        }

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
        if (1 !== preg_match('#^(@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#', $name)) {
            throw new RegistryException(__('":name" isn\'t a valid npm package name.', ['name' => $name]));
        }

        $response = $this->get(self::NPM_URL . '/' . str_replace('%40', '@', rawurlencode($name)), 'npm', $name);
        $latest   = self::stable($response->json('dist-tags.latest'));

        if (null !== $latest) {
            return $latest;
        }

        $versions = $response->json('versions');

        return is_array($versions) ? self::newestStable(array_keys($versions)) : null;
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
