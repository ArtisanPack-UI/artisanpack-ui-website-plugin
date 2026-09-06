<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use ArtisanPackUI\VisualEditor\Concerns\HasBlockContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `package` ContentType records.
 *
 * The Keystone convention is to point every admin-created ContentType at the
 * shared `DynamicContentEditorModel`, letting
 * {@see \Modules\SiteEditor\Resources\KeystoneResourceResolver} rebind the
 * table per-request via `setTable($contentType->table_name)`. That resolver
 * override is a documented no-op in the current release — the
 * `SiteEditorServiceProvider` boot uses `$app->extend(...)` but the resolver
 * is later replaced with `$app->instance(...)`, which writes past the
 * extender. Result: any admin-created ContentType blows up in the visual
 * editor with `Table 'dynamic_content_editor_models' doesn't exist`.
 *
 * Until Keystone lands the real fix, this per-CPT model hard-codes its
 * table name so the visual editor's `findOrFail` runs against `packages`.
 */
final class Package extends Model
{
    use HasBlockContent;

    protected $table = 'packages';

    /** @var array<int, string> */
    protected $fillable = ['title', 'content', 'excerpt', 'status', 'published_at', 'featured_image_id'];

    /**
     * `content` holds the visual editor's block tree JSON — matches the
     * column the ContentModel controller provisions when `editor` is in a
     * type's supports array.
     */
    protected string $blockContentColumn = 'content';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content'      => 'array',
            'published_at' => 'datetime',
        ];
    }
}
