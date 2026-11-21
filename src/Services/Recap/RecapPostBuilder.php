<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\MediaImages;
use ArtisanPackUI\Site\Support\PackageTermSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Works out the recap draft post from a recap context and the AI's draft:
 *
 *   - the title from the title pattern, never the AI;
 *   - the body as visual editor blocks ({@see MarkdownBlocks});
 *   - the Updates category;
 *   - the featured image from the settings, when it still exists;
 *   - the tags: existing tags the AI chose, matched by name or slug, then at
 *     most {@see RecapPrompts::MAX_NEW_TAGS} new ones, at most
 *     {@see RecapPrompts::MAX_TAGS} in all;
 *   - the Packages terms of every package released in the window or named
 *     by the AI.
 *
 * Writing it is a {@see RecapPostWriter}'s job.
 *
 * @since 1.6.0
 */
final class RecapPostBuilder
{
    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly PackageTermSync $terms,
    ) {}

    /**
     * @param  array<string, mixed>  $context  A recap context (see {@see RecapPrompts}).
     * @param  array{body: string, excerpt: string, focusKeyword: string, metaDescription: string, tags: list<string>, newTags: list<string>, packages: list<string>}  $draft
     */
    public function build(array $context, array $draft, int $authorId): RecapPostData
    {
        [$tagIds, $newTags] = $this->tags($draft['tags'], $draft['newTags']);

        return new RecapPostData(
            title: (string) $context['title'],
            excerpt: $draft['excerpt'],
            blocks: MarkdownBlocks::convert($draft['body']),
            featuredImageId: $this->featuredImageId(),
            authorId: $authorId,
            categoryName: RecapPosts::CATEGORY_NAME,
            categorySlug: RecapPosts::CATEGORY_SLUG,
            tagIds: $tagIds,
            newTagNames: $newTags,
            termIds: $this->terms->termIdsFor($this->packageIds($context, $draft['packages'])),
            focusKeyword: $draft['focusKeyword'],
            metaDescription: $draft['metaDescription'],
        );
    }

    private function featuredImageId(): ?int
    {
        $id = $this->settings->recap_featured_image_id;

        return null !== $id && MediaImages::exists($id) ? $id : null;
    }

    /**
     * The existing tag ids to attach and the new tag names to create.
     *
     * A name the AI listed as existing that doesn't exist is treated as a
     * new tag, after the ones it proposed as new.
     *
     * @param  list<string>  $chosen
     * @param  list<string>  $proposed
     *
     * @return array{0: list<int>, 1: list<string>}
     */
    private function tags(array $chosen, array $proposed): array
    {
        $byName = [];
        $bySlug = [];

        foreach (DB::table('post_tags')->get(['id', 'name', 'slug']) as $tag) {
            $byName[mb_strtolower((string) $tag->name)] = (int) $tag->id;
            $bySlug[(string) $tag->slug]                = (int) $tag->id;
        }

        $ids         = [];
        $proposedNew = [];
        $missing     = [];

        foreach (['chosen' => $chosen, 'proposed' => $proposed] as $list => $names) {
            foreach ($names as $name) {
                $slug = Str::slug($name);
                $id   = $byName[mb_strtolower($name)] ?? $bySlug[$slug] ?? null;

                if (null !== $id) {
                    $ids[$id] = $id;
                } elseif ('' !== $slug && 'proposed' === $list) {
                    $proposedNew[$slug] ??= $name;
                } elseif ('' !== $slug) {
                    $missing[$slug] ??= $name;
                }
            }
        }

        $candidates = $proposedNew + $missing;

        $ids = array_slice(array_values($ids), 0, RecapPrompts::MAX_TAGS);
        $new = array_slice(array_values($candidates), 0, max(0, min(RecapPrompts::MAX_NEW_TAGS, RecapPrompts::MAX_TAGS - count($ids))));

        return [$ids, $new];
    }

    /**
     * Every package released in the window, then every package the AI
     * named (matched by title, case-insensitively).
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $named
     *
     * @return list<int>
     */
    private function packageIds(array $context, array $named): array
    {
        $ids = [];

        foreach (is_array($context['releases'] ?? null) ? $context['releases'] : [] as $release) {
            if (is_array($release) && is_numeric($release['packageId'] ?? null)) {
                $ids[(int) $release['packageId']] = (int) $release['packageId'];
            }
        }

        if ([] !== $named) {
            $titles = array_map('mb_strtolower', $named);

            Package::query()->get(['id', 'title'])->each(static function (Package $package) use ($titles, &$ids): void {
                if (in_array(mb_strtolower((string) $package->title), $titles, true)) {
                    $ids[(int) $package->getKey()] = (int) $package->getKey();
                }
            });
        }

        return array_values($ids);
    }
}
