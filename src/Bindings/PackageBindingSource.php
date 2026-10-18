<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Bindings;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\PackageFields;
use ArtisanPackUI\VisualEditor\Services\Bindings\BindingContext;
use ArtisanPackUI\VisualEditor\Services\Bindings\BlockBindingSource;
use Illuminate\Database\Eloquent\Model;

/**
 * The `package` block binding source: values a package card shows that
 * no single field holds.
 *
 * The visual editor's bindings insert a field's raw value, with no prefix
 * or template, so `custom_field` can't turn `composer_name` into a copy
 * chip's `composer require …` or `github_repo` into a GitHub link, and it
 * can't reach the stat snapshots at all. This source computes them from
 * the record in scope, which in a query loop is Keystone's generic
 * content-type model over the `packages` table (not {@see Package}), so
 * only its attributes are read. Records from any other table resolve to
 * null and fall through to the binding's `onEmpty` policy.
 *
 * Bind with `{"source":"package","args":{"key":"install_command"}}`.
 *
 * @since 1.2.0
 */
final class PackageBindingSource implements BlockBindingSource
{
    public const NAME = 'package';

    public function name(): string
    {
        return self::NAME;
    }

    public function resolve(BindingContext $context, array $args): mixed
    {
        $model = $context->model();
        $key   = is_string($args['key'] ?? null) ? $args['key'] : '';

        if (! $model instanceof Model || $model->getTable() !== (new Package)->getTable()) {
            return null;
        }

        return match ($key) {
            'install_command' => $this->installCommand($model),
            'github_url'      => $this->gitHubUrl($model),
            'permalink'       => self::text($model->getAttribute('permalink')),
            'version_label'   => null === ($version = self::text($model->getAttribute('version'))) ? null : 'v' . $version,
            'license'         => self::text($model->getAttribute('license')),
            'downloads'       => self::compact($this->stats()->latest((int) $model->getKey())['downloads']),
            'stars'           => self::compact($this->stats()->latest((int) $model->getKey())['stars']),
            'meta'            => $this->meta($model),
            default           => null,
        };
    }

    public function eagerLoadRelations(array $bindingArgs): array
    {
        return [];
    }

    public function availableFields(string $resource, ?string $modelClass = null): array
    {
        return [
            ['key' => 'install_command', 'label' => __('Install command'), 'type' => 'string'],
            ['key' => 'github_url', 'label' => __('GitHub URL'), 'type' => 'url'],
            ['key' => 'permalink', 'label' => __('Package page URL'), 'type' => 'url'],
            ['key' => 'version_label', 'label' => __('Version (v1.2.0)'), 'type' => 'string'],
            ['key' => 'license', 'label' => __('License'), 'type' => 'string'],
            ['key' => 'downloads', 'label' => __('Downloads (12k)'), 'type' => 'string'],
            ['key' => 'stars', 'label' => __('GitHub stars'), 'type' => 'string'],
            ['key' => 'meta', 'label' => __('Meta line (v1.2.0 · MIT · 12k installs)'), 'type' => 'string'],
        ];
    }

    /**
     * A count as a card shows it: `980`, `6.2k`, `12k`, `1.4M`.
     */
    public static function compact(?int $count): ?string
    {
        if (null === $count) {
            return null;
        }

        $format = static fn (float $value, string $unit): string => ($value < 10
            ? preg_replace('/\.0$/', '', number_format($value, 1, '.', ''))
            : (string) (int) round($value)) . $unit;

        return match (true) {
            $count < 1000                => (string) $count,
            round($count / 1000) < 1000  => $format($count / 1000, 'k'),
            default                      => $format($count / 1_000_000, 'M'),
        };
    }

    /**
     * Resolved on each use, not injected: the registry keeps this source
     * for the life of the process, and the lookup is per request.
     */
    private function stats(): PackageStatsLookup
    {
        return app(PackageStatsLookup::class);
    }

    private function installCommand(Model $package): ?string
    {
        $column = PackageFields::registryNameColumn(self::text($package->getAttribute('registry')));
        $name   = null === $column ? null : self::text($package->getAttribute($column));

        if (null === $name) {
            return null;
        }

        return (PackageFields::REGISTRY_NPM === $package->getAttribute('registry') ? 'npm install ' : 'composer require ') . $name;
    }

    private function gitHubUrl(Model $package): ?string
    {
        $repo = self::text($package->getAttribute('github_repo'));

        if (null === $repo || 1 !== preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repo) || str_contains($repo, '..')) {
            return null;
        }

        return 'https://github.com/' . $repo;
    }

    /**
     * Version, licence and installs, as the large card's mono line.
     */
    private function meta(Model $package): ?string
    {
        $version   = self::text($package->getAttribute('version'));
        $downloads = self::compact($this->stats()->latest((int) $package->getKey())['downloads']);

        $parts = array_filter([
            null === $version ? null : 'v' . $version,
            self::text($package->getAttribute('license')),
            null === $downloads ? null : __(':count installs', ['count' => $downloads]),
        ]);

        return [] === $parts ? null : implode(' · ', $parts);
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return '' === $value ? null : $value;
    }
}
