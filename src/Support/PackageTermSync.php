<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Site\Models\Package;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ContentModel\Models\DynamicContentTerm;
use Throwable;

/**
 * Keeps one term in the blog post `package` taxonomy
 * ({@see PackageContentType::POST_TAXONOMY}) per marketing package, so the
 * author never manages those terms by hand.
 *
 * Each term is remembered against its package in `artisanpack_ui_package_terms`,
 * so a renamed package renames its term in place and the posts tagged with
 * it stay tagged. The term takes the package's title as its name and the
 * package's slug (or its slugged title) as its slug; a slug another
 * package's term holds gets a `-2`, `-3`… suffix. When a package has no
 * term yet, an unclaimed term with its slug is adopted rather than
 * duplicated, so a lost mapping or a term made by hand is picked up.
 * Drafts get terms too, so posts can be tagged ahead of a launch.
 *
 * Terms are written through Keystone's `DynamicContentTerm` model, not the
 * query builder, because Keystone's Seo module redirects a renamed term's
 * archive from that model's `updated` event.
 *
 * Every method is a no-op until the `package` taxonomy exists bound to blog
 * posts, so terms are never written into another type's taxonomy that took
 * the slug first.
 *
 * @since 1.5.0
 */
final class PackageTermSync
{
    /** The plugin's package → term map. */
    public const MAP_TABLE = 'artisanpack_ui_package_terms';

    private const TERMS_TABLE = 'keystone_dynamic_content_terms';

    private const ASSIGNMENTS_TABLE = 'keystone_dynamic_content_term_assignments';

    /**
     * Longest base slug, leaving room for a `-N` suffix inside the term
     * table's 255-character `slug` column.
     */
    private const MAX_BASE_SLUG = 240;

    /**
     * Give every package its term, and remove the terms of packages that
     * no longer exist. Idempotent.
     */
    public function syncAll(): void
    {
        if (! $this->isReady()) {
            return;
        }

        // Orphans first, so their slugs are free before live packages claim them.
        DB::table(self::MAP_TABLE)
            ->whereNotIn('package_id', Package::query()->select('id')->toBase())
            ->pluck('package_id')
            ->each(fn (mixed $packageId) => $this->remove((int) $packageId));

        foreach (Package::query()->lazyById() as $package) {
            $this->upsert($package);
        }
    }

    /**
     * Bring package `$packageId`'s term up to date, or remove it when the
     * package is gone.
     */
    public function syncOne(int $packageId): void
    {
        if (! $this->isReady()) {
            return;
        }

        $package = Package::query()->find($packageId);

        null === $package ? $this->remove($packageId) : $this->upsert($package);
    }

    /**
     * Delete package `$packageId`'s term, and with it every post's
     * assignment of that term.
     */
    public function forget(int $packageId): void
    {
        if ($this->isReady()) {
            $this->remove($packageId);
        }
    }

    /**
     * Remove the `package` taxonomy, all of its terms and their post
     * assignments, and the plugin's map: the clean-up when the plugin is
     * deleted. A `package` taxonomy bound to another type isn't the
     * plugin's, so it's left alone. Doesn't need the map, which the plugin's
     * migration rollback may already have dropped.
     */
    public function purge(): void
    {
        if (! Schema::hasTable(self::TERMS_TABLE) || ! $this->ownsTaxonomy()) {
            return;
        }

        $hasMap = Schema::hasTable(self::MAP_TABLE);

        DB::transaction(static function () use ($hasMap): void {
            $termIds = DB::table(self::TERMS_TABLE)
                ->where('taxonomy_slug', PackageContentType::POST_TAXONOMY)
                ->pluck('id');

            DB::table(self::ASSIGNMENTS_TABLE)->whereIn('term_id', $termIds)->delete();
            DB::table(self::TERMS_TABLE)->whereIn('id', $termIds)->delete();
            DB::table('taxonomies')->where('slug', PackageContentType::POST_TAXONOMY)->delete();

            if ($hasMap) {
                DB::table(self::MAP_TABLE)->delete();
            }
        });
    }

    /**
     * Run `$sync`, logging rather than throwing a failure, for the hooks
     * that call this from a package save or delete: a term that can't be
     * written must not fail the save, and the next sync puts it right.
     */
    public static function safely(callable $sync): void
    {
        try {
            $sync(app(self::class));
        } catch (Throwable $exception) {
            Log::warning('ArtisanPack UI plugin could not sync a package\'s blog post term.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Whether terms can be synced: the host's term tables and the plugin's
     * map exist, and the `package` taxonomy is bound to blog posts.
     */
    public function isReady(): bool
    {
        return Schema::hasTable(self::MAP_TABLE)
            && Schema::hasTable(self::TERMS_TABLE)
            && $this->ownsTaxonomy();
    }

    /**
     * Whether the `package` taxonomy exists bound to blog posts.
     */
    private function ownsTaxonomy(): bool
    {
        if (! Schema::hasTable('taxonomies')) {
            return false;
        }

        $owner = DB::table('taxonomies')
            ->where('slug', PackageContentType::POST_TAXONOMY)
            ->value('content_type_slug');

        return is_string($owner) && PackageContentType::isOwnBinding(PackageContentType::POST_TAXONOMY, $owner);
    }

    private function upsert(Package $package): void
    {
        $name = trim((string) $package->title);

        // Nothing to name the term after yet; the save that adds a title syncs it.
        if ('' === $name) {
            return;
        }

        $packageId = (int) $package->getKey();
        $base      = Str::slug((string) ($package->getAttribute('slug') ?? '')) ?: (Str::slug($name) ?: 'package-' . $packageId);
        $base      = rtrim(substr($base, 0, self::MAX_BASE_SLUG), '-');

        DB::transaction(function () use ($packageId, $name, $base): void {
            $term = $this->mappedTerm($packageId) ?? $this->unclaimedTerm($base) ?? new DynamicContentTerm([
                'taxonomy_slug' => PackageContentType::POST_TAXONOMY,
            ]);

            $term->name = $name;
            $term->slug = $this->availableSlug($base, $term->exists ? (int) $term->getKey() : null);

            if (! $term->exists || $term->isDirty()) {
                $term->save();
            }

            $now = now();

            DB::table(self::MAP_TABLE)->upsert(
                [['package_id' => $packageId, 'term_id' => (int) $term->getKey(), 'created_at' => $now, 'updated_at' => $now]],
                ['package_id'],
                ['term_id', 'updated_at'],
            );
        });
    }

    private function remove(int $packageId): void
    {
        DB::transaction(function () use ($packageId): void {
            $term = $this->mappedTerm($packageId);

            if (null !== $term) {
                DB::table(self::ASSIGNMENTS_TABLE)->where('term_id', $term->getKey())->delete();
                $term->delete();
            }

            DB::table(self::MAP_TABLE)->where('package_id', $packageId)->delete();
        });
    }

    /**
     * Package `$packageId`'s term, or null when it has none or the term was
     * deleted from under it.
     */
    private function mappedTerm(int $packageId): ?DynamicContentTerm
    {
        $termId = DB::table(self::MAP_TABLE)->where('package_id', $packageId)->value('term_id');

        if (null === $termId) {
            return null;
        }

        return DynamicContentTerm::query()
            ->where('taxonomy_slug', PackageContentType::POST_TAXONOMY)
            ->find((int) $termId);
    }

    /**
     * The term with slug `$slug` when no package claims it.
     */
    private function unclaimedTerm(string $slug): ?DynamicContentTerm
    {
        return DynamicContentTerm::query()
            ->where('taxonomy_slug', PackageContentType::POST_TAXONOMY)
            ->where('slug', $slug)
            ->whereNotIn('id', DB::table(self::MAP_TABLE)->select('term_id'))
            ->first();
    }

    /**
     * `$base`, or the first `$base-N` no other term in the taxonomy holds.
     */
    private function availableSlug(string $base, ?int $ownTermId): string
    {
        $slug   = $base;
        $suffix = 2;

        while (DynamicContentTerm::query()
            ->where('taxonomy_slug', PackageContentType::POST_TAXONOMY)
            ->where('slug', $slug)
            ->when(null !== $ownTermId, static fn ($query) => $query->whereKeyNot($ownTermId))
            ->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
