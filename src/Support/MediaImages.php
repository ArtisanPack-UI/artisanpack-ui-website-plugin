<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Images from the host's media library, read straight from its `media`
 * table, for the recap featured image setting.
 *
 * The host's media picker is part of its own bundle and isn't shared with
 * plugins, so the Settings page lists images through this instead. Only
 * images that aren't trashed count. Every method returns nothing, rather
 * than throwing, on an install without a media library.
 *
 * @since 1.6.0
 */
final class MediaImages
{
    /**
     * Images returned per search.
     */
    public const PAGE_SIZE = 24;

    /**
     * Whether `$id` is a media library image that isn't trashed.
     */
    public static function exists(int $id): bool
    {
        return null !== self::find($id);
    }

    /**
     * One image, or null when it doesn't exist, isn't an image or is
     * trashed.
     *
     * @return array{id: int, title: string, url: string|null}|null
     */
    public static function find(int $id): ?array
    {
        if ($id < 1 || ! self::available()) {
            return null;
        }

        $row = self::images()->where('id', $id)->first();

        return null === $row ? null : self::present($row);
    }

    /**
     * The newest images, optionally narrowed to those whose title or file
     * name contains `$search`.
     *
     * @return list<array{id: int, title: string, url: string|null}>
     */
    public static function search(?string $search = null): array
    {
        if (! self::available()) {
            return [];
        }

        $query  = self::images();
        $search = trim((string) $search);

        if ('' !== $search) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where(static function (Builder $query) use ($like): void {
                $query->where('title', 'like', $like)->orWhere('file_name', 'like', $like);
            });
        }

        return $query->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(static fn (object $row): array => self::present($row))
            ->values()
            ->all();
    }

    private static function available(): bool
    {
        try {
            return Schema::hasTable('media');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every media library image that isn't trashed.
     */
    private static function images(): Builder
    {
        return DB::table('media')
            ->select(['id', 'title', 'file_name', 'file_path', 'disk'])
            ->where('mime_type', 'like', 'image/%')
            ->whereNull('deleted_at');
    }

    /**
     * @return array{id: int, title: string, url: string|null}
     */
    private static function present(object $row): array
    {
        $title = trim((string) ($row->title ?? ''));

        return [
            'id'    => (int) $row->id,
            'title' => '' === $title ? (string) ($row->file_name ?? '') : $title,
            'url'   => self::url($row),
        ];
    }

    /**
     * The file's public URL, or null when its disk can't produce one.
     */
    private static function url(object $row): ?string
    {
        try {
            return Storage::disk((string) ($row->disk ?: 'public'))->url((string) $row->file_path);
        } catch (Throwable) {
            return null;
        }
    }
}
