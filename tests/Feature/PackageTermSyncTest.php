<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Sync\PackageSyncRunner;
use ArtisanPackUI\Site\Support\PackageContentType;
use ArtisanPackUI\Site\Support\PackageTermSync;
use ArtisanPackUI\Site\Support\PluginBootstrapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ContentModel\Models\DynamicContentTerm;

/**
 * One blog post `package` term per marketing package (#49): created,
 * renamed and deleted with the package, from the model, Keystone's admin
 * record actions and the sync runner, and removed with the plugin.
 */

/**
 * Persist the `package` taxonomy as the provisioner leaves it, bound to
 * `$binding`.
 */
function bindPackageTaxonomy(string $binding = 'posts'): void
{
    DB::table('taxonomies')->insert([
        'name'              => 'Packages',
        'slug'              => PackageContentType::POST_TAXONOMY,
        'content_type_slug' => $binding,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/**
 * The `package` terms, slug → name.
 *
 * @return array<string, string>
 */
function packageTerms(): array
{
    return DynamicContentTerm::query()
        ->where('taxonomy_slug', PackageContentType::POST_TAXONOMY)
        ->orderBy('slug')
        ->pluck('name', 'slug')
        ->all();
}

function packageTermId(Package $package): ?int
{
    $id = DB::table(PackageTermSync::MAP_TABLE)->where('package_id', $package->getKey())->value('term_id');

    return null === $id ? null : (int) $id;
}

/**
 * Tag blog post `$postId` with `$termId`, as Keystone's Edit Post does.
 */
function assignPackageTerm(int $postId, int $termId): void
{
    DB::table('keystone_dynamic_content_term_assignments')->insert([
        'content_type_slug' => 'post',
        'record_id'         => $postId,
        'term_id'           => $termId,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/**
 * Insert a package row the way Keystone's admin does, with the query
 * builder and no model events.
 */
function insertPackageRow(string $title, ?string $slug = null): int
{
    return (int) DB::table('packages')->insertGetId([
        'title'      => $title,
        'slug'       => $slug,
        'status'     => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('before the taxonomy is the plugin\'s', function (): void {
    it('writes no terms while the package taxonomy is missing', function (): void {
        Package::create(['title' => 'Forms', 'slug' => 'forms']);
        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe([]);
    });

    it('writes no terms into a package taxonomy another type took', function (): void {
        bindPackageTaxonomy('package');

        Package::create(['title' => 'Forms', 'slug' => 'forms']);
        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe([]);
    });
});

describe('with the package taxonomy on posts', function (): void {
    beforeEach(function (): void {
        bindPackageTaxonomy();
    });

    it('gives every package, drafts included, one term named and slugged after it', function (): void {
        DB::table('packages')->insert([
            ['title' => 'Accessibility', 'slug' => 'accessibility', 'status' => 'published'],
            ['title' => 'Visual Editor', 'slug' => null, 'status' => 'draft'],
        ]);

        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe(['accessibility' => 'Accessibility', 'visual-editor' => 'Visual Editor']);
    });

    it('is idempotent', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        $termId  = packageTermId($package);

        app(PackageTermSync::class)->syncAll();
        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe(['forms' => 'Forms'])
            ->and(packageTermId($package))->toBe($termId)
            ->and(DB::table(PackageTermSync::MAP_TABLE)->count())->toBe(1);
    });

    it('renames the term in place when the package is renamed, so tagged posts stay tagged', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        $termId  = packageTermId($package);
        assignPackageTerm(7, $termId);

        $package->update(['title' => 'Form Builder', 'slug' => 'form-builder']);

        expect(packageTerms())->toBe(['form-builder' => 'Form Builder'])
            ->and(packageTermId($package))->toBe($termId)
            ->and(DB::table('keystone_dynamic_content_term_assignments')->where('term_id', $termId)->count())->toBe(1);
    });

    it('deletes the term and its post assignments with the package', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        assignPackageTerm(7, packageTermId($package));

        $package->delete();

        expect(packageTerms())->toBe([])
            ->and(DB::table('keystone_dynamic_content_term_assignments')->count())->toBe(0)
            ->and(DB::table(PackageTermSync::MAP_TABLE)->count())->toBe(0);
    });

    it('gives a slug another package\'s term holds a suffix', function (): void {
        Package::create(['title' => 'Forms', 'slug' => 'forms']);
        Package::create(['title' => 'Forms (legacy)', 'slug' => 'forms']);

        expect(packageTerms())->toBe(['forms' => 'Forms', 'forms-2' => 'Forms (legacy)']);
    });

    it('keeps a suffixed slug inside the term slug column', function (): void {
        $slug = str_repeat('a', 255);
        Package::create(['title' => 'First', 'slug' => $slug]);
        Package::create(['title' => 'Second', 'slug' => $slug]);

        $slugs = array_keys(packageTerms());

        expect($slugs)->toHaveCount(2)
            ->and(max(array_map(strlen(...), $slugs)))->toBeLessThanOrEqual(255);
    });

    it('adopts an unclaimed term with the package\'s slug rather than duplicating it', function (): void {
        $existing = DynamicContentTerm::create(['taxonomy_slug' => PackageContentType::POST_TAXONOMY, 'name' => 'forms', 'slug' => 'forms']);

        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);

        expect(packageTermId($package))->toBe((int) $existing->getKey())
            ->and(packageTerms())->toBe(['forms' => 'Forms']);
    });

    it('recreates a term deleted from under its package', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        DynamicContentTerm::query()->whereKey(packageTermId($package))->delete();

        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe(['forms' => 'Forms']);
    });

    it('removes the terms of packages deleted without model events', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        DB::table('packages')->where('id', $package->getKey())->delete();

        app(PackageTermSync::class)->syncAll();

        expect(packageTerms())->toBe([])
            ->and(DB::table(PackageTermSync::MAP_TABLE)->count())->toBe(0);
    });

    it('follows records Keystone\'s admin creates, updates and deletes', function (): void {
        $id = insertPackageRow('Forms', 'forms');
        doAction('keystone.admin.contentTypes.record.created', 'package', [], $id);

        expect(packageTerms())->toBe(['forms' => 'Forms']);

        DB::table('packages')->where('id', $id)->update(['title' => 'Form Builder', 'slug' => 'form-builder']);
        doAction('keystone.admin.contentTypes.record.updated', 'package', [], $id);

        expect(packageTerms())->toBe(['form-builder' => 'Form Builder']);

        $row = (array) DB::table('packages')->find($id);
        DB::table('packages')->where('id', $id)->delete();
        doAction('keystone.admin.contentTypes.record.deleted', 'package', $row, $id);

        expect(packageTerms())->toBe([]);
    });

    it('follows Keystone\'s bulk delete', function (): void {
        $ids = [insertPackageRow('Forms', 'forms'), insertPackageRow('Icons', 'icons')];
        foreach ($ids as $id) {
            doAction('keystone.admin.contentTypes.record.created', 'package', [], $id);
        }

        DB::table('packages')->whereIn('id', $ids)->delete();
        doAction('keystone.admin.contentTypes.records.bulkDeleted', 'package', $ids);

        expect(packageTerms())->toBe([]);
    });

    it('ignores other content types\' record actions', function (): void {
        $id = insertPackageRow('Forms', 'forms');
        doAction('keystone.admin.contentTypes.record.created', 'event', [], $id);

        expect(packageTerms())->toBe([]);
    });

    it('brings the term up to date on Sync now', function (): void {
        Http::preventStrayRequests();
        Http::fake();

        $package = Package::query()->findOrFail(insertPackageRow('Forms', 'forms'));

        app(PackageSyncRunner::class)->runOne($package);

        expect(packageTerms())->toBe(['forms' => 'Forms']);
    });

    it('removes the taxonomy, its terms and their assignments when the plugin is deleted', function (): void {
        $package = Package::create(['title' => 'Forms', 'slug' => 'forms']);
        assignPackageTerm(7, packageTermId($package));
        DB::table('taxonomies')->insert(['name' => 'Package Tags', 'slug' => PackageContentType::TAG_TAXONOMY, 'content_type_slug' => 'package']);
        Cache::forever(PackageContentType::provisionedMarkerKey(), true);

        PluginBootstrapper::purgeOnDelete('artisanpack-ui');

        expect(packageTerms())->toBe([])
            ->and(DB::table('keystone_dynamic_content_term_assignments')->count())->toBe(0)
            ->and(DB::table(PackageTermSync::MAP_TABLE)->count())->toBe(0)
            ->and(DB::table('taxonomies')->pluck('slug')->all())->toBe([PackageContentType::TAG_TAXONOMY])
            ->and(Cache::has(PackageContentType::provisionedMarkerKey()))->toBeFalse();
    });
});

it('leaves a package taxonomy another type owns alone when the plugin is deleted', function (): void {
    bindPackageTaxonomy('package');

    PluginBootstrapper::purgeOnDelete('artisanpack-ui');

    expect(DB::table('taxonomies')->where('slug', PackageContentType::POST_TAXONOMY)->exists())->toBeTrue();
});
