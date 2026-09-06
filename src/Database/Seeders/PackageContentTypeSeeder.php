<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Database\Seeders;

use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\ContentTypeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use ArtisanPackUI\Site\Models\Package;
use Modules\ContentModel\Support\ContentTypeTables;
use RuntimeException;

/**
 * Registers the `package` ContentType and provisions its dedicated
 * `packages` records table — the same two-phase flow the Content Model
 * admin runs when a human creates a type through the UI (see
 * {@see \Modules\ContentModel\Http\Controllers\ContentTypeController::store()}
 * and its `ensureRecordsTable()` helper).
 *
 * Idempotent — safe to re-run.
 */
final class PackageContentTypeSeeder extends Seeder
{
    private const SLUG    = 'package';
    private const SUPPORTS = ['title', 'editor', 'excerpt', 'featured_image'];

    public function run(ContentTypeManager $manager): void
    {
        $tableName = ContentTypeTables::derive(self::SLUG);

        $payload = [
            'name'          => 'Package',
            'slug'          => self::SLUG,
            'table_name'    => $tableName,
            'model_class'   => Package::class,
            'description'   => 'ArtisanPack UI composer packages — each row is one composer package with its metadata (version, license, install command, links).',
            'hierarchical'  => false,
            'has_archive'   => true,
            'archive_slug'  => 'packages',
            'supports'      => self::SUPPORTS,
            'public'        => true,
            'show_in_admin' => true,
            'icon'          => 'fas.cube',
            'menu_position' => 20,
        ];

        if (! $manager->contentTypeExists(self::SLUG)) {
            $manager->createContentType($payload);
        }

        $this->ensureRecordsTable($tableName, self::SUPPORTS);
    }

    /**
     * @param  list<string>  $supports
     */
    private function ensureRecordsTable(string $tableName, array $supports): void
    {
        if (! ContentTypeTables::claim(self::SLUG, $tableName)) {
            throw new RuntimeException(sprintf(
                'Table "%s" is already owned by content type "%s".',
                $tableName,
                (string) ContentTypeTables::ownerSlug($tableName),
            ));
        }

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                $table->string('status', 32)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $supports): void {
            if (in_array('editor', $supports, true) && ! Schema::hasColumn($tableName, 'content')) {
                $table->longText('content')->nullable();
            }
            if (in_array('excerpt', $supports, true) && ! Schema::hasColumn($tableName, 'excerpt')) {
                $table->text('excerpt')->nullable();
            }
            if (in_array('featured_image', $supports, true) && ! Schema::hasColumn($tableName, 'featured_image_id')) {
                $table->unsignedBigInteger('featured_image_id')->nullable();
            }
            if (in_array('author', $supports, true) && ! Schema::hasColumn($tableName, 'author_id')) {
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }
}
